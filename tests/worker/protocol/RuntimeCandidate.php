<?php
declare(strict_types=1);

namespace KnowKit\Tests\Worker\Protocol;

/** Test qualification must exercise this checkout's builders and locked dependencies.
 * Runtime inventory integrity remains SandboxRuntime's separate responsibility.
 */
final class RuntimeCandidate
{
    private const BUILDERS = [
        'deploy/worker/build_runtime.py',
        'python/knowkit-worker/build_pdf_runtime.py',
        'python/knowkit-worker/build_ocr_runtime.py',
    ];

    public static function requireCurrent(string $runtimeRoot, string $repository): void
    {
        $bytes = @file_get_contents($runtimeRoot . '/bom.json');
        $bom = is_string($bytes) ? json_decode($bytes, true) : null;
        if (!is_array($bom) || ($bom['schemaVersion'] ?? null) !== 'worker-runtime-bom-1.0'
            || !is_array($bom['buildInputs'] ?? null)) {
            throw new \RuntimeException('Worker test runtime has no build provenance; rebuild the runtime');
        }
        foreach (self::BUILDERS as $path) {
            self::requireDigest($repository, $path, $bom['buildInputs'][$path] ?? null);
        }
        self::requireDigest($repository, 'python/knowkit-worker/uv.lock', $bom['lockDigest'] ?? null);
    }

    private static function requireDigest(string $repository, string $relative, mixed $expected): void
    {
        $current = @hash_file('sha256', $repository . '/' . $relative);
        if (!is_string($expected) || !is_string($current) || !hash_equals($current, $expected)) {
            throw new \RuntimeException('Worker test runtime input changed: ' . $relative . '; rebuild the runtime');
        }
    }
}
