<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Gunner42\LaravelEetModule\Actions\ImportCertificate;
use Gunner42\LaravelEetModule\Eet as EetManager;
use Gunner42\LaravelEetModule\Enums\CertificateProblem;
use Gunner42\LaravelEetModule\Enums\Environment;
use Gunner42\LaravelEetModule\Exceptions\InvalidCertificate;
use Gunner42\LaravelEetModule\Facades\Eet;
use Gunner42\LaravelEetModule\Models\CashRegisterCertificate;
use Gunner42\LaravelEetModule\Tests\Support\Fixtures;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    // playground certificates are valid 2026-05-13 – 2027-05-14
    $this->travelTo(frozenNow());
});

/**
 * Imports and returns the exception reason, or null on success.
 */
function importProblem(string $contents, ?string $password = null, string $eic = 'CZ00000019'): ?CertificateProblem
{
    try {
        Eet::importCertificate(taxpayer($eic), $contents, $password);

        return null;
    } catch (InvalidCertificate $exception) {
        return $exception->problem;
    }
}

it('imports a PEM with certificate, encrypted key and password and stores its data [R9, R12]', function (): void {
    $taxpayer = taxpayer('CZ00000019');
    $parsed = openssl_x509_parse(Fixtures::certificate('playground/CZ00000019.cert.pem'));
    assert(is_array($parsed));

    $certificate = Eet::importCertificate($taxpayer, Fixtures::read('playground/CZ00000019.encrypted.pem'), Fixtures::PEM_PASSWORD);

    expect($certificate)->toBeInstanceOf(CashRegisterCertificate::class)
        ->and($certificate->taxpayer_id)->toBe($taxpayer->getKey())
        ->and($certificate->subject_eic)->toBe('CZ00000019')
        ->and($certificate->valid_from)->toBeInstanceOf(CarbonImmutable::class)
        ->and($certificate->valid_from->getTimestamp())->toBe($parsed['validFrom_time_t'])
        ->and($certificate->valid_to)->toBeInstanceOf(CarbonImmutable::class)
        ->and($certificate->valid_to->getTimestamp())->toBe(CarbonImmutable::parse('2027-05-14 09:40:23', 'UTC')->getTimestamp())
        ->and(strtolower($certificate->fingerprint_sha256))->toBe(Fixtures::fingerprint('playground/CZ00000019.cert.pem'))
        ->and(in_array(strtoupper((string) $certificate->serial_number), [strtoupper($parsed['serialNumberHex']), $parsed['serialNumber']], true))->toBeTrue()
        ->and(strtolower((string) openssl_x509_fingerprint($certificate->certificate, 'sha256')))->toBe(Fixtures::fingerprint('playground/CZ00000019.cert.pem'))
        ->and(substr_count((string) $certificate->chain, '-----BEGIN CERTIFICATE-----'))->toBe(2)
        ->and($taxpayer->certificates()->count())->toBe(1);
});

it('imports a plain PEM with an unencrypted key without a password [R9]', function (): void {
    $certificate = importFixture(taxpayer('CZ683555118'), 'playground/CZ683555118.pem');

    expect($certificate->subject_eic)->toBe('CZ683555118')
        ->and($certificate->isUsable())->toBeTrue();
});

it('imports a PKCS#12 with a password [R9]', function (): void {
    $certificate = importFixture(taxpayer('CZ8551015704'), 'playground/CZ8551015704.modern.p12', Fixtures::P12_PASSWORD);

    expect($certificate->subject_eic)->toBe('CZ8551015704')
        ->and(strtolower($certificate->fingerprint_sha256))->toBe(Fixtures::fingerprint('playground/CZ8551015704.cert.pem'));
});

it('imports the legacy CA EET PKCS#12 exactly when the runtime supports it [R9, R70]', function (): void {
    $supported = Eet::supportsLegacyPkcs12();

    try {
        $certificate = importFixture(taxpayer('CZ00000019'), 'playground/CA_EET-Playground-CZ00000019.p12', Fixtures::PLAYGROUND_PASSWORD);
        $problem = null;
        $message = '';
    } catch (InvalidCertificate $exception) {
        $certificate = null;
        $problem = $exception->problem;
        $message = $exception->getMessage();
    }

    expect($supported)->toBeBool();

    if ($supported) {
        expect($certificate?->subject_eic)->toBe('CZ00000019')
            ->and($problem)->toBeNull();
    } else {
        expect($problem)->toBe(CertificateProblem::UnsupportedLegacyFormat)
            ->and($message)->toContain('PEM')
            ->and(CashRegisterCertificate::query()->count())->toBe(0);
    }
});

