<?php

declare(strict_types=1);

namespace KnowKit\Tests\Retrieval\Disclosure;

use KnowKit\Application\Chunking\StructuralChunker;
use KnowKit\Application\Access\AccessContext;
use KnowKit\Application\Access\PolicyChecks;
use KnowKit\Application\Publication\Manifest\Builder;
use KnowKit\Application\Retrieval\Disclosure\CanonicalBuffer;
use KnowKit\Application\Retrieval\Selection\CanonicalUnits;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Contract\StrictJson;
use KnowKit\Port\CanonicalDocuments;
use KnowKit\Tests\Contract\Ports\PortFixture;
use KnowKit\Tests\Retrieval\Selection\Fixture as SelectionFixture;
use KnowKit\Tests\Security\Policy\PolicyFixture;
use KnowKit\Tests\Security\Policy\Clock;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/security/policy/bootstrap.php';

#[Group('retrieval:disclosure')]
final class CanonicalTest extends TestCase
{
    public function testPdfByteOffsetsAndNormalizedMappingReturnExactCanonicalSpelling(): void
    {
        $case = self::corpus()['page'];
        $f = $this->host($case['canonicalText'], true);
        $record = $this->record($f);
        $evidence = $this->evidence($f, $record);
        $fragment = $evidence->toWire()->get('fragments')[0];
        self::assertSame($case['expectedQuote'], $fragment->get('text'));
        self::assertNotSame($case['normalized'], $fragment->get('text'));
        $citation = $fragment->get('citation');
        self::assertSame(hash('sha256', $case['rawSource']), $citation->get('sourceDigest'));
        self::assertSame(hash('sha256', $case['canonicalText']), $citation->get('spans')[0]->get('textDigest'));
        self::assertSame($case['byteStart'], $citation->get('spans')[0]->get('byteStart'));
        self::assertSame($case['byteEnd'], $citation->get('spans')[0]->get('byteEnd'));
        self::assertSame($case['expectedQuote'], substr($case['canonicalText'], $case['byteStart'], $case['byteEnd'] - $case['byteStart']));
        self::assertNotSame($case['expectedQuote'], substr($case['rawSource'], $case['byteStart'], $case['byteEnd'] - $case['byteStart']));
        foreach ($case['mapping'] as $mapping) {
            $canonical = substr($case['canonicalText'], $mapping['canonicalStart'], $mapping['canonicalEnd'] - $mapping['canonicalStart']);
            $normalized = substr($case['normalized'], $mapping['normalizedStart'], $mapping['normalizedEnd'] - $mapping['normalizedStart']);
            $normalizedCanonical = preg_replace('/\s+/u', ' ', $canonical);
            self::assertSame($normalized, $normalizedCanonical);
        }
        $representation = $evidence->searchRepresentations()[0]->toWire();
        self::assertSame('canonical-whitespace:1', $representation->get('revision'));
        self::assertSame($case['normalized'], $representation->get('normalizedText'));
        foreach ($representation->get('mapping') as $mapping) {
            self::assertNotEmpty($mapping->get('sourceRanges'));
            foreach ($mapping->get('sourceRanges') as $range) {
                self::assertSame(0, $range->get('spanIndex'));
                self::assertGreaterThanOrEqual($case['byteStart'], $range->get('byteStart'));
                self::assertLessThanOrEqual($case['byteEnd'], $range->get('byteEnd'));
            }
        }
        $f->documents = [];
        $this->failure(fn () => $this->evidence($f, $record), 'canonical_ir_unavailable', D\Outcome::Unavailable);
    }

    public function testHistoricalCitationAfterReOcrNeverRedirectsToLatest(): void
    {
        $case = self::corpus()['reOcr'];
        $old = $this->host($case['first']);
        $record = $this->record($old);
        $first = $this->evidence($old, $record);
        $digest = $first->canonical();
        $new = $this->host($case['second'], revision: 8);
        $newEvidence = $this->evidence($new, $this->record($new));
        $old->documents += $new->documents;
        $historical = $this->evidence($old, $record);
        $a = $first->toWire()->get('fragments')[0]->get('citation');
        $b = $historical->toWire()->get('fragments')[0]->get('citation');
        self::assertEquals($a->without('citationId'), $b->without('citationId'));
        self::assertSame($case['first'], $historical->toWire()->get('fragments')[0]->get('text'));
        self::assertSame($case['second'], $newEvidence->toWire()->get('fragments')[0]->get('text'));
        self::assertNotSame($a->get('documentRevisionId'), $newEvidence->toWire()->get('fragments')[0]->get('citation')->get('documentRevisionId'));
        self::assertSame($digest, $first->canonical());
        $old->documents[$a->get('irDigest')] = array_values($new->documents)[0];
        $this->failure(fn () => $this->evidence($old, $record), 'immutable_conflict', D\Outcome::Incompatible);
    }

