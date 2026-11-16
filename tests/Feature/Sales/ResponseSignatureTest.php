<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Gunner42\LaravelEetModule\Enums\AttemptOutcome;
use Gunner42\LaravelEetModule\Enums\Environment;
use Gunner42\LaravelEetModule\Enums\SaleStatus;
use Gunner42\LaravelEetModule\Exceptions\InvalidConfiguration;
use Gunner42\LaravelEetModule\Facades\Eet;
use Gunner42\LaravelEetModule\Models\Sale;
use Gunner42\LaravelEetModule\Testing\FakeResponse;
use Gunner42\LaravelEetModule\Tests\Support\EetResponse;
use Gunner42\LaravelEetModule\Tests\Support\Fixtures;
use Gunner42\LaravelEetModule\Tests\Support\SoapMessage;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\AssertionFailedError;

/*
 * R35: signature of the confirmation. Responses go through FakeResponse::raw(),
 * which is processed like a real response including the signature check (R57).
 *
 * - The real playground response (tests/fixtures/pg-vzorek) is verified
 *   against the bundled I.CA certificates (no response_trust_store).
 * - Validly signed but tampered or unusual responses are signed by the own
 *   test CA (tests/fixtures/response-ca) configured as response_trust_store.
 */

beforeEach(function (): void {
    Queue::fake();
});

/**
 * Registers a playground sale of CZ00000019 answered by the real playground
 * response sample (optionally mutated). Time is frozen at its dat_prij.
 */
function sampleSale(?Closure $mutation = null, ?CarbonImmutable $now = null, Environment $environment = Environment::Playground): Sale
{
    test()->travelTo($now ?? CarbonImmutable::parse(Fixtures::SAMPLE_RECEIVED_AT));
    useMessageUuids(Fixtures::SAMPLE_UUID);
    Eet::fake([FakeResponse::raw(sampleResponse($mutation), 200, ['X-Global-Transaction-Id' => 'bc626a986af9dc99035fe7d0'])]);

    return register(cashRegister('CZ00000019', $environment));
}

/**
 * Registers a sale answered by a response of the own test CA.
 */
function testCaSale(EetResponse $response, Environment $environment = Environment::Playground, string $trustStore = TEST_CA_TRUST_STORE): Sale
{
    useTestResponseCa($trustStore);
    useMessageUuids(testUuid(1));
    Eet::fake([FakeResponse::raw($response->toXml())]);

    return register(cashRegister('CZ00000019', $environment));
}

function playgroundConfirmation(string $signer = 'signer-playground'): EetResponse
{
    return EetResponse::confirmed(testUuid(1), EetResponse::confirmationCodeFor(testUuid(1), 'ff'))->test('true')->signedBy($signer);
}

function productionConfirmation(string $signer = 'signer-production'): EetResponse
{
    return EetResponse::confirmed(testUuid(1))->signedBy($signer);
}

function expectInvalidSignature(Sale $sale): void
{
    expect(lastAttempt($sale)->outcome)->toBe(AttemptOutcome::InvalidResponseSignature)
        ->and(freshSale($sale)->status)->toBe(SaleStatus::Unconfirmed)
        ->and(freshSale($sale)->confirmation_code)->toBeNull();
}

