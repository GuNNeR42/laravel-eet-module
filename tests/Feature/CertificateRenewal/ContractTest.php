<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Gunner42\LaravelEetModule\Actions\RenewCertificate;
use Gunner42\LaravelEetModule\Eet as EetManager;
use Gunner42\LaravelEetModule\Enums\RenewalProblem;
use Gunner42\LaravelEetModule\Enums\RenewalStatus;
use Gunner42\LaravelEetModule\Events\CertificateRenewalFailed;
use Gunner42\LaravelEetModule\Events\CertificateRenewed;
use Gunner42\LaravelEetModule\Exceptions\CertificateNotRenewable;
use Gunner42\LaravelEetModule\Exceptions\EetException;
use Gunner42\LaravelEetModule\Models\CashRegisterCertificate;
use Gunner42\LaravelEetModule\Models\CertificateRenewal;
use Gunner42\LaravelEetModule\Tests\Support\Renewal;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

/*
 * The public contract of the renewal (spec/10-interface.md) that the
 * behavior tests do not cover on their own.
 */

beforeEach(function (): void {
    $this->travelTo(Renewal::now());
});

it('offers renewCertificate() on the facade manager and handle() on the RenewCertificate action with the same signature [R90, R104]', function (): void {
    foreach ([[EetManager::class, 'renewCertificate'], [RenewCertificate::class, 'handle']] as [$class, $method]) {
        expect(method_exists($class, $method))->toBeTrue("Missing {$class}::{$method}()");

        $reflection = new ReflectionMethod($class, $method);
        $parameters = $reflection->getParameters();

        expect($reflection->isPublic())->toBeTrue()
            ->and(array_map(fn (ReflectionParameter $parameter): string => $parameter->getName(), $parameters))->toBe(['certificate'])
            ->and((string) $parameters[0]->getType())->toBe(CashRegisterCertificate::class)
            ->and((string) $reflection->getReturnType())->toBe('?'.CertificateRenewal::class);
    }

    $public = array_filter(
        (new ReflectionClass(RenewCertificate::class))->getMethods(ReflectionMethod::IS_PUBLIC),
        fn (ReflectionMethod $method): bool => ! $method->isConstructor() && ! $method->isStatic(),
    );

    expect(array_values(array_map(fn (ReflectionMethod $method): string => $method->getName(), $public)))->toBe(['handle']);
});

it('defines RenewalStatus with snake_case values and isActive() for the first four states [R92]', function (): void {
    expect(array_map(fn (RenewalStatus $case): string => $case->name, RenewalStatus::cases()))->toBe(['Requesting', 'InProcess', 'Downloading', 'Acknowledging', 'Completed', 'Failed'])
        ->and(array_map(fn (RenewalStatus $case): string => $case->value, RenewalStatus::cases()))->toBe(['requesting', 'in_process', 'downloading', 'acknowledging', 'completed', 'failed'])
        ->and(array_map(fn (RenewalStatus $case): bool => $case->isActive(), RenewalStatus::cases()))->toBe([true, true, true, true, false, false]);
});

it('defines RenewalProblem with snake_case values and isTemporary() only for TemporaryError and UnknownPendingRequest [R101]', function (): void {
    $expected = [
        'NotProductionCertificate' => 'not_production_certificate',
        'CertificateNotValid' => 'certificate_not_valid',
        'CertificateUnusable' => 'certificate_unusable',
        'AlreadyRenewed' => 'already_renewed',
        'TemporaryError' => 'temporary_error',
        'UnknownPendingRequest' => 'unknown_pending_request',
        'Rejected' => 'rejected',
        'AlreadyFinished' => 'already_finished',
        'RequestFailed' => 'request_failed',
        'CertificateUnreadable' => 'certificate_unreadable',
        'CertificateMismatch' => 'certificate_mismatch',
    ];
    $actual = [];

    foreach (RenewalProblem::cases() as $case) {
        $actual[$case->name] = $case->value;
    }

    ksort($expected);
    ksort($actual);

    expect($actual)->toBe($expected);

    foreach (RenewalProblem::cases() as $case) {
        expect($case->isTemporary())->toBe(in_array($case, [RenewalProblem::TemporaryError, RenewalProblem::UnknownPendingRequest], true));
    }
});

it('translates every RenewalStatus and RenewalProblem in cs and en through label() [R105, R58]', function (string $locale): void {
    app()->setLocale($locale);

    foreach (['renewal_statuses' => RenewalStatus::cases(), 'renewal_problems' => RenewalProblem::cases()] as $group => $cases) {
        foreach ($cases as $case) {
            $key = "laravel-eet-module::messages.{$group}.{$case->name}";

            expect(trans()->hasForLocale($key, $locale))->toBeTrue("Missing {$locale} translation {$key}")
                ->and(trans($key))->not->toBe('')
                ->and($case->label())->toBe(trans($key));
        }
    }
})->with(['cs', 'en']);

it('derives CertificateNotRenewable from EetException with a public RenewalProblem [R90]', function (): void {
    $property = new ReflectionProperty(CertificateNotRenewable::class, 'problem');

    expect(is_subclass_of(CertificateNotRenewable::class, EetException::class))->toBeTrue()
        ->and($property->isPublic())->toBeTrue()
        ->and((string) $property->getType())->toBe(RenewalProblem::class);
});

it('defines the renewal events with the documented properties [R96, R102]', function (): void {
    $types = fn (string $class): array => collect((new ReflectionClass($class))->getProperties(ReflectionProperty::IS_PUBLIC))
        ->mapWithKeys(fn (ReflectionProperty $property): array => [$property->getName() => (string) $property->getType()])
        ->all();

    expect($types(CertificateRenewed::class))->toMatchArray([
        'certificate' => CashRegisterCertificate::class,
        'successor' => CashRegisterCertificate::class,
        'renewal' => CertificateRenewal::class,
    ])->and($types(CertificateRenewalFailed::class))->toMatchArray([
        'certificate' => CashRegisterCertificate::class,
        'renewal' => CertificateRenewal::class,
        'problem' => RenewalProblem::class,
    ]);
});

