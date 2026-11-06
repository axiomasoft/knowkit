<?php
declare(strict_types=1);

namespace KnowKit\Domain;

use KnowKit\Domain\Contract\{HashDomain, JsonObject};

readonly class EmbeddingSpace
{
    public function __construct(public string $provider, public string $model, public string $revision,
        public int $dimensions, public string $normalization, public string $indexVersion,
        public string $documentRole, public string $queryRole)
    {
        foreach ([$provider,$model,$revision,$indexVersion,$documentRole,$queryRole] as $text) { ValueGuard::text($text, 128); }
        if ($dimensions < 1 || $dimensions > 65536 || !in_array($normalization, ['none','l2'], true)) {
            throw new \InvalidArgumentException('Embedding space required');
        }
    }
    public function toWire(): JsonObject
    {
        return new JsonObject(['provider'=>$this->provider,'model'=>$this->model,'revision'=>$this->revision,
            'dimensions'=>$this->dimensions,'normalization'=>$this->normalization,'indexVersion'=>$this->indexVersion,
            'documentRole'=>$this->documentRole,'queryRole'=>$this->queryRole]);
    }
    public function digest(): Fingerprint { return new Fingerprint(HashDomain::EmbeddingSpace->digest($this->toWire())); }
    public function assertCompatible(self $other): void
    {
        if (!$this->digest()->equals($other->digest())) { throw new DomainFailure(Outcome::Incompatible, 'space_mismatch'); }
    }
}
