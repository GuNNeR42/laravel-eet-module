<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Services;

use LogicException;

/**
 * A response sink that stops accepting data past response_max_bytes (R66).
 * Implemented as a stream wrapper, so it works as a resource for the real
 * cURL handler of Guzzle 7 and 8 (a short write aborts the transfer) and for
 * the sink handling of Http::fake().
 *
 * @internal
 */
final class BoundedSink
{
    private const string PROTOCOL = 'laravel-eet-bounded-sink';

    /** @var array<string, array{limit: int, exceeded: bool}> */
    private static array $sinks = [];

    /** Set by PHP for stream wrappers. */
    public mixed $context = null;

    private string $id = '';

    private string $buffer = '';

    private int $position = 0;

    /**
     * Opens a sink that accepts at most $limit + 1 bytes.
     *
     * @return array{resource, string}
     */
    public static function open(int $limit): array
    {
        if (! in_array(self::PROTOCOL, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::PROTOCOL, self::class);
        }

        $id = bin2hex(random_bytes(8));
        self::$sinks[$id] = ['limit' => $limit + 1, 'exceeded' => false];
        $resource = fopen(self::PROTOCOL.'://'.$id, 'w+');

        if (! is_resource($resource)) {
            throw new LogicException('The response sink could not be opened.');
        }

        return [$resource, $id];
    }

    public static function exceeded(string $id): bool
    {
        return self::$sinks[$id]['exceeded'] ?? false;
    }

    public static function forget(string $id): void
    {
        unset(self::$sinks[$id]);
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $this->id = substr($path, strlen(self::PROTOCOL) + 3);

        return isset(self::$sinks[$this->id]);
    }

    public function stream_write(string $data): int
    {
        $limit = self::$sinks[$this->id]['limit'] ?? 0;
        $accepted = max(0, min(strlen($data), $limit - strlen($this->buffer)));

        if ($accepted < strlen($data)) {
            self::$sinks[$this->id]['exceeded'] = true;
        }

        $this->buffer .= substr($data, 0, $accepted);
        $this->position = strlen($this->buffer);

        return $accepted;
    }

    public function stream_read(int $count): string
    {
        $chunk = substr($this->buffer, $this->position, $count);
        $this->position += strlen($chunk);

        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->position >= strlen($this->buffer);
    }

    public function stream_tell(): int
    {
        return $this->position;
    }

    public function stream_seek(int $offset, int $whence): bool
    {
        $position = match ($whence) {
            SEEK_SET => $offset,
            SEEK_CUR => $this->position + $offset,
            SEEK_END => strlen($this->buffer) + $offset,
            default => -1,
        };

        if ($position < 0 || $position > strlen($this->buffer)) {
            return false;
        }

        $this->position = $position;

        return true;
    }

    /**
     * @return array<string, int>
     */
    public function stream_stat(): array
    {
        return ['size' => strlen($this->buffer)];
    }

    public function stream_flush(): bool
    {
        return true;
    }

    public function stream_close(): void
    {
        $this->buffer = '';
    }
}
