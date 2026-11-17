<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Gunner42\LaravelEetModule\Enums\RenewalStatus;
use Gunner42\LaravelEetModule\Facades\Eet;
use Gunner42\LaravelEetModule\Tests\Support\CaEetApi;
use Gunner42\LaravelEetModule\Tests\Support\Fixtures;
use Gunner42\LaravelEetModule\Tests\Support\Renewal;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Sleep;

/*
 * R99: every API call carries a fresh JWS Compact token (RS256) signed by the
 * private key of the renewed certificate. The tokens are taken from the
 * requests recorded by the HTTP fake.
 */

beforeEach(function (): void {
    $this->travelTo(Renewal::now());
    Sleep::fake(syncWithCarbon: true);
});

/**
 * @return array{header: string, payload: string, signature: string, signingInput: string}
 */
function jwtParts(Request $request): array
{
    $token = CaEetApi::bearerToken($request);

    expect($token)->not->toBeNull();

    $segments = explode('.', (string) $token);

    expect($segments)->toHaveCount(3);

    foreach ($segments as $segment) {
        // base64url without padding
        expect(preg_match('/^[A-Za-z0-9_-]+$/', $segment))->toBe(1);
    }

    return [
        'header' => CaEetApi::base64UrlDecode($segments[0]),
        'payload' => CaEetApi::base64UrlDecode($segments[1]),
        'signature' => CaEetApi::base64UrlDecode($segments[2]),
        'signingInput' => $segments[0].'.'.$segments[1],
    ];
}

function renewedCertificatePem(string $fixture = CaEetApi::RENEWED): string
{
    return Fixtures::certificate($fixture);
}

it('sends a JWS Compact bearer token with exactly the RS256 header and the iat/exp payload on every call [R99]', function (): void {
    $api = CaEetApi::fake(['status' => [CaEetApi::inProcess(3), CaEetApi::issued()]]);

    Eet::renewCertificate(Renewal::certificate());

    $x5t = CaEetApi::x5tS256(renewedCertificatePem());

    expect($api->calls())->toBe(['not-finished', 'renew', 'status', 'status', 'claim-download', 'ack-download']);

    foreach ($api->log() as $entry) {
        $parts = jwtParts($entry['request']);
        $iat = $entry['now']->getTimestamp();

        expect($parts['header'])->toBe('{"alg":"RS256","typ":"JWT","x5t#S256":"'.$x5t.'"}')
            ->and($parts['payload'])->toBe('{"iat":'.$iat.',"exp":'.($iat + 60).'}');
    }
});

it('puts the current time into iat and iat + 60 into exp [R99]', function (): void {
    $api = CaEetApi::fake(['status' => [CaEetApi::inProcess(7), CaEetApi::issued()]]);

    Eet::renewCertificate(Renewal::certificate());

    $payloads = array_map(
        fn (array $entry): array => (array) json_decode(jwtParts($entry['request'])['payload'], true, flags: JSON_THROW_ON_ERROR),
        $api->log(),
    );

    expect($payloads[0]['iat'])->toBe(Renewal::now()->getTimestamp())
        ->and($payloads[array_key_last($payloads)]['iat'])->toBe(Renewal::now()->addSeconds(7)->getTimestamp());

    foreach ($payloads as $payload) {
        expect(array_keys($payload))->toBe(['iat', 'exp'])
            ->and($payload['exp'] - $payload['iat'])->toBe(60);
    }
});

it('signs with RSA-SHA256 by the private key of the renewed certificate, verifiable with its public key [R99]', function (): void {
    $api = CaEetApi::fake();
    $certificate = Renewal::certificate();

    Eet::renewCertificate($certificate);

    $publicKey = openssl_pkey_get_public(renewedCertificatePem());
    $otherKey = openssl_pkey_get_public(CaEetApi::certificateOf(CaEetApi::SUCCESSOR));

    expect($publicKey)->not->toBeFalse()
        ->and($otherKey)->not->toBeFalse();

    foreach ($api->log() as $entry) {
        $parts = jwtParts($entry['request']);

        expect(openssl_verify($parts['signingInput'], $parts['signature'], $publicKey, OPENSSL_ALGO_SHA256))->toBe(1)
            ->and(openssl_verify($parts['signingInput'], $parts['signature'], $otherKey, OPENSSL_ALGO_SHA256))->toBe(0);
    }
});

