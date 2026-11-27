<?php

declare(strict_types=1);

namespace KnowKit\Tests\Retrieval\Disclosure;

use KnowKit\Adapter\Postgres\Artifacts\PdoResolutionStore;
use KnowKit\Adapter\Postgres\Usages\PdoCanonicalDocuments;
use KnowKit\Application\Access\PolicyChecks;
use KnowKit\Application\Access\ReceiptFactory;
use KnowKit\Application\Retrieval\Disclosure\CanonicalBuffer;
use KnowKit\Application\Retrieval\Disclosure\Disclosures;
use KnowKit\Domain as D;
use KnowKit\Domain\Artifacts\DerivedArtifactRef;
use KnowKit\Domain\Artifacts\ResolutionManifest;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Layers\DisclosureMode;
use KnowKit\Domain\Layers\ResolutionTier;
use KnowKit\Port as P;
use KnowKit\Tests\Contract\Ports\PortFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

#[Group('retrieval:disclosure')]
final class MultiResolutionDisclosureTest extends TestCase
{
    public function testDisclosesR0R1R2ThroughResolutionAndBlobStoreWithProvenance(): void
    {
        Fixture::run(function (Fixture $f): void {
            $resolutions = new PdoResolutionStore($f->db(), $f->lexical->catalog->tx, $f->host);
            $this->seedManifest($f, $resolutions, [
                ResolutionTier::R0Outline->value => ['text' => 'Outline: DOC-2026/0042', 'origin' => 'deterministic', 'kind' => 'navigation_outline'],
                ResolutionTier::R1Digest->value => ['text' => 'Summary of DOC-2026/0042', 'origin' => 'generated', 'kind' => 'summary'],
                ResolutionTier::R2Propositions->value => ['text' => 'Prop: DOC-2026/0042 is active.', 'origin' => 'deterministic', 'kind' => 'proposition_set'],
            ]);

            $service = $this->disclosures($f, $resolutions, DisclosureMode::DerivedOnly);
            $selected = $f->select();

            $expected = [
                ResolutionTier::R0Outline->value => ['text' => 'Outline: DOC-2026/0042', 'origin' => 'deterministic', 'kind' => 'navigation_outline'],
                ResolutionTier::R1Digest->value => ['text' => 'Summary of DOC-2026/0042', 'origin' => 'generated', 'kind' => 'summary'],
                ResolutionTier::R2Propositions->value => ['text' => 'Prop: DOC-2026/0042 is active.', 'origin' => 'deterministic', 'kind' => 'proposition_set'],
            ];

            $f->objects->reads = 0;
            foreach ($expected as $tierValue => $spec) {
                $tier = ResolutionTier::from($tierValue);
                $request = $this->requestWithTier($f, $selected, $tier);
                $outcome = $service->disclose($f->host->context(), $selected->token, $f->query(), $f->host->inventory->snapshot, $request);

                self::assertSame(D\Outcome::Success, $outcome->result->outcome, $outcome->result->errorCode ?? 'ok');
                $evidence = $outcome->result->data;
                self::assertInstanceOf(D\EvidenceSet::class, $evidence);

                $fragment = $evidence->toWire()->get('fragments')[0];
                self::assertSame($spec['text'], $fragment->get('text'));
                self::assertSame($spec['kind'], $fragment->get('provenanceKind'));
                $citation = $fragment->get('citation');
                self::assertSame($spec['origin'], $citation->get('origin'));
                self::assertSame(hash('sha256', $spec['text']), $citation->get('contentDigest'));
                self::assertNotEmpty($citation->get('supportingRefs'));
            }
            // Only the 3 derived blobs were read; verbatim IR/chunk objects were never opened.
            self::assertSame(3, $f->objects->reads);
        });
    }

