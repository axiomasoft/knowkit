<?php

declare(strict_types=1);

namespace KnowKit\Tests\Security\Hardening;

use KnowKit\Adapter\LocalBlob\PrivateFilesystem;
use KnowKit\Adapter\ProcessWorker\ProcessWorkerSupervisor;
use KnowKit\Adapter\ProcessWorker\SandboxRuntime;
use KnowKit\Adapter\ProcessWorker\WorkerFiles;
use KnowKit\Adapter\ProcessWorker\WorkerIngressBundleValidator;
use KnowKit\Answer\Adapter\StaticCeilingBudget;
use KnowKit\Answer\Domain as A;
use KnowKit\Domain as D;
use KnowKit\Domain\Origins\DocumentInput;
use KnowKit\Tests\Contract\Ports\PortFixture;
use KnowKit\Http\Server as H;
use PHPUnit\Framework\Attributes\Group;

require_once __DIR__ . '/bootstrap.php';

#[Group('security:hardening')]
final class ResourceTest extends HardeningCase
{
    public function testTraversalSymlinksAndHardlinksCannotReachProtectedBytes(): void
    {
        mkdir($this->temporary . '/private', 0700);
        file_put_contents($this->temporary . '/private/owned', 'safe');
        chmod($this->temporary . '/private/owned', 0600);
        file_put_contents($this->temporary . '/outside', 'SYNTHETIC_PROTECTED');
        chmod($this->temporary . '/outside', 0600);
        symlink($this->temporary . '/outside', $this->temporary . '/private/symlink');
        link($this->temporary . '/outside', $this->temporary . '/private/hardlink');
        $fs = new PrivateFilesystem($this->temporary . '/private');
        foreach ([...$this->cases()['unsafePaths'], 'symlink', 'hardlink'] as $path) {
            $this->failure(fn () => $fs->open($path), 'unsafe_blob_path');
            $this->failure(fn () => WorkerFiles::read(root: $this->temporary . '/private', relative: $path, limit: 64), 'unsafe_output');
        }
        $handle = $fs->open('owned');
        self::assertSame('safe', stream_get_contents($handle));
        fclose($handle);
        self::assertSame('safe', WorkerFiles::read(root: $this->temporary . '/private', relative: 'owned', limit: 4));
        $this->failure(fn () => WorkerFiles::inventory(root: $this->temporary . '/private', limit: 64), 'unsafe_output');
        symlink($this->temporary . '/private', $this->temporary . '/root-link');
        $this->failure(fn () => WorkerFiles::read(root: $this->temporary . '/root-link', relative: 'owned', limit: 4), 'unsafe_output');
        $this->failure(fn () => WorkerFiles::inventory(root: $this->temporary . '/root-link', limit: 64), 'unsafe_output');
    }

