<?php

declare(strict_types=1);

namespace KnowKit\Tests\Reading\Navigation;

use KnowKit\Adapter\Intl\NfdNormalizerAdapter;
use KnowKit\Application\Chunking\CanonicalMap;
use KnowKit\Application\SourceNavigation\CanonicalDocument;
use KnowKit\Application\SourceNavigation\QuoteLocator;
use KnowKit\Application\SourceNavigation\TextQuoteSelector;
use KnowKit\Application\SourceNavigation\WhitespaceProjection;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\ContractViolation;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Contract\LocatorConversion;
use KnowKit\Domain\Navigation as N;
use KnowKit\Tests\Contract\Ports\PortFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

#[Group('reading:navigation')]
final class CanonicalTest extends TestCase
{
    public function testExactUnicodeSpansAndMultiplePagesRetainOriginalBytes(): void
    {
        [$ir, $revision, $id] = Fixture::document();
        $document = Fixture::canonical();
        $locator = new QuoteLocator();
        $spans = [];
        foreach (Fixture::corpus()['exact'] as $case) {
            $selector = new TextQuoteSelector($case['quote'], suffix: $case['quote'] === "e\u{0301}" ? ' и' : '');
            $result = $locator->locate($document, $revision, $selector, N\MatchMode::Exact, new N\Limits());
            self::assertSame('unique_exact', $result->status, $case['quote']);
            self::assertCount(1, $result->candidates);
            $span = $result->candidates[0]->span;
            self::assertSame($case['byteStart'], $span->toWire()->get('byteStart'));
            self::assertSame($case['byteEnd'], $span->toWire()->get('byteEnd'));
            $unit = array_values(array_filter($document->units, static fn (N\CanonicalUnit $unit): bool => $unit->ref->id->value === $case['unit']))[0];
            self::assertSame($case['quote'], $span->slice($unit->text()));
            self::assertSame(1, preg_match('//u', substr($unit->text(), 0, $case['byteStart'])));
            self::assertSame(1, preg_match('//u', substr($unit->text(), $case['byteEnd'])));
            if (in_array($case['quote'], ['Привет мир 😀', '中文引用', "e\u{0301}"], true)) {
                $spans[] = new D\Span($span, $unit->ref->textDigest);
            }
        }
        self::assertSame("Привет мир 😀\n中文引用\ne\u{0301}", (new CanonicalMap($ir, $revision, $id))->assembler->assemble($spans, "\n"));
        self::assertSame(1, $spans[0]->pageNo());
        self::assertSame('xii', $spans[0]->locator->toWire()->get('printedLabel'));
        self::assertSame(3, $spans[1]->pageNo());
        self::assertSame('1', $spans[1]->locator->toWire()->get('printedLabel'));
    }

    public function testWhitespaceMapsEveryRunToCanonicalUtf8WithoutUnicodeOrPunctuationFolding(): void
    {
        $projection = new WhitespaceProjection();
        $whitespace = ["\t", "\n", "\v", "\f", "\r", ' ', "\u{0085}", "\u{00A0}", "\u{1680}",
            "\u{2000}", "\u{2001}", "\u{2002}", "\u{2003}", "\u{2004}", "\u{2005}", "\u{2006}",
            "\u{2007}", "\u{2008}", "\u{2009}", "\u{200A}", "\u{2028}", "\u{2029}", "\u{202F}", "\u{205F}", "\u{3000}"];
        foreach ($whitespace as $space) {
            $original = '前😀' . $space . $space . "e\u{0301}";
            $mapped = $projection->project($original);
            self::assertSame("前😀 e\u{0301}", $mapped->text);
            self::assertSame([7, 7 + strlen($space) * 2], $mapped->canonicalInterval(7, 8));
            self::assertSame([0, strlen($original)], $mapped->canonicalInterval(0, strlen($mapped->text)));
            self::assertNull($mapped->canonicalInterval(1, 3));
        }
        foreach (["\u{200B}", "\u{FEFF}", "\u{001C}", 'A-12'] as $preserved) {
            self::assertSame($preserved, $projection->project($preserved)->text);
        }
        [$ir, $revision] = Fixture::document();
        $document = Fixture::canonical();
        $locator = new QuoteLocator();
        $case = Fixture::corpus()['whitespace'];
        self::assertSame('not_found', $locator->locate($document, $revision, new TextQuoteSelector($case['quote']), N\MatchMode::Exact, new N\Limits())->status);
        $result = $locator->locate($document, $revision, new TextQuoteSelector($case['quote']), N\MatchMode::Whitespace, new N\Limits());
        self::assertSame('candidate_normalized', $result->status);
        self::assertSame('normalized-candidate-v1', $result->candidates[0]->matchKind);
        self::assertSame($case['canonical'], $result->candidates[0]->span->slice($case['canonical']));
        self::assertSame('not_found', $locator->locate($document, $revision, new TextQuoteSelector("cafe\u{0301}"), N\MatchMode::Whitespace, new N\Limits())->status);
        foreach (['wrapword 12', 'wrap-word 12', 'DOC20260042', '10–20'] as $quote) {
            self::assertSame('not_found', $locator->locate($document, $revision, new TextQuoteSelector($quote), N\MatchMode::Whitespace, new N\Limits())->status);
        }
        self::assertSame('candidate_normalized', $locator->locate($document, $revision, new TextQuoteSelector('wrap- word 12'), N\MatchMode::Whitespace, new N\Limits())->status);
    }

