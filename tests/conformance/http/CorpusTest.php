<?php

declare(strict_types=1);

namespace KnowKit\Tests\Conformance\Http;

use KnowKit\Domain as D;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Contract\StrictJson;
use KnowKit\Http\Client as C;
use KnowKit\Tests\Delivery\Http\Fixture as F;
use KnowKit\Tests\Retrieval\Reading\Fixture as ReadingFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Mode.php';

#[Group('delivery:client')]
final class CorpusTest extends TestCase
{
    public function testSixOutcomesAndExactReferencesUseOneCorpusInBothModes(): void
    {
        $corpus = self::corpus();
        $expectedRefs = null;
        $this->modes('search', function (Mode $mode) use ($corpus, &$expectedRefs): void {
            foreach ($corpus->get('outcomes') as $case) {
                $mode->control($case->get('scenario'));
                $response = $mode->invoke(self::call(operation: 'search', body: new JsonObject(['query' => 'gravity'])));
                self::assertSame($case->get('outcome'), $response->outcome?->value);
                self::assertSame($case->get('status'), $response->status);
                self::assertSame($case->get('errorCode'), $response->errorCode);
                self::assertSame('untrusted', $response->trustClass());
                self::assertFalse($response->memoryActivation());
                if ($response->outcome !== D\Outcome::Success) {
                    self::assertNull($response->data());
                    self::assertNull($response->evidence(F::validator()));
                    continue;
                }
                $selection = $response->selection(F::validator());
                self::assertInstanceOf(D\Selection::class, $selection);
                $refs = array_map(static fn (D\ChunkRef $ref): JsonObject => $ref->toWire(), $selection->candidateRefs());
                $expectedRefs ??= $refs;
                self::assertEquals($expectedRefs, $refs, 'Transport must preserve every exact identity field');
                self::assertSame($corpus->get('expectedDigests')->get('selectionSource'), $refs[0]->get('sourceDigest'));
                self::assertSame($corpus->get('hostScopeHash'), $selection->toWire()->get('hostScopeHash'));
            }
        });
    }

    public function testConfiguredDegradationAndSpaceMismatchSurviveBothModes(): void
    {
        $this->modes('search', function (Mode $mode): void {
            $mode->control('hybrid-degraded');
            $degraded = $mode->invoke(self::call(operation: 'search', body: new JsonObject(['query' => 'DOC-2026/0042'])));
            self::assertSame(D\Outcome::Success, $degraded->outcome);
            self::assertTrue($degraded->degraded);
            self::assertSame('lexical-v1', $degraded->effectiveProfile);
            self::assertContains('vector:vector_timeout', $degraded->warnings);
            self::assertNotEmpty($degraded->data()->get('refs'));
            $mode->control('hybrid-mismatch');
            $mismatch = $mode->invoke(self::call(operation: 'search', body: new JsonObject(['query' => 'DOC-2026/0042'])));
            self::assertSame(D\Outcome::Incompatible, $mismatch->outcome);
            self::assertSame('space_mismatch', $mismatch->errorCode);
            self::assertFalse($mismatch->degraded);
            self::assertNull($mismatch->data());
        });
    }

    public function testCachedSearchReplayCitationAndRevocationUseSameCoreStateInBothModes(): void
    {
        $pinned = null;
        $this->modes('reading', function (Mode $mode) use (&$pinned): void {
            $search = self::call(operation: 'search', body: new JsonObject(['query' => 'DOC-2026/0042']));
            $first = $mode->invoke($search)->selection(F::validator());
            $cached = $mode->invoke($search)->selection(F::validator());
            self::assertNotEquals($first->id(), $cached->id());
            self::assertEquals($first->candidateRefs(), $cached->candidateRefs());
            self::assertSame(1, $mode->control('stats')->get('hits'));
            $before = $mode->control('stats')->get('usages');
            $request = new JsonObject([
                'selectionId' => $cached->id()->value,
                'chunkRefs' => array_map(static fn (D\ChunkRef $ref): JsonObject => $ref->toWire(), $cached->candidateRefs()),
                'disclosureAttemptId' => F::id(700),
            ]);
            $call = self::call(operation: 'disclose', body: $request);
            $response = $mode->invoke($call);
            self::assertSame(D\Outcome::Success, $response->outcome, $response->errorCode ?? 'success');
            $evidence = $response->evidence(F::validator());
            self::assertSame('untrusted', $evidence->trustClass());
            self::assertFalse($evidence->memoryActivation());
            self::assertNull($evidence->checkedAt(), 'Remote evidence cannot become a local committed authorization');
            $fragment = $evidence->toWire()->get('fragments')[0];
            $citation = new D\CitationRef(value: $fragment->get('citation'), validator: F::validator());
            $citation->requireChunk($cached->candidateRefs()[0]);
            self::assertSame(self::corpus()->get('expectedDigests')->get('disclosureSource'), $citation->toWire()->get('sourceDigest'));
            $identity = $citation->toWire()->without('citationId');
            $pinned ??= $identity;
            self::assertEquals($pinned, $identity, 'Source/version/IR/spans remain exact across modes');
            self::assertSame($before + 1, $mode->control('stats')->get('usages'));
            $replay = $mode->invoke($call);
            self::assertTrue($replay->data()->get('replay'));
            self::assertNull($replay->evidence(F::validator()));
            self::assertFalse($replay->data()->has('fragments'));
            self::assertSame($before + 1, $mode->control('stats')->get('usages'));
            $resolved = $mode->invoke(self::call(operation: 'readSource', body: new JsonObject([
                'citationId' => $citation->id()->value, 'attemptId' => F::id(703),
            ])));
            self::assertSame(D\Outcome::Success, $resolved->outcome, $resolved->errorCode ?? 'success');
            self::assertEquals($citation, $resolved->citation(F::validator()));
            self::assertSame($before + 2, $mode->control('stats')->get('usages'));
            $mode->control('revoke');
            $denied = $mode->invoke(self::call(operation: 'disclose', body: $request->with('disclosureAttemptId', F::id(701))));
            self::assertSame(D\Outcome::Denied, $denied->outcome);
            self::assertNull($denied->data());
            $readDenied = $mode->invoke(self::call(operation: 'readSource', body: new JsonObject([
                'citationId' => $citation->id()->value, 'attemptId' => F::id(704),
            ]), request: 702));
            self::assertSame(D\Outcome::Denied, $readDenied->outcome);
            self::assertNull($readDenied->data());
            self::assertSame($before + 2, $mode->control('stats')->get('usages'));
            self::assertEquals($identity, $citation->toWire()->without('citationId'));
        });
    }

    private function modes(string $kind, \Closure $scenario): void
    {
        if ($kind === 'reading') {
            ReadingFixture::run(static fn (ReadingFixture $f) => $scenario(new Mode(new Host($f))));
            ReadingFixture::run(static fn (ReadingFixture $f) => $scenario(new Mode(host: new Host($f), cli: true)));
        } else {
            $scenario(new Mode(new Host()));
            $scenario(new Mode(host: new Host(), cli: true));
        }
        $remote = new Remote($kind);
        try {
            $scenario(new Mode($remote));
        } finally {
            $remote->close();
        }
    }

    public static function call(string $operation, JsonObject $body, int $request = 650, array $parameters = []): C\Call
    {
        return C\Call::operation(
            operation: $operation, body: $body, requestId: new D\Ulid(F::id($request)), parameters: $parameters,
        );
    }

    public static function corpus(): JsonObject
    {
        return StrictJson::decode(file_get_contents(__DIR__ . '/v1/cases.json'));
    }
}
