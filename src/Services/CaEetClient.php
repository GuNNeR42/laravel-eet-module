<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Services;

use Carbon\CarbonImmutable;
use Gunner42\LaravelEetModule\Data\CaEetResult;
use Gunner42\LaravelEetModule\Data\TransportResult;
use Gunner42\LaravelEetModule\Enums\RenewalProblem;
use Gunner42\LaravelEetModule\Models\CashRegisterCertificate;
use LogicException;
use OpenSSLAsymmetricKey;
use SensitiveParameter;

/**
 * Calls the CA EET API (R99–R101): every call carries a fresh RS256 JWT
 * signed by the key of the renewed certificate and goes through the
 * hardened transport (R66). The result is classified into success,
 * temporary and final problems; exception texts are never kept.
 */
final class CaEetClient
{
    /** HTTP statuses that are final problems (R101). */
    private const array FINAL_STATUSES = [400, 401, 404, 409];

    public function __construct(private readonly Transport $transport) {}

    /**
     * @param  int  $expectedStatus  the documented success status; 204 has no body
     */
    public function call(CashRegisterCertificate $certificate, #[SensitiveParameter] OpenSSLAsymmetricKey $key, string $method, string $path, int $expectedStatus): CaEetResult
    {
        $url = rtrim(ConfigurationValidator::string('renewal.endpoint'), '/').$path;
        $result = $this->transport->requestJson(
            $method,
            $url,
            $this->token($certificate, $key),
            ConfigurationValidator::integer('renewal.timeout_ms'),
        );

        return $this->classify($result, $expectedStatus);
    }

    private function classify(#[SensitiveParameter] TransportResult $result, int $expectedStatus): CaEetResult
    {
        if ($result->timedOut) {
            return CaEetResult::problem(RenewalProblem::TemporaryError, 'timeout');
        }

        if ($result->failure !== null || $result->status === null) {
            return CaEetResult::problem(RenewalProblem::TemporaryError, $result->failure ?? 'connection', $result->status);
        }

        $status = $result->status;

        if (strlen((string) $result->body) > $this->transport->maxBytes()) {
            return CaEetResult::problem(RenewalProblem::TemporaryError, 'too_large', $status);
        }

        if ($status === $expectedStatus) {
            if ($expectedStatus === 204) {
                return CaEetResult::success($status, []);
            }

            $data = $this->decode($result->body);

            return $data === null
                ? CaEetResult::problem(RenewalProblem::TemporaryError, "HTTP {$status}: invalid response", $status)
                : CaEetResult::success($status, $data);
        }

        $problem = in_array($status, self::FINAL_STATUSES, true) ? RenewalProblem::RequestFailed : RenewalProblem::TemporaryError;

        return CaEetResult::problem($problem, $this->errorDetail($status, $result->body), $status);
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private function decode(#[SensitiveParameter] ?string $body): ?array
    {
        if ($body === null || $body === '') {
            return null;
        }

        $data = json_decode($body, true, 16);

        return is_array($data) ? $data : null;
    }

    /**
     * Only the HTTP status and code/message of the ErrorDTO (R101).
     */
    private function errorDetail(int $status, #[SensitiveParameter] ?string $body): string
    {
        $detail = "HTTP {$status}";
        $error = $this->decode($body);

        if ($error !== null && is_int($error['code'] ?? null)) {
            $detail .= ' code '.$error['code'];
        }

        if ($error !== null && is_string($error['message'] ?? null)) {
            $detail .= ': '.$error['message'];
        }

        return $detail;
    }

    /**
     * JWS Compact with exactly {"alg":"RS256","typ":"JWT","x5t#S256":…} and
     * {"iat":T,"exp":T+60} (R99, Q63).
     */
    private function token(CashRegisterCertificate $certificate, #[SensitiveParameter] OpenSSLAsymmetricKey $key): string
    {
        $issuedAt = CarbonImmutable::now()->getTimestamp();
        $header = json_encode(
            ['alg' => 'RS256', 'typ' => 'JWT', 'x5t#S256' => self::base64Url((string) hex2bin($certificate->fingerprint_sha256))],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );
        $payload = json_encode(['iat' => $issuedAt, 'exp' => $issuedAt + 60], JSON_THROW_ON_ERROR);
        $signingInput = self::base64Url($header).'.'.self::base64Url($payload);

        $signed = openssl_sign($signingInput, $signature, $key, OPENSSL_ALGO_SHA256);
        X509::clearErrors();

        if (! $signed || ! is_string($signature)) {
            throw new LogicException('The CA EET API token cannot be signed.');
        }

        return $signingInput.'.'.self::base64Url($signature);
    }

    private static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
