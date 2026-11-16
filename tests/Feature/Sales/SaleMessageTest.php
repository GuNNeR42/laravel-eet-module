<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Gunner42\LaravelEetModule\Data\SaleMessage;
use Gunner42\LaravelEetModule\Enums\Environment;
use Gunner42\LaravelEetModule\Facades\Eet;
use Gunner42\LaravelEetModule\Models\Sale;
use Gunner42\LaravelEetModule\Testing\FakeResponse;
use Gunner42\LaravelEetModule\Tests\Support\EetResponse;
use Gunner42\LaravelEetModule\Tests\Support\Fixtures;
use Gunner42\LaravelEetModule\Tests\Support\SoapMessage;
use Illuminate\Support\Facades\Queue;

const UUID_V4 = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

beforeEach(function (): void {
    $this->travelTo(frozenNow());
    Queue::fake();
});

/**
 * @return list<SaleMessage>
 */
function fakeSentMessages(): array
{
    $messages = [];
    Eet::assertSent(function (SaleMessage $message) use (&$messages): bool {
        $messages[] = $message;

        return true;
    });

    return $messages;
}

it('omits optional attributes that were not given and the overeni attribute in live mode [R22, R30]', function (): void {
    Eet::fake();

    register(cashRegister(), saleData('1', null, '100.00'));

    $message = fakeSentMessages()[0];
    $data = (new SoapMessage($message->xml))->data();
    $header = (new SoapMessage($message->xml))->header();

    expect($message->delegatingEic)->toBeNull()
        ->and($message->delegatedByMultipleTaxpayers)->toBeFalse()
        ->and($message->amountForLaterDrawing)->toBeNull()
        ->and($message->drawnAmount)->toBeNull()
        ->and($message->verificationMode)->toBeFalse()
        ->and($data)->not->toHaveKeys(['eic_poverujiciho'])
        ->and($data)->not->toHaveKey('povereni_vice_popl')
        ->and($data)->not->toHaveKey('urceno_cerp_zuct')
        ->and($data)->not->toHaveKey('cerp_zuct')
        ->and($header)->not->toHaveKey('overeni')
        ->and($message->xml)->not->toContain('=""');
});

it('writes all given optional attributes [R22]', function (): void {
    Eet::fake();

    register(cashRegister('CZ00000019'), saleData('1', null, '300.00', '100.00', '50.00', 'CZ683555118', true));

    $message = fakeSentMessages()[0];
    $data = (new SoapMessage($message->xml))->data();

    expect($data['eic_poverujiciho'])->toBe('CZ683555118')
        ->and($data['povereni_vice_popl'])->toBe('true')
        ->and($data['urceno_cerp_zuct'])->toBe('100.00')
        ->and($data['cerp_zuct'])->toBe('50.00')
        ->and($data['celk_trzba'])->toBe('300.00')
        ->and($message->delegatingEic)->toBe('CZ683555118')
        ->and($message->delegatedByMultipleTaxpayers)->toBeTrue()
        ->and($message->amountForLaterDrawing)->toBe('100.00')
        ->and($message->drawnAmount)->toBe('50.00');
});

it('omits povereni_vice_popl when delegatedByMultipleTaxpayers is false [R22]', function (): void {
    Eet::fake();

    register(cashRegister('CZ00000019'), saleData('1', null, '100.00', null, null, 'CZ683555118', false));

    $data = (new SoapMessage(fakeSentMessages()[0]->xml))->data();

    expect($data['eic_poverujiciho'])->toBe('CZ683555118')
        ->and($data)->not->toHaveKey('povereni_vice_popl');
});

it('sends a zero drawn amount and a zero total amount as 0.00 [R22, R56]', function (): void {
    Eet::fake();

    register(cashRegister(), saleData('1', null, '0.00', null, '0.00'));

    $data = (new SoapMessage(fakeSentMessages()[0]->xml))->data();

    expect($data['cerp_zuct'])->toBe('0.00')
        ->and($data['celk_trzba'])->toBe('0.00');
});

it('formats occurredAt given in UTC in Europe/Prague winter time [R24]', function (): void {
    $this->travelTo(CarbonImmutable::parse('2027-01-09T05:00:00+01:00'));
    Eet::fake();

    $sale = register(cashRegister(), saleData('1', CarbonImmutable::parse('2027-01-09T03:25:28Z')));

    expect(fakeSentMessages()[0]->occurredAt)->toBe('2027-01-09T04:25:28+01:00')
        ->and($sale->occurred_at)->toBe('2027-01-09T04:25:28+01:00')
        ->and($sale->occurredAt()->equalTo(CarbonImmutable::parse('2027-01-09T03:25:28Z')))->toBeTrue();
});

it('formats occurredAt in Europe/Prague summer time [R24]', function (): void {
    $this->travelTo(CarbonImmutable::parse('2027-06-15T12:30:00+02:00'));
    Eet::fake();

    register(cashRegister(), saleData('1', CarbonImmutable::parse('2027-06-15T10:00:00Z')));

    expect(fakeSentMessages()[0]->occurredAt)->toBe('2027-06-15T12:00:00+02:00');
});

