<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Exceptions;

use Gunner42\LaravelEetModule\Enums\SaleDataProblem;

final class InvalidSaleData extends EetException
{
    public function __construct(public readonly string $field, public readonly SaleDataProblem $problem)
    {
        parent::__construct("Invalid sale data [{$field}]: {$problem->value}.");
    }
}