describe('real playground response verified against the bundled I.CA certificates', function (): void {
    it('confirms the sale with the POK of the real signed playground response [R35, R36, R57]', function (): void {
        $sale = sampleSale();

        expect($sale->status)->toBe(SaleStatus::Confirmed)
            ->and($sale->confirmation_code)->toBe(Fixtures::SAMPLE_CONFIRMATION_CODE)
            ->and($sale->is_test)->toBeTrue()
            ->and($sale->hasValidConfirmationCode())->toBeFalse()
            ->and(lastAttempt($sale)->outcome)->toBe(AttemptOutcome::Confirmed);
    });

    it('stores the subject and fingerprint of the response signing certificate [R35]', function (): void {
        $attempt = lastAttempt(sampleSale());
        $token = (new SoapMessage(sampleResponse()))->tokenFingerprint();

        expect($attempt->signer_subject)->toContain('NEOSTRE - playground prostredi EET')
            ->and($attempt->signer_subject)->toContain('NTRCZ-72080043')
            ->and(strtolower((string) $attempt->signer_fingerprint))->toBe($token);
    });

    it('accepts the token wrapped after 76 characters because it is not signed [R35]', function (): void {
        $sale = sampleSale(fn (string $xml): string => (string) preg_replace_callback(
            '#(<wsse:BinarySecurityToken[^>]*>)([^<]+)(</wsse:BinarySecurityToken>)#',
            fn (array $match): string => $match[1].chunk_split($match[2], 76, "\r\n").$match[3],
            $xml,
        ));

        expect($sale->status)->toBe(SaleStatus::Confirmed);
    });

    it('rejects the sample with a changed POK [R35]', function (): void {
        expectInvalidSignature(sampleSale(fn (string $xml): string => str_replace(Fixtures::SAMPLE_CONFIRMATION_CODE, '5cf9d5a2-c64b-425f-88c7-986a62bc5690-ff', $xml)));
    });

    it('rejects the sample with a changed uuid_zpravy before comparing it [R35, R71]', function (): void {
        $other = '60b96f2c-e521-47e1-92aa-cb60f3034f6f';
        test()->travelTo(CarbonImmutable::parse(Fixtures::SAMPLE_RECEIVED_AT));
        useMessageUuids($other);
        Eet::fake([FakeResponse::raw(sampleResponse(fn (string $xml): string => str_replace(Fixtures::SAMPLE_UUID, $other, $xml)))]);

        expectInvalidSignature(register(cashRegister()));
    });

    it('rejects the sample with a second Body (XML signature wrapping) [R35]', function (): void {
        expectInvalidSignature(sampleSale(fn (string $xml): string => str_replace(
            '</soapenv:Envelope>',
            '<soapenv:Body><eet:Odpoved><eet:Hlavicka uuid_zpravy="60b96f2c-e521-47e1-92aa-cb60f3034f6e" dat_prij="2026-11-15T17:23:53+01:00"/><eet:Potvrzeni pok="11111111-2222-4333-8444-555555555555-ff" test="true"/></eet:Odpoved></soapenv:Body></soapenv:Envelope>',
            $xml,
        )));
    });

    it('rejects the sample when another element carries the Id of the signed Body [R35]', function (string $attribute): void {
        // the attribute is placed outside the signed Body, so the digest stays valid
        expectInvalidSignature(sampleSale(fn (string $xml): string => str_replace(
            '<wsse:Security soapenv:mustUnderstand="1">',
            '<wsse:Security soapenv:mustUnderstand="1" '.$attribute.'="Body-ee4dcc59-29c0-493f-b638-986a62bce8e5">',
            $xml,
        )));
    })->with(['wsu:Id', 'Id', 'ID', 'xml:id']);

    it('rejects the sample when the configured organizationIdentifier differs [R35]', function (): void {
        config()->set('laravel-eet-module.response_signer.organization_identifier', 'NTRCZ-00000000');

        expectInvalidSignature(sampleSale());
    });

    it('rejects the sample when the configured playground CN differs [R35]', function (): void {
        config()->set('laravel-eet-module.response_signer.playground.common_name', 'Jiny nazev');

        expectInvalidSignature(sampleSale());
    });

    it('accepts dat_prij exactly 600 s away from now [R35]', function (int $seconds): void {
        expect(sampleSale(null, CarbonImmutable::parse(Fixtures::SAMPLE_RECEIVED_AT)->addSeconds($seconds))->status)->toBe(SaleStatus::Confirmed);
    })->with([600, -600]);

    it('rejects dat_prij more than 600 s away from now and stores clock_skew [R35]', function (int $seconds): void {
        $sale = sampleSale(null, CarbonImmutable::parse(Fixtures::SAMPLE_RECEIVED_AT)->addSeconds($seconds));

        expectInvalidSignature($sale);
        expect(lastAttempt($sale)->error_message)->toBe('clock_skew');
    })->with([601, -601]);

    it('uses a configured clock tolerance [R35]', function (): void {
        config()->set('laravel-eet-module.response_clock_tolerance_seconds', 1000);

        expect(sampleSale(null, CarbonImmutable::parse(Fixtures::SAMPLE_RECEIVED_AT)->addSeconds(601))->status)->toBe(SaleStatus::Confirmed);
    });

    it('rejects the real playground confirmation for a production sale [R35, R71]', function (): void {
        expectInvalidSignature(sampleSale(null, null, Environment::Production));
    });

    it('rejects the real I.CA signature when another trust store is configured [R35]', function (): void {
        useTestResponseCa();

        expectInvalidSignature(sampleSale());
    });
});

