<?php

declare(strict_types=1);

namespace KnowKit\Tests\Worker\Pdf;

use KnowKit\Adapter\ProcessWorker\ProcessWorkerSupervisor;
use KnowKit\Adapter\ProcessWorker\SandboxRuntime;
use KnowKit\Adapter\ProcessWorker\WorkerFiles;
use KnowKit\Adapter\ProcessWorker\WorkerIngressBundleValidator;
use KnowKit\Domain\Contract\StrictJson;
use KnowKit\Domain\DomainFailure;
use KnowKit\Tests\Worker\Protocol\Fixture;
use KnowKit\Tests\Worker\Protocol\RuntimeCandidate;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/protocol/bootstrap.php';

#[Group('worker:pdf')]
final class RuntimeTest extends TestCase
{
    public function testQualifiedPdfRunsThroughRealSupervisorAndIndependentIngress(): void
    {
        $base = Fixture::temporary();
        mkdir($base . '/stages', 0700);
        try {
            $runtime = $this->runtime($base . '/stages');
            RuntimeCandidate::requireCurrent($runtime->runtimeRoot, Fixture::root());
            $supervisor = new ProcessWorkerSupervisor($runtime, new WorkerIngressBundleValidator(Fixture::validator()));
            $root = Fixture::root() . '/tests/fixtures/documents/pdf/v1/';
            $expected = json_decode(file_get_contents($root . 'manifest.json'), true, flags: JSON_THROW_ON_ERROR);
            foreach (['unicode-label', 'coverage'] as $name) {
                $source = file_get_contents($root . $name . '.pdf');
                $golden = StrictJson::decode(file_get_contents($root . $name . '.ir.json'));
                $signature = $golden->get('parserSignature');
                $limits = Fixture::wire('envelope')->get('limits')->with('wallSeconds', 60)
                    ->with('cpuSeconds', 45)->with('memoryBytes', '2147483648');
                $job = Fixture::job($source, [
                    'parserProfile' => 'docling-pdf-text-v1',
                    'parserSignature' => $signature,
                    'profileFingerprint' => $signature->get('profileDigest'),
                    'limits' => $limits,
                ]);
                $bundle = $supervisor->run($job, $source);
                self::assertSame($expected['expected'][$name]['irDigest'], $bundle->result->irDigest());
                self::assertSame($golden->get('coverage')->get('state'), $bundle->result->toWire()->get('status'));
                self::assertSame([], glob($base . '/stages/*'));
            }
        } finally {
            WorkerFiles::remove($base);
        }
    }

    public function testRuntimeInventoryAllowsWheelNamesAndRejectsTraversalLinksAndExtraFiles(): void
    {
        $base = Fixture::temporary();
        mkdir($base . '/stages', 0700);
        mkdir($base . '/runtime/runtime/bin', 0700, true);
        $files = [
            'seccomp.bpf' => 'filter',
            'runtime/bin/knowkit-worker' => 'worker',
            'torch-2.13.0+cpu.dist-info/METADATA' => 'metadata',
            'templates/[Content_Types].xml' => 'template',
            'templates/script (dev).tmpl' => 'template',
        ];
        try {
            foreach ($files as $path => $bytes) {
                @mkdir(dirname($base . '/runtime/' . $path), 0700, true);
                file_put_contents($base . '/runtime/' . $path, $bytes);
            }
            $bom = ['schemaVersion' => 'worker-runtime-bom-1.0', 'files' => array_map(
                static fn (string $bytes): string => hash('sha256', $bytes), $files,
            )];
            file_put_contents($base . '/runtime/bom.json', json_encode($bom, JSON_THROW_ON_ERROR));
            $runtime = $this->runtime($base . '/stages', $base . '/runtime');
            $runtime->assertAvailable();
            self::assertTrue(true);
            file_put_contents($base . '/runtime/extra', 'unlisted');
            $this->assertRefused($runtime);
            unlink($base . '/runtime/extra');
            $this->assertOversizeRuntimeRefused($base . '/runtime', $runtime);
            symlink('/etc/passwd', $base . '/runtime/link');
            $this->assertRefused($runtime);
            unlink($base . '/runtime/link');
            $bom['files']['../escape'] = hash('sha256', 'escape');
            file_put_contents($base . '/runtime/bom.json', json_encode($bom, JSON_THROW_ON_ERROR));
            $this->assertRefused($runtime);
        } finally {
            WorkerFiles::remove($base);
        }
    }

    private function assertRefused(SandboxRuntime $runtime): void
    {
        try {
            $runtime->assertAvailable();
            self::fail('unsafe runtime accepted');
        } catch (DomainFailure $failure) {
            self::assertSame('sandbox_unavailable', $failure->errorCode);
        }
    }

    private function assertOversizeRuntimeRefused(string $root, SandboxRuntime $runtime): void
    {
        $file = fopen($root . '/oversize', 'wb');
        self::assertIsResource($file);
        self::assertTrue(ftruncate($file, 4294967297));
        fclose($file);
        try {
            $this->assertRefused($runtime);
        } finally {
            unlink($root . '/oversize');
        }
    }

    private function runtime(string $stages, ?string $root = null): SandboxRuntime
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
        $defaultRoot = Fixture::root() . '/python/knowkit-worker/.local-checks/pdf-runtime';
        if (!is_dir($defaultRoot) && is_dir(Fixture::root() . '/python/knowkit-worker/.local-checks/ocr-runtime')) {
            $defaultRoot = Fixture::root() . '/python/knowkit-worker/.local-checks/ocr-runtime';
        }
        $root ??= getenv('KNOWKIT_PDF_RUNTIME') ?: $defaultRoot;
        return new SandboxRuntime(
            $root,
            $stages,
            $path,
            Fixture::root() . '/python/knowkit-worker/.venv/bin/python',
            Fixture::root() . '/deploy/worker/cgroup_exec.py',
        );
    }
}
