<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Data;

use Gunner42\LaravelEetModule\Enums\WarningCode;

/**
 * Warning of the EET service (Varovani, R37). Unknown codes keep type null.
 */
final readonly class Warning
{
    public function __construct(
        public int $code,
        public ?WarningCode $type,
        public string $message,
    ) {}

    public static function fromCode(int $code, string $message): self
    {
        return new self($code, WarningCode::tryFrom($code), $message);
    }

    /**
     * @return array{code: int, message: string}
     */
    public function toArray(): array
    {
        return ['code' => $this->code, 'message' => $this->message];
    }
}