describe('responses signed by the own test CA', function (): void {
    beforeEach(function (): void {
        $this->travelTo(frozenNow());
    });

    it('confirms a validly signed playground confirmation and stores the signer [R35]', function (): void {
        $sale = testCaSale(playgroundConfirmation());
        $attempt = lastAttempt($sale);

        expect($sale->status)->toBe(SaleStatus::Confirmed)
            ->and($attempt->signer_subject)->toContain('NEOSTRE - playground prostredi EET')
            ->and($attempt->signer_subject)->toContain('NTRCZ-72080043')
            ->and(strtolower((string) $attempt->signer_fingerprint))->toBe(Fixtures::fingerprint('response-ca/signer-playground.pem'));
    });

    it('rejects a confirmation with a changed Body [R35]', function (): void {
        expectInvalidSignature(testCaSale(playgroundConfirmation()->afterSigning(function (DOMDocument $document): void {
            EetResponse::first($document, EetResponse::NS_EET, 'Potvrzeni')->setAttribute('pok', EetResponse::confirmationCodeFor(testUuid(9), 'ff'));
        })));
    });

    it('rejects a damaged SignatureValue [R35]', function (): void {
        expectInvalidSignature(testCaSale(playgroundConfirmation()->afterSigning(function (DOMDocument $document): void {
            $value = EetResponse::first($document, EetResponse::NS_DS, 'SignatureValue');
            $bytes = (string) base64_decode((string) $value->nodeValue, true);
            $bytes[10] = chr(ord($bytes[10]) ^ 0xFF);
            $value->nodeValue = base64_encode($bytes);
        })));
    });

    it('rejects a signer certificate outside the trust store [R35]', function (): void {
        expectInvalidSignature(testCaSale(playgroundConfirmation('signer-rogue')));
    });

    it('rejects a signer with another organizationIdentifier [R35]', function (): void {
        expectInvalidSignature(testCaSale(playgroundConfirmation('signer-wrong-org')));
    });

    it('rejects a signer with organizationIdentifier twice in the subject [R35]', function (): void {
        expectInvalidSignature(testCaSale(playgroundConfirmation('signer-two-orgids')));
    });

    it('rejects a signer with two CNs in the subject [R35]', function (): void {
        expectInvalidSignature(testCaSale(playgroundConfirmation('signer-two-cns')));
    });

    it('rejects a signer issued by an intermediate without keyCertSign [R35]', function (): void {
        expectInvalidSignature(testCaSale(playgroundConfirmation('signer-under-no-keycertsign'), Environment::Playground, 'response-ca/trust-store-no-keycertsign.pem'));
    });

    it('rejects a signer with an EC key [R35]', function (): void {
        expectInvalidSignature(testCaSale(playgroundConfirmation('signer-ec')));
    });

    it('rejects a signer with an RSA key shorter than 2048 bits [R35]', function (): void {
        expectInvalidSignature(testCaSale(playgroundConfirmation('signer-rsa1024')));
    });

    it('rejects a signer with another CN for a playground sale [R35]', function (): void {
        expectInvalidSignature(testCaSale(playgroundConfirmation('signer-other-cn')));
    });

    it('rejects the playground CN for a production sale [R35]', function (): void {
        expectInvalidSignature(testCaSale(productionConfirmation('signer-playground'), Environment::Production));
    });

    it('accepts any production CN without "playground" while production.common_name is null [R35]', function (string $signer): void {
        expect(testCaSale(productionConfirmation($signer), Environment::Production)->status)->toBe(SaleStatus::Confirmed);
    })->with(['signer-production', 'signer-other-cn']);

    it('requires an exact match of a configured production.common_name [R35]', function (): void {
        config()->set('laravel-eet-module.response_signer.production.common_name', 'EET produkcni prostredi');

        expect(testCaSale(productionConfirmation('signer-production'), Environment::Production)->status)->toBe(SaleStatus::Confirmed);
    });

    it('rejects a production CN that differs from the configured production.common_name [R35]', function (): void {
        config()->set('laravel-eet-module.response_signer.production.common_name', 'EET produkcni prostredi');

        expectInvalidSignature(testCaSale(productionConfirmation('signer-other-cn'), Environment::Production));
    });

    it('rejects Reference URIs other than a same-document id reference [R35]', function (string $uri): void {
        expectInvalidSignature(testCaSale(playgroundConfirmation()->afterSigning(function (DOMDocument $document) use ($uri): void {
            EetResponse::firstIn(EetResponse::first($document, EetResponse::NS_DS, 'SignedInfo'), EetResponse::NS_DS, 'Reference')->setAttribute('URI', $uri);
        })));
    })->with(['', '#xpointer(/)', "#xpointer(id('Body-ee4dcc59-29c0-493f-b638-986a62bce8e5'))", 'Body-ee4dcc59-29c0-493f-b638-986a62bce8e5', '#1Body']);

    it('rejects a second Transform [R35]', function (): void {
        expectInvalidSignature(testCaSale(playgroundConfirmation()->beforeSigning(function (DOMDocument $document): void {
            $transforms = EetResponse::first($document, EetResponse::NS_DS, 'Transforms');
            $second = $document->createElementNS(EetResponse::NS_DS, 'Transform');
            $second->setAttribute('Algorithm', 'http://www.w3.org/2000/09/xmldsig#enveloped-signature');
            $transforms->appendChild($second);
        })));
    });

    it('rejects algorithms outside the allow-list [R35]', function (string $element, string $algorithm): void {
        expectInvalidSignature(testCaSale(playgroundConfirmation()->beforeSigning(function (DOMDocument $document) use ($element, $algorithm): void {
            EetResponse::first($document, EetResponse::NS_DS, $element)->setAttribute('Algorithm', $algorithm);
        })));
    })->with([
        'inclusive c14n' => ['CanonicalizationMethod', 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315'],
        'SHA-1 digest' => ['DigestMethod', 'http://www.w3.org/2000/09/xmldsig#sha1'],
        'RSA-SHA1 signature' => ['SignatureMethod', 'http://www.w3.org/2000/09/xmldsig#rsa-sha1'],
        'HMAC signature' => ['SignatureMethod', 'http://www.w3.org/2000/09/xmldsig#hmac-sha1'],
        'other transform' => ['Transform', 'http://www.w3.org/2000/09/xmldsig#enveloped-signature'],
    ]);

    it('accepts an InclusiveNamespaces PrefixList and uses it for canonicalization [R35]', function (): void {
        $sale = testCaSale(playgroundConfirmation()->beforeSigning(function (DOMDocument $document): void {
            $transform = EetResponse::first($document, EetResponse::NS_DS, 'Transform');
            $inclusive = $document->createElementNS(EetResponse::NS_EXC_C14N, 'ec:InclusiveNamespaces');
            $inclusive->setAttribute('PrefixList', 'eet');
            $transform->appendChild($inclusive);
        })->afterSigning(function (DOMDocument $document): void {
            // recompute the digest with the PrefixList the response declares
            $body = EetResponse::first($document, EetResponse::NS_SOAP, 'Body');
            $digest = base64_encode(hash('sha256', (string) $body->C14N(true, false, null, ['eet']), true));
            EetResponse::first($document, EetResponse::NS_DS, 'DigestValue')->nodeValue = $digest;
            $signedInfo = EetResponse::first($document, EetResponse::NS_DS, 'SignedInfo');
            openssl_sign((string) $signedInfo->C14N(true, false), $signature, Fixtures::read('response-ca/signer-playground.key'), OPENSSL_ALGO_SHA256);
            EetResponse::first($document, EetResponse::NS_DS, 'SignatureValue')->nodeValue = base64_encode((string) $signature);
        }));

        expect($sale->status)->toBe(SaleStatus::Confirmed);
    });

    it('rejects a KeyInfo with X509Data instead of a SecurityTokenReference [R35]', function (): void {
        expectInvalidSignature(testCaSale(playgroundConfirmation()->afterSigning(function (DOMDocument $document): void {
            $keyInfo = EetResponse::first($document, EetResponse::NS_DS, 'KeyInfo');
            while ($keyInfo->firstChild !== null) {
                $keyInfo->removeChild($keyInfo->firstChild);
            }
            $data = $document->createElementNS(EetResponse::NS_DS, 'X509Data');
            $data->appendChild($document->createElementNS(EetResponse::NS_DS, 'X509Certificate', Fixtures::der('response-ca/signer-playground.pem')));
            $keyInfo->appendChild($data);
        })));
    });

    it('rejects a Signature element whose namespace is not xmldsig [R35]', function (): void {
        expectInvalidSignature(testCaSale(playgroundConfirmation()->mutateXml(fn (string $xml): string => str_replace(
            '<Signature xmlns="http://www.w3.org/2000/09/xmldsig#">',
            '<Signature xmlns="http://www.w3.org/2000/09/xmldsig-more#">',
            $xml,
        ))));
    });

    it('rejects a ds prefix remapped to another namespace [R35]', function (): void {
        expectInvalidSignature(testCaSale(playgroundConfirmation()->mutateXml(fn (string $xml): string => str_replace(
            ['<Signature xmlns="http://www.w3.org/2000/09/xmldsig#">', '</Signature>'],
            ['<ds:Signature xmlns:ds="urn:not-xmldsig" xmlns="http://www.w3.org/2000/09/xmldsig#">', '</ds:Signature>'],
            $xml,
        ))));
    });

    it('rejects a second Signature or a second BinarySecurityToken [R35]', function (string $duplicate): void {
        expectInvalidSignature(testCaSale(playgroundConfirmation()->afterSigning(function (DOMDocument $document) use ($duplicate): void {
            $element = $duplicate === 'Signature'
                ? EetResponse::first($document, EetResponse::NS_DS, 'Signature')
                : EetResponse::first($document, EetResponse::NS_WSSE, 'BinarySecurityToken');
            $copy = $element->cloneNode(true);

            if ($copy instanceof DOMElement && $copy->hasAttributeNS(EetResponse::NS_WSU, 'Id')) {
                $copy->setAttributeNS(EetResponse::NS_WSU, 'wsu:Id', 'SecurityToken-copy');
            }
            $element->parentNode?->appendChild($copy);
        })));
    })->with(['Signature', 'BinarySecurityToken']);

    it('rejects a second Reference in SignedInfo [R35]', function (): void {
        expectInvalidSignature(testCaSale(playgroundConfirmation()->beforeSigning(function (DOMDocument $document): void {
            $reference = EetResponse::first($document, EetResponse::NS_DS, 'Reference');
            $copy = $reference->cloneNode(true);
            $reference->parentNode?->appendChild($copy);
        })));
    });

    it('rejects a token with a ValueType other than X509v3 [R35]', function (): void {
        expectInvalidSignature(testCaSale(playgroundConfirmation()->afterSigning(function (DOMDocument $document): void {
            EetResponse::first($document, EetResponse::NS_WSSE, 'BinarySecurityToken')
                ->setAttribute('ValueType', 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-x509-token-profile-1.0#X509PKIPathv1');
        })));
    });

    it('rejects a token that is not strict base64 of a single certificate [R35]', function (string $suffix): void {
        expectInvalidSignature(testCaSale(playgroundConfirmation()->afterSigning(function (DOMDocument $document) use ($suffix): void {
            $token = EetResponse::first($document, EetResponse::NS_WSSE, 'BinarySecurityToken');
            $token->nodeValue = $token->nodeValue.$suffix;
        })));
    })->with(['not base64' => '!!!!', 'trailing data' => 'AAAA']);

    it('rejects an Odpoved outside the Body and never takes its data [R35]', function (): void {
        $decoy = EetResponse::confirmationCodeFor(testUuid(7), 'ff');
        $sale = testCaSale(playgroundConfirmation()->afterSigning(function (DOMDocument $document) use ($decoy): void {
            $odpoved = $document->createElementNS(EetResponse::NS_EET, 'eet:Odpoved');
            $header = $document->createElementNS(EetResponse::NS_EET, 'eet:Hlavicka');
            $header->setAttribute('uuid_zpravy', testUuid(1));
            $header->setAttribute('dat_prij', '2026-11-20T10:00:00+01:00');
            $potvrzeni = $document->createElementNS(EetResponse::NS_EET, 'eet:Potvrzeni');
            $potvrzeni->setAttribute('pok', $decoy);
            $potvrzeni->setAttribute('test', 'true');
            $odpoved->appendChild($header);
            $odpoved->appendChild($potvrzeni);
            EetResponse::first($document, EetResponse::NS_WSSE, 'Security')->appendChild($odpoved);
        }));

        expectInvalidSignature($sale);
        expect(freshSale($sale)->confirmation_code)->not->toBe($decoy);
    });

    it('rejects any other element in Header or Security [R35]', function (string $parent, string $namespace, string $name): void {
        expectInvalidSignature(testCaSale(playgroundConfirmation()->afterSigning(function (DOMDocument $document) use ($parent, $namespace, $name): void {
            $target = $parent === 'Header'
                ? EetResponse::first($document, EetResponse::NS_SOAP, 'Header')
                : EetResponse::first($document, EetResponse::NS_WSSE, 'Security');
            $target->appendChild($document->createElementNS($namespace, $name));
        })));
    })->with([
        'Timestamp in Security' => ['Security', EetResponse::NS_WSU, 'wsu:Timestamp'],
        'WS-Addressing in Header' => ['Header', 'http://www.w3.org/2005/08/addressing', 'wsa:Action'],
        'second Security in Header' => ['Header', EetResponse::NS_WSSE, 'wsse:Security'],
        'unknown element in Header' => ['Header', 'urn:example', 'x:Extra'],
    ]);

    it('checks the validity of the chain against the frozen time, not the system clock [R35]', function (): void {
        config()->set('laravel-eet-module.minimum_occurred_at.playground', '2025-01-01 00:00');
        $this->travelTo(CarbonImmutable::parse('2025-06-01T10:00:00+02:00'));

        // the system clock is outside the validity of signer-2025 (2025-01-01 – 2025-12-31)
        expect(testCaSale(playgroundConfirmation('signer-2025'))->status)->toBe(SaleStatus::Confirmed);
    });

    it('rejects a signer certificate after its validTo [R35]', function (): void {
        config()->set('laravel-eet-module.minimum_occurred_at.playground', '2025-01-01 00:00');
        $this->travelTo(CarbonImmutable::parse('2026-01-01T00:00:01+00:00'));

        expectInvalidSignature(testCaSale(playgroundConfirmation('signer-2025')));
    });

    it('accepts a trust store with up to the configured maximum number of CA certificates [R35]', function (): void {
        config()->set('laravel-eet-module.response_trust_store_max_certificates', 11);

        expect(testCaSale(playgroundConfirmation(), Environment::Playground, 'response-ca/trust-store-11.pem')->status)->toBe(SaleStatus::Confirmed);
    });

    it('does not verify the signature of an error response [R35]', function (): void {
        $sale = testCaSale(EetResponse::error(testUuid(1), 3)->test('true'));

        expect($sale->status)->toBe(SaleStatus::Rejected);
    });
});

describe('trust store configuration', function (): void {
    beforeEach(function (): void {
        $this->travelTo(frozenNow());
    });

    it('rejects an invalid response_trust_store before storing anything [R35, R68]', function (string $path): void {
        config()->set('laravel-eet-module.response_trust_store', str_starts_with($path, '/') ? $path : Fixtures::path($path));
        Eet::fake();

        try {
            register(cashRegister());

            throw new AssertionFailedError('InvalidConfiguration was not thrown.');
        } catch (InvalidConfiguration $exception) {
            expect($exception->key)->toBe('response_trust_store');
        }

        expect(Sale::query()->count())->toBe(0);
        Eet::assertNothingSent();
    })->with([
        'missing file' => '/nonexistent/eet-trust-store.pem',
        'certificate without CA:TRUE' => 'response-ca/trust-store-with-leaf.pem',
        'eleven certificates' => 'response-ca/trust-store-11.pem',
        'no self-signed anchor' => 'response-ca/trust-store-without-anchor.pem',
        'no intermediate' => 'response-ca/trust-store-without-intermediate.pem',
    ]);

    it('applies a configured response_trust_store_max_certificates [R35, R68]', function (): void {
        config()->set('laravel-eet-module.response_trust_store_max_certificates', 2);
        useTestResponseCa('response-ca/trust-store-3.pem');
        Eet::fake();

        expect(fn (): Sale => register(cashRegister()))->toThrow(InvalidConfiguration::class)
            ->and(Sale::query()->count())->toBe(0);
    });

    it('rejects invalid signer and clock settings [R35, R68]', function (string $key, mixed $value): void {
        config()->set('laravel-eet-module.'.$key, $value);
        Eet::fake();

        try {
            register(cashRegister());

            throw new AssertionFailedError('InvalidConfiguration was not thrown.');
        } catch (InvalidConfiguration $exception) {
            expect($exception->key)->toBe($key);
        }

        expect(Sale::query()->count())->toBe(0);
    })->with([
        ['response_signer.organization_identifier', 'GFR'],
        ['response_signer.organization_identifier', 'NTRCZ-7208004'],
        ['response_signer.playground.common_name', ''],
        ['response_signer.production.common_name', ''],
        ['response_clock_tolerance_seconds', 0],
        ['response_trust_store_max_certificates', 1],
    ]);
});
