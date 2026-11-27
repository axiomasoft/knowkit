<?php
declare(strict_types=1);

namespace KnowKit\Tests\Artifacts\Pyramid;

require_once dirname(__DIR__, 2) . '/storage/catalog/bootstrap.php';

use KnowKit\Adapter\Postgres\Artifacts\PdoResolutionStore;
use KnowKit\Adapter\Postgres\Catalog\{Connection, Migrate, PdoCatalogTransaction};
use KnowKit\Application\Artifacts\{AtomicSpanGuard, ResolutionAcceptance};
use KnowKit\Domain\{
    Decision,
    DocumentRevisionId,
    DocumentRevisionRef,
    DomainFailure,
    ExactSourceRef,
    Fingerprint,
    IrDigest,
    IrDocument,
    LifecycleEvent,
    LocalId,
    Node,
    NodeRefs,
    Outcome,
    Parents,
    ParserSignature,
    Sha256,
    SourceDigest,
    SourceId,
    SourceVersionId,
    TenantId,
    TextLocator,
    Ulid,
    UsabilityReport,
    Usage,
};
use KnowKit\Domain\Artifacts\{
    DerivedArtifactRef,
    DigestArtifact,
    DigestBlock,
    ProtectedAtom,
    ResolutionManifest,
};
use KnowKit\Domain\Contract\{ContractValidator, JsonObject, SchemaSet, StrictJson};
use KnowKit\Domain\Layers\ResolutionTier;
use KnowKit\Port\DependencyStore;
use KnowKit\Tests\Storage\Catalog\{CatalogFixture, TestClock};
use KnowKit\Tests\Tooling\DatabaseSandbox;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('artifacts:pyramid')]
final class PdoResolutionStoreTest extends TestCase
{
    private function validator(): ContractValidator
    {
        static $validator = null;
        if ($validator !== null) {
            return $validator;
        }
        $root = dirname(__DIR__, 3);
        $schemas = [];
        foreach (['common', 'document-ir', 'resolution-manifest'] as $name) {
            $doc = StrictJson::decode((string) file_get_contents($root . '/schemas/' . $name . '.schema.json'), 1048576);
            $schemas[(string) $doc->get('$id')] = $doc;
        }
        return $validator = new ContractValidator(new SchemaSet($schemas));
    }

