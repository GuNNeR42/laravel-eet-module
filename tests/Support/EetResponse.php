<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Tests\Support;

use Carbon\CarbonImmutable;
use Closure;
use DOMDocument;
use DOMElement;
use RuntimeException;

/**
 * Builds SOAP responses of the EET service (Odpoved) for tests.
 *
 * A confirmation (Potvrzeni) is signed like the real playground response in
 * spec/zadani/pg-vzorek/response-potvrzeni.xml (WS-Security, exc-c14n,
 * SHA-256, RSA-SHA256) with a certificate of the own test CA in
 * tests/fixtures/response-ca. An error response (Chyba) is unsigned.
 *
 * This is test support code, not package code.
 */
final class EetResponse
{
    public const NS_SOAP = 'http://schemas.xmlsoap.org/soap/envelope/';

    public const NS_EET = 'http://fs.gov.cz/eet/schema/v4';

    public const NS_DS = 'http://www.w3.org/2000/09/xmldsig#';

    public const NS_WSSE = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd';

    public const NS_WSU = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-utility-1.0.xsd';

    public const NS_EXC_C14N = 'http://www.w3.org/2001/10/xml-exc-c14n#';

    public const X509V3 = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-x509-token-profile-1.0#X509v3';

    public const BASE64_BINARY = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-soap-message-security-1.0#Base64Binary';

    private ?string $uuid;

    private ?string $confirmationCode = null;

    private ?int $errorCode = null;

    private string $errorMessage = '';

    private ?string $receivedAt = null;

    private ?string $rejectedAt = null;

    private ?string $test = null;

    /** @var list<array{0: string, 1: string}> */
    private array $warnings = [];

    private ?string $signer = 'signer-playground';

    /** @var list<Closure(DOMDocument): void> */
    private array $beforeSigning = [];

    /** @var list<Closure(DOMDocument): void> */
    private array $afterSigning = [];

    /** @var list<Closure(string): string> */
    private array $xmlMutations = [];

    private function __construct(?string $uuid)
    {
        $this->uuid = $uuid;
    }

    /**
     * A signed confirmation (Potvrzeni) with dat_prij = now().
     */
    public static function confirmed(?string $uuid, ?string $confirmationCode = null): self
    {
        $response = new self($uuid);
        $response->confirmationCode = $confirmationCode ?? self::confirmationCodeFor($uuid, '01');
        $response->receivedAt = CarbonImmutable::now()->setTimezone('Europe/Prague')->format('Y-m-d\TH:i:sP');

        return $response;
    }

    /**
     * An unsigned error response (Chyba) with dat_odmit = now().
     */
    public static function error(?string $uuid, int $code, string $message = 'Chyba zpracovani'): self
    {
        $response = new self($uuid);
        $response->errorCode = $code;
        $response->errorMessage = $message;
        $response->rejectedAt = CarbonImmutable::now()->setTimezone('Europe/Prague')->format('Y-m-d\TH:i:sP');
        $response->signer = null;

        return $response;
    }

    public static function confirmationCodeFor(?string $uuid, string $suffix): string
    {
        $hash = hash('sha256', $uuid ?? 'confirmation');

        return sprintf(
            '%s-%s-4%s-a%s-%s-%s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            substr($hash, 13, 3),
            substr($hash, 17, 3),
            substr($hash, 20, 12),
            $suffix,
        );
    }

    public function test(?string $value): self
    {
        $this->test = $value;

        return $this;
    }

    public function receivedAt(?string $value): self
    {
        $this->receivedAt = $value;

        return $this;
    }

    public function rejectedAt(?string $value): self
    {
        $this->rejectedAt = $value;

        return $this;
    }

    public function warning(int|string $code, string $text): self
    {
        $this->warnings[] = [(string) $code, $text];

        return $this;
    }

    /**
     * Signs the response with tests/fixtures/response-ca/<name>.pem and .key.
     */
    public function signedBy(string $name): self
    {
        $this->signer = $name;

        return $this;
    }

    public function unsigned(): self
    {
        $this->signer = null;

        return $this;
    }

    /**
     * @param  Closure(DOMDocument): void  $mutation
     */
    public function beforeSigning(Closure $mutation): self
    {
        $this->beforeSigning[] = $mutation;

        return $this;
    }

    /**
     * @param  Closure(DOMDocument): void  $mutation
     */
    public function afterSigning(Closure $mutation): self
    {
        $this->afterSigning[] = $mutation;

        return $this;
    }

    /**
     * @param  Closure(string): string  $mutation
     */
    public function mutateXml(Closure $mutation): self
    {
        $this->xmlMutations[] = $mutation;

        return $this;
    }

    public function toXml(): string
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->loadXML($this->template());

        foreach ($this->beforeSigning as $mutation) {
            $mutation($document);
        }

        if ($this->signer !== null) {
            self::sign($document, Fixtures::read("response-ca/{$this->signer}.key"));
        }

        foreach ($this->afterSigning as $mutation) {
            $mutation($document);
        }

        $xml = (string) $document->saveXML();

        foreach ($this->xmlMutations as $mutation) {
            $xml = $mutation($xml);
        }