    public function testPromptInjectionAndApprovedDocumentRemainUntrustedWithoutContextMutation(): void
    {
        $case = self::corpus()['injection'];
        foreach (['draft', $case['approvalMetadata']['status']] as $approval) {
            $f = $this->host($case['text']);
            $context = $f->identity;
            $before = serialize($context);
            $evidence = $this->evidence($f, $this->record($f));
            self::assertSame($case['text'], $evidence->toWire()->get('fragments')[0]->get('text'), $approval);
            self::assertSame($case['expectedTrust'], $evidence->trustClass());
            self::assertSame($case['expectedMemoryActivation'], $evidence->memoryActivation());
            self::assertSame($before, serialize($f->identity));
            self::assertSame($context->scope->hostScopeHash->value, $evidence->toWire()->get('hostScopeHash'));
        }
    }

    public function testGeneratedEvidenceRetainsOriginAndSupportingReferences(): void
    {
        $f = $this->host('Generated description.', generated: true);
        $evidence = $this->evidence($f, $this->record($f));
        $fragment = $evidence->toWire()->get('fragments')[0];
        self::assertSame('generated', $fragment->get('citation')->get('origin'));
        self::assertSame(['support'], $fragment->get('citation')->get('supportingRefs'));
        self::assertSame('description', $fragment->get('provenanceKind'));
        self::assertSame('untrusted', $evidence->trustClass());
    }

    public function testReadPermissionDoesNotAuthorizeExternalProviderBytes(): void
    {
        $clock = new Clock();
        $owner = PolicyFixture::owner($clock);
        $context = AccessContext::fromHost(PolicyFixture::host());
        $checks = new PolicyChecks($owner, $owner, $clock);
        $checks->prepare($context, PolicyFixture::resources(), D\Operation::Disclose, $owner->binding);
        $owner->egressDecision = D\Decision::Deny;
        $observed = [];
        $this->failure(function () use ($checks, $context, $owner, &$observed): void {
            $checks->transmit($context, PolicyFixture::request(), ['provider input'], static fn () => $owner->binding,
                static function (array $bytes) use (&$observed): void { $observed = $bytes; });
        }, 'policy_denied', D\Outcome::Denied);
        self::assertSame([], $observed);
        self::assertSame(1, $owner->egressCalls);
    }

    public function testRightsChangeBeforePublishCannotReuseExtractionAdmission(): void
    {
        $clock = new Clock();
        $owner = PolicyFixture::owner($clock);
        $context = AccessContext::fromHost(PolicyFixture::host());
        $checks = new PolicyChecks($owner, $owner, $clock);
        $checks->admission($context, PolicyFixture::resources(), D\Operation::Ingest, $owner->binding);
        $owner->decision = D\Decision::Deny;
        $this->failure(fn () => $checks->prepare($context, PolicyFixture::resources(), D\Operation::Publish, $owner->binding),
            'policy_denied', D\Outcome::Denied);
    }

    public function testDisclosureRechecksByteLimitsAndRejectsInvalidRepresentationUtf8(): void
    {
        $f = $this->host('gravity evidence');
        $record = $this->record($f);
        $query = $f->query(D\SelectionLimits::profile('compact', maxBytes: 1, maxBytesPerFragment: 1));
        $selection = new D\Selection($record->selection->toWire()->with('limits', $query->limits->toWire())
            ->with('queryDigest', $query->digest()->value), PortFixture::validator());
        $restricted = new D\SelectionRecord($selection, $record->context, $query, $record->snapshot, $record->createdAt);
        $this->failure(fn () => $this->evidence($f, $restricted), 'limit_exceeded', D\Outcome::Incompatible);
        $fragment = $this->evidence($f, $record)->toWire()->get('fragments')[0];
        $this->failure(fn () => new D\EvidenceRepresentation($fragment->with('text', "\xC3")), 'invalid_utf8', D\Outcome::Incompatible);
        $historical = new D\EvidenceSet($this->evidence($f, $record)->toWire(), PortFixture::validator());
        self::assertNull($historical->checkedAt());
        $this->failure(fn () => (new D\DisclosureDeadline($f->now, true))->requireFresh($f->now),
            'owner_freshness_unknown', D\Outcome::Unknown);
    }

