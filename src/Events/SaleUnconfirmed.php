<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Events;

use Gunner42\LaravelEetModule\Enums\AttemptOutcome;
use Gunner42\LaravelEetModule\Models\Sale;

/**
 * The sale changed from Pending to Unconfirmed (R39).
 */
final class SaleUnconfirmed
{
    public function __construct(public readonly Sale $sale, public readonly AttemptOutcome $outcome) {}
}