        return $xml;
    }

    /**
     * (Re)computes DigestValue and SignatureValue of the single Signature in
     * the document with the given private key.
     */
    public static function sign(DOMDocument $document, string $privateKeyPem): void
    {
        $signature = self::first($document, self::NS_DS, 'Signature');
        $signedInfo = self::firstIn($signature, self::NS_DS, 'SignedInfo');
        $reference = self::firstIn($signedInfo, self::NS_DS, 'Reference');
        $id = ltrim($reference->getAttribute('URI'), '#');

        $body = null;
        foreach ($document->getElementsByTagNameNS(self::NS_SOAP, 'Body') as $candidate) {
            if ($candidate->getAttributeNS(self::NS_WSU, 'Id') === $id) {
                $body = $candidate;

                break;
            }
        }

        if (! $body instanceof DOMElement) {
            throw new RuntimeException('Signed Body not found.');
        }

        $digest = base64_encode(hash('sha256', (string) $body->C14N(true, false), true));
        self::firstIn($reference, self::NS_DS, 'DigestValue')->nodeValue = $digest;

        $canonicalSignedInfo = (string) $signedInfo->C14N(true, false);
        $key = openssl_pkey_get_private($privateKeyPem);

        if ($key === false || ! openssl_sign($canonicalSignedInfo, $signatureValue, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Signing of the test response failed.');
        }

        self::firstIn($signature, self::NS_DS, 'SignatureValue')->nodeValue = base64_encode((string) $signatureValue);
    }

    public static function first(DOMDocument $document, string $namespace, string $localName): DOMElement
    {
        $element = $document->getElementsByTagNameNS($namespace, $localName)->item(0);

        if (! $element instanceof DOMElement) {
            throw new RuntimeException("Element {$localName} not found.");
        }

        return $element;
    }

    public static function firstIn(DOMElement $parent, string $namespace, string $localName): DOMElement
    {
        $element = $parent->getElementsByTagNameNS($namespace, $localName)->item(0);

        if (! $element instanceof DOMElement) {
            throw new RuntimeException("Element {$localName} not found.");
        }

        return $element;
    }

    private function template(): string
    {
        $header = '<eet:Hlavicka'
            .self::attribute('uuid_zpravy', $this->uuid)
            .self::attribute('dat_prij', $this->errorCode === null ? $this->receivedAt : null)
            .self::attribute('dat_odmit', $this->errorCode !== null ? $this->rejectedAt : null)
            .'/>';

        $result = $this->errorCode === null
            ? '<eet:Potvrzeni'.self::attribute('pok', $this->confirmationCode).self::attribute('test', $this->test).'/>'
            : '<eet:Chyba'.self::attribute('kod', (string) $this->errorCode).self::attribute('test', $this->test).'>'
                .htmlspecialchars($this->errorMessage, ENT_XML1).'</eet:Chyba>';

        $warnings = '';
        foreach ($this->warnings as [$code, $text]) {
            $warnings .= '<eet:Varovani kod_varov="'.htmlspecialchars($code, ENT_XML1).'">'.htmlspecialchars($text, ENT_XML1).'</eet:Varovani>';
        }

        $body = '<soapenv:Body wsu:Id="Body-ee4dcc59-29c0-493f-b638-986a62bce8e5"><eet:Odpoved>'.$header.$result.$warnings.'</eet:Odpoved></soapenv:Body>';

        if ($this->signer === null) {
            return '<?xml version="1.0" encoding="UTF-8"?>'
                .'<soapenv:Envelope xmlns:eet="'.self::NS_EET.'" xmlns:wsu="'.self::NS_WSU.'" xmlns:soapenv="'.self::NS_SOAP.'">'
                .'<soapenv:Header/>'.$body.'</soapenv:Envelope>';
        }

        $token = Fixtures::der("response-ca/{$this->signer}.pem");

        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<soapenv:Envelope xmlns:eet="'.self::NS_EET.'" xmlns:ds="'.self::NS_DS.'" xmlns:wsu="'.self::NS_WSU.'" xmlns:wsse="'.self::NS_WSSE.'" xmlns:soapenv="'.self::NS_SOAP.'">'
            .'<soapenv:Header><wsse:Security soapenv:mustUnderstand="1">'
            .'<wsse:BinarySecurityToken wsu:Id="SecurityToken-a813661f-65ab-49d4-a1b7-986a62bc8a55" EncodingType="'.self::BASE64_BINARY.'" ValueType="'.self::X509V3.'">'.$token.'</wsse:BinarySecurityToken>'
            .'<Signature xmlns="'.self::NS_DS.'"><SignedInfo>'
            .'<CanonicalizationMethod Algorithm="'.self::NS_EXC_C14N.'"/>'
            .'<SignatureMethod Algorithm="http://www.w3.org/2001/04/xmldsig-more#rsa-sha256"/>'
            .'<Reference URI="#Body-ee4dcc59-29c0-493f-b638-986a62bce8e5"><Transforms><Transform Algorithm="'.self::NS_EXC_C14N.'"/></Transforms>'
            .'<DigestMethod Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/><DigestValue></DigestValue></Reference>'
            .'</SignedInfo><SignatureValue></SignatureValue>'
            .'<KeyInfo><wsse:SecurityTokenReference xmlns=""><wsse:Reference URI="#SecurityToken-a813661f-65ab-49d4-a1b7-986a62bc8a55" ValueType="'.self::X509V3.'"/></wsse:SecurityTokenReference></KeyInfo>'
            .'</Signature></wsse:Security></soapenv:Header>'
            .$body.'</soapenv:Envelope>';
    }

    private static function attribute(string $name, ?string $value): string
    {
        return $value === null ? '' : ' '.$name.'="'.htmlspecialchars($value, ENT_XML1 | ENT_QUOTES).'"';
    }
}