it('identifies the renewed certificate by x5t#S256 and never sends x5c [R99]', function (): void {
    $api = CaEetApi::fake();
    $certificate = Renewal::certificate();

    Eet::renewCertificate($certificate);

    foreach ($api->log() as $entry) {
        $header = (array) json_decode(jwtParts($entry['request'])['header'], true, flags: JSON_THROW_ON_ERROR);

        expect($header['x5t#S256'] ?? null)->toBe(CaEetApi::base64UrlEncode((string) hex2bin(strtolower($certificate->fingerprint_sha256))))
            ->and($header)->not->toHaveKey('x5c');
    }
});

it('signs the acknowledgement with the renewed certificate even though the successor is already stored [R99]', function (): void {
    $api = CaEetApi::fake();

    Eet::renewCertificate(Renewal::certificate());

    $ack = array_values(array_filter($api->log(), fn (array $entry): bool => $entry['route'] === 'ack-download'));
    $header = (array) json_decode(jwtParts($ack[0]['request'])['header'], true, flags: JSON_THROW_ON_ERROR);

    expect($header['x5t#S256'] ?? null)->toBe(CaEetApi::x5tS256(renewedCertificatePem()))
        ->and($header['x5t#S256'] ?? null)->not->toBe(CaEetApi::x5tS256(CaEetApi::certificateOf(CaEetApi::SUCCESSOR)));
});

it('signs with the certificate being renewed, not with another certificate of the taxpayer [R99]', function (): void {
    $api = CaEetApi::fake(['status' => CaEetApi::inProcess(1)]);
    config()->set('laravel-eet-module.renewal.max_wait_seconds', 0);
    Renewal::certificate('renewal/CZ00000019-until-2027-06-30.pem');
    $renewed = Renewal::certificate('renewal/CZ00000019-until-2026-12-05.pem');

    expect(Eet::renewCertificate($renewed)?->status)->toBe(RenewalStatus::InProcess);

    foreach ($api->log() as $entry) {
        $parts = jwtParts($entry['request']);
        $header = (array) json_decode($parts['header'], true, flags: JSON_THROW_ON_ERROR);
        $publicKey = openssl_pkey_get_public(renewedCertificatePem('renewal/CZ00000019-until-2026-12-05.pem'));

        expect($header['x5t#S256'] ?? null)->toBe(CaEetApi::x5tS256(renewedCertificatePem('renewal/CZ00000019-until-2026-12-05.pem')))
            ->and($publicKey)->not->toBeFalse()
            ->and(openssl_verify($parts['signingInput'], $parts['signature'], $publicKey, OPENSSL_ALGO_SHA256))->toBe(1);
    }
});

it('creates a new token for every call: equal only at the same moment, different after time moved [R99]', function (): void {
    $api = CaEetApi::fake(['status' => [CaEetApi::inProcess(5), CaEetApi::inProcess(5), CaEetApi::issued()]]);

    Eet::renewCertificate(Renewal::certificate());

    $tokens = [];

    foreach ($api->log() as $entry) {
        $tokens[] = ['token' => (string) CaEetApi::bearerToken($entry['request']), 'now' => $entry['now']->getTimestamp()];
    }

    expect(count(array_unique(array_column($tokens, 'now'))))->toBeGreaterThan(1);

    foreach ($tokens as $a) {
        foreach ($tokens as $b) {
            if ($a['now'] !== $b['now']) {
                expect($a['token'])->not->toBe($b['token']);
            }
        }
    }
});

it('does not reuse a token from an earlier call after time moved [R99]', function (): void {
    config()->set('laravel-eet-module.renewal.max_wait_seconds', 0);
    $api = CaEetApi::fake(['status' => [CaEetApi::inProcess(30), CaEetApi::issued()]]);
    $certificate = Renewal::certificate();
    Eet::renewCertificate($certificate);
    $first = (string) CaEetApi::bearerToken($api->log()[0]['request']);
    $api->forget();
    $this->travelTo(CarbonImmutable::parse('2026-11-19 06:00:00', 'UTC'));

    Eet::renewCertificate($certificate);

    foreach ($api->log() as $entry) {
        $payload = (array) json_decode(jwtParts($entry['request'])['payload'], true, flags: JSON_THROW_ON_ERROR);

        expect(CaEetApi::bearerToken($entry['request']))->not->toBe($first)
            ->and($payload['iat'])->toBe(CarbonImmutable::parse('2026-11-19 06:00:00', 'UTC')->getTimestamp());
    }
});