it('reports legacy PKCS#12 support through the facade and the manager [R70]', function (): void {
    expect(Eet::supportsLegacyPkcs12())->toBe(app(EetManager::class)->supportsLegacyPkcs12());
});

it('reports Unreadable for a wrong PKCS#12 password regardless of legacy support [R10, R70]', function (string $fixture): void {
    expect(importProblem(Fixtures::read($fixture), 'SpatneHeslo42'))->toBe(CertificateProblem::Unreadable)
        ->and(CashRegisterCertificate::query()->count())->toBe(0);
})->with([
    'legacy CA EET file' => 'playground/CA_EET-Playground-CZ00000019.p12',
    'modern file' => 'playground/CZ00000019.modern.p12',
]);

it('reports Unreadable for a wrong PEM key password [R10]', function (): void {
    expect(importProblem(Fixtures::read('playground/CZ00000019.encrypted.pem'), 'SpatneHeslo42'))->toBe(CertificateProblem::Unreadable)
        ->and(CashRegisterCertificate::query()->count())->toBe(0);
});

it('reports Unreadable for damaged contents [R10]', function (string $contents): void {
    expect(importProblem($contents, 'whatever'))->toBe(CertificateProblem::Unreadable)
        ->and(CashRegisterCertificate::query()->count())->toBe(0);
})->with([
    'garbage' => 'this is not a certificate',
    'empty' => '',
    'truncated PEM' => "-----BEGIN CERTIFICATE-----\nMIIECjCCAvKgAwIBAgIU\n-----END CERTIFICATE-----\n",
]);

it('reports MissingPrivateKey for a PEM without a key [R10]', function (): void {
    expect(importProblem(Fixtures::read('playground/CZ00000019.cert.pem')))->toBe(CertificateProblem::MissingPrivateKey)
        ->and(CashRegisterCertificate::query()->count())->toBe(0);
});

it('reports KeyMismatch for a PEM with a foreign key [R10]', function (): void {
    expect(importProblem(Fixtures::read('playground/CZ00000019.foreign-key.pem')))->toBe(CertificateProblem::KeyMismatch)
        ->and(CashRegisterCertificate::query()->count())->toBe(0);
});

it('reports Expired for a certificate whose validTo is in the past [R10]', function (): void {
    expect(importProblem(Fixtures::read('cash-register/CZ00000019-expired-2025.pem')))->toBe(CertificateProblem::Expired)
        ->and(CashRegisterCertificate::query()->count())->toBe(0);
});

it('imports a certificate that expires exactly now [R10]', function (): void {
    $this->travelTo(CarbonImmutable::parse('2027-03-01 12:00:00', 'UTC'));

    expect(importProblem(Fixtures::read('cash-register/CZ00000019-until-2027-03-01-noon.pem')))->toBeNull()
        ->and(CashRegisterCertificate::query()->count())->toBe(1);
});

it('reports Expired one second after validTo [R10]', function (): void {
    $this->travelTo(CarbonImmutable::parse('2027-03-01 12:00:01', 'UTC'));

    expect(importProblem(Fixtures::read('cash-register/CZ00000019-until-2027-03-01-noon.pem')))->toBe(CertificateProblem::Expired);
});

it('imports a certificate that is not valid yet [R11]', function (): void {
    $certificate = importFixture(taxpayer(), 'cash-register/CZ00000019-from-2027-01-01.pem');

    expect($certificate->valid_from->format('Y-m-d'))->toBe('2027-01-01')
        ->and($certificate->isValidAt(now()))->toBeFalse()
        ->and($certificate->isValidAt(CarbonImmutable::parse('2027-01-01 00:00:00', 'UTC')))->toBeTrue();
});

