<?php
declare(strict_types=1);
namespace KnowKit\Tests\Ingest\Text;

use KnowKit\Application\Ingest\Markdown\MarkdownParser;
use KnowKit\Application\Ingest\Text\TextParser;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\JsonObject;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/bootstrap.php';

#[Group('ingest:text')]
final class TextTest extends TestCase
{
    public function testVersionedTextCorpusAgainstBothRealParsers(): void
    {
        foreach (TextFixture::manifest('text')['cases'] as $case) {
            $raw = TextFixture::read($case['path']); $e = $case['expected'];
            self::assertSame($case['sourceDigest'], hash('sha256', $raw), $case['id']);
            foreach (['text' => new TextParser(TextFixture::validator()), 'markdown' => new MarkdownParser(TextFixture::validator())] as $format => $parser) {
                $source = TextFixture::source($raw, $format === 'text' ? 'text/plain' : 'text/markdown');
                $profile = TextFixture::profile($format, $case['options']);
                $result = $parser->extract($source, $profile); $wire = $result->document->toWire();
                self::assertSame($e['outcome'], $result->outcome->value, $case['id'] . ':' . $format);
                self::assertSame($e['errorCode'], $result->errorCode);
                self::assertSame($e['status'], $wire->get('segments')[0]->get('status'));
                self::assertSame($e['coverage'], $wire->get('coverage')->get('state'));
                self::assertSame([], $wire->get('pages')); self::assertCount(1, $wire->get('segments'));
                self::assertSame($raw, $source->bytes());
                self::assertEquals($profile->signature->toWire(), $wire->get('parserSignature'));
                self::assertSame($e['irDigests'][$format], hash('sha256', $result->document->canonical()));
                self::assertSame($result->document->canonical(), $parser->parse($source, $profile)->canonical());
                self::assertSame($e['canonicalDigest'], $result->sidecar()->get('normalization')->get('canonicalDigest'));
                if ($e['canonicalDigest'] !== null) {
                    self::assertSame($e['canonicalDigest'], hash('sha256', $wire->get('segments')[0]->get('text')));
                    if (isset($case['canonicalUtf8'])) { self::assertSame($case['canonicalUtf8'], $result->normalization->text); }
                } else {
                    self::assertSame([], $wire->get('blocks')); self::assertSame('', $wire->get('segments')[0]->get('text'));
                    self::assertSame('rejected', $result->sidecar()->get('normalization')->get('state'));
                    self::assertSame([], $result->sidecar()->get('normalization')->get('transforms'));
                }
                if ($e['status'] === 'empty') { self::assertSame([], $wire->get('blocks')); }
            }
        }
    }

    public function testMalformedSequencesAndExplicitEncodingNeverHeuristicallyDecode(): void
    {
        $parser = new TextParser(TextFixture::validator()); $profile = TextFixture::profile('text');
        foreach (["\xc0\xaf", "\xed\xa0\x80", "\xf4\x90\x80\x80", "\xe2\x82", "\x80"] as $bytes) {
            $r = $parser->extract(TextFixture::source($bytes), $profile);
            self::assertSame('invalid_utf8', $r->errorCode);
            self::assertSame(1, $r->document->toWire()->get('coverage')->get('counts')->get('failed'));
        }
        foreach (['text/plain; charset=shift-jis', 'text/plain; charset=utf-16'] as $media) {
            $r = $parser->extract(TextFixture::source('ASCII', $media), $profile);
            self::assertSame('unsupported_encoding', $r->errorCode); self::assertNull($r->normalization);
        }
        self::assertSame('ok', $parser->parse(TextFixture::source('ASCII', 'text/plain; charset="UTF-8"'), $profile)->toWire()->get('segments')[0]->get('status'));
        $r = $parser->extract(TextFixture::source("\xef\xbb\xbf\xef\xbb\xbfA"), $profile);
        self::assertSame("\xef\xbb\xbfA", $r->normalization->text);
    }

    public function testProfileMismatchCannotSilentlyUseDefaultsAndLimitsAreFingerprinted(): void
    {
        foreach (['text' => new TextParser(TextFixture::validator()), 'markdown' => new MarkdownParser(TextFixture::validator())] as $format => $parser) {
            $profile = TextFixture::profile($format); $source = TextFixture::source('a', 'text/' . ($format === 'text' ? 'plain' : 'markdown'));
            foreach ([$profile->options->toWire()->without('maxLines'), $profile->options->toWire()->with('ocr', true),
                $profile->options->toWire()->with('normalization', 'nfc'), $profile->options->toWire()->with('maxSourceBytes', 4194305),
                $profile->options->toWire()->with('maxLineBytes', '100')] as $options) {
                $bad = new D\ParserProfile('bad', $profile->signature->name, '1.0.0', $options);
                TextFixture::failure(fn() => $parser->parse($source, $bad), D\Outcome::Incompatible, 'unsupported_parser_profile');
            }
            foreach ([['other', '1.0.0'], [$profile->signature->name, '2.0.0']] as [$name, $version]) {
                TextFixture::failure(fn() => $parser->parse($source, new D\ParserProfile('bad', $name, $version, $profile->options->toWire())), D\Outcome::Incompatible, 'unsupported_parser_profile');
            }
            foreach (['maxSourceBytes' => 17, 'maxLineBytes' => 17, 'maxLines' => 17, 'maxBlocks' => 17, 'maxTableCells' => 17] as $key => $value) {
                self::assertNotSame($profile->signature->profileDigest->value, TextFixture::profile($format, [$key => $value])->signature->profileDigest->value);
            }
            TextFixture::failure(fn() => $parser->capabilities()->require(D\Capability::ParserOcr, 'ir-1.0'), D\Outcome::Incompatible, 'unsupported_capability');
        }
    }

    public function testSizeLimitCountsBytesAndNeverTruncatesInsideUnicode(): void
    {
        $p = new TextParser(TextFixture::validator()); $s = TextFixture::source('漢字');
        self::assertSame('漢字', $p->extract($s, TextFixture::profile('text', ['maxSourceBytes' => 6]))->normalization->text);
        $r = $p->extract($s, TextFixture::profile('text', ['maxSourceBytes' => 5]));
        self::assertSame('document_too_large', $r->errorCode); self::assertNull($r->normalization);
        self::assertSame(1, $r->document->toWire()->get('coverage')->get('counts')->get('skipped'));
        self::assertStringNotContainsString('漢', $r->display());
    }
}