    public function testMaxDisclosureModeEnforcedBeforeLoadingAnyBytes(): void
    {
        Fixture::run(function (Fixture $f): void {
            $resolutions = new PdoResolutionStore($f->db(), $f->lexical->catalog->tx, $f->host);
            $this->seedManifest($f, $resolutions, [
                ResolutionTier::R0Outline->value => ['text' => 'Outline only', 'origin' => 'deterministic', 'kind' => 'navigation_outline'],
                ResolutionTier::R1Digest->value => ['text' => 'Summary only', 'origin' => 'generated', 'kind' => 'summary'],
            ]);

            $selected = $f->select();
            $f->objects->reads = 0;

            $derivedService = $this->disclosures($f, $resolutions, DisclosureMode::DerivedOnly);
            $r3Denied = $derivedService->disclose(
                $f->host->context(),
                $selected->token,
                $f->query(),
                $f->host->inventory->snapshot,
                $this->requestWithTier($f, $selected, ResolutionTier::R3Verbatim),
            );
            self::assertSame(D\Outcome::Denied, $r3Denied->result->outcome);
            self::assertSame('verbatim_disclosure_forbidden', $r3Denied->result->errorCode);
            self::assertSame(0, $f->objects->reads);

            $summaryService = $this->disclosures($f, $resolutions, DisclosureMode::SummaryOnly);
            $r2Denied = $summaryService->disclose(
                $f->host->context(),
                $selected->token,
                $f->query(),
                $f->host->inventory->snapshot,
                $this->requestWithTier($f, $selected, ResolutionTier::R2Propositions),
            );
            self::assertSame(D\Outcome::Denied, $r2Denied->result->outcome);
            self::assertSame('verbatim_disclosure_forbidden', $r2Denied->result->errorCode);
            self::assertSame(0, $f->objects->reads);

            $canonical = new CanonicalBuffer(
                $f->catalog,
                new PdoCanonicalDocuments($f->db(), $f->objects, PortFixture::validator()),
                $f->host,
                null,
                $resolutions,
                $f->objects,
            );
            $record = new D\SelectionRecord($selected->result->data, $f->host->context()->policy, $f->query(), $f->host->inventory->snapshot, $f->host->now);
            try {
                $canonical->preload($record, $this->requestWithTier($f, $selected, ResolutionTier::R3Verbatim), DisclosureMode::DerivedOnly);
                self::fail('Expected verbatim_disclosure_forbidden');
            } catch (D\DomainFailure $failure) {
                self::assertSame(D\Outcome::Denied, $failure->outcome);
                self::assertSame('verbatim_disclosure_forbidden', $failure->errorCode);
            }
            self::assertSame(0, $f->objects->reads);
        });
    }

    public function testMissingDerivedArtifactUnderDerivedOnlyFailsClosedNeverFallsBackToR3(): void
    {
        Fixture::run(function (Fixture $f): void {
            $resolutions = new PdoResolutionStore($f->db(), $f->lexical->catalog->tx, $f->host);
            // Seed only R0; R1 and R2 are missing.
            $this->seedManifest($f, $resolutions, [
                ResolutionTier::R0Outline->value => ['text' => 'Outline only', 'origin' => 'deterministic', 'kind' => 'navigation_outline'],
            ]);

            $service = $this->disclosures($f, $resolutions, DisclosureMode::DerivedOnly);
            $selected = $f->select();
            $f->objects->reads = 0;

            foreach ([ResolutionTier::R1Digest, ResolutionTier::R2Propositions] as $missingTier) {
                $outcome = $service->disclose(
                    $f->host->context(),
                    $selected->token,
                    $f->query(),
                    $f->host->inventory->snapshot,
                    $this->requestWithTier($f, $selected, $missingTier),
                );
                self::assertSame(D\Outcome::Incompatible, $outcome->result->outcome);
                self::assertSame('resolution_unavailable', $outcome->result->errorCode);
            }
            self::assertSame(0, $f->objects->reads);
        });
    }

    public function testCorruptedDerivedBlobFailsSha256Verification(): void
    {
        Fixture::run(function (Fixture $f): void {
            $resolutions = new PdoResolutionStore($f->db(), $f->lexical->catalog->tx, $f->host);
            $expectedText = 'Authentic R1 summary text';
            $this->seedManifest($f, $resolutions, [
                ResolutionTier::R1Digest->value => ['text' => $expectedText, 'origin' => 'generated', 'kind' => 'summary'],
            ]);
            // Tamper with the blob bytes in BlobStore while keeping the same length under the expected digest key.
            $f->objects->bodies[hash('sha256', $expectedText)] = str_repeat('X', strlen($expectedText));

            $service = $this->disclosures($f, $resolutions, DisclosureMode::DerivedOnly);
            $selected = $f->select();
            $outcome = $service->disclose(
                $f->host->context(),
                $selected->token,
                $f->query(),
                $f->host->inventory->snapshot,
                $this->requestWithTier($f, $selected, ResolutionTier::R1Digest),
            );

            self::assertSame(D\Outcome::Incompatible, $outcome->result->outcome);
            self::assertSame('digest_mismatch', $outcome->result->errorCode);
        });
    }

