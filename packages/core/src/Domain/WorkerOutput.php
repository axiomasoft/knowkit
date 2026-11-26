<?php

declare(strict_types=1);

namespace KnowKit\Domain;

/** Private extraction bytes; no catalog, checkpoint or publication authority. */
readonly class WorkerOutput
{
    /** @param array<string, string> $assets Relative path => original bytes. */
    public function __construct(
        public WorkerResult $result,
        public IrDocument $ir,
        public array $assets,
        public ?ParserCapabilities $capabilities = null,
    ) {
    }

    /**
     * Independently bind the transport inventory to local IR asset IDs.
     *
     * @return array<string, string>
     */
    public function assetsForIr(): array
    {
        $byDigest = [];
        $paths = [];
        foreach ($this->result->assets() as $asset) {
            $bytes = $this->assets[$asset->relativePath] ?? null;
            if ($bytes === null || isset($paths[$asset->relativePath])
                || !hash_equals($asset->digest, hash('sha256', $bytes))
                || $asset->byteLength !== (string) strlen($bytes)) {
                throw new DomainFailure(Outcome::Incompatible, 'digest_mismatch');
            }
            $paths[$asset->relativePath] = true;
            $byDigest[$asset->digest . ':' . $asset->mediaType . ':' . $asset->byteLength] = $bytes;
        }
        if (array_diff_key($this->assets, $paths) !== []) {
            throw new DomainFailure(Outcome::Incompatible, 'inventory_mismatch');
        }
        $local = [];
        foreach ($this->ir->assets() as $asset) {
            $key = $asset->get('digest') . ':' . $asset->get('mediaType') . ':' . $asset->get('byteLength');
            $local[$asset->get('assetId')] = $byDigest[$key]
                ?? throw new DomainFailure(Outcome::Incompatible, 'inventory_mismatch');
        }
        return $local;
    }

    public function __debugInfo(): array
    {
        return ['resultDigest' => $this->result->resultDigest()];
    }
}
