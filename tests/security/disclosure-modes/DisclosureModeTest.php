<?php

declare(strict_types=1);

namespace KnowKit\Tests\Security\DisclosureModes;

use KnowKit\Adapter\LocalBlob\Preview\BoundedObjects;
use KnowKit\Adapter\Postgres\Artifacts\PdoResolutionStore;
use KnowKit\Adapter\Postgres\Usages\PdoCanonicalDocuments;
use KnowKit\Application\Access\AccessContext;
use KnowKit\Application\Access\PolicyChecks;
use KnowKit\Application\Access\PreparedDecision;
use KnowKit\Application\Access\ReceiptFactory;
use KnowKit\Application\Reading\CanonicalReads;
use KnowKit\Application\Reading\Readings;
use KnowKit\Application\Retrieval\Disclosure\CanonicalBuffer;
use KnowKit\Application\Retrieval\Disclosure\Disclosures;
use KnowKit\Application\Retrieval\Disclosure\FreshDisclosure;
use KnowKit\Application\Retrieval\Disclosure\HostDisclosureContext;
use KnowKit\Application\SourceNavigation;
use KnowKit\Domain as D;
use KnowKit\Domain\Access\PolicyContext;
use KnowKit\Domain\Artifacts\DerivedArtifactRef;
use KnowKit\Domain\Artifacts\ResolutionManifest;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Layers\DisclosureMode;
use KnowKit\Domain\Layers\ResolutionTier;
use KnowKit\Domain\Navigation as N;
use KnowKit\Domain\Reading as R;
use KnowKit\Port as P;
use KnowKit\Tests\Contract\Ports\PortFixture;
use KnowKit\Tests\Reading\Navigation\Fixture as NavigationFixture;
use KnowKit\Tests\Retrieval\Disclosure\Fixture as DisclosureFixture;
use KnowKit\Tests\Retrieval\Reading\Fixture as ReadingFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/retrieval/disclosure/bootstrap.php';
require_once dirname(__DIR__, 2) . '/reading/navigation/bootstrap.php';
require_once dirname(__DIR__, 2) . '/retrieval/reading/bootstrap.php';

#[Group('security:disclosure-modes')]
final class DisclosureModeTest extends TestCase
{
    public function testReceiptFactoryAndPreparedDecisionIntersection(): void
    {
        DisclosureFixture::run(function (DisclosureFixture $f): void {
            $factory = new ReceiptFactory(PortFixture::validator(), $f->host);
            $scope = $f->host->identity->scope;
            $kb = new D\ResourceRef($scope->tenantId, 'knowledge_base', $scope->kbId);
            $src = new D\ResourceRef($scope->tenantId, 'source', $f->ref()->revisionRef->sourceRef->sourceId);

            $rKb = $factory->issue(D\Decision::Allow, $scope, [$kb], D\Operation::Disclose, $f->host->binding, 'rev-1', DisclosureMode::VerbatimAllowed);
            $rSrc = $factory->issue(D\Decision::Allow, $scope, [$src], D\Operation::Disclose, $f->host->binding, 'rev-1', DisclosureMode::DerivedOnly);
            self::assertSame(DisclosureMode::VerbatimAllowed, $rKb->maxDisclosureMode());
            self::assertSame(DisclosureMode::DerivedOnly, $rSrc->maxDisclosureMode());

            $context = AccessContext::fromHost($f->host);
            $prepared = new PreparedDecision($context, D\Operation::Disclose, [[$kb, $rKb], [$src, $rSrc]], null);
            self::assertSame(DisclosureMode::DerivedOnly, $prepared->maxDisclosureMode());
            $prepared->requireTier(ResolutionTier::R2Propositions);

            $denied = false;
            try {
                $prepared->requireTier(ResolutionTier::R3Verbatim);
            } catch (D\DomainFailure $e) {
                $denied = true;
                self::assertSame(D\Outcome::Denied, $e->outcome);
                self::assertSame('verbatim_disclosure_forbidden', $e->errorCode);
            }
            self::assertTrue($denied);

            $rIndex = $factory->issue(D\Decision::Allow, $scope, [$src], D\Operation::Disclose, $f->host->binding, 'rev-1', DisclosureMode::IndexOnly);
            $mostRestrictive = new PreparedDecision($context, D\Operation::Disclose, [[$kb, $rKb], [$src, $rIndex]], null);
            self::assertSame(DisclosureMode::IndexOnly, $mostRestrictive->maxDisclosureMode());
        });
    }