it('registers eet:certificate:renew with repeatable --taxpayer and --certificate and a --dry-run flag [R105]', function (): void {
    $commands = Artisan::all();

    expect($commands)->toHaveKey('eet:certificate:renew');

    $definition = $commands['eet:certificate:renew']->getDefinition();

    expect($definition->getArguments())->toBe([])
        ->and($definition->hasOption('taxpayer'))->toBeTrue()
        ->and($definition->getOption('taxpayer')->isArray())->toBeTrue()
        ->and($definition->hasOption('certificate'))->toBeTrue()
        ->and($definition->getOption('certificate')->isArray())->toBeTrue()
        ->and($definition->hasOption('dry-run'))->toBeTrue()
        ->and($definition->getOption('dry-run')->acceptValue())->toBeFalse();
});

it('merges the renewal configuration with the documented defaults [R100, R94, R105]', function (): void {
    expect(config('laravel-eet-module.renewal.endpoint'))->toBe('https://ca.caeet.gov.cz/api')
        ->and(config('laravel-eet-module.renewal.allowed_hosts'))->toBe(['ca.caeet.gov.cz', 'ca.zkus.caeet.gov.cz', 'ca.test.caeet.gov.cz'])
        ->and(config('laravel-eet-module.renewal.days_before_expiry'))->toBe(21)
        ->and(config('laravel-eet-module.renewal.max_wait_seconds'))->toBe(120)
        ->and(config('laravel-eet-module.renewal.timeout_ms'))->toBe(10000);
});

it('creates the eet_certificate_renewals table with the documented columns [R92]', function (): void {
    expect(Schema::hasTable('eet_certificate_renewals'))->toBeTrue()
        ->and(Schema::hasColumns('eet_certificate_renewals', [
            'id', 'certificate_id', 'successor_id', 'request_id', 'status', 'problem', 'problem_detail',
            'acknowledged_at', 'completed_at', 'failed_at', 'created_at', 'updated_at',
        ]))->toBeTrue()
        ->and((new CertificateRenewal)->getTable())->toBe('eet_certificate_renewals')
        // the certificate table itself does not change
        ->and(Schema::getColumnListing('eet_cash_register_certificates'))->toEqualCanonicalizing([
            'id', 'taxpayer_id', 'serial_number', 'subject_eic', 'valid_from', 'valid_to', 'fingerprint_sha256',
            'certificate', 'chain', 'private_key', 'created_at', 'updated_at',
        ]);
});

it('keeps request_id nullable and not unique, so a taken-over reqId can repeat [R92, R93]', function (): void {
    $certificate = Renewal::certificate();
    $other = Renewal::certificate('renewal/CZ00000019-until-2027-06-30.pem');
    Renewal::insert($certificate, RenewalStatus::Requesting, null);
    Renewal::insert($other, RenewalStatus::Requesting, null);
    Renewal::insert($certificate, RenewalStatus::Failed, '3fa85f64-5717-4562-b3fc-2c963f66afa6', RenewalProblem::CertificateUnreadable);
    Renewal::insert($certificate, RenewalStatus::InProcess, '3fa85f64-5717-4562-b3fc-2c963f66afa6');

    expect(CertificateRenewal::query()->where('request_id', '3fa85f64-5717-4562-b3fc-2c963f66afa6')->count())->toBe(2)
        ->and(CertificateRenewal::query()->whereNull('request_id')->count())->toBe(2);
});

it('casts the renewal columns to the enums and immutable dates [R92]', function (): void {
    $certificate = Renewal::certificate();
    $renewal = Renewal::insert($certificate, RenewalStatus::Failed, '3fa85f64-5717-4562-b3fc-2c963f66afa6', RenewalProblem::CertificateMismatch, 'detail');

    expect($renewal->status)->toBe(RenewalStatus::Failed)
        ->and($renewal->problem)->toBe(RenewalProblem::CertificateMismatch)
        ->and($renewal->failed_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($renewal->certificate()->first()?->getKey())->toBe($certificate->getKey())
        ->and($renewal->successor()->first())->toBeNull()
        ->and(Renewal::fresh($certificate)->renewals())->toBeInstanceOf(HasMany::class);
});

it('publishes the migration of eet_certificate_renewals with the other migrations [R92]', function (): void {
    $sources = array_keys(ServiceProvider::pathsToPublish(null, 'laravel-eet-module-migrations'));
    $contents = '';

    foreach ($sources as $source) {
        foreach (File::isDirectory($source) ? File::allFiles($source) : [new SplFileInfo($source)] as $file) {
            $contents .= (string) file_get_contents($file->getPathname());
        }
    }

    // assert on a boolean, so a failure does not print the migration sources
    expect(str_contains($contents, 'eet_certificate_renewals'))->toBeTrue();
});

it('lets both renewal events use SerializesModels [R96, R102]', function (string $event): void {
    expect(class_uses_recursive($event))->toHaveKey(SerializesModels::class);
})->with([CertificateRenewed::class, CertificateRenewalFailed::class]);

it('relies on Illuminate\\Http\\Client\\PendingRequest having no private properties [R100]', function (): void {
    $private = array_map(
        fn (ReflectionProperty $property): string => $property->getName(),
        (new ReflectionClass(PendingRequest::class))->getProperties(ReflectionProperty::IS_PRIVATE),
    );

    expect($private)->toBe([]);
});
