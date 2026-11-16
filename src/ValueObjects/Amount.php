<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\ValueObjects;

use Gunner42\LaravelEetModule\Exceptions\InvalidAmount;
use Illuminate\Contracts\Database\Eloquent\Castable;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Amount in halere (R56). Never accepts a float.
 */
final readonly class Amount implements Castable
{
    private const int LIMIT = 10_000_000_000;

    private function __construct(private int $halere) {}

    public static function fromHalere(int $halere): self
    {
        if ($halere >= self::LIMIT || $halere <= -self::LIMIT) {
            throw new InvalidAmount;
        }

        return new self($halere);
    }

    public static function fromString(string $value): self
    {
        if (preg_match('/^(-?)(0|[1-9][0-9]{0,7})(?:\.([0-9]{1,2}))?$/D', $value, $match) !== 1) {
            throw new InvalidAmount;
        }

        $fraction = str_pad($match[3] ?? '', 2, '0');
        $halere = (int) $match[2] * 100 + (int) $fraction;

        return self::fromHalere($match[1] === '-' ? -$halere : $halere);
    }

    public function halere(): int
    {
        return $this->halere;
    }

    public function toEetString(): string
    {
        $absolute = abs($this->halere);

        return ($this->halere < 0 ? '-' : '').intdiv($absolute, 100).'.'.str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
    }

    public function negate(): self
    {
        return new self(-$this->halere);
    }

    public function isPositive(): bool
    {
        return $this->halere > 0;
    }

    public function isNegative(): bool
    {
        return $this->halere < 0;
    }

    public function isZero(): bool
    {
        return $this->halere === 0;
    }

    public function equals(self $other): bool
    {
        return $this->halere === $other->halere;
    }

    /**
     * @param  array<int, mixed>  $arguments
     * @return CastsAttributes<self, self>
     */
    public static function castUsing(array $arguments): CastsAttributes
    {
        return new class implements CastsAttributes
        {
            /**
             * @param  array<string, mixed>  $attributes
             */
            public function get(Model $model, string $key, mixed $value, array $attributes): ?Amount
            {
                return $value === null ? null : Amount::fromHalere((int) $value);
            }

            /**
             * @param  array<string, mixed>  $attributes
             */
            public function set(Model $model, string $key, mixed $value, array $attributes): ?int
            {
                if ($value === null) {
                    return null;
                }

                if (! $value instanceof Amount) {
                    throw new InvalidAmount;
                }

                return $value->halere();
            }
        };
    }
}