    public function testUsageLineageMustMatchCanonicalEvidenceAndCurrentAttempt(): void
    {
        $f = $this->host('gravity evidence');
        $record = $this->record($f);
        $wire = $this->evidence($f, $record)->toWire();
        $request = new D\DisclosureRequest(new JsonObject(['selectionId' => $record->selection->id()->value,
            'disclosureAttemptId' => $f->next()->value,
            'chunkRefs' => array_map(static fn (D\ChunkRef $ref): JsonObject => $ref->toWire(), $record->selection->candidateRefs()),
            'usageContext' => new JsonObject(['runRef' => 'run-1'])]), PortFixture::validator());
        $buffer = new D\DisclosureBuffer($wire->get('fragments'), $record->selection->candidateRefs(), $wire->get('totalBytes'));
        $prepared = $buffer->prepare($record, $request, new D\UsageId($f->next()->value), $f->now, PortFixture::validator());
        $prepared->requireRequest($record, $request, $f->now);
        $usage = $prepared->usage->toWire();
        $fragment = $usage->get('fragmentRefs')[0]->with('fragmentDigest', str_repeat('f', 64));
        $wrong = new D\Usage($usage->with('fragmentRefs', [$fragment]), PortFixture::validator());
        $this->failure(fn () => new D\PreparedDisclosure($prepared->evidence, $wrong, $prepared->event), 'immutable_conflict', D\Outcome::Incompatible);
        $this->failure(fn () => $prepared->requireRequest($record, $request, $f->now->plusSeconds(1)), 'immutable_conflict', D\Outcome::Incompatible);
    }

    public function testDisclosureRejectsNegativeAndExcessiveTokenizerCounts(): void
    {
        $f = $this->host('gravity evidence');
        $record = $this->record($f);
        $identity = new D\TokenizerIdentity(new D\AdapterId('test.disclosure'), '1', new D\Sha256(str_repeat('c', 64)));
        $tokenizer = new class($identity) implements \KnowKit\Port\Tokenizer {
            public int $tokens = 1;

            public function __construct(private D\TokenizerIdentity $identity) {}

            public function capabilities(): D\TokenizerCapabilities
            {
                return new D\TokenizerCapabilities($this->identity, true);
            }

            public function count(string $utf8, D\TokenizerIdentity $identity): int
            {
                $this->capabilities()->requireExact($identity);
                return $this->tokens;
            }
        };
        $query = $f->query(D\SelectionLimits::profile('compact', maxTokens: 1, tokenizer: $identity));
        $selection = new D\Selection($record->selection->toWire()->with('limits', $query->limits->toWire())
            ->with('queryDigest', $query->digest()->value), PortFixture::validator());
        $restricted = new D\SelectionRecord($selection, $record->context, $query, $record->snapshot, $record->createdAt);
        self::assertSame('gravity evidence', $this->evidence($f, $restricted, $tokenizer)->toWire()->get('fragments')[0]->get('text'));
        $tokenizer->tokens = -1;
        $this->failure(fn () => $this->evidence($f, $restricted, $tokenizer), 'adapter_contract_violation', D\Outcome::Incompatible);
        $tokenizer->tokens = PHP_INT_MAX;
        $this->failure(fn () => $this->evidence($f, $restricted, $tokenizer), 'limit_exceeded', D\Outcome::Incompatible);
    }

