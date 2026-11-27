<?php
declare(strict_types=1);

namespace KnowKit\Domain\Contract;

/**
 * The single normative typed-digest table (knowledge/contracts#hash-domains, mirrored in
 * specs/contracts/hash-domains.v1.json). digest = SHA256(ASCII(domain) || 0x00 || UTF8(JCS(payload))).
 * irDigest, raw content digests, hostScopeHash and legacy release hashes are deliberately not cases.
 */
enum HashDomain: string
{
    case IndexManifest = 'knowkit:index-manifest:v1';
    case PublicationSnapshotManifest = 'knowkit:publication-snapshot:v1';
    case ChunkSetManifest = 'knowkit:chunkset-manifest:v1';
    case EmbeddingSpace = 'knowkit:embedding-space:v1';
    case DomainEventPayload = 'knowkit:event:v1';
    case WorkerResult = 'knowkit:worker-result:v1';
    case ProcessingIdentity = 'knowkit:processing-identity:v1';
    case ParserProfile = 'knowkit:parser-profile:v1';
    case ChunkerProfile = 'knowkit:chunker-profile:v1';
    case QueryBinding = 'knowkit:query-binding:v1';
    case ScopeBinding = 'knowkit:scope-binding:v1';
    case PolicyBinding = 'knowkit:policy-binding:v1';
    case DecisionReceipt = 'knowkit:decision-receipt:v1';
    case RetrievalCacheKey = 'knowkit:retrieval-cache:v1';
    case SourceIdentity = 'knowkit:source-identity:v1';
    case DependencyNodeIdentity = 'knowkit:dependency-node:v1';
    case DependencyParentSet = 'knowkit:dependency-parents:v1';
    case IdempotencyRequest = 'knowkit:command:v1';
    case EvidenceSet = 'knowkit:evidence-set:v1';
    case ResourceInventory = 'knowkit:resource-inventory:v1';
    case TombstoneSet = 'knowkit:tombstone-set:v1';
    case AnswerInvocationFingerprint = 'knowkit:answer-invocation:v1';
    case AnswerOutcomeRecord = 'knowkit:answer-outcome:v1';
    case LayerNode = 'knowkit:layer-node:v1';
    case LayerPrefix = 'knowkit:layer-prefix:v1';
    case LayerSession = 'knowkit:layer-session:v1';
    case LayerStack = 'knowkit:layer-stack:v1';
    case DirectiveArtifact = 'knowkit:directive-artifact:v1';
    case CatalogMount = 'knowkit:catalog-mount:v1';
    case ResolutionManifest = 'knowkit:resolution-manifest:v1';
    case ConceptEdgeSet = 'knowkit:concept-edge-set:v1';
    case CompiledContext = 'knowkit:compiled-context:v1';
    case TrainingExportManifest = 'knowkit:training-export-manifest:v1';

    /** Table key, e.g. indexManifest. */
    public function key(): string
    {
        return lcfirst($this->name);
    }

    public static function fromKey(string $key): self
    {
        foreach (self::cases() as $case) {
            if ($case->key() === $key) {
                return $case;
            }
        }
        throw new ContractViolation('unknown_reference');
    }

    public function digest(mixed $payload): string
    {
        return hash('sha256', $this->value . "\x00" . Jcs::canonicalize($payload));
    }
}
