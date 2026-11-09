<?php

declare(strict_types=1);

namespace KnowKit\Load;

use KnowKit\Tooling\Qa;

/** Nearest rank, no interpolation, units always explicit. */
final class Metrics
{
    public static function percentiles(array $samples): array
    {
        Qa::ensure($samples !== [], 'empty measurement');
        foreach ($samples as $value) {
            Qa::ensure(is_numeric($value) && is_finite((float) $value) && $value >= 0, 'invalid measurement');
        }
        sort($samples, SORT_NUMERIC);
        $result = ['count' => count($samples)];
        foreach (['p50' => 0.50, 'p95' => 0.95, 'p99' => 0.99] as $key => $fraction) {
            $result[$key] = (float) $samples[(int) ceil(count($samples) * $fraction) - 1];
        }

        return $result;
    }

    public static function cpuMs(int $who = 0): float
    {
        $usage = getrusage($who);

        return ($usage['ru_utime.tv_sec'] + $usage['ru_stime.tv_sec']) * 1000
            + ($usage['ru_utime.tv_usec'] + $usage['ru_stime.tv_usec']) / 1000;
    }

    public static function timed(callable $operation, array &$samples): mixed
    {
        $start = hrtime(true);
        $result = $operation();
        $samples[] = (hrtime(true) - $start) / 1e6;

        return $result;
    }
}
