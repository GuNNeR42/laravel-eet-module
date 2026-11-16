<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Services;

use DateTimeImmutable;
use DOMDocument;
use DOMElement;
use DOMNode;
use Gunner42\LaravelEetModule\Data\ResponseEvaluation;
use Gunner42\LaravelEetModule\Data\TransportResult;
use Gunner42\LaravelEetModule\Data\Warning;
use Gunner42\LaravelEetModule\Enums\AttemptOutcome;
use Gunner42\LaravelEetModule\Enums\Environment;
use Gunner42\LaravelEetModule\Enums\SendingMode;
use ValueError;

/**
 * Evaluates a response in the binding order of R71: transport, safe parsing
 * (R65), value masks (R65), signature of a confirmation (R35), match of
 * uuid_zpravy, mode and environment, classification (R33, R36).
 */
final class ResponseEvaluator
{
    private const string UUID_MASK = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$/D';

    private const string POK_MASK = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-4[0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}-[0-9a-fA-F]{2}$/D';

    private const string DATE_TIME_MASK = '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(Z|[+\-]\d\d:\d\d)$/D';

    private const string ERROR_CODE_MASK = '/^-?\d{1,3}$/D';

    private const string WARNING_CODE_MASK = '/^[1-9]\d{0,2}$/D';

    private const array BOOLEAN_VALUES = ['true', 'false', '1', '0'];

    private const string XINCLUDE = 'http://www.w3.org/2001/XInclude';

    public function __construct(private readonly ResponseSignatureVerifier $verifier) {}

