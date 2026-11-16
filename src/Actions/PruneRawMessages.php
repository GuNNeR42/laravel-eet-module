<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Actions;

use Gunner42\LaravelEetModule\Models\SubmissionAttempt;
use Illuminate\Database\Eloquent\Builder;

/**
 * Deletes the raw request and response XML of attempts older than N days;
 * sales, attempts, POK and metadata stay (R52).
 */
final class PruneRawMessages
{
    public function handle(int $days): int
    {
        return SubmissionAttempt::query()
            ->where('sent_at', '<', now()->subDays($days)->setTimezone(date_default_timezone_get()))
            ->where(function (Builder $query): void {
                $query->whereNotNull('request_xml')->orWhereNotNull('response_xml');
            })
            ->update(['request_xml' => null, 'response_xml' => null]);
    }
}
