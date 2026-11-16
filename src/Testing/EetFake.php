<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Testing;

use Gunner42\LaravelEetModule\Data\SaleMessage;
use Gunner42\LaravelEetModule\Data\TransportResult;
use Gunner42\LaravelEetModule\Enums\Environment;
use PHPUnit\Framework\Assert;

/**
 * Replaces sending in tests (R57): no network call, no certificate, scripted
 * responses consumed in order, then the default for the mode and environment.
 */
final class EetFake
{
    /** @var list<SaleMessage> */
    private array $sent = [];

    /**
     * @param  array<int, FakeResponse>  $responses
     */
    public function __construct(private array $responses = []) {}

    /**
     * @internal
     *
     * @return array{TransportResult, bool}
     */
    public function respond(SaleMessage $message, Environment $environment): array
    {
        $this->sent[] = $message;
        $response = array_shift($this->responses)
            ?? ($message->verificationMode ? FakeResponse::error(0) : FakeResponse::confirmed());

        return $response->toTransportResult($message, $environment);
    }

    /**
     * @param  (callable(SaleMessage): bool)|null  $callback
     */
    public function assertSent(?callable $callback = null): void
    {
        $matching = $callback === null ? $this->sent : array_filter($this->sent, $callback);

        Assert::assertNotEmpty($matching, 'The expected data message was not sent.');
    }

    public function assertSentCount(int $count): void
    {
        Assert::assertCount($count, $this->sent, 'An unexpected number of data messages was sent.');
    }

    public function assertNothingSent(): void
    {
        Assert::assertEmpty($this->sent, 'Data messages were sent unexpectedly.');
    }

    /**
     * @return list<SaleMessage>
     */
    public function sent(): array
    {
        return $this->sent;
    }
}
