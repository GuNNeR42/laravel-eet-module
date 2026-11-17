<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Services;

use DateTimeImmutable;
use DateTimeZone;
use Gunner42\LaravelEetModule\Enums\Environment;
use Gunner42\LaravelEetModule\Enums\SendingMode;
use Gunner42\LaravelEetModule\Exceptions\InvalidConfiguration;

/**
 * Validates the package configuration before an operation writes or sends
 * anything (R68). Each operation validates only the keys it uses.
 */
final class ConfigurationValidator
{
    public const string SEQUENCE_NUMBER_MASK = '/^[0-9a-zA-Z.,:;\/#\-_ ]{1,25}$/D';

    private const array LOCK_DRIVERS = ['redis', 'database', 'memcached', 'dynamodb', 'file'];

    private const array FORBIDDEN_QUEUE_DRIVERS = ['sync', 'deferred', 'background', 'null'];

    public function __construct(private readonly TrustStore $trustStore) {}

    /**
     * createTaxpayer(), changeEnvironment() and eet:certificate:import.
     */
    public function forTaxpayer(): void
    {
        $this->environment();
    }

    /**
     * Every operation that sends (all keys except retention.*).
     */
    public function forSending(): void
    {
        $this->environment();
        $this->mode();

        foreach (Environment::cases() as $environment) {
            $this->allowedHosts($environment);
        }

        foreach (Environment::cases() as $environment) {
            $this->endpoint($environment);
        }

        $this->integerAtLeast('response_max_bytes', 1024);
        $this->proxy();
        $this->integerAtLeast('response_timeout_ms', 2000);
        $this->timezone();
        $this->minimumOccurredAt(Environment::Playground);
        $this->minimumOccurredAt(Environment::Production);
        $this->responseSigner();
        $this->integerAtLeast('response_clock_tolerance_seconds', 1);
        $this->trustStore();
        $this->lockStore();
        $this->queueConnection();
        $this->queueName();
        $this->integerAtLeast('resubmission.min_backoff', 1);
        $this->backoff();
        $this->integerAtLeast('resubmission.max_ambiguous_attempts', 1);
        $this->verificationSequenceNumber();
    }

    /**
     * renewCertificate() and eet:certificate:renew (R91, R100, R104).
     */
    public function forRenewal(): void
    {
        $this->renewalAllowedHosts();
        $this->renewalEndpoint();
        $this->renewalDaysBeforeExpiry();
        $this->integerAtLeast('renewal.max_wait_seconds', 0);
        $this->integerAtLeast('renewal.timeout_ms', 1000);
        $this->integerAtLeast('response_max_bytes', 1024);
        $this->proxy();
        $this->lockStore();
    }

    /**
     * eet:prune.
     */
    public function forPrune(): void
    {
        $days = $this->value('retention.raw_xml_days');

        if ($days !== null && (! is_int($days) || $days < 1)) {
            throw new InvalidConfiguration('retention.raw_xml_days');
        }
    }

    /**
     * eet:status.
     */
    public function forStatus(): void
    {
        $this->mode();
        $this->integerAtLeast('response_timeout_ms', 2000);
        $this->responseSigner();
        $this->trustStore();
        $this->renewalDaysBeforeExpiry();
    }

    /**
     * A validated integer value; an invalid value is InvalidConfiguration,
     * never a default (R68).
     */
    public static function integer(string $key): int
    {
        $value = config('laravel-eet-module.'.$key);

        if (! is_int($value)) {
            throw new InvalidConfiguration($key);
        }

        return $value;
    }

    /**
     * A validated string value; an invalid value is InvalidConfiguration (R68).
     */
    public static function string(string $key): string
    {
        $value = config('laravel-eet-module.'.$key);

        if (! is_string($value) || $value === '') {
            throw new InvalidConfiguration($key);
        }

        return $value;
    }

    private function environment(): void
    {
        if (! is_string($this->value('default_environment')) || Environment::tryFrom($this->value('default_environment')) === null) {
            throw new InvalidConfiguration('default_environment');
        }
    }

    private function mode(): void
    {
        if (! is_string($this->value('mode')) || SendingMode::tryFrom($this->value('mode')) === null) {
            throw new InvalidConfiguration('mode');
        }
    }

