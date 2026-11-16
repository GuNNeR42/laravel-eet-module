<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Enums;

use Gunner42\LaravelEetModule\Enums\Concerns\HasLabel;

enum CertificateProblem: string
{
    use HasLabel;

    case Unreadable = 'unreadable';

    case MissingPrivateKey = 'missing_private_key';

    case KeyMismatch = 'key_mismatch';

    case Expired = 'expired';

    case UnsupportedLegacyFormat = 'unsupported_legacy_format';

    protected static function translationGroup(): string
    {
        return 'certificate_problems';
    }
}