    public function testFreshDisclosureEnforcesDisclosureModeMatrixBeforeLoadingBytes(): void
    {
        $matrix = [
            [DisclosureMode::VerbatimAllowed, ResolutionTier::R0Outline, true],
            [DisclosureMode::VerbatimAllowed, ResolutionTier::R1Digest, true],
            [DisclosureMode::VerbatimAllowed, ResolutionTier::R2Propositions, true],
            [DisclosureMode::VerbatimAllowed, ResolutionTier::R3Verbatim, true],
            [DisclosureMode::DerivedOnly, ResolutionTier::R0Outline, true],
            [DisclosureMode::DerivedOnly, ResolutionTier::R1Digest, true],
            [DisclosureMode::DerivedOnly, ResolutionTier::R2Propositions, true],
            [DisclosureMode::DerivedOnly, ResolutionTier::R3Verbatim, false],
            [DisclosureMode::SummaryOnly, ResolutionTier::R0Outline, true],
            [DisclosureMode::SummaryOnly, ResolutionTier::R1Digest, true],
            [DisclosureMode::SummaryOnly, ResolutionTier::R2Propositions, false],
            [DisclosureMode::SummaryOnly, ResolutionTier::R3Verbatim, false],
            [DisclosureMode::MetadataOnly, ResolutionTier::R0Outline, true],
            [DisclosureMode::MetadataOnly, ResolutionTier::R1Digest, false],
            [DisclosureMode::MetadataOnly, ResolutionTier::R2Propositions, false],
            [DisclosureMode::MetadataOnly, ResolutionTier::R3Verbatim, false],
            [DisclosureMode::IndexOnly, ResolutionTier::R0Outline, false],
            [DisclosureMode::IndexOnly, ResolutionTier::R1Digest, false],
            [DisclosureMode::IndexOnly, ResolutionTier::R2Propositions, false],
            [DisclosureMode::IndexOnly, ResolutionTier::R3Verbatim, false],
        ];

        DisclosureFixture::run(function (DisclosureFixture $f) use ($matrix): void {
            $selected = $f->select();
            $host = new class($f, $selected->token) implements HostDisclosureContext {
                public function __construct(
                    private DisclosureFixture $fixture,
                    private D\SelectionToken $token,
                ) {}

                public function current(): ?PolicyContext
                {
                    return $this->fixture->host->identity;
                }

                public function selection(D\Scope $scope, D\SelectionId $selection): D\DisclosureBinding
                {
                    return new D\DisclosureBinding($this->token, $this->fixture->query(), $this->fixture->host->inventory->snapshot);
                }
            };

            $modeRef = new \stdClass();
            $modeRef->mode = DisclosureMode::VerbatimAllowed;
            $factory = new ReceiptFactory(PortFixture::validator(), $f->host);
            $policy = new class($f, $factory, $modeRef) implements P\AccessPolicy, P\EgressPolicy {
                public function __construct(
                    private DisclosureFixture $f,
                    private ReceiptFactory $factory,
                    private \stdClass $modeRef,
                ) {}

                public function authorizeQuery(D\Scope $scope): D\QueryGrant
                {
                    return $this->f->owner->authorizeQuery($scope);
                }

                public function decide(D\Scope $scope, D\ResourceRef $resource, D\Operation $operation): D\DecisionReceipt
                {
                    $mode = $resource->kind === 'knowledge_base' ? DisclosureMode::VerbatimAllowed : $this->modeRef->mode;

                    return $this->factory->issue(D\Decision::Allow, $scope, [$resource], $operation, $this->f->host->binding, 'rev-mode', $mode);
                }

                public function mayEgress(D\Scope $scope, D\EgressRequest $request): D\DecisionReceipt
                {
                    return $this->f->owner->mayEgress($scope, $request);
                }
            };

            $resolutions = new PdoResolutionStore($f->db(), $f->lexical->catalog->tx, $f->host);
            $chunk = $f->lexical->sets['exact']->chunkSet->chunks[0];
            $locator = $chunk->spans[0]->locator;
            $artifacts = [];
            foreach ([
                [ResolutionTier::R0Outline, 'Outline: DOC-2026/0042', 'deterministic', 'navigation_outline'],
                [ResolutionTier::R1Digest, 'Summary of DOC-2026/0042', 'generated', 'summary'],
                [ResolutionTier::R2Propositions, 'Prop: DOC-2026/0042 is active.', 'deterministic', 'proposition_set'],
            ] as [$tier, $text, $origin, $kind]) {
                $digest = hash('sha256', $text);
                $f->objects->bodies[$digest] = $text;
                $artifacts[] = new DerivedArtifactRef(
                    $tier,
                    $f->host->next(),
                    new D\Sha256($digest),
                    strlen($text),
                    max(1, str_word_count($text)),
                    'cl100k',
                    $origin,
                    $kind,
                    $f->host->next(),
                    0,
                    [$locator],
                );
            }
            $resolutions->saveManifest(ResolutionManifest::create(
                $f->host->next(),
                $chunk->ref->revisionRef,
                new D\Fingerprint(str_repeat('e', 64)),
                $artifacts,
                PortFixture::validator(),
            ));

            $canonical = new CanonicalBuffer($f->catalog, new PdoCanonicalDocuments($f->db(), $f->objects, PortFixture::validator()), $f->host, null, $resolutions, $f->objects);
            $checks = new PolicyChecks($policy, $policy, $f->host);
            $disclosures = new Disclosures($f->selections, $checks, $canonical, $f->usages, $f->host, PortFixture::validator());
            $fresh = new FreshDisclosure($disclosures, $f->usages, $host);
            $scope = $f->host->identity->scope;

            foreach ($matrix as [$mode, $tier, $allowed]) {
                $modeRef->mode = $mode;
                $wire = $f->request($selected)->toWire()->with('resolutionTier', $tier->value);
                $request = new D\DisclosureRequest($wire, PortFixture::validator());
                $readsBefore = $f->objects->reads;
                $outcome = $fresh->disclose($scope, $request);
                if ($allowed) {
                    self::assertSame(D\Outcome::Success, $outcome->result->outcome, "{$mode->value} + {$tier->value}");
                } else {
                    self::assertSame(D\Outcome::Denied, $outcome->result->outcome, "{$mode->value} + {$tier->value}");
                    self::assertSame('verbatim_disclosure_forbidden', $outcome->result->errorCode, "{$mode->value} + {$tier->value}");
                    self::assertSame($readsBefore, $f->objects->reads, "0 bytes must be read for {$mode->value} + {$tier->value}");
                }
            }

            // Default (omitted) resolutionTier must resolve to R3Verbatim and be denied under DerivedOnly with 0 bytes read.
            $modeRef->mode = DisclosureMode::DerivedOnly;
            $readsBefore = $f->objects->reads;
            $defaultOutcome = $fresh->disclose($scope, $f->request($selected));
            self::assertSame(D\Outcome::Denied, $defaultOutcome->result->outcome);
            self::assertSame('verbatim_disclosure_forbidden', $defaultOutcome->result->errorCode);
            self::assertSame($readsBefore, $f->objects->reads);
        });
    }