it('switches the offset at the start of summer time [R24]', function (): void {
    $this->travelTo(CarbonImmutable::parse('2027-03-28T04:00:00+02:00'));
    Eet::fake();
    $cashRegister = cashRegister();

    register($cashRegister, saleData('1', CarbonImmutable::parse('2027-03-28 01:59:59', 'Europe/Prague')));
    register($cashRegister, saleData('2', CarbonImmutable::parse('2027-03-28 03:00:00', 'Europe/Prague')));

    $messages = fakeSentMessages();

    expect($messages[0]->occurredAt)->toBe('2027-03-28T01:59:59+01:00')
        ->and($messages[1]->occurredAt)->toBe('2027-03-28T03:00:00+02:00');
});

it('drops fractions of a second from occurredAt and sentAt [R24]', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-20T10:00:00.654321+01:00'));
    Eet::fake();

    register(cashRegister(), saleData('1', CarbonImmutable::parse('2026-11-20T09:59:58.987654+01:00')));

    $message = fakeSentMessages()[0];

    expect($message->occurredAt)->toBe('2026-11-20T09:59:58+01:00')
        ->and($message->sentAt)->toBe('2026-11-20T10:00:00+01:00');
});

it('uses the configured timezone for occurredAt and sentAt [R24]', function (): void {
    config()->set('laravel-eet-module.timezone', 'UTC');
    Eet::fake();

    register(cashRegister(), saleData('1', CarbonImmutable::parse('2026-11-20T09:00:00+01:00')));

    $message = fakeSentMessages()[0];

    expect($message->occurredAt)->toBe('2026-11-20T08:00:00+00:00')
        ->and($message->sentAt)->toBe('2026-11-20T09:00:00+00:00');
});

it('computes the unique sale code of the MF example exactly [R25]', function (): void {
    $this->travelTo(CarbonImmutable::parse('2027-01-09T17:00:00+01:00'));
    Eet::fake();

    $sale = register(
        cashRegister('CZ00000019', Environment::Production, 243, '24/A-6/Brno_2'),
        saleData('#135433c/11/2027', CarbonImmutable::parse('2027-01-09T16:45:36+01:00'), '3264.5'),
    );

    expect($sale->unique_sale_code)->toBe('CZ00000019|243|24/A-6/Brno_2|#135433c/11/2027|2027-01-09T16:45:36+01:00|3264.50');
});

it('gives every attempt a new version 4 UUID and sends prvni_zaslani="false" from the second attempt [R29]', function (): void {
    Eet::fake([FakeResponse::timeout(), FakeResponse::timeout(), FakeResponse::confirmed()]);

    $sale = register(cashRegister());
    $this->travel(5)->minutes();
    Eet::resubmit(freshSale($sale));
    $this->travel(5)->minutes();
    Eet::resubmit(freshSale($sale));

    $messages = fakeSentMessages();
    $uuids = array_map(fn (SaleMessage $message): string => $message->messageUuid, $messages);

    expect($messages)->toHaveCount(3)
        ->and(array_unique($uuids))->toHaveCount(3)
        ->and(array_filter($uuids, fn (string $uuid): bool => preg_match(UUID_V4, $uuid) === 1))->toHaveCount(3)
        ->and(array_map(fn (SaleMessage $message): bool => $message->isFirstSubmission, $messages))->toBe([true, false, false])
        ->and($messages[1]->sentAt)->toBe('2026-11-20T10:05:00+01:00')
        ->and($messages[2]->sentAt)->toBe('2026-11-20T10:10:00+01:00')
        ->and(attemptsOf($sale)->pluck('message_uuid')->all())->toBe($uuids)
        ->and(attemptsOf($sale)->pluck('is_first_submission')->all())->toBe([true, false, false]);
});

it('lets tests choose the message UUIDs and restores the default generator with null [R29]', function (): void {
    Eet::fake();
    $uuids = ['8a3cbb7e-5d4f-4c39-9b1e-6c2b0f4e8a11', '0f4b7a1c-2d3e-4f5a-8b6c-7d8e9fa0b1c2'];
    Eet::createMessageUuidsUsing(function () use (&$uuids): string {
        return (string) array_shift($uuids);
    });

    $first = register(cashRegister(), saleData('1'));
    $second = register(cashRegister('CZ00000019', Environment::Playground, 181, 'POKLADNA-2'), saleData('2'));
    Eet::createMessageUuidsUsing(null);
    $third = register(cashRegister('CZ00000019', Environment::Playground, 181, 'POKLADNA-3'), saleData('3'));

    expect(lastAttempt($first)->message_uuid)->toBe('8a3cbb7e-5d4f-4c39-9b1e-6c2b0f4e8a11')
        ->and(lastAttempt($second)->message_uuid)->toBe('0f4b7a1c-2d3e-4f5a-8b6c-7d8e9fa0b1c2')
        ->and(lastAttempt($third)->message_uuid)->toMatch(UUID_V4)
        ->and(lastAttempt($third)->message_uuid)->not->toBeIn(['8a3cbb7e-5d4f-4c39-9b1e-6c2b0f4e8a11', '0f4b7a1c-2d3e-4f5a-8b6c-7d8e9fa0b1c2']);
});

