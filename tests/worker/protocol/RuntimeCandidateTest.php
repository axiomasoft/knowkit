<?php
declare(strict_types=1);

namespace KnowKit\Tests\Worker\Protocol;

use KnowKit\Adapter\ProcessWorker\WorkerFiles;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

#[Group('worker:protocol')]
final class RuntimeCandidateTest extends TestCase
{
    public function testCachedRuntimeRejectsBuilderAndDependencyChangesBeforeQualification(): void
    {
        $root = Fixture::temporary();
        $builders = ['deploy/worker/build_runtime.py', 'python/knowkit-worker/build_pdf_runtime.py',
            'python/knowkit-worker/build_ocr_runtime.py'];
        $inputs = [...$builders, 'python/knowkit-worker/uv.lock'];
        try {
            mkdir($root . '/runtime');
            foreach ($inputs as $path) {
                if (!is_dir(dirname($root . '/' . $path))) {
                    mkdir(dirname($root . '/' . $path), 0700, true);
                }
                file_put_contents($root . '/' . $path, 'built revision');
            }
            $bom = ['schemaVersion' => 'worker-runtime-bom-1.0',
                'buildInputs' => array_fill_keys($builders, hash('sha256', 'built revision')),
                'lockDigest' => hash('sha256', 'built revision')];
            file_put_contents($root . '/runtime/bom.json', json_encode($bom, JSON_THROW_ON_ERROR));
            RuntimeCandidate::requireCurrent($root . '/runtime', $root);
            foreach ($inputs as $path) {
                file_put_contents($root . '/' . $path, 'changed revision');
                try {
                    RuntimeCandidate::requireCurrent($root . '/runtime', $root);
                    self::fail('A previous runtime qualified changed build inputs');
                } catch (\RuntimeException $failure) {
                    self::assertStringContainsString($path, $failure->getMessage());
                    self::assertStringContainsString('rebuild the runtime', $failure->getMessage());
                } finally {
                    file_put_contents($root . '/' . $path, 'built revision');
                }
            }
            foreach ([[], ['schemaVersion' => 'worker-runtime-bom-1.0'], $bom + ['ignored' => true]] as $legacy) {
                unset($legacy['buildInputs']);
                file_put_contents($root . '/runtime/bom.json', json_encode($legacy, JSON_THROW_ON_ERROR));
                try {
                    RuntimeCandidate::requireCurrent($root . '/runtime', $root);
                    self::fail('Runtime without build provenance qualified');
                } catch (\RuntimeException $failure) {
                    self::assertStringContainsString('no build provenance', $failure->getMessage());
                }
            }
        } finally {
            WorkerFiles::remove($root);
        }
    }
}
