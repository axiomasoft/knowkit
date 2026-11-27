<?php

declare(strict_types=1);

namespace KnowKit\Tests\Tooling;

use Composer\Autoload\ClassLoader;

/** Prefer the checkout over Composer path copies, only for already connected packages. */
final class CandidateAutoload
{
    private static ?\Closure $autoload = null;

    public static function register(string $root): void
    {
        $packages = [
            'KnowKit\\Bridge\\AgenTools\\' => 'agentools-bridge',
            'KnowKit\\Bridge\\Mcp\\' => 'mcp-bridge',
            'KnowKit\\ContextCompilers\\' => 'context-compilers',
            'KnowKit\\Distill\\' => 'distill',
            'KnowKit\\TrainingExport\\' => 'training-export',
            'KnowKit\\PgVector\\' => 'pgvector',
            'KnowKit\\Laravel\\' => 'laravel',
            'KnowKit\\Chatom\\' => 'chatom',
            'KnowKit\\Answer\\' => 'answer',
            'KnowKit\\Http\\' => 'http',
            'KnowKit\\' => 'core',
        ];
        $registered = [];
        foreach (ClassLoader::getRegisteredLoaders() as $loader) {
            $registered += $loader->getPrefixesPsr4();
        }
        $paths = [];
        foreach ($packages as $prefix => $package) {
            $path = $root . '/packages/' . $package . '/src/';
            if (isset($registered[$prefix]) && is_dir($path)) {
                $paths[$prefix] = $path;
            }
        }
        if (self::$autoload !== null) {
            spl_autoload_unregister(self::$autoload);
        }
        self::$autoload = static function (string $class) use ($paths): void {
            if (str_starts_with($class, 'KnowKit\\Tests\\')) {
                return;
            }
            foreach ($paths as $prefix => $directory) {
                if (str_starts_with($class, $prefix)) {
                    $path = $directory . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                    if (is_file($path)) {
                        require_once $path;
                    }
                    return;
                }
            }
        };
        // A later Composer install prepends its loader. Re-register after each such import.
        // This also takes priority over optimized classmaps and cached Composer misses.
        spl_autoload_register(self::$autoload, true, true);
    }
}
