<?php

declare(strict_types=1);

use Gunner42\LaravelEetModule\Eet as EetManager;
use Gunner42\LaravelEetModule\Enums\AttemptOutcome;
use Gunner42\LaravelEetModule\Enums\CertificateProblem;
use Gunner42\LaravelEetModule\Enums\Environment;
use Gunner42\LaravelEetModule\Enums\ErrorCode;
use Gunner42\LaravelEetModule\Enums\PreflightWarning;
use Gunner42\LaravelEetModule\Enums\SaleDataProblem;
use Gunner42\LaravelEetModule\Enums\SaleStatus;
use Gunner42\LaravelEetModule\Enums\SendingMode;
use Gunner42\LaravelEetModule\Enums\WarningCode;
use Gunner42\LaravelEetModule\Exceptions\ActiveDatabaseTransaction;
use Gunner42\LaravelEetModule\Exceptions\CertificateTaxpayerMismatch;
use Gunner42\LaravelEetModule\Exceptions\DuplicateCashRegister;
use Gunner42\LaravelEetModule\Exceptions\DuplicateRegistrationUnit;
use Gunner42\LaravelEetModule\Exceptions\DuplicateSequenceNumber;
use Gunner42\LaravelEetModule\Exceptions\DuplicateTaxpayer;
use Gunner42\LaravelEetModule\Exceptions\EetException;
use Gunner42\LaravelEetModule\Exceptions\InvalidAmount;
use Gunner42\LaravelEetModule\Exceptions\InvalidCertificate;
use Gunner42\LaravelEetModule\Exceptions\InvalidConfiguration;
use Gunner42\LaravelEetModule\Exceptions\InvalidEic;
use Gunner42\LaravelEetModule\Exceptions\InvalidIdentifier;
use Gunner42\LaravelEetModule\Exceptions\InvalidSaleData;
use Gunner42\LaravelEetModule\Exceptions\ReversalEnvironmentMismatch;
use Gunner42\LaravelEetModule\Exceptions\ReversalTaxpayerMismatch;
use Gunner42\LaravelEetModule\Exceptions\SaleNotReopenable;
use Gunner42\LaravelEetModule\Facades\Eet;
use Gunner42\LaravelEetModule\Jobs\ResubmitSale;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * @return array<string, list<UnitEnum>>
 */
function translatedEnums(): array
{
    return [
        'error_codes' => ErrorCode::cases(),
        'warning_codes' => WarningCode::cases(),
        'sale_statuses' => SaleStatus::cases(),
        'attempt_outcomes' => AttemptOutcome::cases(),
        'certificate_problems' => CertificateProblem::cases(),
        'sale_data_problems' => SaleDataProblem::cases(),
        'preflight_warnings' => PreflightWarning::cases(),
    ];
}

it('resolves the facade to the package manager singleton with the methods of the public contract [R19]', function (): void {
    expect(Eet::getFacadeRoot())->toBeInstanceOf(EetManager::class)
        ->and(app(EetManager::class))->toBe(app(EetManager::class));

    foreach ([
        'createTaxpayer', 'changeEnvironment', 'createRegistrationUnit', 'createCashRegister', 'importCertificate',
        'supportsLegacyPkcs12', 'register', 'reverse', 'resubmit', 'verifyConnection', 'fake',
        'createMessageUuidsUsing',
    ] as $method) {
        expect(method_exists(EetManager::class, $method))->toBeTrue("Missing Eet::{$method}()");
    }
});

it('has a translation in cs and en for every enum value, returned by label() [R58]', function (string $locale): void {
    app()->setLocale($locale);

    foreach (translatedEnums() as $group => $cases) {
        foreach ($cases as $case) {
            $key = "laravel-eet-module::messages.{$group}.{$case->name}";

            expect(trans()->hasForLocale($key, $locale))->toBeTrue("Missing {$locale} translation {$key}")
                ->and(trans($key))->not->toBe('')
                ->and($case->label())->toBe(trans($key));
        }
    }
})->with(['cs', 'en']);