    public function evaluate(
        TransportResult $result,
        string $expectedUuid,
        Environment $environment,
        SendingMode $mode,
        bool $verifySignature,
        int $maxBytes,
    ): ResponseEvaluation {
        // 1. transport
        if ($result->timedOut) {
            return new ResponseEvaluation(AttemptOutcome::Timeout);
        }

        if ($result->failure !== null || $result->status === null || $result->body === null) {
            return new ResponseEvaluation(AttemptOutcome::TransportError, httpStatus: $result->status, errorMessage: $result->failure ?? 'connection');
        }

        $status = $result->status;
        $body = $result->body;
        $globalTransactionId = self::sanitize((string) $result->header('X-Global-Transaction-Id'), 64);
        $failure = static fn (string $category, ?string $xml = null): ResponseEvaluation => new ResponseEvaluation(
            AttemptOutcome::TransportError,
            httpStatus: $status,
            responseXml: $xml,
            globalTransactionId: $globalTransactionId,
            errorMessage: $category,
        );

        if ($status >= 300 && $status < 400) {
            return $failure('redirect');
        }

        $contentEncoding = $result->header('Content-Encoding');

        if ($contentEncoding !== null && strtolower(trim($contentEncoding)) !== 'identity') {
            return $failure('content_encoding');
        }

        if (strlen($body) > $maxBytes) {
            return $failure('too_large');
        }

        // 2. safe parsing
        if (! $this->hasValidEncoding($body)) {
            return $failure('encoding');
        }

        if ($this->hasForbiddenMarkup($body)) {
            return $failure('doctype', $body);
        }

        $document = $this->parse($body);

        if ($document === null) {
            return $failure('invalid_xml', $body);
        }

        if ($this->containsForbiddenNodes($document)) {
            return $failure('doctype', $body);
        }

        $root = $document->documentElement;
        // the first Body; a second Body is rejected by the signature check of a confirmation (R35)
        $soapBody = $root instanceof DOMElement && self::is($root, MessageBuilder::NS_SOAP, 'Envelope')
            ? (self::children($root, MessageBuilder::NS_SOAP, 'Body')[0] ?? null)
            : null;

        if ($soapBody !== null && self::children($soapBody, MessageBuilder::NS_SOAP, 'Fault') !== []) {
            return $failure('soap_fault', $body);
        }

        $odpoved = $soapBody !== null ? $this->odpoved($soapBody) : null;

        if ($odpoved === null) {
            return $failure('structure', $body);
        }

        // 3. value masks
        $header = self::children($odpoved, MessageBuilder::NS_EET, 'Hlavicka')[0];
        $confirmation = self::children($odpoved, MessageBuilder::NS_EET, 'Potvrzeni')[0] ?? null;
        $error = self::children($odpoved, MessageBuilder::NS_EET, 'Chyba')[0] ?? null;
        $answer = $confirmation ?? $error;
        $warningElements = self::children($odpoved, MessageBuilder::NS_EET, 'Varovani');

        if ($answer === null || ! $this->masksMatch($header, $confirmation, $error, $warningElements)) {
            return $failure('mask', $body);
        }

        $uuid = $header->hasAttribute('uuid_zpravy') ? $header->getAttribute('uuid_zpravy') : null;
        $receivedAt = $header->hasAttribute('dat_prij') ? $header->getAttribute('dat_prij') : null;
        $rejectedAt = $header->hasAttribute('dat_odmit') ? $header->getAttribute('dat_odmit') : null;
        $test = $answer->hasAttribute('test') ? $answer->getAttribute('test') : null;
        $testFlag = $test === 'true' || $test === '1';
        $confirmationCode = $confirmation?->getAttribute('pok');
        $errorCode = $error !== null ? (int) $error->getAttribute('kod') : null;
        $errorMessage = $error !== null ? self::sanitize($error->textContent, 100) : null;
        $warnings = array_map(
            static fn (DOMElement $warning): Warning => Warning::fromCode((int) $warning->getAttribute('kod_varov'), (string) self::sanitize($warning->textContent, 100)),
            $warningElements,
        );

        $evaluation = static fn (AttemptOutcome $outcome, ?string $message = null, ?string $subject = null, ?string $fingerprint = null): ResponseEvaluation => new ResponseEvaluation(
            $outcome,
            $status,
            $body,
            $globalTransactionId,
            $errorCode,
            $message,
            $receivedAt,
            $rejectedAt,
            $confirmationCode,
            $testFlag,
            $warnings,
            $subject,
            $fingerprint,
        );

        // 4. signature of a confirmation
        $signer = null;

        if ($confirmation !== null && $verifySignature) {
            $check = $this->verifier->verify($document, $environment, (string) $receivedAt);

            if (! $check['valid']) {
                return new ResponseEvaluation(
                    AttemptOutcome::InvalidResponseSignature,
                    httpStatus: $status,
                    responseXml: $body,
                    globalTransactionId: $globalTransactionId,
                    errorMessage: $check['clockSkew'] ? 'clock_skew' : null,
                );
            }

            $signer = $check;
        }

        // 5. match of uuid_zpravy, mode and environment
        $mismatch = ($confirmation !== null && $uuid === null)
            || ($uuid !== null && strtolower($uuid) !== strtolower($expectedUuid))
            || ($confirmation !== null && $mode === SendingMode::Verification)
            || ($environment === Environment::Production && ($testFlag || ($confirmationCode !== null && str_ends_with(strtolower($confirmationCode), '-ff'))))
            || ($environment === Environment::Playground && ! $testFlag);

        if ($mismatch) {
            return $evaluation(AttemptOutcome::ResponseMismatch, $errorMessage, $signer['subject'] ?? null, $signer['fingerprint'] ?? null);
        }

        // 6. classification
        if ($confirmation !== null) {
            return $evaluation(AttemptOutcome::Confirmed, null, $signer['subject'] ?? null, $signer['fingerprint'] ?? null);
        }

        $outcome = match (true) {
            $errorCode === 0 && $mode === SendingMode::Verification => AttemptOutcome::Verified,
            $errorCode === -1 => AttemptOutcome::TemporaryError,
            in_array($errorCode, [2, 3, 4, 6, 7], true) => AttemptOutcome::Rejected,
            default => AttemptOutcome::AmbiguousError,
        };

        return $evaluation($outcome, $errorMessage);
    }

    /**
     * Keeps ASCII 9, 10, 13 and 32–126 and limits the length (R65).
     */
    public static function sanitize(string $text, int $length): ?string
    {
        $clean = substr((string) preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '', $text), 0, $length);