    private static function corpus(): array
    {
        return json_decode(file_get_contents(Fixture::root() . '/tests/fixtures/evidence/v1/corpus.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    private function host(string $text, bool $page = false, int $revision = 4, bool $generated = false): SelectionFixture
    {
        $f = new SelectionFixture();
        $base = array_values($f->documents)[0]->toWire();
        $unit = new JsonObject(['text' => $text, 'textDigest' => hash('sha256', $text), 'status' => 'ok']);
        $span = new JsonObject(['kind' => 'segment', 'segmentId' => 's1', 'blockId' => 'b1', 'byteStart' => 0, 'byteEnd' => strlen($text)]);
        if ($page) {
            $case = self::corpus()['page'];
            $unit = $unit->with('pageId', 'p1')->with('physicalNo', 1)->with('printedLabel', 'i');
            $span = new JsonObject(['kind' => 'page', 'pageId' => 'p1', 'physicalNo' => 1, 'printedLabel' => 'i',
                'blockId' => 'b1', 'byteStart' => $case['byteStart'], 'byteEnd' => $case['byteEnd']]);
        }
        $block = $base->get('blocks')[0]->with('spans', [$span]);
        if ($generated) {
            $block = $block->with('origin', 'generated')->with('provenance', new JsonObject(['kind' => 'description', 'supportingRefs' => ['support']]));
            $support = $base->get('blocks')[0]->with('blockId', 'support')->with('order', 1)
                ->with('spans', [$span->with('blockId', 'support')]);
        }
        $wire = $base->with('pages', $page ? [$unit] : [])->with('segments', $page ? [] : [$unit->with('segmentId', 's1')])
            ->with('blocks', $generated ? [$block, $support] : [$block]);
        $ir = new D\IrDocument($wire, PortFixture::validator());
        $r = new D\DocumentRevisionRef(new D\SourceVersionRef($f->identity->scope->tenantId, new D\SourceId(Fixture::id(2)),
            new D\SourceVersionId(Fixture::id(3)), new D\SourceDigest(hash('sha256', $page ? self::corpus()['page']['rawSource'] : $text))),
            new D\DocumentRevisionId(Fixture::id($revision)), new D\IrDigest(hash('sha256', $ir->canonical())), PortFixture::signature($wire->get('parserSignature')));
        $set = (new StructuralChunker($r, new D\DocumentId(Fixture::id(5))))->build($ir,
            new D\ChunkerProfile('disclosure', 'structural', '1', new JsonObject(['maxBytes' => 1024])));
        $manifest = (new Builder(PortFixture::validator()))->build($f->identity->scope, new D\GenerationId(Fixture::id(14)), [$set], [], $ir->toWire()->get('coverage'));
        $f->documents = [$r->irDigest->value => $ir];
        $f->inventory = (new CanonicalUnits())->build($f->inventory->snapshot, $manifest, $f->documents);

        return $f;
    }

    private function record(SelectionFixture $f): D\SelectionRecord
    {
        $result = $f->retrieve();
        self::assertSame(D\Outcome::Success, $result->result->outcome);

        return $f->service()->requireBound($f->context(), $result->token, $f->query(), $f->inventory->snapshot, 'run-1');
    }

    private function evidence(SelectionFixture $f, D\SelectionRecord $record, ?\KnowKit\Port\Tokenizer $tokenizer = null): D\EvidenceSet
    {
        $reader = new class($f) implements CanonicalDocuments {
            public function __construct(private SelectionFixture $fixture) {}

            public function read(D\DocumentRevisionRef $revision, int $byteLimit): D\IrDocument
            {
                return $this->fixture->documents[$revision->irDigest->value]
                    ?? throw new D\DomainFailure(D\Outcome::Unavailable, 'canonical_ir_unavailable');
            }

            public function readChunk(D\ChunkRef $chunk, int $byteLimit): string
            {
                return $this->fixture->inventory->unit($chunk)->chunk->text();
            }
        };
        $request = new D\DisclosureRequest(new JsonObject(['selectionId' => $record->selection->id()->value,
            'disclosureAttemptId' => $f->next()->value, 'chunkRefs' => array_map(static fn (D\ChunkRef $ref): JsonObject => $ref->toWire(), $record->selection->candidateRefs()),
            'usageContext' => new JsonObject(['runRef' => 'run-1'])]), PortFixture::validator());
        $buffer = (new CanonicalBuffer($f, $reader, $f, $tokenizer))->preload($record, $request);

        return $buffer->prepare($record, $request, new D\UsageId($f->next()->value), $f->now, PortFixture::validator())->evidence;
    }

    private function failure(callable $call, string $code, D\Outcome $outcome): void
    {
        try {
            $call();
            self::fail('Invalid boundary accepted');
        } catch (D\DomainFailure $error) {
            self::assertSame($code, $error->errorCode);
            self::assertSame($outcome, $error->outcome);
        }
    }
}
