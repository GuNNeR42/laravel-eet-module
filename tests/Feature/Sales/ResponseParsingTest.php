<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Gunner42\LaravelEetModule\Enums\AttemptOutcome;
use Gunner42\LaravelEetModule\Enums\SaleStatus;
use Gunner42\LaravelEetModule\Facades\Eet;
use Gunner42\LaravelEetModule\Models\Sale;
use Gunner42\LaravelEetModule\Testing\FakeResponse;
use Gunner42\LaravelEetModule\Tests\Support\EetResponse;
use Gunner42\LaravelEetModule\Tests\Support\Fixtures;
use Illuminate\Support\Facades\Queue;

/*
 * R65: safe processing of the response. Every violation is TransportError
 * with only a category in error_message (R66).
 */

beforeEach(function (): void {
    $this->travelTo(frozenNow());
    Queue::fake();
    useTestResponseCa();
});

/**
 * @param  array<string, string>  $headers
 */
function parsedSale(string $body, array $headers = []): Sale
{
    useMessageUuids(testUuid(1));
    Eet::fake([FakeResponse::raw($body, 200, $headers)]);

    return register(cashRegister());
}

function signedPlaygroundConfirmation(): EetResponse
{
    return EetResponse::confirmed(testUuid(1), EetResponse::confirmationCodeFor(testUuid(1), 'ff'))->test('true');
}

function expectTransportError(Sale $sale, ?string $category = null): void
{
    $attempt = lastAttempt($sale);

    expect($attempt->outcome)->toBe(AttemptOutcome::TransportError)
        ->and(freshSale($sale)->status)->toBe(SaleStatus::Unconfirmed)
        ->and(freshSale($sale)->confirmation_code)->toBeNull();

    if ($category !== null) {
        expect($attempt->error_message)->toBe($category);
    }
}

it('accepts a UTF-8 BOM and an XML declaration like the real response [R65]', function (): void {
    $this->travelTo(CarbonImmutable::parse(Fixtures::SAMPLE_RECEIVED_AT));
    config()->set('laravel-eet-module.response_trust_store', null);
    useMessageUuids(Fixtures::SAMPLE_UUID);
    Eet::fake([FakeResponse::raw("\xEF\xBB\xBF".sampleResponse())]);

    expect(register(cashRegister())->status)->toBe(SaleStatus::Confirmed);
});

it('accepts an encoding declaration in any letter case [R65]', function (): void {
    $xml = str_replace('encoding="UTF-8"', 'encoding="utf-8"', signedPlaygroundConfirmation()->toXml());

    expect(parsedSale($xml)->status)->toBe(SaleStatus::Confirmed);
});

it('rejects a body that is not valid UTF-8 before parsing [R65]', function (string $body): void {
    expectTransportError(parsedSale($body), 'encoding');
})->with([
    'UTF-16 BOM' => fn (): string => "\xFF\xFE".(string) mb_convert_encoding(EetResponse::error(testUuid(1), -1)->test('true')->toXml(), 'UTF-16LE', 'UTF-8'),
    'NUL byte' => fn (): string => str_replace('<eet:Odpoved>', "<eet:Odpoved>\0", EetResponse::error(testUuid(1), -1)->test('true')->toXml()),
    'invalid UTF-8 sequence' => fn (): string => str_replace('Chyba zpracovani', "Chyba \xC3\x28 zpracovani", EetResponse::error(testUuid(1), -1)->test('true')->toXml()),
]);

it('rejects a declared encoding other than UTF-8 [R65]', function (): void {
    $xml = str_replace('encoding="UTF-8"', 'encoding="UTF-16"', EetResponse::error(testUuid(1), -1)->test('true')->toXml());

    expectTransportError(parsedSale($xml), 'encoding');
});

it('rejects a DOCTYPE without expanding it [R65]', function (string $doctype): void {
    $xml = str_replace('<soapenv:Envelope', $doctype.'<soapenv:Envelope', EetResponse::error(testUuid(1), -1)->test('true')->toXml());

    expectTransportError(parsedSale($xml), 'doctype');
})->with([
    'plain' => '<!DOCTYPE soapenv:Envelope>',
    'internal entity' => '<!DOCTYPE foo [<!ENTITY x "-1">]>',
    'external entity' => '<!DOCTYPE foo [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>',
    'external DTD' => '<!DOCTYPE foo SYSTEM "http://evil.example/eet.dtd">',
]);

it('rejects processing instructions and XInclude with the doctype category [R65, R66]', function (string $search, string $replace): void {
    $xml = str_replace($search, $replace, EetResponse::error(testUuid(1), -1)->test('true')->toXml());

    expectTransportError(parsedSale($xml), 'doctype');
})->with([
    'processing instruction' => ['<eet:Odpoved>', '<?xml-stylesheet href="x.xsl"?><eet:Odpoved>'],
    'XInclude' => ['<eet:Odpoved>', '<eet:Odpoved><xi:include xmlns:xi="http://www.w3.org/2001/XInclude" href="file:///etc/passwd"/>'],
]);