    public function testRepeatedQuotesStayAmbiguousWithExplicitTruncationAndInternalContextDisambiguation(): void
    {
        [, $revision] = Fixture::document();
        $document = Fixture::canonical();
        $locator = new QuoteLocator();
        foreach ([N\MatchMode::Exact, N\MatchMode::Whitespace] as $mode) {
            $result = $locator->locate($document, $revision, new TextQuoteSelector('echo'), $mode, new N\Limits());
            self::assertSame('ambiguous', $result->status);
            self::assertTrue($result->truncated);
            self::assertCount(16, $result->candidates);
            self::assertSame(20, $result->candidates[0]->candidateCount);
        }
        $result = $locator->locate($document, $revision, new TextQuoteSelector('needle'), N\MatchMode::Exact, new N\Limits());
        self::assertSame('ambiguous', $result->status);
        self::assertFalse($result->truncated);
        $selected = $locator->locate($document, $revision, new TextQuoteSelector('needle', 'context ', ' after.'), N\MatchMode::Exact, new N\Limits());
        self::assertSame('unique_exact', $selected->status);
        self::assertEquals($result->candidates[1]->span->toWire(), $selected->candidates[0]->span->toWire());
        $p1 = $document->units[0];
        $position = $selected->candidates[0]->span->toWire()->get('byteStart');
        self::assertSame('unique_exact', $locator->locate($document, $revision,
            new TextQuoteSelector('needle', positionUnit: $p1->ref, position: $position), N\MatchMode::Exact, new N\Limits())->status);
        self::assertSame('not_found', $locator->locate($document, $revision,
            new TextQuoteSelector('needle', positionUnit: $p1->ref, position: $position + 1), N\MatchMode::Exact, new N\Limits())->status);
    }

    public function testLateWindowKeepsFullQuoteAndNeverSplitsUnicodeOrClampsRange(): void
    {
        [, $revision] = Fixture::document();
        $document = Fixture::canonical();
        $locator = new QuoteLocator();
        $quote = Fixture::corpus()['late']['quote'];
        $result = $locator->locate($document, $revision, new TextQuoteSelector($quote), N\MatchMode::Exact, new N\Limits());
        self::assertSame('unique_exact', $result->status);
        $span = $result->candidates[0]->span->toWire();
        self::assertGreaterThan(8192, $span->get('byteStart'));
        $late = array_values(array_filter($document->units, static fn (N\CanonicalUnit $u): bool => $u->ref->id->value === 'late'))[0];
        $window = $locator->window($late->text(), $span->get('byteStart'), $span->get('byteEnd'), 255);
        self::assertLessThanOrEqual(255, strlen($window['text']));
        self::assertSame(1, preg_match('//u', $window['text']));
        self::assertStringContainsString($quote, $window['text']);
        $this->rejects(fn () => $locator->window($late->text(), $span->get('byteStart'), $span->get('byteEnd'), 1), 'limit_exceeded');
        $this->rejects(fn () => $locator->window("\xff", 0, 1, 10), 'invalid_utf8');
        foreach ([[1, 25], [0, 1], [900, 899], [1, 4294967295]] as [$start, $end]) {
            $this->rejects(fn () => N\UnitRange::pages($start, $end), 'invalid_locator');
        }
        $this->rejects(fn () => new N\Limits(maxCandidates: 17), 'limit_exceeded');
        $this->rejects(fn () => new N\Limits(maxUnits: 25), 'limit_exceeded');
        $this->rejects(fn () => new N\Limits(maxRangeWidth: 25), 'limit_exceeded');
        $this->rejects(fn () => new N\Limits(maxTextBytes: 8193), 'limit_exceeded');
        $this->rejects(fn () => new N\QuoteLocation('unique_exact', $revision), 'invalid_location');
        $this->rejects(fn () => new N\QuoteLocation('not_found', $revision, $result->candidates), 'invalid_location');
        $span = $result->candidates[0]->span;
        $this->rejects(fn () => new N\QuoteCandidate($span, 'exact', 0), 'invalid_location');
        $this->rejects(fn () => new N\QuoteLocation('unique_exact', $revision,
            [new N\QuoteCandidate($span, 'exact', 2)]), 'invalid_location');
    }

