<?php

declare(strict_types=1);

namespace KnowKit\Tests\Worker\Ocr;

use KnowKit\Adapter\ProcessWorker\OcrMetadataReader;
use KnowKit\Adapter\ProcessWorker\ProcessWorkerSupervisor;
use KnowKit\Adapter\ProcessWorker\SandboxRuntime;
use KnowKit\Adapter\ProcessWorker\WorkerFiles;
use KnowKit\Adapter\ProcessWorker\WorkerIngressBundleValidator;
use KnowKit\Domain\LocalId;
use KnowKit\Domain\Capability;
use KnowKit\Tests\Worker\Protocol\Fixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/OcrFixture.php';

#[Group('worker:ocr')]
final class RuntimeTest extends TestCase
{
    public function testRealSandboxOcrAndPageLimitPassIndependentIngress(): void
    {
        $base = Fixture::temporary();
        mkdir($base . '/stages', 0700);
        try {
            $supervisor = new ProcessWorkerSupervisor($this->runtime($base . '/stages'), new WorkerIngressBundleValidator(Fixture::validator()));
            foreach (['approved-eng', 'mixed', 'page-limit'] as $name) {
                $expected = OcrFixture::document($name);
                $sourceName = $name === 'approved-eng' ? $name : 'mixed';
                $source = file_get_contents(OcrFixture::corpus() . "/{$sourceName}.pdf");
                $signature = $expected->toWire()->get('parserSignature');
                $limits = Fixture::wire('envelope')->get('limits')->with('wallSeconds', 60)->with('cpuSeconds', 45)
                    ->with('memoryBytes', '2147483648')->with('maxOutputBytes', '67108864')
                    ->with('tempBytes', '4294967296')->with('maxPages', $name === 'page-limit' ? 2 : 500);
                $job = Fixture::job($source, [
                    'parserProfile' => 'docling-pdf-ocr-eng-v1',
                    'parserSignature' => $signature,
                    'profileFingerprint' => $signature->get('profileDigest'),
                    'limits' => $limits,
                ]);
                $bundle = $supervisor->run($job, $source);
                self::assertNotNull($bundle->capabilities);
                $bundle->capabilities->require(Capability::ParserOcr, 'ir-1.0');
                self::assertSame($expected->canonical(), $bundle->ir->canonical());
                self::assertSame(hash('sha256', $expected->canonical()), $bundle->result->irDigest());
                $metadata = (new OcrMetadataReader())->forBlock($bundle->ir, OcrFixture::firstOcrId($bundle->ir));
                self::assertSame('eng', $metadata->language);
                self::assertSame([], glob($base . '/stages/*'));
            }
        } finally {
            WorkerFiles::remove($base);
        }
    }

    private function runtime(string $stages): SandboxRuntime
    {
        $path = getenv('KNOWKIT_TEST_CGROUP_PARENT');
        if (!is_string($path) || $path === '') {
            $path = '/sys/fs/cgroup' . trim(explode('::', file_get_contents('/proc/self/cgroup'))[1]);
            while (str_starts_with($path, '/sys/fs/cgroup/')) {
                $enabled = explode(' ', trim(file_get_contents($path . '/cgroup.subtree_control')));
                if (is_writable($path) && array_diff(['cpu', 'memory', 'pids'], $enabled) === []) {
                    break;
                }
                $path = dirname($path);
            }
        }

        return new SandboxRuntime(
            getenv('KNOWKIT_OCR_RUNTIME') ?: Fixture::root() . '/python/knowkit-worker/.local-checks/ocr-runtime',
            $stages,
            $path,
            Fixture::root() . '/python/knowkit-worker/.venv/bin/python',
            Fixture::root() . '/deploy/worker/cgroup_exec.py',
        );
    }
}
