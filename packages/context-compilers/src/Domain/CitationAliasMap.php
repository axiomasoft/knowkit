<?php
declare(strict_types=1);

namespace KnowKit\ContextCompilers\Domain;

use KnowKit\Domain\CitationRef;
use KnowKit\Domain\Contract\ContractValidator;
use KnowKit\Domain\Contract\HashDomain;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Contract\SchemaSet;
use KnowKit\Domain\EvidenceSet;
use KnowKit\Domain\Fingerprint;
use KnowKit\Domain\Ulid;

final readonly class CitationAliasMap
{
    /**
     * @param array<string, CitationRef> $byAlias
     * @param array<string, string> $aliasByCitationId
     */
    private function __construct(
        private array $byAlias,
        private array $aliasByCitationId,
    ) {
    }

    public static function fromEvidenceSet(EvidenceSet $evidence, ?ContractValidator $validator = null): self
    {
        $citationValidator = $validator ?? self::fallbackCitationValidator();
        $fragments = $evidence->toWire()->get('fragments');
        if (!is_array($fragments)) {
            throw new \InvalidArgumentException('EvidenceSet fragments must be an array');
        }

        $byAlias = [];
        $aliasByCitationId = [];
        $index = 1;

        foreach ($fragments as $fragment) {
            if (!$fragment instanceof JsonObject) {
                throw new \InvalidArgumentException('EvidenceSet fragment must be a JsonObject');
            }
            $citationWire = $fragment->get('citation');
            if (!$citationWire instanceof JsonObject) {
                throw new \InvalidArgumentException('EvidenceSet fragment citation must be a JsonObject');
            }

            $citation = new CitationRef($citationWire, $citationValidator);
            $citationId = $citation->id()->value;
            if (isset($aliasByCitationId[$citationId])) {
                throw new \InvalidArgumentException('Duplicate citationId in EvidenceSet: ' . $citationId);
            }

            $alias = '#' . $index;
            $byAlias[$alias] = $citation;
            $aliasByCitationId[$citationId] = $alias;
            ++$index;
        }

        return new self($byAlias, $aliasByCitationId);
    }

    public function aliasFor(Ulid $citationId): ?string
    {
        return $this->aliasByCitationId[$citationId->value] ?? null;
    }

    public function resolve(string $alias): ?CitationRef
    {
        return $this->byAlias[$alias] ?? null;
    }

    /** @return array<string, CitationRef> */
    public function all(): array
    {
        return $this->byAlias;
    }

    public function digest(): Fingerprint
    {
        $entries = [];
        foreach ($this->byAlias as $alias => $citation) {
            $entries[] = [
                'alias' => $alias,
                'citation' => $citation->toWire(),
            ];
        }

        return new Fingerprint(HashDomain::CompiledContext->digest([
            'kind' => 'citation-alias-map:1',
            'entries' => $entries,
        ]));
    }

    private static function fallbackCitationValidator(): ContractValidator
    {
        $schemaId = 'urn:knowkit:schema:citation-ref:1.0';

        return new ContractValidator(new SchemaSet([
            $schemaId => new JsonObject([
                '$schema' => SchemaSet::DIALECT,
                '$id' => $schemaId,
                'type' => 'object',
            ]),
        ]));
    }
}