    public function testCanonicalUnitHardCapRejectsBeforeMaterializingNavigation(): void
    {
        [$ir, $revision, $id] = Fixture::document();
        $segments = $ir->segments();
        $extra = 1025 - count($ir->pages()) - count($segments);
        for ($i = 0; $i < $extra; ++$i) {
            $segments[] = new JsonObject(['segmentId' => 'empty-' . $i, 'status' => 'empty',
                'text' => '', 'textDigest' => hash('sha256', '')]);
        }
        $coverage = $ir->toWire()->get('coverage');
        $coverage = $coverage->with('counts', $coverage->get('counts')->with('expected', 1025)
            ->with('processed', 1025)->with('empty', $extra + 1));
        $larger = new D\IrDocument($ir->toWire()->with('segments', $segments)->with('coverage', $coverage),
            PortFixture::validator());
        $bound = new D\DocumentRevisionRef($revision->sourceRef, $revision->documentRevisionId,
            new D\IrDigest(hash('sha256', $larger->canonical())), $revision->parserSignature);
        $this->rejects(fn () => new CanonicalDocument($larger, $bound, $id), 'canonical_unit_limit');
    }

    public function testOptionalActualIntlCapabilityMapsDecompositionAndRejectsPartialCharacters(): void
    {
        $adapter = new NfdNormalizerAdapter();
        if ($adapter->readiness() === 'failed') {
            $this->rejects(fn () => $adapter->fingerprint(), 'normalizer_unavailable');

            return;
        }
        self::assertSame('ready', $adapter->readiness());
        self::assertEquals($adapter->fingerprint(), (new NfdNormalizerAdapter())->fingerprint());
        self::assertSame("cafe\u{0301}", $adapter->project('café')->text);
        self::assertSame([3, 5], $adapter->project('café')->canonicalInterval(3, 6));
        self::assertNull($adapter->project('café')->canonicalInterval(3, 4));
        self::assertSame("e\u{0327}\u{0301}", $adapter->project("é\u{0327}")->text);
        [, $revision] = Fixture::document();
        $result = (new QuoteLocator($adapter))->locate(Fixture::canonical(), $revision,
            new TextQuoteSelector("cafe\u{0301}"), N\MatchMode::UnicodeNfd, new N\Limits());
        self::assertSame('candidate_normalized', $result->status);
        self::assertSame('unicode-nfd-intl-v1', $result->candidates[0]->matchKind);
        self::assertEquals($adapter->fingerprint(), $result->normalizerFingerprint);
        self::assertSame('not_found', (new QuoteLocator($adapter))->locate(Fixture::canonical(), $revision,
            new TextQuoteSelector('qe'), N\MatchMode::UnicodeNfd, new N\Limits())->status);
        $this->rejects(fn () => (new QuoteLocator())->locate(Fixture::canonical(), $revision,
            new TextQuoteSelector('café'), N\MatchMode::UnicodeNfd, new N\Limits()), 'normalizer_unavailable');
    }

    public function testPageConversionRequiresExplicitCoordinatesAndPreservesPrintedLabel(): void
    {
        $input = new JsonObject(['pageIndex' => 0, 'pageId' => 'p1', 'printedLabel' => 'xii', 'blockId' => 'b-p1',
            'byteStart' => 0, 'byteEnd' => 10, 'pageWidth' => '100', 'pageHeight' => '200',
            'coordinateOrigin' => 'top-left', 'bbox' => new JsonObject(['x0' => '10', 'y0' => '20', 'x1' => '50', 'y1' => '100'])]);
        $locator = LocatorConversion::pageIndexToLocator($input);
        self::assertSame(1, $locator->get('physicalNo'));
        self::assertSame('xii', $locator->get('printedLabel'));
        self::assertSame('page-millionths-top-left-v1', $locator->get('bbox')->get('space'));
        self::assertSame('rotated-page', $locator->get('bbox')->get('view'));
        self::assertSame(100000, $locator->get('bbox')->get('x0'));
        try {
            LocatorConversion::pageIndexToLocator($input->without('coordinateOrigin'));
            self::fail('Coordinates inferred');
        } catch (ContractViolation $error) {
            self::assertSame('missing_coordinate_system', $error->errorCode);
        }
    }

    private function rejects(callable $action, string $code): void
    {
        try {
            $action();
            self::fail('Invalid operation accepted');
        } catch (D\DomainFailure $failure) {
            self::assertSame($code, $failure->errorCode);
        }
    }
}
