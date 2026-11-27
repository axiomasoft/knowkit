<?php
declare(strict_types=1);

namespace KnowKit\Domain\Artifacts;

use KnowKit\Domain\{
    DocumentRevisionId,
    DocumentRevisionRef,
    DomainFailure,
    ExactSourceRef,
    Fingerprint,
    IrDigest,
    Outcome,
    ParserSignature,
    Sha256,
    SourceDigest,
    SourceId,
    SourceVersionId,
    TenantId,
    Ulid,
};
use KnowKit\Domain\Contract\{ContractValidator, ContractViolation, HashDomain, JsonObject};
use KnowKit\Domain\Layers\ResolutionTier;

final readonly class ResolutionManifest
{
    public const SCHEMA_VERSION = 'resolution-manifest:1';
    public const SCHEMA_ID = 'urn:knowkit:schema:resolution-manifest:1.0';

    /** @var array<string, DerivedArtifactRef> */
    public array $artifacts;

    /**
     * @param list<DerivedArtifactRef>|array<string, DerivedArtifactRef> $artifacts
     */
    public function __construct(
        public Ulid $manifestId,
        public DocumentRevisionRef $documentRevisionRef,
        public Fingerprint $processingDigest,
        array $artifacts,
        public Fingerprint $manifestDigest,
    ) {
        if ($artifacts === [] || count($artifacts) > 3) {
            throw new \InvalidArgumentException('ResolutionManifest requires 1..3 artifacts');
        }
        $byTier = self::indexByTier($artifacts);
        ksort($byTier, SORT_STRING);
        $this->artifacts = $byTier;

        $expected = HashDomain::ResolutionManifest->digest($this->wireWithoutDigest());
        if (!hash_equals($expected, $manifestDigest->value)) {
            throw new DomainFailure(Outcome::Incompatible, 'digest_mismatch');
        }
    }

    /**
     * @param list<DerivedArtifactRef>|array<string, DerivedArtifactRef> $artifacts
     */
    public static function create(
        Ulid $manifestId,
        DocumentRevisionRef $documentRevisionRef,
        Fingerprint $processingDigest,
        array $artifacts,
        ?ContractValidator $validator = null,
    ): self {
        $byTier = self::indexByTier($artifacts);
        ksort($byTier, SORT_STRING);

        $wireWithoutDigest = new JsonObject([
            'schemaVersion' => self::SCHEMA_VERSION,
            'manifestId' => $manifestId->value,
            'documentRevisionRef' => $documentRevisionRef->toWire(),
            'processingDigest' => $processingDigest->value,
            'artifacts' => array_values(array_map(
                static fn(DerivedArtifactRef $ref): JsonObject => $ref->toWire(),
                $byTier,
            )),
        ]);
        $digest = new Fingerprint(HashDomain::ResolutionManifest->digest($wireWithoutDigest));

        $manifest = new self(
            manifestId: $manifestId,
            documentRevisionRef: $documentRevisionRef,
            processingDigest: $processingDigest,
            artifacts: $byTier,
            manifestDigest: $digest,
        );
        if ($validator !== null) {
            $manifest->assertValid($validator);
        }
        return $manifest;
    }

    public function artifactFor(ResolutionTier $tier): ?DerivedArtifactRef
    {
        return $this->artifacts[$tier->value] ?? null;
    }

    public function assertValid(ContractValidator $validator): void
    {
        $errors = $validator->validateValue($this->toWire(), self::SCHEMA_ID);
        if ($errors !== []) {
            throw new ContractViolation($errors[0]['code'], $errors[0]['path']);
        }
    }

    public function toWire(): JsonObject
    {
        return $this->wireWithoutDigest()->with('manifestDigest', $this->manifestDigest->value);
    }

    public static function fromWire(JsonObject $wire): self
    {
        $revWire = $wire->get('documentRevisionRef');
        if (!$revWire instanceof JsonObject) {
            throw new DomainFailure(Outcome::Incompatible, 'immutable_conflict');
        }
        $sigWire = $revWire->get('parserSignature');
        if (!$sigWire instanceof JsonObject) {
            throw new DomainFailure(Outcome::Incompatible, 'immutable_conflict');
        }
        $revisionRef = new DocumentRevisionRef(
            new ExactSourceRef(
                new TenantId((string) $revWire->get('tenantId')),
                new SourceId((string) $revWire->get('sourceId')),
                new SourceVersionId((string) $revWire->get('sourceVersionId')),
                new SourceDigest((string) $revWire->get('sourceDigest')),
            ),
            new DocumentRevisionId((string) $revWire->get('documentRevisionId')),
            new IrDigest((string) $revWire->get('irDigest')),
            new ParserSignature(
                (string) $sigWire->get('name'),
                (string) $sigWire->get('version'),
                new Fingerprint((string) $sigWire->get('profileDigest')),
            ),
        );

        $artifactsRaw = $wire->get('artifacts');
        if (!is_array($artifactsRaw)) {
            throw new DomainFailure(Outcome::Incompatible, 'immutable_conflict');
        }
        $artifacts = [];
        foreach ($artifactsRaw as $item) {
            if (!$item instanceof JsonObject) {
                throw new DomainFailure(Outcome::Incompatible, 'immutable_conflict');
            }
            $artifacts[] = DerivedArtifactRef::fromWire($item);
        }

        return new self(
            manifestId: new Ulid((string) $wire->get('manifestId')),
            documentRevisionRef: $revisionRef,
            processingDigest: new Fingerprint((string) $wire->get('processingDigest')),
            artifacts: $artifacts,
            manifestDigest: new Fingerprint((string) $wire->get('manifestDigest')),
        );
    }

    private function wireWithoutDigest(): JsonObject
    {
        return new JsonObject([
            'schemaVersion' => self::SCHEMA_VERSION,
            'manifestId' => $this->manifestId->value,
            'documentRevisionRef' => $this->documentRevisionRef->toWire(),
            'processingDigest' => $this->processingDigest->value,
            'artifacts' => array_values(array_map(
                static fn(DerivedArtifactRef $ref): JsonObject => $ref->toWire(),
                $this->artifacts,
            )),
        ]);
    }

    /**
     * @param list<DerivedArtifactRef>|array<string, DerivedArtifactRef> $artifacts
     * @return array<string, DerivedArtifactRef>
     */
    private static function indexByTier(array $artifacts): array
    {
        $byTier = [];
        foreach ($artifacts as $artifact) {
            $key = $artifact->tier->value;
            if (isset($byTier[$key])) {
                throw new \InvalidArgumentException('Duplicate artifact tier in ResolutionManifest');
            }
            $byTier[$key] = $artifact;
        }
        ksort($byTier, SORT_STRING);
        return $byTier;
    }
}
