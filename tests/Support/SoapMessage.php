<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Tests\Support;

use DOMDocument;
use DOMElement;
use DOMXPath;
use LibXMLError;
use RuntimeException;

/**
 * Reads a SOAP message (the request sent by the package or a response) in
 * tests: Trzba attributes, XSD validation and an independent WS-Security
 * signature check following spec/01-eet-2-0-fakta.md chapter 4.
 *
 * This is test support code, not package code.
 */
final class SoapMessage
{
    private DOMDocument $document;

    public function __construct(public readonly string $xml)
    {
        $this->document = new DOMDocument;

        if (! $this->document->loadXML($xml, LIBXML_NONET)) {
            throw new RuntimeException('The SOAP message is not well-formed XML.');
        }
    }

    /**
     * @return array<string, string>
     */
    public function header(): array
    {
        return $this->attributes(EetResponse::first($this->document, EetResponse::NS_EET, 'Hlavicka'));
    }

    /**
     * @return array<string, string>
     */
    public function data(): array
    {
        return $this->attributes(EetResponse::first($this->document, EetResponse::NS_EET, 'Data'));
    }

    public function uuid(): string
    {
        return $this->header()['uuid_zpravy'] ?? '';
    }

    /**
     * Validates the Trzba element against EETXMLSchema.xsd 4.1.
     *
     * @return list<string> validation errors
     */
    public function schemaErrors(): array
    {
        $trzba = EetResponse::first($this->document, EetResponse::NS_EET, 'Trzba');
        $standalone = new DOMDocument;
        $standalone->appendChild($standalone->importNode($trzba, true));

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $standalone->schemaValidate(Fixtures::path('xsd/EETXMLSchema.xsd'));
        $errors = array_map(static fn (LibXMLError $error): string => trim($error->message), libxml_get_errors());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return array_values($errors);
    }

    /**
     * Local names of the direct children of soap:Header.
     *
     * @return list<string>
     */
    public function headerChildren(): array
    {
        return $this->childNames(EetResponse::first($this->document, EetResponse::NS_SOAP, 'Header'));
    }

    /**
     * Local names of the direct children of wsse:Security.
     *
     * @return list<string>
     */
    public function securityChildren(): array
    {
        return $this->childNames(EetResponse::first($this->document, EetResponse::NS_WSSE, 'Security'));
    }

    /**
     * The certificate from wsse:BinarySecurityToken as PEM.
     */
    public function tokenCertificate(): string
    {
        $token = EetResponse::first($this->document, EetResponse::NS_WSSE, 'BinarySecurityToken');
        $der = base64_decode((string) preg_replace('/\s+/', '', $token->textContent), true);

        if ($der === false) {
            throw new RuntimeException('BinarySecurityToken is not base64.');
        }

        return "-----BEGIN CERTIFICATE-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END CERTIFICATE-----\n";
    }

    public function tokenFingerprint(): string
    {
        return strtolower((string) openssl_x509_fingerprint($this->tokenCertificate(), 'sha256'));
    }