        return $clean === '' ? null : $clean;
    }

    private function hasValidEncoding(string $body): bool
    {
        $body = self::withoutBom($body);

        if (str_contains($body, "\0") || preg_match('//u', $body) !== 1) {
            return false;
        }

        if (preg_match('/^<\?xml\s[^>]*?\bencoding\s*=\s*(["\'])(.*?)\1/', $body, $match) === 1) {
            return strcasecmp($match[2], 'UTF-8') === 0;
        }

        return true;
    }

    private function hasForbiddenMarkup(string $body): bool
    {
        $body = (string) preg_replace('/^<\?xml\s[^>]*\?>/', '', self::withoutBom($body));

        return stripos($body, '<!DOCTYPE') !== false
            || stripos($body, '<!ENTITY') !== false
            || str_contains($body, '<?')
            || str_contains($body, self::XINCLUDE);
    }

    private function parse(string $body): ?DOMDocument
    {
        $body = self::withoutBom($body);

        if (trim($body) === '') {
            return null;
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);

        try {
            $loaded = $document->loadXML($body, LIBXML_NONET);
        } catch (ValueError) {
            $loaded = false;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $loaded ? $document : null;
    }

    private function containsForbiddenNodes(DOMNode $node): bool
    {
        if ($node instanceof DOMDocument && $node->doctype !== null) {
            return true;
        }

        if (in_array($node->nodeType, [XML_PI_NODE, XML_ENTITY_REF_NODE, XML_ENTITY_NODE, XML_DOCUMENT_TYPE_NODE], true)) {
            return true;
        }

        if ($node instanceof DOMElement && $node->namespaceURI === self::XINCLUDE) {
            return true;
        }

        foreach ($node->childNodes as $child) {
            if ($this->containsForbiddenNodes($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The Body holds exactly one Odpoved with one Hlavicka, exactly one of
     * Potvrzeni/Chyba and 0–10 Varovani (R65).
     */
    private function odpoved(DOMElement $body): ?DOMElement
    {
        $elements = self::elementChildren($body);

        if (count($elements) !== 1 || ! self::is($elements[0], MessageBuilder::NS_EET, 'Odpoved')) {
            return null;
        }

        $odpoved = $elements[0];
        $counts = ['Hlavicka' => 0, 'Potvrzeni' => 0, 'Chyba' => 0, 'Varovani' => 0];

        foreach (self::elementChildren($odpoved) as $child) {
            if ($child->namespaceURI !== MessageBuilder::NS_EET || ! array_key_exists((string) $child->localName, $counts)) {
                return null;
            }

            $counts[(string) $child->localName]++;
        }

        if ($counts['Hlavicka'] !== 1 || $counts['Potvrzeni'] + $counts['Chyba'] !== 1 || $counts['Varovani'] > 10) {
            return null;
        }

        return $odpoved;
    }

    /**
     * @param  list<DOMElement>  $warnings
     */
    private function masksMatch(DOMElement $header, ?DOMElement $confirmation, ?DOMElement $error, array $warnings): bool
    {
        if ($header->hasAttribute('uuid_zpravy') && preg_match(self::UUID_MASK, $header->getAttribute('uuid_zpravy')) !== 1) {
            return false;
        }

        foreach (['dat_prij', 'dat_odmit'] as $attribute) {
            if ($header->hasAttribute($attribute) && ! self::isDateTime($header->getAttribute($attribute))) {
                return false;
            }
        }

        if ($confirmation !== null) {
            if (! $header->hasAttribute('dat_prij') || preg_match(self::POK_MASK, $confirmation->getAttribute('pok')) !== 1) {
                return false;
            }

            if ($confirmation->hasAttribute('test') && ! in_array($confirmation->getAttribute('test'), self::BOOLEAN_VALUES, true)) {
                return false;
            }
        }

        if ($error !== null) {
            if (preg_match(self::ERROR_CODE_MASK, $error->getAttribute('kod')) !== 1) {
                return false;
            }

            if ($error->hasAttribute('test') && ! in_array($error->getAttribute('test'), self::BOOLEAN_VALUES, true)) {
                return false;
            }
        }

        foreach ($warnings as $warning) {
            if (preg_match(self::WARNING_CODE_MASK, $warning->getAttribute('kod_varov')) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * Matches the mask and is a real calendar date and time with an offset
     * within ±14:00 (xs:dateTime), R65.
     */
    private static function isDateTime(string $value): bool
    {
        if (preg_match(self::DATE_TIME_MASK, $value) !== 1) {
            return false;
        }

        if (! str_ends_with($value, 'Z')) {
            [$hours, $minutes] = array_map('intval', explode(':', substr($value, -5)));

            if ($minutes > 59 || $hours * 60 + $minutes > 14 * 60) {
                return false;
            }
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $value);
        $errors = DateTimeImmutable::getLastErrors();

        if ($parsed === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return false;
        }

        $expected = str_ends_with($value, 'Z') ? substr($value, 0, -1).'+00:00' : $value;

        return $parsed->format('Y-m-d\TH:i:sP') === $expected;
    }

    private static function withoutBom(string $body): string
    {
        return str_starts_with($body, "\xEF\xBB\xBF") ? substr($body, 3) : $body;
    }

    public static function is(DOMElement $element, string $namespace, string $localName): bool
    {
        return $element->namespaceURI === $namespace && $element->localName === $localName;
    }

    /**
     * @return list<DOMElement>
     */
    public static function elementChildren(DOMNode $parent): array
    {
        $children = [];

        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $children[] = $child;
            }
        }

        return $children;
    }

    /**
     * @return list<DOMElement>
     */
    public static function children(DOMNode $parent, string $namespace, string $localName): array
    {
        return array_values(array_filter(
            self::elementChildren($parent),
            static fn (DOMElement $child): bool => self::is($child, $namespace, $localName),
        ));
    }
}