it('reports validity through isValidAt including both boundaries [R10, R16]', function (): void {
    $certificate = importFixture(taxpayer(), 'cash-register/CZ00000019-until-2027-03-01-noon.pem');

    expect($certificate->isValidAt(CarbonImmutable::parse('2026-01-01 00:00:00', 'UTC')))->toBeTrue()
        ->and($certificate->isValidAt(CarbonImmutable::parse('2027-03-01 12:00:00', 'UTC')))->toBeTrue()
        ->and($certificate->isValidAt(CarbonImmutable::parse('2027-03-01 12:00:01', 'UTC')))->toBeFalse()
        ->and($certificate->isValidAt(CarbonImmutable::parse('2025-12-31 23:59:59', 'UTC')))->toBeFalse();
});

it('stores the private key encrypted and never the password [R12, R15]', function (): void {
    $certificate = importFixture(taxpayer(), 'playground/CZ00000019.encrypted.pem', Fixtures::PEM_PASSWORD);

    $row = (array) DB::table('eet_cash_register_certificates')->where('id', $certificate->getKey())->first();
    $serializedRow = json_encode($row, JSON_THROW_ON_ERROR);
    $plainKeyBody = trim((string) preg_replace('/-----[A-Z ]+-----/', '', Fixtures::read('playground/CZ00000019.key.pem')));
    $firstKeyLine = strtok($plainKeyBody, "\n");

    expect($row['private_key'])->not->toContain('PRIVATE KEY')
        ->and($row['private_key'])->not->toContain((string) $firstKeyLine)
        ->and($serializedRow)->not->toContain(Fixtures::PEM_PASSWORD);

    foreach (DB::table('eet_taxpayers')->get() as $taxpayerRow) {
        expect(json_encode($taxpayerRow, JSON_THROW_ON_ERROR))->not->toContain(Fixtures::PEM_PASSWORD);
    }
});

it('keeps the private key and the password out of model serialization [R12, R15]', function (): void {
    $certificate = importFixture(taxpayer(), 'playground/CZ00000019.encrypted.pem', Fixtures::PEM_PASSWORD);
    $fresh = CashRegisterCertificate::query()->findOrFail($certificate->getKey());

    foreach ([$certificate, $fresh] as $model) {
        expect($model->toArray())->not->toHaveKey('private_key')
            ->and($model->toJson())->not->toContain('PRIVATE KEY')
            ->and($model->toJson())->not->toContain(Fixtures::PEM_PASSWORD)
            ->and($model->getHidden())->toContain('private_key');
    }

    expect(taxpayer()->load('certificates')->toJson())->not->toContain('PRIVATE KEY');
});

it('marks the contents and password parameters as sensitive [R15]', function (string $class, string $method): void {
    $parameters = collect((new ReflectionMethod($class, $method))->getParameters())
        ->keyBy(fn (ReflectionParameter $parameter): string => $parameter->getName());

    expect($parameters)->toHaveKeys(['contents', 'password']);

    foreach (['contents', 'password'] as $name) {
        $attributes = array_map(
            fn (ReflectionAttribute $attribute): string => $attribute->getName(),
            $parameters[$name]->getAttributes(),
        );

        expect($attributes)->toContain(SensitiveParameter::class);
    }
})->with([
    'manager' => [EetManager::class, 'importCertificate'],
    'action' => [ImportCertificate::class, 'handle'],
]);

it('keeps the password and the key out of exception messages, traces and logs [R15]', function (): void {
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
        $logged[] = $event->message.json_encode($event->context);
    });
    $contents = Fixtures::read('playground/CZ00000019.encrypted.pem');
    $secretPassword = 'SpatneHeslo42';

    try {
        // called through the action, because the facade's __callStatic frame
        // holds the plain arguments outside the package's control
        app(ImportCertificate::class)->handle(taxpayer(), $contents, $secretPassword);
        $this->fail('InvalidCertificate was not thrown.');
    } catch (InvalidCertificate $exception) {
        $rendered = (string) $exception.$exception->getTraceAsString().print_r($exception->getTrace(), true);

        expect($rendered)->not->toContain($secretPassword)
            ->and($rendered)->not->toContain('ENCRYPTED PRIVATE KEY')
            ->and($exception->getMessage())->not->toContain($secretPassword);
    }

    importFixture(taxpayer(), 'playground/CZ00000019.encrypted.pem', Fixtures::PEM_PASSWORD);

    expect(implode("\n", $logged))->not->toContain($secretPassword)
        ->and(implode("\n", $logged))->not->toContain(Fixtures::PEM_PASSWORD)
        ->and(implode("\n", $logged))->not->toContain('PRIVATE KEY');
});

