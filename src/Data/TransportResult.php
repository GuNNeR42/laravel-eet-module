<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Data;

/**
 * What the transport got back: an HTTP response, a timeout or a failure
 * with a category (R66).
 *
 * @internal
 */
final readonly class TransportResult
{
    /**
     * @param  array<string, string>  $headers  lower-case names
     */
    private function __construct(
        public ?int $status,
        public array $headers,
        public ?string $body,
        public bool $timedOut,
        public ?string $failure,
    ) {}

    /**
     * @param  array<string, string>  $headers
     */
    public static function response(int $status, array $headers, string $body): self
    {
        return new self($status, array_change_key_case($headers, CASE_LOWER), $body, false, null);
    }

    public static function timeout(): self
    {
        return new self(null, [], null, true, null);
    }

    public static function failure(string $category, ?int $status = null): self
    {
        return new self($status, [], null, false, $category);
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
