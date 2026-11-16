<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Services;

use DOMDocument;
use DOMElement;
use LogicException;
use OpenSSLAsymmetricKey;
use SensitiveParameter;

/**
 * Signs the soap:Body with WS-Security (R31): exc-c14n, SHA-256, RSA-SHA256,
 * the certificate in wsse:BinarySecurityToken referenced by
 * wsse:SecurityTokenReference. No other headers.
 */
final class MessageSigner
{
    public function sign(DOMDocument $document, string $certificatePem, #[SensitiveParameter] OpenSSLAsymmetricKey $privateKey): string
    {
        $body = $document->getElementsByTagNameNS(MessageBuilder::NS_SOAP, 'Body')->item(0);
        $header = $document->getElementsByTagNameNS(MessageBuilder::NS_SOAP, 'Header')->item(0);

        if (! $body instanceof DOMElement || ! $header instanceof DOMElement) {
            throw new LogicException('The envelope has no Header or Body.');
        }

        $bodyId = $body->getAttributeNS(MessageBuilder::NS_WSU, 'Id');
        $suffix = substr($bodyId, 3);
        $tokenId = 'X509-'.$suffix;
        $der = (string) preg_replace('/-----[A-Z ]+-----|\s+/', '', $certificatePem);

        $security = $document->createElementNS(MessageBuilder::NS_WSSE, 'wsse:Security');
        $security->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:wsu', MessageBuilder::NS_WSU);
        $header->appendChild($security);

        $token = $document->createElementNS(MessageBuilder::NS_WSSE, 'wsse:BinarySecurityToken', $der);
        $token->setAttribute('EncodingType', MessageBuilder::BASE64_BINARY);
        $token->setAttribute('ValueType', MessageBuilder::X509V3);
        $token->setAttributeNS(MessageBuilder::NS_WSU, 'wsu:Id', $tokenId);
        $security->appendChild($token);

        $signature = $document->createElementNS(MessageBuilder::NS_DS, 'ds:Signature');
        $signature->setAttribute('Id', 'SIG-'.$suffix);
        $security->appendChild($signature);

        $signedInfo = $this->element($document, $signature, 'ds:SignedInfo');
        $canonicalization = $this->element($document, $signedInfo, 'ds:CanonicalizationMethod');
        $canonicalization->setAttribute('Algorithm', MessageBuilder::NS_EXC_C14N);
        $this->inclusiveNamespaces($document, $canonicalization, 'soapenv v4');
        $this->element($document, $signedInfo, 'ds:SignatureMethod')->setAttribute('Algorithm', 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256');

        $reference = $this->element($document, $signedInfo, 'ds:Reference');
        $reference->setAttribute('URI', '#'.$bodyId);
        $transform = $this->element($document, $this->element($document, $reference, 'ds:Transforms'), 'ds:Transform');
        $transform->setAttribute('Algorithm', MessageBuilder::NS_EXC_C14N);
        $this->inclusiveNamespaces($document, $transform, 'v4');
        $this->element($document, $reference, 'ds:DigestMethod')->setAttribute('Algorithm', 'http://www.w3.org/2001/04/xmlenc#sha256');
        $digestValue = $this->element($document, $reference, 'ds:DigestValue');
        $signatureValue = $this->element($document, $signature, 'ds:SignatureValue');

        $keyInfo = $this->element($document, $signature, 'ds:KeyInfo');
        $tokenReference = $document->createElementNS(MessageBuilder::NS_WSSE, 'wsse:SecurityTokenReference');
        $keyInfo->appendChild($tokenReference);
        $tokenReferenceTarget = $document->createElementNS(MessageBuilder::NS_WSSE, 'wsse:Reference');
        $tokenReferenceTarget->setAttribute('URI', '#'.$tokenId);
        $tokenReferenceTarget->setAttribute('ValueType', MessageBuilder::X509V3);
        $tokenReference->appendChild($tokenReferenceTarget);

        $digestValue->appendChild($document->createTextNode(
            base64_encode(hash('sha256', (string) $body->C14N(true, false, null, ['v4']), true)),
        ));

        $signed = openssl_sign((string) $signedInfo->C14N(true, false, null, ['soapenv', 'v4']), $value, $privateKey, OPENSSL_ALGO_SHA256);
        X509::clearErrors();

        if (! $signed || ! is_string($value)) {
            throw new LogicException('The data message could not be signed.');
        }

        $signatureValue->appendChild($document->createTextNode(base64_encode($value)));

        return (string) $document->saveXML();
    }

    private function element(DOMDocument $document, DOMElement $parent, string $name): DOMElement
    {
        $element = $document->createElementNS(MessageBuilder::NS_DS, $name);
        $parent->appendChild($element);

        return $element;
    }

    private function inclusiveNamespaces(DOMDocument $document, DOMElement $parent, string $prefixes): void
    {
        $inclusive = $document->createElementNS(MessageBuilder::NS_EXC_C14N, 'ec:InclusiveNamespaces');
        $inclusive->setAttribute('PrefixList', $prefixes);
        $parent->appendChild($inclusive);
    }
}