/**
 * Collects the strings of a stack trace (arguments up to a small depth),
 * without rendering whole objects.
 *
 * @param  list<array<string, mixed>>  $trace
 * @return list<string>
 */
function traceStrings(array $trace): array
{
    $strings = [];
    $collect = function (mixed $value, int $depth) use (&$collect, &$strings): void {
        if (is_string($value)) {
            $strings[] = $value;
        } elseif (is_array($value) && $depth < 3) {
            foreach ($value as $item) {
                $collect($item, $depth + 1);
            }
        }
    };

    foreach ($trace as $frame) {
        $collect($frame['args'] ?? [], 0);
    }

    return $strings;
}

it('keeps the key, the contents and the password out of the trace when persisting fails [R15]', function (string $via): void {
    CashRegisterCertificate::creating(function (): void {
        throw new RuntimeException('Persisting failed');
    });
    $contents = Fixtures::read('playground/CZ00000019.encrypted.pem');
    $keyLine = (string) strtok(trim((string) preg_replace('/-----[A-Z ]+-----/', '', Fixtures::read('playground/CZ00000019.key.pem'))), "\n");
    $contentsLine = (string) strtok(trim((string) preg_replace('/-----[A-Z ]+-----/', '', $contents)), "\n");
    $taxpayer = taxpayer();
    $thrown = null;

    try {
        $via === 'facade'
            ? Eet::importCertificate($taxpayer, $contents, Fixtures::PEM_PASSWORD)
            : app(ImportCertificate::class)->handle($taxpayer, $contents, Fixtures::PEM_PASSWORD);
    } catch (Throwable $exception) {
        $thrown = $exception;
    } finally {
        CashRegisterCertificate::flushEventListeners();
    }

    expect($thrown)->not->toBeNull();

    for ($exception = $thrown; $exception !== null; $exception = $exception->getPrevious()) {
        $rendered = implode("\n", traceStrings($exception->getTrace()))."\n".$exception->getTraceAsString()."\n".$exception->getMessage();

        expect($rendered)->not->toContain('PRIVATE KEY')
            ->and($rendered)->not->toContain($keyLine)
            ->and($rendered)->not->toContain($contentsLine)
            ->and($rendered)->not->toContain(Fixtures::PEM_PASSWORD);
    }

    expect(CashRegisterCertificate::query()->count())->toBe(0);
})->with(['facade', 'action']);

it('returns the existing record when the same certificate is imported again [R13]', function (): void {
    $taxpayer = taxpayer();
    $first = importFixture($taxpayer, 'playground/CZ00000019.pem');
    $second = importFixture($taxpayer, 'playground/CZ00000019.pem');
    $third = importFixture($taxpayer, 'playground/CZ00000019.modern.p12', Fixtures::P12_PASSWORD);

    expect($second->getKey())->toBe($first->getKey())
        ->and($third->getKey())->toBe($first->getKey())
        ->and(CashRegisterCertificate::query()->count())->toBe(1);
});

it('keeps certificates of different taxpayers separate even with the same fingerprint [R13]', function (): void {
    importFixture(taxpayer('CZ00000019'), 'playground/CZ00000019.pem');
    importFixture(taxpayer('CZ683555118'), 'playground/CZ00000019.pem');

    expect(CashRegisterCertificate::query()->count())->toBe(2);
});

it('imports a certificate whose CN differs from the taxpayer EIČ [R14]', function (): void {
    $certificate = importFixture(taxpayer('CZ00000019'), 'playground/CZ683555118.pem');

    expect($certificate->subject_eic)->toBe('CZ683555118')
        ->and($certificate->taxpayer->eic)->toBe('CZ00000019');
});

it('imports a playground certificate for a production taxpayer without checking the environment [R60]', function (): void {
    $certificate = importFixture(taxpayer('CZ00000019', Environment::Production));

    expect($certificate->exists)->toBeTrue()
        ->and(taxpayer()->environment)->toBe(Environment::Production);
});

it('reports a usable certificate whose key can be decrypted [R69]', function (): void {
    expect(importFixture(taxpayer())->isUsable())->toBeTrue();
});

it('exposes the import as an action [R9]', function (): void {
    $certificate = app(ImportCertificate::class)->handle(taxpayer(), Fixtures::read('playground/CZ00000019.pem'), null);

    expect($certificate->subject_eic)->toBe('CZ00000019');
});