it('translates differently in Czech and English [R58]', function (): void {
    app()->setLocale('cs');
    $czech = ErrorCode::TemporaryTechnicalError->label();
    app()->setLocale('en');
    $english = ErrorCode::TemporaryTechnicalError->label();

    expect($czech)->not->toBe($english);
});

it('defines the enums with the documented backing values [R33, R58]', function (): void {
    expect(array_map(fn (SaleStatus $case): string => $case->value, SaleStatus::cases()))->toBe(['pending', 'confirmed', 'verified', 'unconfirmed', 'rejected'])
        ->and(array_map(fn (AttemptOutcome $case): string => $case->value, AttemptOutcome::cases()))->toBe([
            'confirmed', 'verified', 'rejected', 'temporary_error', 'ambiguous_error', 'timeout',
            'transport_error', 'invalid_response_signature', 'response_mismatch', 'certificate_unavailable',
        ])
        ->and(array_map(fn (SendingMode $case): string => $case->value, SendingMode::cases()))->toBe(['live', 'verification'])
        ->and(array_map(fn (Environment $case): string => $case->value, Environment::cases()))->toBe(['playground', 'production'])
        ->and(array_map(fn (ErrorCode $case): int => $case->value, ErrorCode::cases()))->toBe([-1, 0, 2, 3, 4, 6, 7, 8])
        ->and(array_map(fn (WarningCode $case): int => $case->value, WarningCode::cases()))->toBe([1, 2, 4, 5, 6, 7])
        ->and(array_map(fn (CertificateProblem $case): string => $case->value, CertificateProblem::cases()))->toBe(['unreadable', 'missing_private_key', 'key_mismatch', 'expired', 'unsupported_legacy_format'])
        ->and(array_map(fn (SaleDataProblem $case): string => $case->value, SaleDataProblem::cases()))->toBe(['invalid_format', 'delegating_eic_equals_taxpayer_eic', 'too_old', 'before_environment_start', 'sign_mismatch', 'parts_exceed_total'])
        ->and(array_map(fn (PreflightWarning $case): string => $case->value, PreflightWarning::cases()))->toBe(['sale_in_future', 'unusual_registration_unit_number', 'certificate_eic_mismatch']);
});

it('derives every package exception from EetException [R15]', function (string $class): void {
    expect(is_subclass_of($class, EetException::class))->toBeTrue()
        ->and(is_subclass_of(EetException::class, RuntimeException::class))->toBeTrue();
})->with([
    InvalidEic::class, InvalidIdentifier::class, InvalidSaleData::class, InvalidAmount::class,
    DuplicateTaxpayer::class, DuplicateRegistrationUnit::class, DuplicateCashRegister::class,
    DuplicateSequenceNumber::class, ActiveDatabaseTransaction::class, InvalidCertificate::class,
    CertificateTaxpayerMismatch::class, ReversalTaxpayerMismatch::class, ReversalEnvironmentMismatch::class,
    SaleNotReopenable::class, InvalidConfiguration::class,
]);

it('queues ResubmitSale with the sale id [R40]', function (): void {
    $job = new ResubmitSale(42);

    expect($job)->toBeInstanceOf(ShouldQueue::class)
        ->and((new ReflectionClass(ResubmitSale::class))->getConstructor()?->getParameters()[0]->getType()?->__toString())->toBe('int');
});

it('registers the Artisan commands of the public contract [R18, R52, R53, R54, R55]', function (): void {
    $commands = Artisan::all();

    expect($commands)->toHaveKeys(['eet:certificate:import', 'eet:verify', 'eet:resubmit', 'eet:status', 'eet:prune'])
        ->and($commands['eet:resubmit']->getDefinition()->hasOption('sale'))->toBeTrue()
        ->and($commands['eet:resubmit']->getDefinition()->getOption('sale')->isArray())->toBeTrue()
        ->and($commands['eet:resubmit']->getDefinition()->hasOption('force'))->toBeTrue()
        ->and($commands['eet:prune']->getDefinition()->hasOption('days'))->toBeTrue()
        ->and($commands['eet:verify']->getDefinition()->hasArgument('eic'))->toBeTrue()
        ->and($commands['eet:verify']->getDefinition()->hasArgument('registration-unit'))->toBeTrue()
        ->and($commands['eet:verify']->getDefinition()->hasArgument('cash-register'))->toBeTrue()
        ->and($commands)->not->toHaveKey('laravel-eet-module:placeholder');
});

