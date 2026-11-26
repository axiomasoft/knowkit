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
        $offset = $bom ? 3 : 0;
        $rawLength = strlen($raw);
        $transforms = $bom ? [self::run('bom', 0, 3, 0, 0)] : [];
        $canonical = '';
        $copyFrom = $offset;
        $lineFrom = $offset;
        $lineCount = 0;
        $lineTooLong = false;
        $i = $offset;
        // One pass: LF stays in the copy run; CR and CRLF become their own runs. Line-count rejects before line-size.
        while ($i < $rawLength) {
            $i += strcspn($raw, "\r\n", $i);
            if ($i >= $rawLength) {
                break;
            }
            if ($i - $lineFrom > $settings->maxLineBytes) {
                $lineTooLong = true;
            }
            $lineCount++;
            if ($raw[$i] === "\n") {
                $lineFrom = $i + 1;
                $i++;
                continue;
            }
            $width = ($i + 1 < $rawLength && $raw[$i + 1] === "\n") ? 2 : 1;
            if ($i > $copyFrom) {
                $copyLen = $i - $copyFrom;
                $transforms[] = self::run('copy', $copyFrom, $i, strlen($canonical), strlen($canonical) + $copyLen);
                $canonical .= substr($raw, $copyFrom, $copyLen);
            }
            $transforms[] = self::run($width === 2 ? 'crlf' : 'cr', $i, $i + $width, strlen($canonical), strlen($canonical) + 1);
            $canonical .= "\n";
            $i += $width;
            $copyFrom = $i;
            $lineFrom = $i;
        }
        if ($rawLength - $lineFrom > $settings->maxLineBytes) {
            $lineTooLong = true;
        }
        $lineCount++;
        if ($lineCount > $settings->maxLines) {
            self::reject('line_count_limit');
        }
        if ($lineTooLong) {
            self::reject('line_size_limit');
        }
        if ($copyFrom < $rawLength) {
            $copyLen = $rawLength - $copyFrom;
            $transforms[] = self::run('copy', $copyFrom, $rawLength, strlen($canonical), strlen($canonical) + $copyLen);
            $canonical .= substr($raw, $copyFrom, $copyLen);
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
        $runs = $this->transforms;
        $lo = 0;
        $hi = count($runs) - 1;
        $found = null;
        while ($lo <= $hi) {
            $mid = intdiv($lo + $hi, 2);
            $run = $runs[$mid];
            if ($run->get('kind') === 'bom') {
                $lo = $mid + 1;
                continue;
            }
            $start = $run->get('canonicalStart');
            $end = $run->get('canonicalEnd');
            if ($offset < $start) {
                $hi = $mid - 1;
                continue;
            }
            if ($offset > $end) {
                $lo = $mid + 1;
                continue;
            }
            // A boundary belongs to the earlier run, matching a forward scan.
            $found = $run;
            $hi = $mid - 1;
        }
        if (!$found instanceof JsonObject) {
            throw new \LogicException('Unmapped canonical boundary');
        }
        return $offset === $found->get('canonicalEnd')
            ? $found->get('sourceEnd')
            : $found->get('sourceStart') + $offset - $found->get('canonicalStart');
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
