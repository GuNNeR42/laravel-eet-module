<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Services;

use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use Gunner42\LaravelEetModule\Enums\Environment;
use OpenSSLAsymmetricKey;
use OpenSSLCertificate;
use Throwable;

/**
 * Binding verification of the signature of a confirmation (R35). Elements
 * and attributes are compared by (namespace URI, local name); success of a
 * cryptographic operation is only exactly 1 or true.
 */
final class ResponseSignatureVerifier
{
    private const string ID_REFERENCE = '/^#[A-Za-z_][A-Za-z0-9._-]*$/D';

    private const string NCNAME = '/^[A-Za-z_][A-Za-z0-9._-]*$/D';

    private const string RSA_SHA256 = 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256';

    private const string SHA256 = 'http://www.w3.org/2001/04/xmlenc#sha256';

    private const string NS_XML = 'http://www.w3.org/XML/1998/namespace';

    public function __construct(private readonly TrustStore $trustStore) {}

    /**
     * @return array{valid: bool, clockSkew: bool, subject: ?string, fingerprint: ?string}
     */
    public function verify(DOMDocument $document, Environment $environment, string $receivedAt): array
    {
        $invalid = ['valid' => false, 'clockSkew' => false, 'subject' => null, 'fingerprint' => null];

        try {
            $signer = $this->verifiedSigner($document, $environment);
        } catch (Throwable) {
            $signer = null;
        }

        if ($signer === null) {
            return $invalid;
        }

        // 8. time
        $tolerance = ConfigurationValidator::integer('response_clock_tolerance_seconds');

        try {
            $difference = abs(CarbonImmutable::parse($receivedAt)->getTimestamp() - CarbonImmutable::now()->getTimestamp());
        } catch (Throwable) {
            return $invalid;
        }

        if ($difference > $tolerance) {
            return ['valid' => false, 'clockSkew' => true, 'subject' => null, 'fingerprint' => null];
        }

        return ['valid' => true, 'clockSkew' => false, 'subject' => X509::rfc2253Subject($signer), 'fingerprint' => X509::fingerprint($signer)];
    }

