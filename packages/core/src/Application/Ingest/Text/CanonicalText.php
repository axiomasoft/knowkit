<?php
declare(strict_types=1);

namespace KnowKit\Application\Ingest\Text;

use KnowKit\Domain\{DomainFailure, Outcome, SourceBytes, SourceVersionRef};
use KnowKit\Domain\Contract\{JsonObject, LocatorConversion};

/**
 * text-v1: remove one leading UTF-8 BOM; CRLF/CR -> LF; no Unicode normalization.
 * UTF8/span/hash checks adapted from AgenTools GenericValidator (private local authority).
 * Qualification and incompatible donor rules: tests/ingest/text/evidence/P2.4-reuse.json.
 */
final readonly class CanonicalText
{
    /** @param list<JsonObject> $transforms */
    private function __construct(public string $text, public bool $bom, public array $transforms,
        private SourceVersionRef $sourceRef, private string $mediaType) {}

    public static function from(SourceBytes $source, ParserSettings $settings, string $format): self
    {
        if ($source->byteLength() > $settings->maxSourceBytes) { self::reject('document_too_large'); }
        $media = strtolower(trim($source->mediaType));
        $type = $format === 'text' ? 'text/plain' : 'text/markdown';
        if (str_contains($media, 'charset=') && preg_match('/;\s*charset=(?:utf-8|"utf-8")$/D', $media) !== 1) {
            self::reject('unsupported_encoding');
        }
        if (preg_match('~^' . preg_quote($type, '~') . '(?:;\s*charset=(?:utf-8|"utf-8"))?$~D', $media) !== 1) {
            self::reject('unsupported_media_type');
        }
        $raw = $source->bytes();
        foreach (["\xFF\xFE", "\xFE\xFF", "\x00\x00\xFE\xFF"] as $bom) {
            if (str_starts_with($raw, $bom)) { self::reject('unsupported_encoding'); }
        }
        if (preg_match('//u', $raw) !== 1) { self::reject('invalid_utf8'); }
        $bom = str_starts_with($raw, "\xEF\xBB\xBF");
        $start = $bom ? 3 : 0;
        $lines = preg_split('/\r\n|\r|\n/', substr($raw, $start));
        if ($lines === false || count($lines) > $settings->maxLines) { self::reject('line_count_limit'); }
        foreach ($lines as $line) {
            if (strlen($line) > $settings->maxLineBytes) { self::reject('line_size_limit'); }
        }
        $transforms = $bom ? [self::run('bom', 0, 3, 0, 0)] : [];
        $canonical = '';
        preg_match_all('/\r\n|\r/', $raw, $matches, PREG_OFFSET_CAPTURE, $start);
        foreach ($matches[0] as [$ending, $offset]) {
            if ($offset > $start) {
                $copy = substr($raw, $start, $offset - $start);
                $transforms[] = self::run('copy', $start, $offset, strlen($canonical), strlen($canonical) + strlen($copy));
                $canonical .= $copy;
            }
            $transforms[] = self::run(strlen($ending) === 2 ? 'crlf' : 'cr', $offset, $offset + strlen($ending),
                strlen($canonical), strlen($canonical) + 1);
            $canonical .= "\n";
            $start = $offset + strlen($ending);
        }
        if ($start < strlen($raw)) {
            $transforms[] = self::run('copy', $start, strlen($raw), strlen($canonical), strlen($canonical) + strlen($raw) - $start);
            $canonical .= substr($raw, $start);
        }
        return new self($canonical, $bom, $transforms, $source->ref, $source->mediaType);
    }

    public function assertSource(SourceBytes $source): void
    {
        $this->sourceRef->assertSame($source->ref);
        if ($this->mediaType !== $source->mediaType) { self::reject('immutable_conflict'); }
    }

    /** Exact raw-byte range for a nonempty canonical range; never a PDF byte locator. */
    public function sourceRange(int $start, int $end): JsonObject
    {
        LocatorConversion::resolveSpan(new JsonObject(['text' => $this->text, 'byteStart' => $start, 'byteEnd' => $end]));
        return new JsonObject(['sourceStart' => $this->sourceBoundary($start), 'sourceEnd' => $this->sourceBoundary($end)]);
    }

    private function sourceBoundary(int $offset): int
    {
        foreach ($this->transforms as $run) {
            if ($run->get('kind') === 'bom') { continue; }
            $a = $run->get('canonicalStart'); $b = $run->get('canonicalEnd');
            if ($offset >= $a && $offset <= $b) {
                return $offset === $b ? $run->get('sourceEnd') : $run->get('sourceStart') + $offset - $a;
            }
        }
        throw new \LogicException('Unmapped canonical boundary');
    }

    private static function run(string $kind, int $a, int $b, int $c, int $d): JsonObject
    {
        return new JsonObject(['kind' => $kind, 'sourceStart' => $a, 'sourceEnd' => $b,
            'canonicalStart' => $c, 'canonicalEnd' => $d]);
    }
    private static function reject(string $code): never { throw new DomainFailure(Outcome::Incompatible, $code); }
    /** @return array<string, mixed> */
    public function __debugInfo(): array { return ['normalization' => 'text-v1', 'byteLength' => strlen($this->text)]; }
}
