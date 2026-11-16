<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Models\Concerns;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Carbon;

/**
 * Stored date columns are read explicitly in the application timezone.
 * Older Carbon 3 releases (e.g. 3.8.4) otherwise interpret a value without
 * an offset in the timezone of a test "now" set by travelTo().
 */
trait ReadsDatesInApplicationTimezone
{
    protected function asDateTime(mixed $value): Carbon
    {
        if (is_string($value) && ! is_numeric($value)) {
            $date = DateTimeImmutable::createFromFormat('!'.$this->getDateFormat(), $value, new DateTimeZone(date_default_timezone_get()));

            if ($date !== false && $date->format($this->getDateFormat()) === $value) {
                return Carbon::instance($date);
            }
        }

        return parent::asDateTime($value);
    }
}