    public function testSourceNavigationEnforcesDisclosureModeBeforeLoadingIr(): void
    {
        NavigationFixture::run(function (NavigationFixture $f): void {
            $modeRef = new \stdClass();
            $modeRef->mode = DisclosureMode::DerivedOnly;
            $factory = new ReceiptFactory(PortFixture::validator(), $f->upstream->host);
            $policy = new class($f, $factory, $modeRef) implements P\AccessPolicy, P\EgressPolicy {
                public function __construct(
                    private NavigationFixture $f,
                    private ReceiptFactory $factory,
                    private \stdClass $modeRef,
                ) {}

                public function authorizeQuery(D\Scope $scope): D\QueryGrant
                {
                    return $this->f->upstream->owner->authorizeQuery($scope);
                }

                public function decide(D\Scope $scope, D\ResourceRef $resource, D\Operation $operation): D\DecisionReceipt
                {
                    return $this->factory->issue(D\Decision::Allow, $scope, [$resource], $operation, $this->f->upstream->host->binding, 'rev-nav', $this->modeRef->mode);
                }

                public function mayEgress(D\Scope $scope, D\EgressRequest $request): D\DecisionReceipt
                {
                    throw new \LogicException('Not used');
                }
            };

            $nav = new SourceNavigation(
                $f->upstream->host,
                $policy,
                new PolicyChecks($policy, $policy, $f->upstream->host),
                $f->store,
                new PdoCanonicalDocuments($f->upstream->db(), $f->upstream->objects, PortFixture::validator()),
                $f->upstream->host,
                $f->upstream->host,
            );
            $scope = $f->scope();
            $range = N\UnitRange::pages(1);

            foreach ([DisclosureMode::DerivedOnly, DisclosureMode::SummaryOnly, DisclosureMode::MetadataOnly] as $mode) {
                $modeRef->mode = $mode;
                $outline = $nav->listStructure($scope, $f->revision, 3, 10);
                self::assertSame(D\Outcome::Success, $outline->status, "listStructure allowed under {$mode->value}");

                $readsBefore = $f->upstream->objects->reads;
                $units = $nav->readUnits($scope, $f->revision, $range, 4096);
                self::assertSame(D\Outcome::Denied, $units->result->outcome, "readUnits denied under {$mode->value}");
                self::assertSame('verbatim_disclosure_forbidden', $units->result->errorCode);
                self::assertSame($readsBefore, $f->upstream->objects->reads, "0 IR bytes read for readUnits under {$mode->value}");

                $quote = $nav->locateQuote($scope, $f->revision, 'p1', N\MatchMode::Exact);
                self::assertSame('denied', $quote->status, "locateQuote denied under {$mode->value}");
                self::assertSame('verbatim_disclosure_forbidden', $quote->errorCode);
                self::assertSame($readsBefore, $f->upstream->objects->reads, "0 IR bytes read for locateQuote under {$mode->value}");
            }

            $modeRef->mode = DisclosureMode::IndexOnly;
            $readsBefore = $f->upstream->objects->reads;
            $outline = $nav->listStructure($scope, $f->revision, 3, 10);
            self::assertSame(D\Outcome::Denied, $outline->status);
            self::assertSame('verbatim_disclosure_forbidden', $outline->errorCode);
            self::assertSame($readsBefore, $f->upstream->objects->reads);

            $units = $nav->readUnits($scope, $f->revision, $range, 4096);
            self::assertSame(D\Outcome::Denied, $units->result->outcome);
            self::assertSame('verbatim_disclosure_forbidden', $units->result->errorCode);
            self::assertSame($readsBefore, $f->upstream->objects->reads);

            $quote = $nav->locateQuote($scope, $f->revision, 'p1', N\MatchMode::Exact);
            self::assertSame('denied', $quote->status);
            self::assertSame('verbatim_disclosure_forbidden', $quote->errorCode);
            self::assertSame($readsBefore, $f->upstream->objects->reads);
        });
    }