it('removed the scaffold view, route and translation [R58]', function (): void {
    expect(view()->exists('laravel-eet-module::placeholder'))->toBeFalse()
        ->and(Route::has('laravel-eet-module.placeholder'))->toBeFalse()
        ->and(trans()->has('laravel-eet-module::messages.placeholder'))->toBeFalse();
});

it('publishes config, migrations and translations under the documented tags, but not the bundled certificates [R58, R68, R35]', function (): void {
    $published = [];

    foreach (['laravel-eet-module-config', 'laravel-eet-module-migrations', 'laravel-eet-module-lang', 'laravel-eet-module'] as $tag) {
        $published[$tag] = array_values(ServiceProvider::pathsToPublish(null, $tag));
    }

    $config = implode('|', $published['laravel-eet-module-config']);
    $migrations = implode('|', $published['laravel-eet-module-migrations']);
    $lang = implode('|', $published['laravel-eet-module-lang']);

    expect($config)->toContain(config_path('laravel-eet-module.php'))
        ->and($migrations)->toContain(database_path('migrations'))
        ->and($lang)->toContain(lang_path('vendor/laravel-eet-module'))
        ->and(count($published['laravel-eet-module']))->toBeGreaterThanOrEqual(3)
        ->and(ServiceProvider::pathsToPublish(null, 'laravel-eet-module-views'))->toBe([])
        ->and(ServiceProvider::pathsToPublish(null, 'laravel-eet-module-assets'))->toBe([])
        // the bundled I.CA certificates (resources/certs) are not publishable (R35)
        ->and(implode('|', array_merge(
            array_keys(ServiceProvider::pathsToPublish(null, 'laravel-eet-module')),
            array_values(ServiceProvider::pathsToPublish(null, 'laravel-eet-module')),
        )))->not->toContain('certs');
});

it('publishes the migrations of the eet_* tables and the cs and en translations [R58]', function (): void {
    // Parallel-safe: the registered publish paths are copied into a private
    // temporary directory instead of the Testbench skeleton shared by workers.
    $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'eet-publish-'.bin2hex(random_bytes(8));

    /**
     * @return string the directory the tag was published into
     */
    $publish = function (string $tag, string $name) use ($root): string {
        $target = $root.DIRECTORY_SEPARATOR.$name;
        File::ensureDirectoryExists($target);

        foreach (ServiceProvider::pathsToPublish(null, $tag) as $source => $destination) {
            if (File::isDirectory($source)) {
                File::copyDirectory($source, $target);
            } else {
                File::copy($source, $target.DIRECTORY_SEPARATOR.basename($destination));
            }
        }

        return $target;
    };

    try {
        $migrationsTarget = $publish('laravel-eet-module-migrations', 'migrations');
        $langTarget = $publish('laravel-eet-module-lang', 'lang');

        $migrations = implode("\n", array_map(fn (SplFileInfo $file): string => (string) file_get_contents($file->getPathname()), File::allFiles($migrationsTarget)));

        foreach (['eet_taxpayers', 'eet_registration_units', 'eet_cash_registers', 'eet_cash_register_certificates', 'eet_sales', 'eet_submission_attempts'] as $table) {
            expect($migrations)->toContain($table);
        }

        expect(File::exists($langTarget.'/cs/messages.php'))->toBeTrue()
            ->and(File::exists($langTarget.'/en/messages.php'))->toBeTrue();
    } finally {
        File::deleteDirectory($root);
    }
});
