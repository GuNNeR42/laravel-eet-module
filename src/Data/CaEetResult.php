<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Data;

use Gunner42\LaravelEetModule\Enums\RenewalProblem;
use LogicException;

/**
 * Outcome of one CA EET API call (R100, R101): the decoded body of the
 * expected response, or a problem with a detail that holds only the HTTP
 * status and code/message of the ErrorDTO. Never serialized, because the
 * body of claim-download is sensitive (R103).
 *
 * @internal
 */
final readonly class CaEetResult
{
    /**
     * @param  array<array-key, mixed>  $data
     */
    private function __construct(
        public array $data,
        public ?int $status,
        public ?RenewalProblem $problem,
        public ?string $detail,
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function success(int $status, array $data): self
    {
        return new self($data, $status, null, null);
    }

    public static function problem(RenewalProblem $problem, string $detail, ?int $status = null): self
    {
        return new self([], $status, $problem, mb_substr($detail, 0, 255));
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new LogicException('A CA EET API result cannot be serialized.');
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['status' => $this->status, 'problem' => $this->problem, 'detail' => $this->detail];
    }
}
