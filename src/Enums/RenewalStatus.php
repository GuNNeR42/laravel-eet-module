<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Enums;

use Gunner42\LaravelEetModule\Enums\Concerns\HasLabel;

/**
 * State of a certificate renewal (R92–R98).
 */
enum RenewalStatus: string
{
    use HasLabel;

    case Requesting = 'requesting';

    case InProcess = 'in_process';

    case Downloading = 'downloading';

    case Acknowledging = 'acknowledging';

    case Completed = 'completed';

    case Failed = 'failed';

    /**
     * The renewal continues on the next call (R92).
     */
    public function isActive(): bool
    {
        return in_array($this, [self::Requesting, self::InProcess, self::Downloading, self::Acknowledging], true);
    }

    /**
     * @return list<string>
     */
    public static function activeValues(): array
    {
        return array_values(array_map(
            static fn (self $status): string => $status->value,
            array_filter(self::cases(), static fn (self $status): bool => $status->isActive()),
        ));
    }

    protected static function translationGroup(): string
    {
        return 'renewal_statuses';
    }
}
