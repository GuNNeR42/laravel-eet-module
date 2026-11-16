<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Testing;

use Carbon\CarbonImmutable;
use Gunner42\LaravelEetModule\Data\SaleMessage;
use Gunner42\LaravelEetModule\Data\TransportResult;
use Gunner42\LaravelEetModule\Data\Warning;
use Gunner42\LaravelEetModule\Enums\Environment;
use Gunner42\LaravelEetModule\Enums\ErrorCode;
use Gunner42\LaravelEetModule\Services\MessageBuilder;
use Gunner42\LaravelEetModule\Services\MessageUuid;

/**
 * A scripted response of the EET service for Eet::fake() (R57).
 */
final class FakeResponse
{
    public const string VERIFICATION_TEXT = 'Datovou zpravu evidovane trzby v overovacim modu se podarilo zpracovat';

    /**
     * @param  array<int, Warning>  $warnings
     * @param  array<string, string>  $headers
     */
    private function __construct(
        private readonly string $kind,
        private readonly ?string $confirmationCode = null,
        private readonly ?int $errorCode = null,
        private readonly ?string $message = null,
        private readonly array $warnings = [],
        private readonly ?bool $test = null,
        private readonly string $xml = '',
        private readonly int $status = 200,
        private readonly array $headers = [],
    ) {}

    /**
     * Potvrzeni; without a code the POK follows the environment of the sale.
     *
     * @param  array<int, Warning>  $warnings
     */
    public static function confirmed(?string $confirmationCode = null, array $warnings = [], ?bool $test = null): self
    {
        return new self('confirmed', confirmationCode: $confirmationCode, warnings: $warnings, test: $test);
    }

    /**
     * @param  array<int, Warning>  $warnings
     */
    public static function error(int|ErrorCode $code, ?string $message = null, array $warnings = [], ?bool $test = null): self
    {
        return new self('error', errorCode: $code instanceof ErrorCode ? $code->value : $code, message: $message, warnings: $warnings, test: $test);
    }

    public static function timeout(): self
    {
        return new self('timeout');
    }

    public static function transportError(): self
    {
        return new self('transport_error');
    }

    /**
     * A raw HTTP response; processed in full, including the signature check.
     *
     * @param  array<string, string>  $headers
     */
    public static function raw(string $xml, int $status = 200, array $headers = []): self
    {
        return new self('raw', xml: $xml, status: $status, headers: $headers);
    }

    /**
     * The transport result for the sent message and whether its signature is
     * verified (only raw responses).
     *
     * @internal
     *
     * @return array{TransportResult, bool}
     */
    public function toTransportResult(SaleMessage $message, Environment $environment): array
    {
        return match ($this->kind) {
            'timeout' => [TransportResult::timeout(), false],
            'transport_error' => [TransportResult::failure('connection'), false],
            'raw' => [TransportResult::response($this->status, $this->headers, $this->xml), true],
            default => [TransportResult::response(200, ['Content-Type' => 'text/xml; charset=UTF-8'], $this->odpoved($message, $environment)), false],
        };
    }

    private function odpoved(SaleMessage $message, Environment $environment): string
    {
        $now = MessageBuilder::formatDateTime(CarbonImmutable::now());
        $test = match ($this->test) {
            null => $environment === Environment::Playground ? 'true' : null,
            true => 'true',
            false => 'false',
        };

        if ($this->kind === 'confirmed') {
            $code = $this->confirmationCode ?? MessageUuid::random().($environment === Environment::Playground ? '-ff' : '-01');
            $header = '<eet:Hlavicka'.self::attribute('uuid_zpravy', $message->messageUuid).self::attribute('dat_prij', $now).'/>';
            $result = '<eet:Potvrzeni'.self::attribute('pok', $code).self::attribute('test', $test).'/>';
        } else {
            $header = '<eet:Hlavicka'.self::attribute('uuid_zpravy', $message->messageUuid).self::attribute('dat_odmit', $now).'/>';
            $text = $this->message ?? ($this->errorCode === 0 ? self::VERIFICATION_TEXT : '');
            $result = '<eet:Chyba'.self::attribute('kod', (string) $this->errorCode).self::attribute('test', $test).'>'
                .htmlspecialchars($text, ENT_XML1, 'UTF-8').'</eet:Chyba>';
        }

        $warnings = '';

        foreach ($this->warnings as $warning) {
            $warnings .= '<eet:Varovani'.self::attribute('kod_varov', (string) $warning->code).'>'
                .htmlspecialchars($warning->message, ENT_XML1, 'UTF-8').'</eet:Varovani>';
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<soapenv:Envelope xmlns:soapenv="'.MessageBuilder::NS_SOAP.'" xmlns:eet="'.MessageBuilder::NS_EET.'">'
            .'<soapenv:Header/><soapenv:Body><eet:Odpoved>'.$header.$result.$warnings.'</eet:Odpoved></soapenv:Body>'
            .'</soapenv:Envelope>';
    }

    private static function attribute(string $name, ?string $value): string
    {
        return $value === null ? '' : ' '.$name.'="'.htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8').'"';
    }
}