    public function testReadingsAndCanonicalReadsBlockDirectReadsWhenNotVerbatimAllowed(): void
    {
        ReadingFixture::run(function (ReadingFixture $f): void {
            $modeRef = new \stdClass();
            $modeRef->mode = DisclosureMode::DerivedOnly;
            $factory = new ReceiptFactory(PortFixture::validator(), $f->upstream->host);
            $policy = new class($f, $factory, $modeRef) implements P\AccessPolicy, P\EgressPolicy {
                public function __construct(
                    private ReadingFixture $f,
                    private ReceiptFactory $factory,
                    private \stdClass $modeRef,
                ) {}

                public function authorizeQuery(D\Scope $scope): D\QueryGrant
                {
                    return $this->f->upstream->owner->authorizeQuery($scope);
                }

                public function decide(D\Scope $scope, D\ResourceRef $resource, D\Operation $operation): D\DecisionReceipt
                {
                    return $this->factory->issue(D\Decision::Allow, $scope, [$resource], $operation, $this->f->upstream->host->binding, 'rev-read', $this->modeRef->mode);
                }

                public function mayEgress(D\Scope $scope, D\EgressRequest $request): D\DecisionReceipt
                {
                    throw new \LogicException('Not used');
                }
            };

            $canonical = new CanonicalReads(
                new PdoCanonicalDocuments($f->upstream->db(), $f->upstream->objects, PortFixture::validator()),
                $f->store,
                new BoundedObjects($f->upstream->objects),
            );
            $readings = new Readings(
                $f->store,
                new PolicyChecks($policy, $policy, $f->upstream->host),
                $canonical,
                $f->upstream->host,
            );
            $context = AccessContext::fromHost($f->upstream->host);

            $targets = [
                R\ReadTarget::quote(),
                R\ReadTarget::page(physicalNo: 1),
                R\ReadTarget::source(),
                R\ReadTarget::region(new D\LocalId('p1'), new D\Bbox(0, 0, 100, 100)),
            ];

            foreach ([DisclosureMode::DerivedOnly, DisclosureMode::SummaryOnly, DisclosureMode::MetadataOnly, DisclosureMode::IndexOnly] as $mode) {
                $modeRef->mode = $mode;
                foreach ($targets as $target) {
                    $readsBefore = $f->upstream->objects->reads;
                    $result = $readings->read($context, $f->request($target));
                    self::assertSame(D\Outcome::Denied, $result->outcome, "{$target->kind} denied under {$mode->value}");
                    self::assertSame('verbatim_disclosure_forbidden', $result->errorCode);
                    self::assertSame($readsBefore, $f->upstream->objects->reads, "0 bytes read for {$target->kind} under {$mode->value}");
                }
            }

            // Direct CanonicalReads::preload check with DerivedOnly throws before any I/O.
            $readsBefore = $f->upstream->objects->reads;
            $threw = false;
            try {
                $canonical->preload($f->record(), $f->request(R\ReadTarget::source()), DisclosureMode::DerivedOnly);
            } catch (D\DomainFailure $e) {
                $threw = true;
                self::assertSame(D\Outcome::Denied, $e->outcome);
                self::assertSame('verbatim_disclosure_forbidden', $e->errorCode);
            }
            self::assertTrue($threw);
            self::assertSame($readsBefore, $f->upstream->objects->reads);
        });
    }
}