    public function testAcceptanceSavesAndFindsArtifactsAndRejectsConflicts(): void
    {
        CatalogFixture::run(function (CatalogFixture $fx): void {
            $db = $fx->db;
            $tx = $fx->tx;
            $clock = $fx->clock;
            $store = new PdoResolutionStore($db, $tx, $clock);

            $tenantId = new TenantId(CatalogFixture::id(1));
            $sourceId = new SourceId(CatalogFixture::id(2));
            $versionId = new SourceVersionId(CatalogFixture::id(3));
            $resourceId = CatalogFixture::id(4);
            $revisionId = new DocumentRevisionId(CatalogFixture::id(5));
            $now = $clock->now()->value;
            $digestA = str_repeat('a', 64);
            $digestB = str_repeat('b', 64);
            $digestC = str_repeat('c', 64);

            $blobResourceId = CatalogFixture::id(6);
            $tx->run(static function () use ($db, $tenantId, $sourceId, $versionId, $resourceId, $blobResourceId, $revisionId, $now, $digestA, $digestB, $digestC): void {
                $db->execute('INSERT INTO ' . $db->table('tenants') . ' (id, name, tenant_policy_epoch, serving_state) VALUES (?, ?, 1, ?)', [$tenantId->value, 'Tenant One', 'ready']);
                $db->execute(
                    'INSERT INTO ' . $db->table('sources') . ' (tenant_id, id, owner_ref, origin, lifecycle_state, lifecycle_epoch, source_policy_epoch) VALUES (?, ?, ?, ?, ?, 1, 1)',
                    [$tenantId->value, $sourceId->value, 'owner-1', 'local-fs', 'active'],
                );
                $db->execute(
                    'INSERT INTO ' . $db->table('resource_ledger') . ' (tenant_id, id, source_id, owner_kind, storage_id, object_version, digest, byte_length, state, resource_class) VALUES (?, ?, ?, ?, ?, ?, ?, 1024, ?, ?)',
                    [$tenantId->value, $blobResourceId, $sourceId->value, 'owned', 'local', 'v1', $digestA, 'accepted', 'original'],
                );
                $db->execute(
                    'INSERT INTO ' . $db->table('resource_ledger') . ' (tenant_id, id, source_id, owner_kind, storage_id, object_version, digest, byte_length, state, resource_class) VALUES (?, ?, ?, ?, ?, ?, ?, 1024, ?, ?)',
                    [$tenantId->value, $resourceId, $sourceId->value, 'owned', 'local', 'v1', $digestB, 'accepted', 'ir'],
                );
                $db->execute(
                    'INSERT INTO ' . $db->table('source_versions') . ' (tenant_id, id, source_id, blob_resource_id, source_digest, byte_length, media_type, rights_ref, idempotency_fingerprint, created_at) VALUES (?, ?, ?, ?, ?, 1024, ?, ?, ?, ?)',
                    [$tenantId->value, $versionId->value, $sourceId->value, $blobResourceId, $digestA, 'text/markdown', 'internal', $digestA, $now],
                );
                $db->execute(
                    'INSERT INTO ' . $db->table('document_revisions') . ' (tenant_id, id, source_version_id, parser_name, parser_version, profile_digest, ir_schema, ir_digest, ir_resource_id, coverage_state, coverage_json, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb, ?)',
                    [$tenantId->value, $revisionId->value, $versionId->value, 'pdf-parser', '1.0.0', $digestC, 'ir-1.0', $digestB, $resourceId, 'complete', '{"state":"complete","counts":{"processed":1,"empty":0,"failed":0,"skipped":0},"missingFeatures":[],"diagnostics":[]}', $now],
                );
            });

            $formula = '$$ a^2 + b^2 = c^2 $$';
            $irWire = StrictJson::decode((string) json_encode([
                'schemaVersion' => 'ir-1.0',
                'tenantId' => $tenantId->value,
                'sourceVersionId' => $versionId->value,
                'parserSignature' => ['name' => 'pdf-parser', 'version' => '1.0.0', 'profileDigest' => $digestC],
                'pages' => [],
                'segments' => [
                    ['segmentId' => 'seg-1', 'text' => $formula, 'textDigest' => hash('sha256', $formula), 'status' => 'ok'],
                ],
                'blocks' => [
                    [
                        'blockId' => 'b-f1',
                        'kind' => 'formula',
                        'parentId' => null,
                        'order' => 1,
                        'origin' => 'source',
                        'spans' => [['kind' => 'segment', 'segmentId' => 'seg-1', 'blockId' => 'b-f1', 'byteStart' => 0, 'byteEnd' => strlen($formula)]],
                        'provenance' => ['kind' => 'verbatim', 'supportingRefs' => []],
                    ],
                ],
                'assets' => [],
                'coverage' => [
                    'state' => 'complete',
                    'counts' => ['expected' => 1, 'processed' => 1, 'empty' => 0, 'failed' => 0, 'skipped' => 0],
                    'missingFeatures' => [],
                    'diagnostics' => [],
                ],
            ], JSON_THROW_ON_ERROR));
            \assert($irWire instanceof JsonObject);
            $ir = new IrDocument($irWire, $this->validator());

            $revRef = new DocumentRevisionRef(
                new ExactSourceRef($tenantId, $sourceId, $versionId, new SourceDigest($digestA)),
                $revisionId,
                new IrDigest($digestB),
                new ParserSignature('pdf-parser', '1.0.0', new Fingerprint($digestC)),
            );

            $locator = TextLocator::segment(new LocalId('seg-1'), new LocalId('b-f1'), 0, strlen($formula));
            $fAtom = ProtectedAtom::fromContent(new LocalId('b-f1'), 'formula', $formula, $locator);
            $digestArtifact = new DigestArtifact($revRef, [new DigestBlock(new LocalId('d-1'), 'Pythagoras', [$fAtom], [$locator])]);

            $r0 = new DerivedArtifactRef(
                ResolutionTier::R0Outline,
                new Ulid(CatalogFixture::id(10)),
                new Sha256(str_repeat('1', 64)),
                120,
                30,
                'cl100k',
                'deterministic',
                'navigation_outline',
                new Ulid(CatalogFixture::id(11)),
                0,
                [$locator],
            );
            $r1 = new DerivedArtifactRef(
                ResolutionTier::R1Digest,
                new Ulid(CatalogFixture::id(12)),
                $digestArtifact->digest(),
                240,
                60,
                'cl100k',
                'generated',
                'summary',
                new Ulid(CatalogFixture::id(13)),
                1,
                [$locator],
            );

            $manifest = ResolutionManifest::create(
                new Ulid(CatalogFixture::id(20)),
                $revRef,
                new Fingerprint(str_repeat('d', 64)),
                [$r0, $r1],
                $this->validator(),
            );

            $depStore = new class implements DependencyStore {
                public ?Node $registeredNode = null;
                public ?Parents $registeredParents = null;
                public function register(Node $node, Parents $parents): void
                {
                    $this->registeredNode = $node;
                    $this->registeredParents = $parents;
                }
                public function checkAncestors(NodeRefs $refs): UsabilityReport
                {
                    return new UsabilityReport(Decision::Allow, new NodeRefs($refs->tenantId, []));
                }
                public function recordUsage(Usage $usage): void {}
                public function invalidate(LifecycleEvent $event): void {}
            };

            $acceptance = new ResolutionAcceptance(
                $this->validator(),
                new AtomicSpanGuard(),
                $depStore,
                $store,
                null,
                $tx,
            );

            $acceptance->accept($manifest, $ir, $digestArtifact);

            self::assertNotNull($depStore->registeredNode);
            self::assertCount(2, $depStore->registeredParents->refs);
            self::assertSame('source_version', $depStore->registeredParents->refs[0]->kind);
            self::assertSame('document_revision', $depStore->registeredParents->refs[1]->kind);

            $foundR0 = $store->findArtifact($tenantId, $revisionId, ResolutionTier::R0Outline);
            $foundR1 = $store->findArtifact($tenantId, $revisionId, ResolutionTier::R1Digest);
            $foundR2 = $store->findArtifact($tenantId, $revisionId, ResolutionTier::R2Propositions);
            $foundR3 = $store->findArtifact($tenantId, $revisionId, ResolutionTier::R3Verbatim);

            self::assertNotNull($foundR0);
            self::assertSame($r0->artifactId->value, $foundR0->artifactId->value);
            self::assertCount(1, $foundR0->supportingLocators);
            self::assertNotNull($foundR1);
            self::assertSame($r1->blobDigest->value, $foundR1->blobDigest->value);
            self::assertNull($foundR2);
            self::assertNull($foundR3);

            // Idempotent re-save succeeds
            $store->saveManifest($manifest);

            // Conflicting manifest for same (tenant, revision, processingDigest) fails
            $conflicting = ResolutionManifest::create(
                new Ulid(CatalogFixture::id(21)),
                $revRef,
                new Fingerprint(str_repeat('d', 64)),
                [$r0],
                $this->validator(),
            );
            try {
                $store->saveManifest($conflicting);
                self::fail('Expected immutable_conflict');
            } catch (DomainFailure $failure) {
                self::assertSame(Outcome::Incompatible, $failure->outcome);
                self::assertSame('immutable_conflict', $failure->errorCode);
            }
        });
    }
}