it('rejects a SOAP fault as TransportError [R65, R33]', function (): void {
    $fault = '<?xml version="1.0" encoding="UTF-8"?><soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"><soapenv:Body><soapenv:Fault><faultcode>soapenv:Client</faultcode><faultstring>Bad request</faultstring></soapenv:Fault></soapenv:Body></soapenv:Envelope>';

    expectTransportError(parsedSale($fault), 'soap_fault');
});

it('rejects malformed XML as TransportError [R65]', function (): void {
    expectTransportError(parsedSale('<?xml version="1.0" encoding="UTF-8"?><soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"><soapenv:Body>'), 'invalid_xml');
});

it('rejects a response that violates the Odpoved structure [R65]', function (Closure $mutation): void {
    $response = EetResponse::error(testUuid(1), -1)->test('true')->beforeSigning($mutation);

    // guards against a dataset that does not change the response
    expect($response->toXml())->not->toBe(EetResponse::error(testUuid(1), -1)->test('true')->toXml());

    expectTransportError(parsedSale($response->toXml()), 'structure');
})->with([
    'two Odpoved' => function (DOMDocument $document): void {
        $odpoved = EetResponse::first($document, EetResponse::NS_EET, 'Odpoved');
        $odpoved->parentNode?->appendChild($odpoved->cloneNode(true));
    },
    'missing Hlavicka' => function (DOMDocument $document): void {
        $header = EetResponse::first($document, EetResponse::NS_EET, 'Hlavicka');
        $header->parentNode?->removeChild($header);
    },
    'eleven Varovani' => function (DOMDocument $document): void {
        $odpoved = EetResponse::first($document, EetResponse::NS_EET, 'Odpoved');
        for ($i = 0; $i < 11; $i++) {
            $warning = $document->createElementNS(EetResponse::NS_EET, 'eet:Varovani', 'Varovani');
            $warning->setAttribute('kod_varov', '1');
            $odpoved->appendChild($warning);
        }
    },
    'neither Potvrzeni nor Chyba' => function (DOMDocument $document): void {
        $chyba = EetResponse::first($document, EetResponse::NS_EET, 'Chyba');
        $chyba->parentNode?->removeChild($chyba);
    },
    'both Potvrzeni and Chyba' => function (DOMDocument $document): void {
        $potvrzeni = $document->createElementNS(EetResponse::NS_EET, 'eet:Potvrzeni');
        $potvrzeni->setAttribute('pok', EetResponse::confirmationCodeFor(testUuid(1), 'ff'));
        EetResponse::first($document, EetResponse::NS_EET, 'Odpoved')->appendChild($potvrzeni);
    },
    'Odpoved in another namespace' => function (DOMDocument $document): void {
        $body = EetResponse::first($document, EetResponse::NS_SOAP, 'Body');
        $odpoved = EetResponse::first($document, EetResponse::NS_EET, 'Odpoved');
        $foreign = $document->createElementNS('http://fs.gov.cz/eet/schema/v3', 'v3:Odpoved');
        while ($odpoved->firstChild !== null) {
            $foreign->appendChild($odpoved->firstChild);
        }
        $body->replaceChild($foreign, $odpoved);
    },
]);

it('accepts ten Varovani [R65]', function (): void {
    $response = signedPlaygroundConfirmation();
    for ($i = 0; $i < 10; $i++) {
        $response->warning(1, 'Varovani '.$i);
    }

    $sale = parsedSale($response->toXml());

    expect($sale->status)->toBe(SaleStatus::Confirmed)
        ->and(freshSale($sale)->warnings())->toHaveCount(10);
});

it('rejects values outside their masks even in a validly signed response [R65]', function (Closure $mutation): void {
    $xml = signedPlaygroundConfirmation()->beforeSigning($mutation)->toXml();

    // guards against a dataset that does not change the response
    expect($xml)->not->toBe(signedPlaygroundConfirmation()->toXml());

    expectTransportError(parsedSale($xml), 'mask');
})->with([
    'POK with 38 characters' => function (DOMDocument $document): void {
        EetResponse::first($document, EetResponse::NS_EET, 'Potvrzeni')->setAttribute('pok', substr(EetResponse::confirmationCodeFor(testUuid(1), 'ff'), 0, 38));
    },
    'POK with 40 characters' => function (DOMDocument $document): void {
        EetResponse::first($document, EetResponse::NS_EET, 'Potvrzeni')->setAttribute('pok', EetResponse::confirmationCodeFor(testUuid(1), 'ff').'0');
    },
    'dat_prij without timezone' => function (DOMDocument $document): void {
        EetResponse::first($document, EetResponse::NS_EET, 'Hlavicka')->setAttribute('dat_prij', '2026-11-20T10:00:00');
    },
    'dat_prij with fractions' => function (DOMDocument $document): void {
        EetResponse::first($document, EetResponse::NS_EET, 'Hlavicka')->setAttribute('dat_prij', '2026-11-20T10:00:00.123+01:00');
    },
    'test="yes"' => function (DOMDocument $document): void {
        EetResponse::first($document, EetResponse::NS_EET, 'Potvrzeni')->setAttribute('test', 'yes');
    },
    'invalid uuid_zpravy' => function (DOMDocument $document): void {
        EetResponse::first($document, EetResponse::NS_EET, 'Hlavicka')->setAttribute('uuid_zpravy', 'not-a-uuid');
    },
    'kod_varov 0' => function (DOMDocument $document): void {
        $warning = $document->createElementNS(EetResponse::NS_EET, 'eet:Varovani', 'Varovani');
        $warning->setAttribute('kod_varov', '0');
        EetResponse::first($document, EetResponse::NS_EET, 'Odpoved')->appendChild($warning);
    },
]);

