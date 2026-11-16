<?php

declare(strict_types=1);

use Gunner42\LaravelEetModule\Exceptions\EetException;
use Gunner42\LaravelEetModule\Exceptions\InvalidAmount;
use Gunner42\LaravelEetModule\ValueObjects\Amount;

it('accepts the amounts from the MF examples and formats them with two decimals [R56]', function (string $input, int $halere, string $eet): void {
    $amount = Amount::fromString($input);

    expect($amount->halere())->toBe($halere)
        ->and($amount->toEetString())->toBe($eet);
})->with([
    ['20.45', 2045, '20.45'],
    ['-187.20', -18720, '-187.20'],
    ['0.56', 56, '0.56'],
    ['0', 0, '0.00'],
    ['3264.5', 326450, '3264.50'],
    ['99999999.99', 9999999999, '99999999.99'],
    ['-99999999.99', -9999999999, '-99999999.99'],
    ['-0.01', -1, '-0.01'],
]);

it('rejects strings outside the amount mask [R56]', function (string $input): void {
    expect(fn (): Amount => Amount::fromString($input))->toThrow(InvalidAmount::class);
})->with([
    'leading zero' => '020.45',
    'negative zero with leading zero' => '-00.00',
    'missing integer part' => '.20',
    'one hundred million' => '100000000.00',
    'decimal comma' => '81,60',
    'leading space' => ' 81.60',
    'trailing space' => '81.60 ',
    'three decimals' => '1.234',
    'plus sign' => '+1.00',
    'empty' => '',
    'exponent' => '1e3',
    'thousands separator' => '1 000.00',
]);

it('never formats negative zero [R56]', function (): void {
    $amount = Amount::fromString('-0.00');

    expect($amount->toEetString())->toBe('0.00')
        ->and($amount->halere())->toBe(0)
        ->and($amount->isZero())->toBeTrue()
        ->and($amount->isNegative())->toBeFalse();
});

it('creates amounts from halere below one hundred million crowns [R56]', function (): void {
    expect(Amount::fromHalere(9999999999)->toEetString())->toBe('99999999.99')
        ->and(Amount::fromHalere(-9999999999)->toEetString())->toBe('-99999999.99')
        ->and(Amount::fromHalere(-18720)->toEetString())->toBe('-187.20')
        ->and(Amount::fromHalere(0)->toEetString())->toBe('0.00');
});

it('rejects halere with an absolute value of one hundred million crowns or more [R56]', function (int $halere): void {
    expect(fn (): Amount => Amount::fromHalere($halere))->toThrow(InvalidAmount::class);
})->with([10000000000, -10000000000, PHP_INT_MAX]);

it('offers negate, sign checks and equality [R56]', function (): void {
    $amount = Amount::fromString('5450.00');

    expect($amount->negate()->toEetString())->toBe('-5450.00')
        ->and($amount->isPositive())->toBeTrue()
        ->and($amount->isNegative())->toBeFalse()
        ->and($amount->negate()->isNegative())->toBeTrue()
        ->and($amount->equals(Amount::fromHalere(545000)))->toBeTrue()
        ->and($amount->equals($amount->negate()))->toBeFalse()
        ->and(Amount::fromHalere(0)->negate()->toEetString())->toBe('0.00');
});

it('is immutable [R56]', function (): void {
    $amount = Amount::fromString('10.00');
    $amount->negate();

    $reflection = new ReflectionClass(Amount::class);

    expect($amount->toEetString())->toBe('10.00')
        ->and($reflection->isFinal())->toBeTrue()
        ->and($reflection->isReadOnly() || collect($reflection->getProperties())->every(fn (ReflectionProperty $property): bool => $property->isReadOnly()))->toBeTrue();
});

it('never accepts a float [R56]', function (): void {
    $types = collect((new ReflectionClass(Amount::class))->getMethods(ReflectionMethod::IS_PUBLIC))
        ->flatMap(fn (ReflectionMethod $method): array => $method->getParameters())
        ->map(fn (ReflectionParameter $parameter): string => (string) $parameter->getType());

    expect($types->filter(fn (string $type): bool => str_contains($type, 'float'))->all())->toBe([]);
});

it('throws a package exception for invalid amounts [R56]', function (): void {
    expect(fn (): Amount => Amount::fromString('abc'))->toThrow(EetException::class);
});