it('throws a LogicException and sends nothing when the UUID factory returns an invalid UUID [R29]', function (string $invalid): void {
    Eet::fake();
    Eet::createMessageUuidsUsing(fn (): string => $invalid);

    expect(fn (): Sale => register(cashRegister()))->toThrow(LogicException::class);
    Eet::assertNothingSent();
})->with([
    'garbage' => 'abc',
    'version 1 UUID' => 'c232ab00-9414-11ec-b3c8-9f6bdeced846',
    'wrong variant' => '8a3cbb7e-5d4f-4c39-7b1e-6c2b0f4e8a11',
]);

it('sends a message that validates against EETXMLSchema.xsd 4.1 in live mode [R30]', function (): void {
    confirmingEetService();
    importFixture(taxpayer());

    register(cashRegister(), saleData('#25/c-12/1A_2/2027', null, '-187.20', null, '-87.20', 'CZ683555118', true));

    $message = sentSoapMessages()[0];

    expect($message->schemaErrors())->toBe([])
        ->and($message->header())->not->toHaveKey('overeni')
        ->and($message->data()['celk_trzba'])->toBe('-187.20')
        ->and($message->data()['cerp_zuct'])->toBe('-87.20');
});

it('sends overeni="true" in verification mode and validates against the schema [R30, R46]', function (): void {
    config()->set('laravel-eet-module.mode', 'verification');
    useTestResponseCa();
    fakeEetService(fn (SoapMessage $message): EetResponse => EetResponse::error($message->uuid(), 0, 'Datovou zpravu evidovane trzby v overovacim modu se podarilo zpracovat')->test('true'));
    importFixture(taxpayer());

    register(cashRegister());

    $message = sentSoapMessages()[0];

    expect($message->header()['overeni'])->toBe('true')
        ->and($message->schemaErrors())->toBe([]);
});

it('signs exactly the soap:Body with exc-c14n, SHA-256 and RSA-SHA256 and a verifiable signature [R31]', function (): void {
    confirmingEetService();
    importFixture(taxpayer(), 'playground/CZ00000019.pem');

    register(cashRegister());

    $message = sentSoapMessages()[0];
    $verification = $message->verifySignature();
    $algorithms = $message->signatureAlgorithms();

    expect($verification['valid'])->toBeTrue($verification['reason'])
        ->and($verification['references'])->toHaveCount(1)
        ->and($verification['signedLocalName'])->toBe('Body')
        ->and($algorithms['canonicalization'])->toBe('http://www.w3.org/2001/10/xml-exc-c14n#')
        ->and($algorithms['signature'])->toBe('http://www.w3.org/2001/04/xmldsig-more#rsa-sha256')
        ->and($algorithms['digest'])->toBe('http://www.w3.org/2001/04/xmlenc#sha256')
        ->and($algorithms['transforms'])->toBe(['http://www.w3.org/2001/10/xml-exc-c14n#'])
        ->and($algorithms['keyInfo'])->toBe([EetResponse::NS_WSSE.'#SecurityTokenReference'])
        ->and($message->tokenFingerprint())->toBe(Fixtures::fingerprint('playground/CZ00000019.cert.pem'));
});

it('sends no other headers than wsse:Security with the token and the signature [R31]', function (): void {
    confirmingEetService();
    importFixture(taxpayer());

    register(cashRegister());

    $message = sentSoapMessages()[0];

    expect($message->headerChildren())->toBe(['Security'])
        ->and($message->securityChildren())->toEqualCanonicalizing(['BinarySecurityToken', 'Signature'])
        ->and($message->xml)->not->toContain('Timestamp')
        ->and($message->xml)->not->toContain('http://www.w3.org/2005/08/addressing');
});

it('gives the fake an unsigned envelope with the parsed message [R57, R31]', function (): void {
    Eet::fake();

    register(cashRegister('CZ00000019', Environment::Playground, 181, 'POKLADNA-1'), saleData('42', null, '20.45'));

    $message = fakeSentMessages()[0];

    expect($message->eic)->toBe('CZ00000019')
        ->and($message->registrationUnitId)->toBe(181)
        ->and($message->cashRegisterId)->toBe('POKLADNA-1')
        ->and($message->sequenceNumber)->toBe('42')
        ->and($message->totalAmount)->toBe('20.45')
        ->and($message->isFirstSubmission)->toBeTrue()
        ->and($message->messageUuid)->toMatch(UUID_V4)
        ->and($message->xml)->not->toContain('SignatureValue')
        ->and((new SoapMessage($message->xml))->schemaErrors())->toBe([]);
});
