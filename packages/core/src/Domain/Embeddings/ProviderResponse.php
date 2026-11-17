<?php

declare(strict_types=1);

namespace KnowKit\Domain\Embeddings;

use KnowKit\Domain\DomainFailure;
use KnowKit\Domain\EmbeddingBatch;
use KnowKit\Domain\Fingerprint;
use KnowKit\Domain\Outcome;
use KnowKit\Domain\SpaceRef;
use KnowKit\Domain\Vector;
use KnowKit\Domain\Contract\Jcs;

/** Untrusted adapter output; validation happens before local acceptance. */
final readonly class ProviderResponse
{
    /** @param array<array-key, mixed> $vectors */
    public function __construct(
        public SpaceRef $space,
        public string $role,
        public array $vectors,
        public ?string $providerRequestId = null,
    ) {
        RequestReceipt::assertRequestId($providerRequestId);
    }

    public function validated(SpaceRef $expected, string $role, int $count): EmbeddingBatch
    {
        $expected->assertCompatible($this->space);
        if ($role !== $this->role || !array_is_list($this->vectors) || count($this->vectors) !== $count) {
            throw new DomainFailure(Outcome::Incompatible, 'adapter_contract_violation');
        }
        $vectors = [];
        foreach ($this->vectors as $values) {
            if (!is_array($values)) {
                throw new DomainFailure(Outcome::Incompatible, 'adapter_contract_violation');
            }
            /** @var list<float|int> $values */
            $vectors[] = new Vector($values, $expected);
        }

        return new EmbeddingBatch($vectors, $expected);
    }

    public static function fingerprint(EmbeddingBatch $batch, string $role): Fingerprint
    {
        // JCS wire profile deliberately excludes floats. Canonical IEEE-754 strings
        // retain coordinate identity and make integer/float equivalents identical.
        $vectors = [];
        foreach ($batch->vectors as $vector) {
            $vectors[] = array_map(
                static fn(int|float $v): string => bin2hex(pack('E', (float) $v === 0.0 ? 0.0 : (float) $v)),
                $vector->values,
            );
        }

        return new Fingerprint(hash('sha256', "knowkit:embedding-result:v1\0" . Jcs::canonicalize([
            'space' => $batch->space->digest()->value,
            'role' => $role,
            'vectors' => $vectors,
        ])));
    }

    public function __debugInfo(): array
    {
        return ['space' => $this->space->digest()->value, 'role' => $this->role, 'count' => count($this->vectors)];
    }
}
