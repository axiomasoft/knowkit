<?php

declare(strict_types=1);

namespace KnowKit\Tests\Retrieval\Reading;

use KnowKit\Adapter\LocalBlob\Preview\BoundedObjects;
use KnowKit\Adapter\Postgres\Usages\PdoCanonicalDocuments;
use KnowKit\Application\Reading\CanonicalReads;
use KnowKit\Domain as D;
use KnowKit\Domain\Reading as R;
use KnowKit\Tests\Contract\Ports\PortFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

#[Group('retrieval:reading')]
final class CanonicalTest extends TestCase
{
    public function testMultipleSpansRetainOrderExplicitJoinerAndDigestOfActuallyProducedBytes(): void
    {
        Fixture::run(function (Fixture $f): void {
            $text = Fixture::corpus()['page'];
            $wire = $f->citation->toWire();
            $span = $wire->get('spans')[0];
            $split = strpos($text, "\n");
            $spans = [$span->with('byteStart', $split + 1)->with('byteEnd', strlen($text) - 1),
                $span->with('byteStart', 0)->with('byteEnd', $split)];
            $expected = '12 | ' . substr($text, 0, $split);
            $digest = new D\Sha256(hash('sha256', $expected));
            $citation = new D\CitationRef($wire->with('spans', $spans)->with('contentDigest', $digest->value), PortFixture::validator());
            $chunk = new D\ChunkRef($f->chunk->revisionRef, $f->chunk->chunkSetId, $f->chunk->chunkId, $digest);
            $record = new R\CitationRecord($citation, $chunk, $f->selectionId, $f->scope()->kbId, $f->scope()->hostScopeHash, ' | ', true);
            [$body] = $this->canonical($f)->preload($record, $f->request());
            self::assertSame($expected, $body->bytes());
            self::assertEquals($digest, $body->digest);
            self::assertSame(' | ', $body->metadata()->get('joiner'));
            self::assertEquals($spans, $body->metadata()->get('spans'));
            $this->failure(fn () => new R\CitationRecord($citation, $chunk, $f->selectionId,
                $f->scope()->kbId, $f->scope()->hostScopeHash, null, true), 'invalid_span');
        });
    }

    public function testChangedSpanDigestAndUtf8ContinuationOffsetCannotReturnApproximateQuote(): void
    {
        Fixture::run(function (Fixture $f): void {
            $wire = $f->citation->toWire();
            $span = $wire->get('spans')[0];
            foreach ([[$span->with('textDigest', str_repeat('0', 64)), 'digest_mismatch'],
                [$span->with('byteStart', 1), 'utf8_boundary']] as [$changed, $code]) {
                $citation = new D\CitationRef($wire->with('spans', [$changed]), PortFixture::validator());
                $record = new R\CitationRecord($citation, $f->chunk, $f->selectionId,
                    $f->scope()->kbId, $f->scope()->hostScopeHash, null, true);
                $this->failure(fn () => $this->canonical($f)->preload($record, $f->request()), $code);
            }
            $changed = new D\CitationRef($wire->with('sourceVersionId', Fixture::id(29999)), PortFixture::validator());
            $this->failure(fn () => new R\CitationRecord($changed, $f->chunk, $f->selectionId,
                $f->scope()->kbId, $f->scope()->hostScopeHash, null, true), 'immutable_conflict');
        });
    }

    private function canonical(Fixture $f): CanonicalReads
    {
        return new CanonicalReads(new PdoCanonicalDocuments($f->upstream->db(), $f->upstream->objects, PortFixture::validator()),
            $f->store, new BoundedObjects($f->upstream->objects));
    }

    private function failure(callable $action, string $code): void
    {
        try {
            $action();
            self::fail('Invalid immutable citation accepted');
        } catch (D\DomainFailure $error) {
            self::assertSame(D\Outcome::Incompatible, $error->outcome);
            self::assertSame($code, $error->errorCode);
        }
    }
}
