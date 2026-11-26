<?php

declare(strict_types=1);

namespace KnowKit\Tests\Chatom\Qualification;

use Composer\InstalledVersions;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class RuntimePins
{
    public static function requireInstalledProfile(?array $matrix = null): void
    {
        $matrix ??= NativeHost::matrix();
        $root = dirname(__DIR__, 3);
        $lock = $root . '/' . $matrix['composerLock']['path'];
        if (PHP_VERSION !== $matrix['phpVersion']
            || self::lockHash($lock) !== $matrix['composerLock']['sha256']
            || InstalledVersions::getReference('axiomasoft/chatom') !== $matrix['chatom']['reference']
            || InstalledVersions::getPrettyVersion('laravel/framework') !== $matrix['laravel']['version']
            || InstalledVersions::getReference('laravel/framework') !== $matrix['laravel']['reference']
            || self::nativeTreeDigest() !== $matrix['chatom']['installedTreeSha256']) {
            throw new RuntimeException('Exact installed PHP/Composer/Chatom/Laravel profile mismatch; no substitution');
        }
    }

    private static function lockHash(string $path): string
    {
        static $hashes = [];
        if (isset($hashes[$path])) {
            return $hashes[$path];
        }
        if (!is_file($path)) {
            throw new RuntimeException('Exact installed PHP/Composer/Chatom/Laravel profile mismatch; no substitution');
        }
        $hash = hash_file('sha256', $path);
        if ($hash === false) {
            throw new RuntimeException('Exact installed PHP/Composer/Chatom/Laravel profile mismatch; no substitution');
        }

        return $hashes[$path] = $hash;
    }

    public static function nativeTreeDigest(): string
    {
        static $digest = null;
        if (is_string($digest)) {
            return $digest;
        }
        $root = dirname(__DIR__, 3) . '/packages/chatom/vendor/axiomasoft/chatom/';
        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile()) {
                $files[substr($file->getPathname(), strlen($root))] = $file->getPathname();
            }
        }
        ksort($files, SORT_STRING);
        $hash = hash_init('sha256');
        foreach ($files as $relative => $path) {
            hash_update($hash, $relative . "\0" . hash_file('sha256', $path) . "\n");
        }

        return $digest = hash_final($hash);
    }
}
