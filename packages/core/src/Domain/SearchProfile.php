<?php

declare(strict_types=1);

namespace KnowKit\Domain;

/** Trusted host policy; provider/model/space never inferred from the query. */
final readonly class SearchProfile
{
    public function __construct(
        public string $id = 'lexical-v1',
        public ?EmbeddingSpace $space = null,
        public bool $allowDegrade = false,
        public ?RerankProfile $rerank = null,
        public bool $rerankRequired = false,
        public string $lexicalBackend = 'postgres-fts-simple-v1',
    ) {
        if (!in_array($id, ['lexical-v1', 'hybrid-v1'], true)
            || (($id === 'hybrid-v1') !== ($space !== null))
            || ($rerankRequired && $rerank === null)) {
            throw new DomainFailure(Outcome::Incompatible, 'profile_invalid');
        }
        ValueGuard::text($lexicalBackend, 80);
    }

    public function mayDegrade(DomainFailure $failure, bool $required = false): bool
    {
        return $this->allowDegrade && !$required && $failure->outcome === Outcome::Unavailable;
    }
}
