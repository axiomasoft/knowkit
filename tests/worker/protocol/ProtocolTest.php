<?php

declare(strict_types=1);

namespace KnowKit\Tests\Worker\Protocol;

use KnowKit\Adapter\ProcessWorker\WorkerFiles;
use KnowKit\Adapter\ProcessWorker\WorkerIngressBundleValidator;
use KnowKit\Domain\Contract\ContractViolation;
use KnowKit\Domain\Contract\Jcs;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\DomainFailure;
use KnowKit\Domain\JobEnvelope;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

#[Group('worker:protocol')]
final class ProtocolTest extends TestCase
{
    private string $directory;
    private WorkerIngressBundleValidator $validator;

    protected function setUp(): void
    {
        $this->directory = Fixture::temporary() . '/output';
        Fixture::output($this->directory);
        $this->validator = new WorkerIngressBundleValidator(Fixture::validator());
    }

    protected function tearDown(): void
    {
        WorkerFiles::remove(dirname($this->directory));
    }

    public function testGoldenIndependentValidation(): void
    {
        $bundle = $this->validator->verify(Fixture::job(), $this->directory);
        self::assertSame(Fixture::wire('result')->get('irDigest'), $bundle->result->irDigest());
        self::assertSame([], $bundle->assets);
        self::assertSame(Fixture::wire('ir')->get('sourceVersionId'), $bundle->ir->toWire()->get('sourceVersionId'));
    }

    public function testStaleFenceAndEpochAndSource(): void
    {
        foreach (['fence' => '8', 'sourceEpoch' => '4', 'tenantId' => '01JC0000000000000000000009',
            'sourceId' => '01JC0000000000000000000008', 'sourceVersionId' => '01JC0000000000000000000007',
            'attemptId' => '01JC0000000000000000000006', 'profileFingerprint' => str_repeat('0', 64)] as $key => $value) {
            $this->reject(Fixture::sign(Fixture::wire('result')->with($key, $value)), 'worker_identity_mismatch');
        }
    }

    public function testExpiredLeaseRefusesLateValidOutput(): void
    {
        $job = Fixture::job(changes: ['leaseExpiresAt' => '2000-01-01T00:00:00Z']);
        $this->expectExceptionMessage('stale_lease');
        $this->validator->verify($job, $this->directory);
    }

    public function testInputAndOutputEscape(): void
    {
        foreach (['inputPath' => '/input/../source.bin', 'outDir' => '/output/../../tmp'] as $key => $value) {
            try {
                Fixture::job(changes: [$key => $value]);
                self::fail('escaping envelope accepted');
            } catch (ContractViolation $failure) {
                self::assertSame('pattern', $failure->errorCode);
            }
        }
    }

    public function testForgedOutputPathAndDuplicates(): void
    {
        foreach (['../ir.json', '/input/source.bin', 'a%2fir.json', 'a\\ir.json'] as $path) {
            $this->reject(Fixture::sign(Fixture::wire('result')->with('irPath', $path)), 'pattern');
        }
        $asset = new JsonObject(['relativePath' => 'image.png', 'digest' => hash('sha256', 'x'),
            'byteLength' => '1', 'mediaType' => 'image/png']);
        $this->reject(Fixture::sign(Fixture::wire('result')->with('assets', [$asset, $asset])), 'duplicate_identifier');
    }

    public function testSchemaMismatchAndWrongDigest(): void
    {
        $this->reject(Fixture::wire('result')->with('schemaVersion', 'worker-result-2.0'), 'unsupported_schema_version');
        $this->reject(Fixture::wire('result')->with('resultDigest', str_repeat('0', 64)), 'digest_mismatch');
        $this->reject(Fixture::sign(Fixture::wire('result')->with('irDigest', str_repeat('0', 64))), 'digest_mismatch');
        $this->reject(Fixture::sign(Fixture::wire('result')->with('irByteLength', '1')), 'digest_mismatch');
    }

    public function testLinksAndUnlistedFilesAreRejected(): void
    {
        $original = file_get_contents($this->directory . '/ir.json');
        unlink($this->directory . '/ir.json');
        symlink(__DIR__ . '/fixtures/v1/ir.json', $this->directory . '/ir.json');
        $this->reject(Fixture::wire('result'), 'unsafe_output');
        unlink($this->directory . '/ir.json');
        file_put_contents($this->directory . '/ir.json', $original);
        link($this->directory . '/ir.json', $this->directory . '/alias');
        $this->reject(Fixture::wire('result'), 'unsafe_output');
        unlink($this->directory . '/alias');
        file_put_contents($this->directory . '/unlisted', 'x');
        $this->reject(Fixture::wire('result'), 'inventory_mismatch');
    }

    public function testCoverageAndAssetInventoryAreIndependent(): void
    {
        $coverage = Fixture::wire('result')->get('coverage')->with('state', 'partial');
        $this->reject(Fixture::sign(Fixture::wire('result')->with('coverage', $coverage)), 'coverage_mismatch');
        $asset = new JsonObject(['relativePath' => 'image.png', 'digest' => hash('sha256', 'x'),
            'byteLength' => '1', 'mediaType' => 'image/png']);
        $this->reject(Fixture::sign(Fixture::wire('result')->with('assets', [$asset])), 'inventory_mismatch');
    }

    public function testInventoryRefreshesExternalWrites(): void
    {
        $directory = dirname($this->directory) . '/external';
        mkdir($directory);
        file_put_contents($directory . '/growing', 'x');
        self::assertSame(['growing' => 1], WorkerFiles::inventory($directory, 64));
        $writer = proc_open([PHP_BINARY, '-r', 'file_put_contents($argv[1], str_repeat("x", 128));',
            $directory . '/growing'], [0 => ['file', '/dev/null', 'r'],
            1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        self::assertSame(0, proc_close($writer));
        $this->expectExceptionMessage('output_limit');
        WorkerFiles::inventory($directory, 64);
    }

    private function reject(JsonObject $result, string $code): void
    {
        file_put_contents($this->directory . '/result.json', Jcs::canonicalize($result));
        try {
            $this->validator->verify(Fixture::job(), $this->directory);
            self::fail('untrusted output accepted');
        } catch (DomainFailure $failure) {
            self::assertSame($code, $failure->errorCode);
        } catch (ContractViolation $failure) {
            self::assertSame($code, $failure->errorCode);
        }
    }
}