    public function testRevokedSourceSynchronouslyBlocksDerivedR0R1R2InCommitTransaction(): void
    {
        Fixture::run(function (Fixture $f): void {
            $resolutions = new PdoResolutionStore($f->db(), $f->lexical->catalog->tx, $f->host);
            $this->seedManifest($f, $resolutions, [
                ResolutionTier::R1Digest->value => ['text' => 'Summary before revocation', 'origin' => 'generated', 'kind' => 'summary'],
            ]);

            $checkCalls = 0;
            $inTxWhenChecked = false;
            $spyDeps = new class($f->dependencies, $f->db(), $checkCalls, $inTxWhenChecked) implements P\DependencyStore {
                public function __construct(
                    private P\DependencyStore $inner,
                    private \KnowKit\Adapter\Postgres\Catalog\Connection $db,
                    private int &$calls,
                    private bool &$inTx,
                ) {}

                public function register(D\Node $node, D\Parents $parents): void
                {
                    $this->inner->register($node, $parents);
                }

                public function checkAncestors(D\NodeRefs $refs): D\UsabilityReport
                {
                    ++$this->calls;
                    $this->inTx = $this->db->pdo->inTransaction();

                    return $this->inner->checkAncestors($refs);
                }

                public function recordUsage(D\Usage $usage): void
                {
                    $this->inner->recordUsage($usage);
                }

                public function invalidate(D\LifecycleEvent $event): void
                {
                    $this->inner->invalidate($event);
                }
            };

            $service = $this->disclosures($f, $resolutions, DisclosureMode::DerivedOnly, $spyDeps);
            $selected = $f->select();

            // Active source: checkAncestors() is invoked inside the commit transaction.
            $allowed = $service->disclose(
                $f->host->context(),
                $selected->token,
                $f->query(),
                $f->host->inventory->snapshot,
                $this->requestWithTier($f, $selected, ResolutionTier::R1Digest),
            );
            self::assertSame(D\Outcome::Success, $allowed->result->outcome);
            self::assertSame(1, $checkCalls);
            self::assertTrue($inTxWhenChecked);

            // Revoke the underlying source without changing policy epoch: R1 is synchronously blocked.
            $f->revoke(epoch: false);

            $outcome = $service->disclose(
                $f->host->context(),
                $selected->token,
                $f->query(),
                $f->host->inventory->snapshot,
                $this->requestWithTier($f, $selected, ResolutionTier::R1Digest),
            );

            self::assertSame(D\Outcome::Denied, $outcome->result->outcome);
            self::assertSame('ancestor_unusable', $outcome->result->errorCode);
        });
    }

    /**
     * @param array<string, array{text: string, origin: string, kind: string}> $tiers
     */
    private function seedManifest(Fixture $f, PdoResolutionStore $resolutions, array $tiers): void
    {
        $chunk = $f->lexical->sets['exact']->chunkSet->chunks[0];
        $revRef = $chunk->ref->revisionRef;
        $locator = $chunk->spans[0]->locator;

        $artifacts = [];
        foreach ($tiers as $tierValue => $spec) {
            $tier = ResolutionTier::from($tierValue);
            $text = $spec['text'];
            $digest = hash('sha256', $text);
            $f->objects->bodies[$digest] = $text;

            $artifacts[] = new DerivedArtifactRef(
                $tier,
                $f->host->next(),
                new D\Sha256($digest),
                strlen($text),
                max(1, str_word_count($text)),
                'cl100k',
                $spec['origin'],
                $spec['kind'],
                $f->host->next(),
                0,
                [$locator],
            );
        }

        $manifest = ResolutionManifest::create(
            $f->host->next(),
            $revRef,
            new D\Fingerprint(str_repeat('e', 64)),
            $artifacts,
            PortFixture::validator(),
        );
        $resolutions->saveManifest($manifest);
    }

    private function requestWithTier(Fixture $f, D\RetrievalOutcome $selected, ResolutionTier $tier): D\DisclosureRequest
    {
        $wire = new JsonObject([
            'selectionId' => $selected->result->data->id()->value,
            'chunkRefs' => [$f->ref()->toWire()],
            'disclosureAttemptId' => $f->host->next()->value,
            'usageContext' => new JsonObject(['runRef' => 'run-1']),
            'resolutionTier' => $tier->value,
        ]);

        return new D\DisclosureRequest($wire, PortFixture::validator());
    }

    private function disclosures(
        Fixture $f,
        PdoResolutionStore $resolutions,
        DisclosureMode $mode,
        ?P\DependencyStore $dependencies = null,
    ): Disclosures {
        $factory = new ReceiptFactory(PortFixture::validator(), $f->host);
        $policy = new class($f->owner, $factory, $mode) implements P\AccessPolicy {
            public function __construct(
                private P\AccessPolicy $inner,
                private ReceiptFactory $factory,
                private DisclosureMode $mode,
            ) {}

            public function authorizeQuery(D\Scope $scope): D\QueryGrant
            {
                return $this->inner->authorizeQuery($scope);
            }

            public function decide(D\Scope $scope, D\ResourceRef $resource, D\Operation $operation): D\DecisionReceipt
            {
                $receipt = $this->inner->decide($scope, $resource, $operation);
                $wire = $receipt->toWire();

                return $this->factory->issue(
                    D\Decision::from((string) $wire->get('decision')),
                    $scope,
                    [$resource],
                    $operation,
                    $wire->get('binding'),
                    'policy-rev-1',
                    $this->mode,
                );
            }
        };

        $checks = new PolicyChecks($policy, $f->owner, $f->host);
        $canonical = new CanonicalBuffer(
            $f->catalog,
            new PdoCanonicalDocuments($f->db(), $f->objects, PortFixture::validator()),
            $f->host,
            null,
            $resolutions,
            $f->objects,
        );

        return new Disclosures(
            $f->selections,
            $checks,
            $canonical,
            $f->usages,
            $f->host,
            PortFixture::validator(),
            $dependencies ?? $f->dependencies,
        );
    }
}
