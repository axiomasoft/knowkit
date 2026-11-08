<?php

declare(strict_types=1);

namespace KnowKit\Adapter\ProcessWorker;

use KnowKit\Domain\DomainFailure;
use KnowKit\Domain\Outcome;

/** Used only after the entire cgroup is quiescent; stage is private to this host. */
final class WorkerFiles
{
    public static function relative(string $path): bool
    {
        return strlen($path) <= 1024
            && preg_match('~^[A-Za-z0-9_-][A-Za-z0-9._-]{0,127}(/[A-Za-z0-9_-][A-Za-z0-9._-]{0,127}){0,15}$~D', $path) === 1;
    }

    public static function read(string $root, string $relative, int $limit): string
    {
        clearstatcache();
        if (!self::relative($relative)) {
            throw new DomainFailure(Outcome::Denied, 'unsafe_output');
        }
        $path = $root;
        foreach (explode('/', $relative) as $part) {
            $path .= '/' . $part;
            if (is_link($path)) {
                throw new DomainFailure(Outcome::Denied, 'unsafe_output');
            }
        }
        $stat = @lstat($path);
        if ($stat === false || ($stat['mode'] & 0170000) !== 0100000 || $stat['nlink'] !== 1
            || $stat['size'] > $limit) {
            throw new DomainFailure(Outcome::Denied, 'unsafe_output');
        }
        $bytes = file_get_contents($path, false, null, 0, $limit + 1);
        if ($bytes === false || strlen($bytes) > $limit) {
            throw new DomainFailure(Outcome::Incompatible, 'output_limit');
        }
        return $bytes;
    }

    /** @return array<string, int> */
    public static function inventory(string $root, int $limit): array
    {
        // Files are written by another process; PHP's previous stat cache is never authoritative.
        clearstatcache();
        $files = [];
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        $count = 0;
        $total = 0;
        foreach ($entries as $entry) {
            if (++$count > 32768 || $entry->isLink()) {
                throw new DomainFailure(Outcome::Denied, 'unsafe_output');
            }
            if ($entry->isDir()) {
                continue;
            }
            $relative = substr($entry->getPathname(), strlen($root) + 1);
            $stat = lstat($entry->getPathname());
            if (!self::relative($relative) || ($stat['mode'] & 0170000) !== 0100000 || $stat['nlink'] !== 1) {
                throw new DomainFailure(Outcome::Denied, 'unsafe_output');
            }
            $files[$relative] = $stat['size'];
            $total += $stat['size'];
            if ($total > $limit) {
                throw new DomainFailure(Outcome::Incompatible, 'output_limit');
            }
        }
        return $files;
    }

    public static function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            chmod($path, 0700);
            foreach (new \FilesystemIterator($path) as $child) {
                self::remove($child->getPathname());
            }
            rmdir($path);
            return;
        }
        unlink($path);
    }
}
