<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Events;

use Gunner42\LaravelEetModule\Enums\PreflightWarning;
use Gunner42\LaravelEetModule\Models\Sale;

/**
 * The sale received new local warnings (R62).
 */
final class SalePreflightWarned
{
    /**
     * @param  list<PreflightWarning>  $warnings
     */
    public function __construct(public readonly Sale $sale, public readonly array $warnings) {}
}