it('rejects an error code or dat_odmit outside the masks [R65]', function (string $attribute, string $value): void {
    $response = EetResponse::error(testUuid(1), -1)->test('true')->beforeSigning(function (DOMDocument $document) use ($attribute, $value): void {
        $element = $attribute === 'kod'
            ? EetResponse::first($document, EetResponse::NS_EET, 'Chyba')
            : EetResponse::first($document, EetResponse::NS_EET, 'Hlavicka');
        $element->setAttribute($attribute, $value);
    });

    expectTransportError(parsedSale($response->toXml()), 'mask');
})->with([
    ['kod', '1000'],
    ['kod', 'abc'],
    ['kod', '+1'],
    ['dat_odmit', '20.11.2026 10:00'],
]);

it('rejects dat_odmit that matches the syntax but is not a valid calendar date and time [R65]', function (string $value): void {
    $sale = parsedSale(EetResponse::error(testUuid(1), -1)->test('true')->rejectedAt($value)->toXml());

    expectTransportError($sale, 'mask');
    expect(attemptsOf($sale))->toHaveCount(1);
})->with([
    'month 13, hour 25' => '2026-13-45T25:61:61+01:00',
    'February 30' => '2026-02-30T10:00:00+01:00',
    'offset hour 25' => '2026-11-20T10:00:00+25:00',
]);

it('rejects dat_prij of a signed confirmation that is not a valid calendar date and time [R65, R71]', function (string $value): void {
    $sale = parsedSale(signedPlaygroundConfirmation()->receivedAt($value)->toXml());

    expectTransportError($sale, 'mask');
    expect(attemptsOf($sale))->toHaveCount(1);
})->with([
    'month 13, hour 25' => '2026-13-45T25:61:61+01:00',
    'February 30' => '2026-02-30T10:00:00+01:00',
]);

it('removes disallowed characters from error texts and limits them to 100 characters [R65]', function (): void {
    $text = 'Chybný údaj – '.str_repeat('x', 150);

    $attempt = lastAttempt(parsedSale(EetResponse::error(testUuid(1), 3, $text)->test('true')->toXml()));

    expect($attempt->error_message)->toBe(substr('Chybn daj  '.str_repeat('x', 150), 0, 100));
});

it('removes disallowed characters from a short error text without replacing them [R65]', function (): void {
    $attempt = lastAttempt(parsedSale(EetResponse::error(testUuid(1), 3, 'Neplatný podpis')->test('true')->toXml()));

    expect($attempt->error_message)->toBe('Neplatn podpis');
});

it('truncates an ASCII error text of 150 characters to the first 100 [R65]', function (): void {
    $text = str_repeat('abcdefghij', 15);

    $attempt = lastAttempt(parsedSale(EetResponse::error(testUuid(1), 3, $text)->test('true')->toXml()));

    expect($attempt->error_message)->toBe(substr($text, 0, 100));
});

it('removes disallowed characters from warning texts and limits them to 100 characters [R65]', function (): void {
    $response = signedPlaygroundConfirmation()->warning(1, 'Varování: '.str_repeat('y', 150));

    $warning = lastAttempt(parsedSale($response->toXml()))->warnings()[0];

    expect($warning->message)->toBe(substr('Varovn: '.str_repeat('y', 150), 0, 100));
});

it('restricts X-Global-Transaction-Id to 64 ASCII characters [R65]', function (): void {
    $attempt = lastAttempt(parsedSale(
        EetResponse::error(testUuid(1), -1)->test('true')->toXml(),
        ['X-Global-Transaction-Id' => str_repeat('a', 80)],
    ));

    expect($attempt->global_transaction_id)->toBe(str_repeat('a', 64));
});

it('keeps texts with HTML characters as data [R65]', function (): void {
    $attempt = lastAttempt(parsedSale(EetResponse::error(testUuid(1), 3, '<script>alert(1)</script>')->test('true')->toXml()));

    expect($attempt->error_message)->toBe('<script>alert(1)</script>');
});
