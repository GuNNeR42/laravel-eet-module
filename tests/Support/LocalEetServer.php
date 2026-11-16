<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Tests\Support;

use RuntimeException;

/**
 * A local EET service on 127.0.0.1 (PHP built-in server with
 * tests/Support/eet-server-router.php), so that requests go through the real
 * cURL handler without Http::fake() (Q42). The loopback is not network access
 * (spec/05).
 *
 * The test writes a scenario (scenario.json) into the server directory; the
 * router answers the received request accordingly and appends every received
 * request (URI and headers) to requests.jsonl.
 *
 * This is test support code, not package code.
 */
final class LocalEetServer
{
    /** @var resource|null */
    private $process;

    private function __construct(public readonly int $port, public readonly string $directory) {}

    public static function start(): self
    {
        $lastError = 'unknown';

        for ($try = 0; $try < 3; $try++) {
            $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'eet-server-'.bin2hex(random_bytes(8));
            mkdir($directory, 0777, true);
            $server = new self(self::freePort(), $directory);
            $server->writeScenario(['type' => 'confirmed']);

            if ($server->launch()) {
                return $server;
            }

            $lastError = "port {$server->port} did not accept connections";
            $server->stop();
        }

        throw new RuntimeException("The local EET server could not be started: {$lastError}.");
    }

    public function endpoint(): string
    {
        return "http://127.0.0.1:{$this->port}/eet/services/EETServiceSOAP/v4";
    }

    /**
     * @param  array<string, mixed>  $scenario
     */
    public function writeScenario(array $scenario): void
    {
        file_put_contents($this->directory.DIRECTORY_SEPARATOR.'scenario.json', json_encode($scenario, JSON_THROW_ON_ERROR));
    }

    /**
     * Every request the server received, in order.
     *
     * @return list<array{uri: string, headers: array<string, string>}>
     */
    public function requests(): array
    {
        $file = $this->directory.DIRECTORY_SEPARATOR.'requests.jsonl';

        if (! is_file($file)) {
            return [];
        }

        $requests = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            /** @var array{uri: string, headers: array<string, string>} $request */
            $request = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $requests[] = $request;
        }

        return $requests;
    }

    /**
     * Lower-cased header names of a received request.
     *
     * @return list<string>
     */
    public function headerNames(int $index = 0): array
    {
        $request = $this->requests()[$index] ?? null;

        return $request === null ? [] : array_map('strtolower', array_keys($request['headers']));
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }

        $this->process = null;

        foreach (glob($this->directory.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->directory);
    }

    private function launch(): bool
    {
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $environment = getenv();
        $environment['EET_TEST_SERVER_DIR'] = $this->directory;

        $process = proc_open(
            [PHP_BINARY, '-d', 'display_errors=0', '-d', 'display_startup_errors=0', '-d', 'log_errors=1', '-d', 'error_log='.$this->directory.DIRECTORY_SEPARATOR.'php-errors.log', '-S', "127.0.0.1:{$this->port}", __DIR__.DIRECTORY_SEPARATOR.'eet-server-router.php'],
            [0 => ['pipe', 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']],
            $pipes,
            $this->directory,
            $environment,
        );

        if (! is_resource($process)) {
            return false;
        }

        $this->process = $process;
        $deadline = microtime(true) + 10;

        while (microtime(true) < $deadline) {
            $connection = @fsockopen('127.0.0.1', $this->port, $errorCode, $errorMessage, 0.2);

            if (is_resource($connection)) {
                fclose($connection);

                return true;
            }

            usleep(50_000);
        }

        return false;
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);

        if ($socket === false) {
            throw new RuntimeException("No free port: {$errorMessage}");
        }

        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }
}
