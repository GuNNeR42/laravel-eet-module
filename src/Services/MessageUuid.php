<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Services;

use Closure;
use LogicException;

/**
 * Version 4 UUIDs of data messages without another dependency (R29).
 * Tests may replace the generator through Eet::createMessageUuidsUsing().
 */
final class MessageUuid
{
    public const string MASK = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-4[0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$/D';

    /**
     * @param  (Closure(): string)|null  $factory
     */
    public static function next(?Closure $factory = null): string
    {
        if ($factory === null) {
            return self::random();
        }

        $uuid = $factory();

        if (preg_match(self::MASK, $uuid) !== 1) {
            throw new LogicException('The message UUID factory must return a version 4 UUID.');
        }

        return $uuid;
    }

    public static function random(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
