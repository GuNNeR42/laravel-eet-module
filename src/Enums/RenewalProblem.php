<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Enums;

use Gunner42\LaravelEetModule\Enums\Concerns\HasLabel;

/**
 * Why a certificate cannot be renewed (R90) or what went wrong in a renewal
 * (R101).
 */
enum RenewalProblem: string
{
    use HasLabel;

    case NotProductionCertificate = 'not_production_certificate';

    case CertificateNotValid = 'certificate_not_valid';

    case CertificateUnusable = 'certificate_unusable';

    case AlreadyRenewed = 'already_renewed';

    case TemporaryError = 'temporary_error';

    case UnknownPendingRequest = 'unknown_pending_request';

    case Rejected = 'rejected';

    case AlreadyFinished = 'already_finished';

    case RequestFailed = 'request_failed';

    case CertificateUnreadable = 'certificate_unreadable';

    case CertificateMismatch = 'certificate_mismatch';

    /**
     * The renewal keeps its state and the next run tries again (R101).
     */
    public function isTemporary(): bool
    {
        return $this === self::TemporaryError || $this === self::UnknownPendingRequest;
    }

    protected static function translationGroup(): string
    {
        return 'renewal_problems';
    }
}