    /**
     * Steps 1–7; returns the verified signing certificate.
     */
    private function verifiedSigner(DOMDocument $document, Environment $environment): ?OpenSSLCertificate
    {
        // 1. structure
        $root = $document->documentElement;

        if (! $root instanceof DOMElement || ! ResponseEvaluator::is($root, MessageBuilder::NS_SOAP, 'Envelope')) {
            return null;
        }

        $header = $this->single($root, MessageBuilder::NS_SOAP, 'Header');
        $body = $this->single($root, MessageBuilder::NS_SOAP, 'Body');

        if ($header === null || $body === null || count(ResponseEvaluator::elementChildren($root)) !== 2) {
            return null;
        }

        $security = $this->only($header, [[MessageBuilder::NS_WSSE, 'Security']])[0] ?? null;

        if ($security === null) {
            return null;
        }

        $securityChildren = $this->only($security, [[MessageBuilder::NS_DS, 'Signature'], [MessageBuilder::NS_WSSE, 'BinarySecurityToken']]);

        if ($securityChildren === null) {
            return null;
        }

        [$signature, $token] = $securityChildren;

        if ($token->getAttribute('ValueType') !== MessageBuilder::X509V3 || $token->getAttribute('EncodingType') !== MessageBuilder::BASE64_BINARY) {
            return null;
        }

        $der = base64_decode((string) preg_replace('/[ \t\r\n]+/', '', $token->textContent), true);
        $certificate = is_string($der) && $der !== '' ? X509::read(X509::derToPem($der)) : null;

        if ($certificate === null || X509::toDer($certificate) !== $der) {
            return null;
        }

        // 2. references, 3. algorithms, 4. key
        $signatureChildren = $this->only($signature, [
            [MessageBuilder::NS_DS, 'SignedInfo'],
            [MessageBuilder::NS_DS, 'SignatureValue'],
            [MessageBuilder::NS_DS, 'KeyInfo'],
        ]);

        if ($signatureChildren === null) {
            return null;
        }

        [$signedInfo, $signatureValue, $keyInfo] = $signatureChildren;
        $signedInfoChildren = $this->only($signedInfo, [
            [MessageBuilder::NS_DS, 'CanonicalizationMethod'],
            [MessageBuilder::NS_DS, 'SignatureMethod'],
            [MessageBuilder::NS_DS, 'Reference'],
        ]);

        if ($signedInfoChildren === null) {
            return null;
        }

        [$canonicalization, $signatureMethod, $reference] = $signedInfoChildren;

        if (! $this->referencesUniquely($document, $reference->getAttribute('URI'), $body)) {
            return null;
        }

        $referenceChildren = $this->only($reference, [
            [MessageBuilder::NS_DS, 'Transforms'],
            [MessageBuilder::NS_DS, 'DigestMethod'],
            [MessageBuilder::NS_DS, 'DigestValue'],
        ]);

        if ($referenceChildren === null) {
            return null;
        }

        [$transforms, $digestMethod, $digestValue] = $referenceChildren;
        $transform = $this->only($transforms, [[MessageBuilder::NS_DS, 'Transform']])[0] ?? null;

        if ($transform === null
            || $canonicalization->getAttribute('Algorithm') !== MessageBuilder::NS_EXC_C14N
            || $transform->getAttribute('Algorithm') !== MessageBuilder::NS_EXC_C14N
            || $digestMethod->getAttribute('Algorithm') !== self::SHA256
            || $signatureMethod->getAttribute('Algorithm') !== self::RSA_SHA256) {
            return null;
        }

        $signedInfoPrefixes = $this->prefixList($canonicalization);
        $transformPrefixes = $this->prefixList($transform);

        if ($signedInfoPrefixes === false || $transformPrefixes === false) {
            return null;
        }

        $tokenReference = $this->only($keyInfo, [[MessageBuilder::NS_WSSE, 'SecurityTokenReference']])[0] ?? null;
        $tokenTarget = $tokenReference !== null ? ($this->only($tokenReference, [[MessageBuilder::NS_WSSE, 'Reference']])[0] ?? null) : null;

        if ($tokenTarget === null
            || $tokenTarget->getAttribute('ValueType') !== MessageBuilder::X509V3
            || ! $this->referencesUniquely($document, $tokenTarget->getAttribute('URI'), $token)) {
            return null;
        }

        $publicKey = openssl_pkey_get_public($certificate);
        X509::clearErrors();

        if (! $publicKey instanceof OpenSSLAsymmetricKey) {
            return null;
        }

        $details = openssl_pkey_get_details($publicKey);

        if (! is_array($details) || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA || ! is_int($details['bits'] ?? null) || $details['bits'] < 2048) {
            return null;
        }

        // 5. cryptography over the same DOM the data is read from
        $expectedDigest = base64_decode((string) preg_replace('/\s+/', '', $digestValue->textContent), true);
        $actualDigest = hash('sha256', (string) $body->C14N(true, false, null, $transformPrefixes), true);

        if (! is_string($expectedDigest) || ! hash_equals($expectedDigest, $actualDigest)) {
            return null;
        }

        $signatureBytes = base64_decode((string) preg_replace('/\s+/', '', $signatureValue->textContent), true);

        if (! is_string($signatureBytes) || $signatureBytes === '') {
            return null;
        }

        $verified = openssl_verify((string) $signedInfo->C14N(true, false, null, $signedInfoPrefixes), $signatureBytes, $publicKey, OPENSSL_ALGO_SHA256);
        X509::clearErrors();

        if ($verified !== 1) {
            return null;
        }

        // 6. chain, 7. identity
        return $this->chainIsTrusted($certificate) && $this->identityMatches($certificate, $environment) ? $certificate : null;
    }

    private function single(DOMElement $parent, string $namespace, string $localName): ?DOMElement
    {
        $children = ResponseEvaluator::children($parent, $namespace, $localName);

        return count($children) === 1 ? $children[0] : null;
    }

    /**
     * The element children are exactly the given elements, each once, in any
     * order; returns them in the given order.
     *
     * @param  list<array{string, string}>  $expected
     * @return list<DOMElement>|null
     */
    private function only(DOMElement $parent, array $expected): ?array
    {
        $children = ResponseEvaluator::elementChildren($parent);

        if (count($children) !== count($expected)) {
            return null;
        }

        $found = [];

        foreach ($expected as [$namespace, $localName]) {
            $matches = ResponseEvaluator::children($parent, $namespace, $localName);

            if (count($matches) !== 1) {
                return null;
            }

            $found[] = $matches[0];
        }

        return $found;
    }

