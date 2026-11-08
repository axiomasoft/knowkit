<?php

declare(strict_types=1);

namespace KnowKit\Tests\Worker\Acceptance;

use KnowKit\Adapter\ProcessWorker\WorkerFiles;
use KnowKit\Adapter\ProcessWorker\WorkerIngressBundleValidator;
use KnowKit\Domain\Contract\Jcs;
use KnowKit\Domain\Contract\StrictJson;
use KnowKit\Domain\DomainFailure;
use KnowKit\Domain\Outcome;
use KnowKit\Tests\Worker\Protocol\Fixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

#[Group('worker:acceptance')]
final class IngressTest extends TestCase
{
    public function testFullInventoryRejectsExtraSymlinkHardlinkNonregularEscapeAndDuplicate(): void
    {
        foreach (['extra', 'symlink', 'hardlink', 'fifo', 'escape', 'duplicate'] as $mode) {
            $root = Fixture::temporary();
            try {
                FixtureRunner::write($root, Fixture::job(), "KnowKit protocol fixture.\n");
                $this->forge($root, $mode);
                try {
                    (new WorkerIngressBundleValidator(Fixture::validator()))->verify(Fixture::job(), $root);
                    self::fail('Unsafe inventory accepted: ' . $mode);
                } catch (DomainFailure $failure) {
                    self::assertContains($failure->outcome, [Outcome::Denied, Outcome::Incompatible]);
                    self::assertContains($failure->errorCode, ['unsafe_output', 'inventory_mismatch']);
                } catch (\KnowKit\Domain\Contract\ContractViolation $failure) {
                    self::assertNotEmpty($failure->errorCode);
                }
            } finally {
                WorkerFiles::remove($root);
            }
        }
    }

    public function testAssetsAreHashedAndMappedToImmutableIrIdsIndependently(): void
    {
        $root = Fixture::temporary();
        try {
            $ir = Fixture::wire('ir');
            $result = Fixture::wire('result');
            $bytes = hex2bin('89504e470d0a1a0a');
            $asset = new \KnowKit\Domain\Contract\JsonObject([
                'relativePath' => 'image.png', 'digest' => hash('sha256', $bytes),
                'byteLength' => (string) strlen($bytes), 'mediaType' => 'image/png',
            ]);
            $ir = $ir->with('assets', [$asset->without('relativePath')->with('assetId', 'a1')->with('pageId', null)]);
            $irBytes = Jcs::canonicalize($ir);
            $result = Fixture::sign($result->with('assets', [$asset])->with('irDigest', \KnowKit\Domain\Contract\Digests::ir($ir))
                ->with('irByteLength', (string) strlen($irBytes)));
            file_put_contents($root . '/image.png', $bytes);
            file_put_contents($root . '/ir.json', $irBytes);
            file_put_contents($root . '/result.json', Jcs::canonicalize($result));
            $validator = new WorkerIngressBundleValidator(Fixture::validator());
            $output = $validator->verify(Fixture::job(), $root);
            self::assertSame(['a1' => $bytes], $output->assetsForIr());
            file_put_contents($root . '/image.png', 'forged');
            $this->expectExceptionMessage('digest_mismatch');
            $validator->verify(Fixture::job(), $root);
        } finally {
            WorkerFiles::remove($root);
        }
    }

    private function forge(string $root, string $mode): void
    {
        if ($mode === 'extra') {
            file_put_contents($root . '/extra', 'unexpected');
            return;
        }
        if ($mode === 'symlink') {
            symlink($root . '/ir.json', $root . '/extra');
            return;
        }
        if ($mode === 'hardlink') {
            link($root . '/ir.json', $root . '/extra');
            return;
        }
        if ($mode === 'fifo') {
            self::assertTrue(posix_mkfifo($root . '/extra', 0600));
            return;
        }
        $wire = StrictJson::decode(file_get_contents($root . '/result.json'));
        if ($mode === 'escape') {
            $wire = $wire->with('irPath', '../ir.json');
        } else {
            $asset = new \KnowKit\Domain\Contract\JsonObject([
                'relativePath' => 'image.png', 'digest' => hash('sha256', 'image'), 'byteLength' => '5', 'mediaType' => 'image/png',
            ]);
            file_put_contents($root . '/image.png', 'image');
            $ir = StrictJson::decode(file_get_contents($root . '/ir.json'))->with('assets', [
                $asset->without('relativePath')->with('assetId', 'a1')->with('pageId', null),
                $asset->without('relativePath')->with('assetId', 'a2')->with('pageId', null),
            ]);
            $bytes = Jcs::canonicalize($ir);
            file_put_contents($root . '/ir.json', $bytes);
            $wire = $wire->with('irDigest', \KnowKit\Domain\Contract\Digests::ir($ir))->with('irByteLength', (string) strlen($bytes));
            $wire = $wire->with('assets', [$asset, $asset]);
        }
        file_put_contents($root . '/result.json', Jcs::canonicalize(Fixture::sign($wire)));
    }
}
