<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Enums;

use Gunner42\LaravelEetModule\Enums\Concerns\HasLabel;

enum ErrorCode: int
{
    use HasLabel;

    case TemporaryTechnicalError = -1;

    case VerificationSucceeded = 0;

    case InvalidXmlEncoding = 2;

    case SchemaValidationFailed = 3;

    case InvalidSignature = 4;

    case InvalidEicStructure = 6;

    case MessageTooLarge = 7;

    case ProcessingFailed = 8;

    protected static function translationGroup(): string
    {
        return 'error_codes';
    }
}