    private function allowedHosts(Environment $environment): void
    {
        $key = 'endpoints.allowed_hosts.'.$environment->value;
        $hosts = $this->value($key);

        if (! is_array($hosts) || $hosts === [] || ! array_is_list($hosts)) {
            throw new InvalidConfiguration($key);
        }

        foreach ($hosts as $host) {
            if (! is_string($host) || ! self::isDnsName(self::normalizeHost($host))) {
                throw new InvalidConfiguration($key);
            }
        }
    }

    private function endpoint(Environment $environment): void
    {
        $key = 'endpoints.'.$environment->value;
        $endpoint = $this->value($key);

        if (! is_string($endpoint) || ! self::isAllowedEndpoint($endpoint, $this->allowedHostList($environment))) {
            throw new InvalidConfiguration($key);
        }
    }

    /**
     * @return list<string>
     */
    private function allowedHostList(Environment $environment): array
    {
        /** @var list<string> $hosts */
        $hosts = $this->value('endpoints.allowed_hosts.'.$environment->value);

        return array_map(static fn (string $host): string => self::normalizeHost($host), $hosts);
    }

    /**
     * @param  list<string>  $allowedHosts
     */
    public static function isAllowedEndpoint(string $endpoint, array $allowedHosts): bool
    {
        $parts = parse_url($endpoint);

        // tests may run a local EET service on the IPv4 loopback (R66)
        if (is_array($parts) && app()->runningUnitTests()
            && strtolower($parts['scheme'] ?? '') === 'http'
            && ($parts['host'] ?? null) === '127.0.0.1'
            && ! isset($parts['user']) && ! isset($parts['pass'])) {
            return true;
        }

        if (! is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https' || ! isset($parts['host'])) {
            return false;
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        if (isset($parts['port']) && $parts['port'] !== 443) {
            return false;
        }

        $host = self::normalizeHost($parts['host']);

        return self::isDnsName($host) && in_array($host, $allowedHosts, true);
    }

    private static function normalizeHost(string $host): string
    {
        return strtolower(rtrim($host, '.'));
    }

    /**
     * A DNS name, never an IP address in any notation (R66).
     */
    private static function isDnsName(string $host): bool
    {
        if ($host === '' || strlen($host) > 253 || filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false) {
            return false;
        }

        // inet_aton notations: decimal, octal, hexadecimal and shortened forms
        if (preg_match('/^(0x[0-9a-f]*|[0-9]+)(\.(0x[0-9a-f]*|[0-9]+)){0,3}$/iD', $host) === 1) {
            return false;
        }

        if (preg_match('/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/D', $host) !== 1) {
            return false;
        }

        $labels = explode('.', $host);

        return preg_match('/[a-z]/', (string) end($labels)) === 1;
    }

    private function renewalAllowedHosts(): void
    {
        $hosts = $this->value('renewal.allowed_hosts');

        if (! is_array($hosts) || $hosts === [] || ! array_is_list($hosts)) {
            throw new InvalidConfiguration('renewal.allowed_hosts');
        }

        foreach ($hosts as $host) {
            if (! is_string($host) || ! self::isDnsName(self::normalizeHost($host))) {
                throw new InvalidConfiguration('renewal.allowed_hosts');
            }
        }
    }

    private function renewalEndpoint(): void
    {
        $endpoint = $this->value('renewal.endpoint');
        /** @var list<string> $hosts */
        $hosts = $this->value('renewal.allowed_hosts');
        $hosts = array_map(static fn (string $host): string => self::normalizeHost($host), $hosts);

        if (! is_string($endpoint) || ! self::isAllowedEndpoint($endpoint, $hosts)) {
            throw new InvalidConfiguration('renewal.endpoint');
        }
    }

    private function renewalDaysBeforeExpiry(): void
    {
        $days = $this->value('renewal.days_before_expiry');

        if (! is_int($days) || $days < 1 || $days > 365) {
            throw new InvalidConfiguration('renewal.days_before_expiry');
        }
    }

    private function proxy(): void
    {
        $proxy = $this->value('proxy');

        if ($proxy === null) {
            return;
        }

        $parts = is_string($proxy) ? parse_url($proxy) : false;

        if (! is_array($parts) || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || ($parts['host'] ?? '') === '') {
            throw new InvalidConfiguration('proxy');
        }
    }

    private function timezone(): void
    {
        $timezone = $this->value('timezone');

        if (! is_string($timezone) || ! in_array($timezone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
            throw new InvalidConfiguration('timezone');
        }
    }

    private function minimumOccurredAt(Environment $environment): void
    {
        $key = 'minimum_occurred_at.'.$environment->value;
        $value = $this->value($key);

        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/D', $value) !== 1) {
            throw new InvalidConfiguration($key);
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $value);

        if ($parsed === false || $parsed->format('Y-m-d H:i') !== $value) {
            throw new InvalidConfiguration($key);
        }
    }

    private function responseSigner(): void
    {
        $organizationIdentifier = $this->value('response_signer.organization_identifier');

        if (! is_string($organizationIdentifier) || preg_match('/^NTRCZ-\d{8}$/D', $organizationIdentifier) !== 1) {
            throw new InvalidConfiguration('response_signer.organization_identifier');
        }

        $playground = $this->value('response_signer.playground.common_name');

        if (! is_string($playground) || $playground === '') {
            throw new InvalidConfiguration('response_signer.playground.common_name');
        }

        $production = $this->value('response_signer.production.common_name');

        if ($production !== null && (! is_string($production) || $production === '')) {
            throw new InvalidConfiguration('response_signer.production.common_name');
        }
    }

    private function trustStore(): void
    {
        $this->integerAtLeast('response_trust_store_max_certificates', 2);
        $path = $this->value('response_trust_store');

        if ($path === null) {
            return;
        }

        if (! is_string($path)) {
            throw new InvalidConfiguration('response_trust_store');
        }

        $this->trustStore->validateConfigured($path, (int) $this->value('response_trust_store_max_certificates'));
    }

    private function lockStore(): void
    {
        $store = $this->value('lock_store') ?? config('cache.default');

        if (! is_string($store) || ! $this->lockStoreIsAtomic($store, 0)) {
            throw new InvalidConfiguration('lock_store');
        }
    }

    private function lockStoreIsAtomic(string $store, int $depth): bool
    {
        $config = config('cache.stores.'.$store);

        if (! is_array($config) || ! is_string($config['driver'] ?? null) || $depth > 5) {
            return false;
        }

        $driver = $config['driver'];

        if ($driver === 'failover') {
            $stores = $config['stores'] ?? null;

            if (! is_array($stores) || $stores === []) {
                return false;
            }

            foreach ($stores as $underlying) {
                if (! is_string($underlying) || ! $this->lockStoreIsAtomic($underlying, $depth + 1)) {
                    return false;
                }
            }

            return true;
        }

        if ($driver === 'memo') {
            $underlying = $config['store'] ?? null;

            return is_string($underlying) && $this->lockStoreIsAtomic($underlying, $depth + 1);
        }

        return in_array($driver, self::LOCK_DRIVERS, true)
            || ($driver === 'array' && app()->runningUnitTests());
    }

    private function queueConnection(): void
    {
        $connection = $this->value('resubmission.connection') ?? config('queue.default');
        $driver = is_string($connection) ? config('queue.connections.'.$connection.'.driver') : null;

        if (! is_string($driver)) {
            throw new InvalidConfiguration('resubmission.connection');
        }

        if (in_array($driver, self::FORBIDDEN_QUEUE_DRIVERS, true) && ! ($driver === 'sync' && app()->runningUnitTests())) {
            throw new InvalidConfiguration('resubmission.connection');
        }
    }

    private function queueName(): void
    {
        $queue = $this->value('resubmission.queue');

        if ($queue !== null && (! is_string($queue) || $queue === '')) {
            throw new InvalidConfiguration('resubmission.queue');
        }
    }

    private function backoff(): void
    {
        $backoff = $this->value('resubmission.backoff');
        $minimum = (int) $this->value('resubmission.min_backoff');

        if (! is_array($backoff) || $backoff === [] || ! array_is_list($backoff)) {
            throw new InvalidConfiguration('resubmission.backoff');
        }

        foreach ($backoff as $seconds) {
            if (! is_int($seconds) || $seconds < $minimum) {
                throw new InvalidConfiguration('resubmission.backoff');
            }
        }
    }

    private function verificationSequenceNumber(): void
    {
        $value = $this->value('verification.sequence_number');

        if (! is_string($value) || preg_match(self::SEQUENCE_NUMBER_MASK, $value) !== 1) {
            throw new InvalidConfiguration('verification.sequence_number');
        }
    }

    private function integerAtLeast(string $key, int $minimum): void
    {
        $value = $this->value($key);

        if (! is_int($value) || $value < $minimum) {
            throw new InvalidConfiguration($key);
        }
    }

    private function value(string $key): mixed
    {
        return config('laravel-eet-module.'.$key);
    }
}
