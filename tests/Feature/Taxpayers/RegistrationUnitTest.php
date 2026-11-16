<?php

declare(strict_types=1);

use Gunner42\LaravelEetModule\Actions\CreateRegistrationUnit;
use Gunner42\LaravelEetModule\Exceptions\DuplicateRegistrationUnit;
use Gunner42\LaravelEetModule\Exceptions\InvalidIdentifier;
use Gunner42\LaravelEetModule\Facades\Eet;
use Gunner42\LaravelEetModule\Models\RegistrationUnit;

it('creates registration units with numbers from 1 to 999999999 [R3]', function (int $number): void {
    $taxpayer = taxpayer();

    $unit = Eet::createRegistrationUnit($taxpayer, $number, 'Prodejna');

    expect($unit)->toBeInstanceOf(RegistrationUnit::class)
        ->and($unit->number)->toBe($number)
        ->and($unit->name)->toBe('Prodejna')
        ->and($unit->taxpayer->is($taxpayer))->toBeTrue()
        ->and($taxpayer->registrationUnits()->count())->toBe(1);
})->with([1, 5, 181, 999999999]);

it('rejects a registration unit number outside the mask and stores nothing [R3]', function (int $number): void {
    $taxpayer = taxpayer();

    try {
        Eet::createRegistrationUnit($taxpayer, $number);
        $this->fail('InvalidIdentifier was not thrown.');
    } catch (InvalidIdentifier $exception) {
        expect($exception->field)->toBe('registrationUnitNumber');
    }

    expect(RegistrationUnit::query()->count())->toBe(0);
})->with([0, -1, 1000000000]);

it('rejects the number 01 because the facade only accepts integers [R3]', function (): void {
    // "01" cannot reach the package as a number: the facade takes int and
    // strict_types forbids the string.
    $parameter = (new ReflectionMethod(CreateRegistrationUnit::class, 'handle'))->getParameters()[1];

    expect((string) $parameter->getType())->toBe('int');
});

it('rejects a second registration unit with the same number for the same taxpayer [R4]', function (): void {
    $taxpayer = taxpayer();
    Eet::createRegistrationUnit($taxpayer, 181);

    expect(fn (): RegistrationUnit => Eet::createRegistrationUnit($taxpayer, 181))->toThrow(DuplicateRegistrationUnit::class)
        ->and(RegistrationUnit::query()->count())->toBe(1);
});

it('allows the same registration unit number for different taxpayers [R4]', function (): void {
    Eet::createRegistrationUnit(taxpayer('CZ00000019'), 181);
    Eet::createRegistrationUnit(taxpayer('CZ683555118'), 181);

    expect(RegistrationUnit::query()->where('number', 181)->count())->toBe(2);
});

it('accepts a number without the DIS+ format and reports it through hasDisFormat [R5]', function (int $number, bool $disFormat): void {
    $unit = Eet::createRegistrationUnit(taxpayer(), $number);

    expect($unit->hasDisFormat())->toBe($disFormat);
})->with([
    'DIS+ number ending with 1' => [181, true],
    'two digits ending with 4' => [24, true],
    'ending with 2' => [243, true],
    'ending with 3' => [11003, true],
    'single digit' => [5, false],
    'single digit 1' => [1, false],
    'ending with 5' => [185, false],
    'ending with 0' => [180, false],
    'ending with 9' => [999999999, false],
]);
