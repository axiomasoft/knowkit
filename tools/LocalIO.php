<?php

declare(strict_types=1);

namespace KnowKit\Tooling;

final class LocalIO
{
    /** Argument to fgets(); the returned line is at most this many bytes minus one. */
    public const LINE_LIMIT = 1048576;

    public static function read(string $path): string
    {
        $data = file_get_contents($path);
        if ($data === false) {
            throw new \RuntimeException('Cannot read required local file');
        }
        return $data;
    }

    /** @return list<string> */
    public static function paths(string $pattern, int $flags = 0): array
    {
        $paths = glob($pattern, $flags);
        if ($paths === false) {
            throw new \RuntimeException('Cannot enumerate required local files');
        }
        return $paths;
    }

    /** @param resource $stream */
    public static function line($stream, int $length = self::LINE_LIMIT): string
    {
        if ($length < 1) {
            throw new \InvalidArgumentException('IPC line limit must be positive');
        }
        $line = fgets($stream, $length);
        if ($line === false) {
            throw new \RuntimeException('Missing IPC input');
        }
        return $line;
    }

    public static function decode(string $data): string
    {
        $decoded = base64_decode($data, true);
        if ($decoded === false) {
            throw new \RuntimeException('Invalid IPC encoding');
        }
        return $decoded;
    }
}
