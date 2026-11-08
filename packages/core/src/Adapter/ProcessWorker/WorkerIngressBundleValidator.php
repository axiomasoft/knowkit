<?php

declare(strict_types=1);

namespace KnowKit\Adapter\ProcessWorker;

use KnowKit\Domain\Contract\ContractValidator;
use KnowKit\Domain\Contract\Digests;
use KnowKit\Domain\DomainFailure;
use KnowKit\Domain\IrDocument;
use KnowKit\Domain\JobEnvelope;
use KnowKit\Domain\Outcome;
use KnowKit\Domain\WorkerResult;

final readonly class WorkerIngressBundleValidator
{
    public function __construct(private ContractValidator $contracts)
    {
    }

    public function verify(JobEnvelope $job, string $outputRoot): WorkerBundle
    {
        $limits = $job->limits();
        $files = WorkerFiles::inventory($outputRoot, $limits->maxOutputBytes);
        $prefix = $job->outputRelativePath();
        $outDir = $prefix === '' ? $outputRoot : $outputRoot . '/' . $prefix;
        $manifest = WorkerFiles::read($outDir, 'result.json', 1048576);
        $wire = $this->contracts->decodeValid($manifest, 'urn:knowkit:schema:worker-result:1.0');
        $result = new WorkerResult($wire, $this->contracts);
        $irBytes = WorkerFiles::read($outDir, $result->irPath(), min(67108864, $limits->maxOutputBytes));
        $irWire = $this->contracts->decodeValid($irBytes, 'urn:knowkit:schema:document-ir:1.0');
        $ir = new IrDocument($irWire, $this->contracts);
        if ($result->irPath() === 'result.json' || (string) strlen($irBytes) !== $result->irByteLength()
            || !hash_equals($result->irDigest(), Digests::ir($irWire))) {
            throw new DomainFailure(Outcome::Incompatible, 'digest_mismatch');
        }
        $result->assertOutput($job, $ir);
        $assets = $this->assets($result, $outDir, $limits->maxOutputBytes, $limits->maxAssets);
        $expected = ['result.json', $result->irPath(), ...array_keys($assets)];
        if ($prefix !== '') {
            $expected = array_map(static fn (string $path): string => $prefix . '/' . $path, $expected);
        }
        if (count($expected) !== count(array_unique($expected)) || array_diff(array_keys($files), $expected) !== []
            || array_diff($expected, array_keys($files)) !== []) {
            throw new DomainFailure(Outcome::Denied, 'inventory_mismatch');
        }
        return new WorkerBundle($result, $ir, $assets);
    }

    /** @return array<string, string> */
    private function assets(WorkerResult $result, string $outDir, int $limit, int $maxAssets): array
    {
        if (count($result->assets()) > $maxAssets) {
            throw new DomainFailure(Outcome::Incompatible, 'output_limit');
        }
        $assets = [];
        foreach ($result->assets() as $asset) {
            $bytes = WorkerFiles::read($outDir, $asset->relativePath, $limit);
            if (!hash_equals($asset->digest, hash('sha256', $bytes)) || (string) strlen($bytes) !== $asset->byteLength) {
                throw new DomainFailure(Outcome::Incompatible, 'digest_mismatch');
            }
            $assets[$asset->relativePath] = $bytes;
        }
        return $assets;
    }
}
