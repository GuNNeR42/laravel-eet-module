<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Enums;

use Gunner42\LaravelEetModule\Enums\Concerns\HasLabel;

enum AttemptOutcome: string
{
    use HasLabel;

    case Confirmed = 'confirmed';

    case Verified = 'verified';

    case Rejected = 'rejected';

    case TemporaryError = 'temporary_error';

    case AmbiguousError = 'ambiguous_error';

    case Timeout = 'timeout';

    case TransportError = 'transport_error';

    case InvalidResponseSignature = 'invalid_response_signature';

    case ResponseMismatch = 'response_mismatch';

    case CertificateUnavailable = 'certificate_unavailable';

    protected static function translationGroup(): string
    {
        return 'attempt_outcomes';
    }
}
