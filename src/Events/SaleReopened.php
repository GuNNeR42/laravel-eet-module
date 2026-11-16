<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Events;

use Gunner42\LaravelEetModule\Models\Sale;

/**
 * A rejected sale was reopened (R64).
 */
final class SaleReopened
{
    public function __construct(public readonly Sale $sale) {}
}
