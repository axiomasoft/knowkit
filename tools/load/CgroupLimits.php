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
                    $ratio = $numbers[1] === 'max' ? INF : (float) $numbers[1] / (float) $numbers[2];
                    if ($cpu === null || $ratio < $cpu[0]) {
                        $cpu = [$ratio, $quota];
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
        $result['processCpuMax'] = $invalidCpu ? null : ($cpu[1] ?? null);
        $result['processMemoryMax'] = $invalidMemory ? null : $memory;
        $result['cgroupLimitObservation']['status'] = 'visible-hierarchy';
        $result['cgroupLimitObservation']['cpu'] = $invalidCpu ? 'invalid' : ($cpu === null ? 'unavailable' : 'observed');
        $result['cgroupLimitObservation']['memory'] = $invalidMemory ? 'invalid' : ($memory === null ? 'unavailable' : 'observed');
        $result['cgroupLimitObservation']['observedFiles'] = $observed;
        return $result;
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