    /**
     * The URI is a same-document id reference to the target's wsu:Id and no
     * other element carries that value in Id, ID, xml:id or wsu:Id.
     */
    private function referencesUniquely(DOMDocument $document, string $uri, DOMElement $target): bool
    {
        if (preg_match(self::ID_REFERENCE, $uri) !== 1) {
            return false;
        }

        $id = substr($uri, 1);

        if ($target->getAttributeNS(MessageBuilder::NS_WSU, 'Id') !== $id) {
            return false;
        }

        foreach ($document->getElementsByTagName('*') as $element) {
            if ($element->isSameNode($target)) {
                continue;
            }

            foreach ([
                $element->hasAttributeNS(null, 'Id') ? $element->getAttributeNS(null, 'Id') : null,
                $element->hasAttributeNS(null, 'ID') ? $element->getAttributeNS(null, 'ID') : null,
                $element->hasAttributeNS(self::NS_XML, 'id') ? $element->getAttributeNS(self::NS_XML, 'id') : null,
                $element->hasAttributeNS(MessageBuilder::NS_WSU, 'Id') ? $element->getAttributeNS(MessageBuilder::NS_WSU, 'Id') : null,
            ] as $value) {
                if ($value === $id) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * The only allowed child is InclusiveNamespaces with #default or NCName
     * tokens. Returns null without a list, false when invalid.
     *
     * @return list<string>|false|null
     */
    private function prefixList(DOMElement $method): array|false|null
    {
        $children = ResponseEvaluator::elementChildren($method);

        if ($children === []) {
            return null;
        }

        if (count($children) !== 1 || ! ResponseEvaluator::is($children[0], MessageBuilder::NS_EXC_C14N, 'InclusiveNamespaces')) {
            return false;
        }

        $tokens = preg_split('/[ \t\r\n]+/', trim($children[0]->getAttribute('PrefixList'))) ?: [];
        $tokens = array_values(array_filter($tokens, static fn (string $token): bool => $token !== ''));

        foreach ($tokens as $token) {
            if ($token !== '#default' && preg_match(self::NCNAME, $token) !== 1) {
                return false;
            }
        }

        return $tokens === [] ? null : $tokens;
    }

    /**
     * Leaf → intermediate from the trust store → anchor, verified by
     * signatures and validated against now() (step 6).
     */
    private function chainIsTrusted(OpenSSLCertificate $leaf): bool
    {
        $now = CarbonImmutable::now()->getTimestamp();

        if (! X509::isValidAt($leaf, $now)) {
            return false;
        }

        ['anchors' => $anchors, 'intermediates' => $intermediates] = $this->trustStore->certificates();

        foreach ($intermediates as $intermediate) {
            if (! X509::isIssuedBy($leaf, $intermediate)
                || ! X509::isCa($intermediate)
                || ! X509::hasKeyUsage($intermediate, 'Certificate Sign')
                || ! X509::isValidAt($intermediate, $now)) {
                continue;
            }

            foreach ($anchors as $anchor) {
                if (X509::isValidAt($anchor, $now) && X509::isIssuedBy($intermediate, $anchor)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * CA:FALSE, digitalSignature, one organizationIdentifier, one CN by the
     * environment of the sale (step 7).
     */
    private function identityMatches(OpenSSLCertificate $leaf, Environment $environment): bool
    {
        if (! X509::isNotCa($leaf) || ! X509::hasKeyUsage($leaf, 'Digital Signature')) {
            return false;
        }

        $organizationIdentifiers = X509::subjectValues($leaf, ['organizationIdentifier', X509::OID_ORGANIZATION_IDENTIFIER]);

        if (count($organizationIdentifiers) !== 1 || $organizationIdentifiers[0] !== config('laravel-eet-module.response_signer.organization_identifier')) {
            return false;
        }

        $commonNames = X509::subjectValues($leaf, ['CN']);

        if (count($commonNames) !== 1) {
            return false;
        }

        $expected = config('laravel-eet-module.response_signer.'.$environment->value.'.common_name');

        if (is_string($expected)) {
            return $commonNames[0] === $expected;
        }

        return $environment === Environment::Production && stripos($commonNames[0], 'playground') === false;
    }
}