    public function testArchiveAndOoxmlBombAreUnsupportedBeforeHeavyRead(): void
    {
        $bomb = file_get_contents(__DIR__ . '/fixtures/v1/archive-bomb.zip');
        self::assertLessThan(4096, strlen($bomb));
        foreach (['application/zip', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'] as $mime) {
            $reads = 0;
            $this->failure(function () use ($bomb, $mime, &$reads): void {
                DocumentInput::assertMime(bytes: $bomb, mediaType: $mime);
                $reads++;
            }, code: 'archive_unsupported', outcome: D\Outcome::Incompatible);
            self::assertSame(0, $reads);
        }
        $this->failure(fn () => $this->supervisor()->run(job: $this->job($bomb), source: $bomb),
            code: 'archive_unsupported', outcome: D\Outcome::Incompatible);
    }

    public function testMaliciousPdfCannotRunWhenSandboxUnavailable(): void
    {
        $pdf = file_get_contents(__DIR__ . '/fixtures/v1/malicious.pdf');
        DocumentInput::assertMime(bytes: $pdf, mediaType: 'application/pdf');
        $this->failure(fn () => $this->supervisor()->run(job: $this->job($pdf), source: $pdf),
            code: 'sandbox_unavailable', outcome: D\Outcome::Unavailable);
        self::assertSame([], array_values(array_diff(scandir($this->temporary), ['.', '..'])));
        $runtime = $this->runtime();
        $argv = $runtime->argv(stage: '/synthetic-stage', group: '/synthetic-group', limits: $this->limits(), probe: false);
        foreach (['--unshare-all', '--clearenv', '--seccomp', '--cap-drop', '--die-with-parent'] as $flag) {
            self::assertContains($flag, $argv);
        }
    }

    public function testUploadPagesAssetsOutputRequestAndQueryBudgetsAreFinite(): void
    {
        foreach (['maxPages' => 501, 'maxAssets' => 2001, 'maxOutputBytes' => 268435457, 'memoryBytes' => 2147483649,
            'tempBytes' => 4294967297, 'wallSeconds' => 601, 'cpuSeconds' => 481] as $name => $value) {
            $limits = get_object_vars($this->limits());
            $limits[$name] = $value;
            $this->failure(fn () => new D\WorkerLimits(...$limits), code: 'limit_exceeded', outcome: D\Outcome::Incompatible);
        }
        file_put_contents($this->temporary . '/oversize', '12345');
        $this->failure(fn () => WorkerFiles::inventory(root: $this->temporary, limit: 4),
            code: 'output_limit', outcome: D\Outcome::Incompatible);
        $source = new D\SourceVersionRef(new D\TenantId(PortFixture::id(1)), new D\SourceId(PortFixture::id(2)),
            new D\SourceVersionId(PortFixture::id(3)), new D\SourceDigest(hash('sha256', 'safe')));
        $this->failure(fn () => new D\SourceBytes(ref: $source, bytes: str_repeat('x', 67108865), mediaType: 'text/plain'),
            code: 'input_limit', outcome: D\Outcome::Incompatible);
        foreach (['x' => 4097, 'я' => 2049] as $text => $count) {
            try {
                new D\Query(str_repeat($text, $count));
                self::fail('Query byte limit bypassed');
            } catch (\InvalidArgumentException $failure) {
                self::assertStringContainsString('UTF-8', $failure->getMessage());
            }
        }
        self::assertSame(4096, strlen((new D\Query(str_repeat('я', 2048)))->text()));
        try {
            (new H\Request(method: 'POST', target: '/v1/search', headers: ['Content-Length' => '1048577']))->read(1048576);
            self::fail('Declared JSON limit bypassed');
        } catch (H\InputFailure $failure) {
            self::assertSame(413, $failure->status);
        }
    }

    public function testTokenFloodAndUnknownCountDeniedBeforeProvider(): void
    {
        $budget = new StaticCeilingBudget();
        $sent = 0;
        foreach ([[16, 1000000], [8193, null], [16, 8193], [16, null]] as [$bytes, $tokens]) {
            $reservation = $budget->reserve(key: new A\InvocationKey('token-flood'), estimate: new A\Estimate(
                scope: PortFixture::scope(), inputBytes: $bytes, inputTokens: $tokens, maxOutputTokens: 2048, maxOutputBytes: 65536,
            ));
            if ($reservation->status === A\ReservationStatus::Confirmed) {
                $sent++;
            }
            self::assertSame(A\ReservationStatus::Denied, $reservation->status);
        }
        self::assertSame(0, $sent);
        $accepted = $budget->reserve(key: new A\InvocationKey('bounded'), estimate: new A\Estimate(
            scope: PortFixture::scope(), inputBytes: 16, inputTokens: 1, maxOutputTokens: 1, maxOutputBytes: 16,
        ));
        self::assertSame(A\ReservationStatus::Confirmed, $accepted->status);
    }

    private function limits(): D\WorkerLimits
    {
        return new D\WorkerLimits(wallSeconds: 3, cpuSeconds: 3, memoryBytes: 134217728, tempBytes: 8388608,
            maxPages: 8, maxOutputBytes: 8388608, maxAssets: 16);
    }

    private function job(string $source): D\JobEnvelope
    {
        $wire = PortFixture::json('tests/worker/protocol/fixtures/v1/envelope.json')->with('inputDigest', hash('sha256', $source));

        return new D\JobEnvelope($wire, PortFixture::validator());
    }

    private function runtime(): SandboxRuntime
    {
        return new SandboxRuntime(runtimeRoot: $this->temporary, stagingParent: $this->temporary,
            cgroupParent: $this->temporary, python: '/unavailable/python', launcher: '/unavailable/launcher', bubblewrap: '/unavailable/bwrap');
    }

    private function supervisor(): ProcessWorkerSupervisor
    {
        return new ProcessWorkerSupervisor(runtime: $this->runtime(), validator: new WorkerIngressBundleValidator(PortFixture::validator()));
    }
}