    /**
     * Independent verification of the WS-Security signature.
     *
     * @return array{valid: bool, references: list<string>, signedLocalName: ?string, reason: string}
     */
    public function verifySignature(): array
    {
        $xpath = new DOMXPath($this->document);
        $xpath->registerNamespace('ds', EetResponse::NS_DS);
        $xpath->registerNamespace('wsse', EetResponse::NS_WSSE);

        $signatures = $xpath->query('//ds:Signature');

        if ($signatures === false || $signatures->length !== 1) {
            return $this->failure('expected exactly one ds:Signature');
        }

        /** @var DOMElement $signature */
        $signature = $signatures->item(0);
        $references = $xpath->query('ds:SignedInfo/ds:Reference', $signature);

        if ($references === false || $references->length === 0) {
            return $this->failure('no reference');
        }

        $uris = [];
        foreach ($references as $reference) {
            if ($reference instanceof DOMElement) {
                $uris[] = $reference->getAttribute('URI');
            }
        }

        /** @var DOMElement $reference */
        $reference = $references->item(0);
        $id = substr($reference->getAttribute('URI'), 1);
        $signed = null;
        foreach ($this->document->getElementsByTagName('*') as $element) {
            if ($element->getAttributeNS(EetResponse::NS_WSU, 'Id') === $id) {
                $signed = $element;
            }
        }

        if (! $signed instanceof DOMElement) {
            return ['valid' => false, 'references' => $uris, 'signedLocalName' => null, 'reason' => 'referenced element not found'];
        }

        $transformPrefixes = $this->prefixList($xpath, 'ds:Transforms/ds:Transform', $reference);
        $digest = base64_encode(hash('sha256', (string) $signed->C14N(true, false, null, $transformPrefixes), true));
        $digestValue = trim((string) $xpath->evaluate('string(ds:DigestValue)', $reference));

        if (! hash_equals($digestValue, $digest)) {
            return ['valid' => false, 'references' => $uris, 'signedLocalName' => $signed->localName, 'reason' => 'digest mismatch'];
        }

        /** @var DOMElement $signedInfo */
        $signedInfo = $xpath->query('ds:SignedInfo', $signature)?->item(0);
        $signedInfoPrefixes = $this->prefixList($xpath, 'ds:CanonicalizationMethod', $signedInfo);
        $canonical = (string) $signedInfo->C14N(true, false, null, $signedInfoPrefixes);
        $signatureValue = base64_decode(trim((string) $xpath->evaluate('string(ds:SignatureValue)', $signature)), true);

        $publicKey = openssl_pkey_get_public($this->tokenCertificate());

        if ($publicKey === false || $signatureValue === false) {
            return ['valid' => false, 'references' => $uris, 'signedLocalName' => $signed->localName, 'reason' => 'no key'];
        }

        $valid = openssl_verify($canonical, $signatureValue, $publicKey, OPENSSL_ALGO_SHA256) === 1;

        return ['valid' => $valid, 'references' => $uris, 'signedLocalName' => $signed->localName, 'reason' => $valid ? 'ok' : 'signature mismatch'];
    }

    /**
     * Algorithm URIs used by the signature.
     *
     * @return array{canonicalization: string, signature: string, digest: string, transforms: list<string>, keyInfo: list<string>}
     */
    public function signatureAlgorithms(): array
    {
        $xpath = new DOMXPath($this->document);
        $xpath->registerNamespace('ds', EetResponse::NS_DS);

        $transforms = [];
        foreach ($xpath->query('//ds:Reference/ds:Transforms/ds:Transform') ?: [] as $transform) {
            if ($transform instanceof DOMElement) {
                $transforms[] = $transform->getAttribute('Algorithm');
            }
        }

        $keyInfo = [];
        $keyInfoElement = $xpath->query('//ds:KeyInfo')?->item(0);

        if ($keyInfoElement instanceof DOMElement) {
            foreach ($keyInfoElement->childNodes as $child) {
                if ($child instanceof DOMElement) {
                    $keyInfo[] = $child->namespaceURI.'#'.$child->localName;
                }
            }
        }

        return [
            'canonicalization' => (string) $xpath->evaluate('string(//ds:SignedInfo/ds:CanonicalizationMethod/@Algorithm)'),
            'signature' => (string) $xpath->evaluate('string(//ds:SignedInfo/ds:SignatureMethod/@Algorithm)'),
            'digest' => (string) $xpath->evaluate('string(//ds:Reference/ds:DigestMethod/@Algorithm)'),
            'transforms' => $transforms,
            'keyInfo' => $keyInfo,
        ];
    }

    /**
     * @return array{valid: bool, references: list<string>, signedLocalName: ?string, reason: string}
     */
    private function failure(string $reason): array
    {
        return ['valid' => false, 'references' => [], 'signedLocalName' => null, 'reason' => $reason];
    }

    /**
     * @return list<string>|null
     */
    private function prefixList(DOMXPath $xpath, string $path, DOMElement $context): ?array
    {
        $xpath->registerNamespace('ec', EetResponse::NS_EXC_C14N);
        $list = trim((string) $xpath->evaluate("string({$path}/ec:InclusiveNamespaces/@PrefixList)", $context));

        if ($list === '') {
            return null;
        }

        return array_values(array_filter(preg_split('/\s+/', $list) ?: [], static fn (string $prefix): bool => $prefix !== ''));
    }

    /**
     * @return array<string, string>
     */
    private function attributes(DOMElement $element): array
    {
        $attributes = [];
        foreach ($element->attributes ?? [] as $attribute) {
            $attributes[$attribute->nodeName] = (string) $attribute->nodeValue;
        }

        return $attributes;
    }

    /**
     * @return list<string>
     */
    private function childNames(DOMElement $element): array
    {
        $names = [];
        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $names[] = (string) $child->localName;
            }
        }

        return $names;
    }
}
