<?php
declare(strict_types=1);

namespace KnowKit\Domain;

use KnowKit\Domain\Contract\Jcs;
use KnowKit\Domain\Contract\JsonObject;

final readonly class WorkerResult extends SchemaValue
{
    protected const SCHEMA = 'worker-result';

    public function irPath(): string
    {
        return $this->toWire()->get('irPath');
    }

    public function irDigest(): string
    {
        return $this->toWire()->get('irDigest');
    }

    public function irByteLength(): string
    {
        return $this->toWire()->get('irByteLength');
    }

    public function resultDigest(): Sha256
    {
        return new Sha256($this->toWire()->get('resultDigest'));
    }

    public function coverage(): Coverage
    {
        return new Coverage($this->toWire()->get('coverage'));
    }

    /** @return list<WorkerAsset> */
    public function assets(): array
    {
        return array_map(static fn (JsonObject $asset): WorkerAsset => new WorkerAsset(
            $asset->get('relativePath'),
            $asset->get('digest'),
            $asset->get('byteLength'),
            $asset->get('mediaType'),
        ), $this->toWire()->get('assets'));
    }

    public function assertOutput(JobEnvelope $job, IrDocument $ir): void
    {
        $job->assertResult($this->toWire());
        $job->assertIr($ir);
        $this->assertCoverage($ir);
        $this->assertInventory($ir);
    }

    private function assertCoverage(IrDocument $ir): void
    {
        $wire = $this->toWire();
        $status = $wire->get('status');
        $coverage = $ir->toWire()->get('coverage');
        if (!in_array($status, ['complete', 'partial'], true)
            || $coverage->get('state') !== $status
            || Jcs::canonicalize($coverage) !== Jcs::canonicalize($wire->get('coverage'))) {
            throw new DomainFailure(Outcome::Incompatible, 'coverage_mismatch');
        }
        foreach ($wire->get('diagnostics') as $diagnostic) {
            if ($status === 'complete' && $diagnostic->get('severity') === 'error') {
                throw new DomainFailure(Outcome::Incompatible, 'coverage_mismatch');
            }
        }
    }

    private function assertInventory(IrDocument $ir): void
    {
        $inventory = [];
        foreach ($this->assets() as $asset) {
            $inventory[] = $asset->mediaType . ':' . $asset->digest . ':' . $asset->byteLength;
        }
        $irInventory = [];
        foreach ($ir->assets() as $asset) {
            $irInventory[] = $asset->get('mediaType') . ':' . $asset->get('digest') . ':' . $asset->get('byteLength');
        }
        sort($inventory);
        sort($irInventory);
        if ($inventory !== $irInventory) {
            throw new DomainFailure(Outcome::Incompatible, 'inventory_mismatch');
        }
    }
}
