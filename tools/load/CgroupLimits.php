<?php

declare(strict_types=1);

namespace KnowKit\Load;

/** Diagnostic cgroup v2 observations, never a hardware allocation or qualification. */
final class CgroupLimits
{
    /** @param \Closure(string): ?string $read Missing or unreadable optional files return null. */
    public static function observe(?string $membership, ?string $mountinfo, \Closure $read): array
    {
        $result = ['processCpuMax' => null, 'processMemoryMax' => null,
            'cgroupLimitObservation' => ['status' => 'unavailable', 'cpu' => 'unavailable', 'memory' => 'unavailable',
                'scope' => 'Readable cgroup v2 ancestors in the current mount/namespace view only; hidden ancestors, affinity and shared contention are not inferred.']];
        if ($membership === null || $mountinfo === null || preg_match('/^0::([^\r\n]+)$/m', $membership, $match) !== 1
            || !self::absolute($match[1])) {
            return $result;
        }
        $group = $match[1];
        $mounts = [];
        foreach (explode("\n", $mountinfo) as $line) {
            $parts = explode(' - ', $line, 2);
            if (count($parts) !== 2 || !str_starts_with($parts[1], 'cgroup2 ')) {
                continue;
            }
            $fields = explode(' ', $parts[0]);
            $root = self::unescape($fields[3] ?? '');
            $mount = self::unescape($fields[4] ?? '');
            if (self::absolute($root) && self::absolute($mount)
                && ($root === '/' || $group === $root || str_starts_with($group, $root . '/'))) {
                $mounts[] = [$root, $mount];
            }
        }
        // Prefer the widest visible hierarchy when both full and subtree mounts exist.
        usort($mounts, static fn (array $a, array $b): int => strlen($a[0]) <=> strlen($b[0]));
        if ($mounts === []) {
            return $result;
        }
        [$root, $mount] = $mounts[0];
        $relative = $root === '/' ? $group : substr($group, strlen($root));
        $path = rtrim($mount, '/') . ($relative === '/' ? '' : $relative);
        $cpu = $memory = null;
        $invalidCpu = $invalidMemory = false;
        $observed = ['cpu' => 0, 'memory' => 0];
        do {
            $quota = $read($path . '/cpu.max');
            if ($quota !== null) {
                $quota = trim($quota);
                if (preg_match('/\A(max|[1-9][0-9]{0,18}) ([1-9][0-9]{0,18})\z/D', $quota, $numbers) !== 1) {
                    $invalidCpu = true;
                } else {
                    $observed['cpu']++;
                    // cpu.max values can exceed the float mantissa. Compare the ratio exactly.
                    if ($cpu === null || self::cpuTighter($numbers[1], $numbers[2], $cpu[0], $cpu[1])) {
                        $cpu = [$numbers[1], $numbers[2], $quota];
                    }
                }
            }
            $limit = $read($path . '/memory.max');
            if ($limit !== null) {
                $limit = trim($limit);
                if (preg_match('/\A(?:max|0|[1-9][0-9]{0,19})\z/D', $limit) !== 1) {
                    $invalidMemory = true;
                } else {
                    $observed['memory']++;
                    if ($memory === null || ($limit !== 'max' && ($memory === 'max'
                        || strlen($limit) < strlen($memory) || (strlen($limit) === strlen($memory) && strcmp($limit, $memory) < 0)))) {
                        $memory = $limit;
                    }
                }
            }
            if ($path === $mount) {
                break;
            }
            $path = dirname($path);
        } while ($path === $mount || str_starts_with($path, rtrim($mount, '/') . '/'));
        $result['processCpuMax'] = $invalidCpu ? null : ($cpu[2] ?? null);
        $result['processMemoryMax'] = $invalidMemory ? null : $memory;
        $result['cgroupLimitObservation']['status'] = 'visible-hierarchy';
        $result['cgroupLimitObservation']['cpu'] = $invalidCpu ? 'invalid' : ($cpu === null ? 'unavailable' : 'observed');
        $result['cgroupLimitObservation']['memory'] = $invalidMemory ? 'invalid' : ($memory === null ? 'unavailable' : 'observed');
        $result['cgroupLimitObservation']['observedFiles'] = $observed;
        return $result;
    }

    /** True when quota/period is a strictly smaller share than the current observation. */
    private static function cpuTighter(string $quota, string $period, string $currentQuota, string $currentPeriod): bool
    {
        if ($quota === 'max') {
            return false;
        }
        if ($currentQuota === 'max') {
            return true;
        }

        return self::decimalCmp(self::decimalMul($quota, $currentPeriod), self::decimalMul($currentQuota, $period)) < 0;
    }

    private static function decimalMul(string $left, string $right): string
    {
        $left = ltrim($left, '0') ?: '0';
        $right = ltrim($right, '0') ?: '0';
        if ($left === '0' || $right === '0') {
            return '0';
        }
        $width = strlen($left);
        $height = strlen($right);
        $digits = array_fill(0, $width + $height, 0);
        for ($i = $width - 1; $i >= 0; $i--) {
            $carry = 0;
            for ($j = $height - 1; $j >= 0; $j--) {
                $sum = $digits[$i + $j + 1] + ((int) $left[$i]) * ((int) $right[$j]) + $carry;
                $digits[$i + $j + 1] = $sum % 10;
                $carry = intdiv($sum, 10);
            }
            $digits[$i] += $carry;
        }
        $product = ltrim(implode('', $digits), '0');

        return $product === '' ? '0' : $product;
    }

    private static function decimalCmp(string $left, string $right): int
    {
        $left = ltrim($left, '0') ?: '0';
        $right = ltrim($right, '0') ?: '0';
        if (strlen($left) !== strlen($right)) {
            return strlen($left) <=> strlen($right);
        }

        return strcmp($left, $right);
    }

    private static function absolute(string $path): bool
    {
        return str_starts_with($path, '/') && !str_contains($path, "\0")
            && !in_array('.', explode('/', $path), true) && !in_array('..', explode('/', $path), true);
    }

    private static function unescape(string $path): string
    {
        return preg_replace_callback('/\\\\(040|011|012|134)/', static fn (array $match): string => chr(intval($match[1], 8)), $path) ?? '';
    }
}
