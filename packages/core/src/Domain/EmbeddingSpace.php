<?php
declare(strict_types=1);

namespace KnowKit\Domain;

use KnowKit\Domain\Contract\{HashDomain, JsonObject};

readonly class EmbeddingSpace
{
    public function __construct(public string $provider, public string $model, public string $revision,
        public int $dimensions, public string $normalization, public string $indexVersion,
        public string $documentRole, public string $queryRole,
        public string $storageFormat = 'float32', public ?int $mrlSourceDimensions = null)
    {
        foreach ([$provider,$model,$revision,$indexVersion,$documentRole,$queryRole] as $text) { ValueGuard::text($text, 128); }
        if ($dimensions < 1 || $dimensions > 65536 || !in_array($normalization, ['none','l2'], true)
            || !in_array($storageFormat, ['float32', 'halfvec', 'binary_quantized'], true)
            || ($mrlSourceDimensions !== null && ($mrlSourceDimensions < 1 || $mrlSourceDimensions > 65536))) {
            throw new \InvalidArgumentException('Embedding space required');
        }
    }
    public function toWire(): JsonObject
    {
        return new JsonObject(['provider'=>$this->provider,'model'=>$this->model,'revision'=>$this->revision,
            'dimensions'=>$this->dimensions,'normalization'=>$this->normalization,'indexVersion'=>$this->indexVersion,
            'documentRole'=>$this->documentRole,'queryRole'=>$this->queryRole,
            'storageFormat'=>$this->storageFormat,'mrlSourceDimensions'=>$this->mrlSourceDimensions]);
    }
    public function digest(): Fingerprint
    {
        return new Fingerprint(HashDomain::EmbeddingSpace->digest($this->toWire()));
    }

    public function equals(self $other): bool
    {
        return $this->provider === $other->provider
            && $this->model === $other->model
            && $this->revision === $other->revision
            && $this->dimensions === $other->dimensions
            && $this->normalization === $other->normalization
            && $this->indexVersion === $other->indexVersion
            && $this->documentRole === $other->documentRole
            && $this->queryRole === $other->queryRole
            && $this->storageFormat === $other->storageFormat
            && $this->mrlSourceDimensions === $other->mrlSourceDimensions;
    }

    public function assertCompatible(self $other): void
    {
        if (!$this->digest()->equals($other->digest())) {
            throw new DomainFailure(Outcome::Incompatible, 'space_mismatch');
        }
    }
}
