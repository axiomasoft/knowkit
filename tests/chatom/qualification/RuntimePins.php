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
        if (PHP_VERSION !== $matrix['phpVersion']
            || hash_file('sha256', $root . '/' . $matrix['composerLock']['path']) !== $matrix['composerLock']['sha256']
            || InstalledVersions::getReference('axiomasoft/chatom') !== $matrix['chatom']['reference']
            || InstalledVersions::getPrettyVersion('laravel/framework') !== $matrix['laravel']['version']
            || InstalledVersions::getReference('laravel/framework') !== $matrix['laravel']['reference']
            || self::nativeTreeDigest() !== $matrix['chatom']['installedTreeSha256']) {
            throw new RuntimeException('Exact installed PHP/Composer/Chatom/Laravel profile mismatch; no substitution');
        }
    }

    public static function nativeTreeDigest(): string
    {
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

        return hash_final($hash);
    }
}
