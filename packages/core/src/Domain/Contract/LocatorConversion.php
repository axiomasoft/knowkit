<?php
declare(strict_types=1);

namespace KnowKit\Domain\Contract;

/**
 * Float-free locator conversion. Donor page indexes are zero-based (DeepTutor parsing/types.py,
 * f7aed0744c36c5afef16a4261c31ebee1208cac2); KnowKit physicalNo is 1-based and printedLabel stays a
 * separate nullable string. Bboxes arrive as decimal strings (<= 6 integer and 6 fraction digits)
 * with an explicit coordinate origin and page size, and become integer millionths rounded half-even
 * once. Integer arithmetic stays below 2^63 for every accepted input.
 */
final class LocatorConversion
{
    private const SPACE = 'page-millionths-top-left-v1';

    public static function normalizeBbox(JsonObject $source): JsonObject
    {
        $origin = $source->get('coordinateOrigin');
        if ($origin !== 'top-left' && $origin !== 'bottom-left') {
            throw new ContractViolation('missing_coordinate_system');
        }
        $width = self::micro($source->get('pageWidth'));
        $height = self::micro($source->get('pageHeight'));
        [$x0, $y0, $x1, $y1] = array_map(static fn(string $key): int => self::micro($source->get($key)), ['x0', 'y0', 'x1', 'y1']);
        if ($width === 0 || $height === 0 || max($x0, $x1) > $width || max($y0, $y1) > $height) {
            throw new ContractViolation('invalid_bbox');
        }
        if ($origin === 'bottom-left') {
            [$y0, $y1] = [$height - $y1, $height - $y0];
        }
        $box = ['space' => self::SPACE, 'origin' => 'top-left', 'view' => 'rotated-page',
            'x0' => self::halfEven($x0 * 1000000, $width), 'y0' => self::halfEven($y0 * 1000000, $height),
            'x1' => self::halfEven($x1 * 1000000, $width), 'y1' => self::halfEven($y1 * 1000000, $height)];
        if (!($box['x0'] < $box['x1'] && $box['y0'] < $box['y1'])) {
            throw new ContractViolation('invalid_bbox');
        }
        return new JsonObject($box);
    }

    public static function pageIndexToLocator(JsonObject $source): JsonObject
    {
        $index = $source->get('pageIndex');
        if (!is_int($index) || $index < 0 || $index >= 4294967295) {
            throw new ContractViolation('invalid_page_index');
        }
        $label = $source->get('printedLabel');
        if ($label !== null && (!is_string($label) || $label === '')) {
            throw new ContractViolation('invalid_page_index');
        }
        $start = $source->get('byteStart');
        $end = $source->get('byteEnd');
        if (!is_int($start) || !is_int($end) || $start < 0 || $end <= $start) {
            throw new ContractViolation('invalid_span');
        }
        $locator = ['kind' => 'page', 'pageId' => $source->get('pageId'), 'physicalNo' => $index + 1,
            'printedLabel' => $label, 'blockId' => $source->get('blockId'), 'byteStart' => $start, 'byteEnd' => $end];
        if ($source->has('bbox')) {
            $box = $source->get('bbox') instanceof JsonObject ? $source->get('bbox') : new JsonObject();
            foreach (['pageWidth', 'pageHeight', 'coordinateOrigin'] as $key) {
                if ($source->has($key)) {
                    $box = $box->with($key, $source->get($key));
                }
            }
            $locator['bbox'] = self::normalizeBbox($box);
        }
        return new JsonObject($locator);
    }

    /** Resolve a half-open byte span on immutable UTF-8 text; both ends must be codepoint boundaries. */
    public static function resolveSpan(JsonObject $source): JsonObject
    {
        $text = $source->get('text');
        $start = $source->get('byteStart');
        $end = $source->get('byteEnd');
        if (!is_string($text) || !is_int($start) || !is_int($end) || $start < 0 || $end <= $start) {
            throw new ContractViolation('invalid_span');
        }
        if ($end > strlen($text)) {
            throw new ContractViolation('span_out_of_range');
        }
        foreach ([$start, $end] as $offset) {
            if ($offset < strlen($text) && (ord($text[$offset]) & 0xC0) === 0x80) {
                throw new ContractViolation('utf8_boundary');
            }
        }
        return new JsonObject(['text' => substr($text, $start, $end - $start), 'byteLength' => $end - $start]);
    }

    private static function micro(mixed $value): int
    {
        if (!is_string($value) || preg_match('/^(0|[1-9][0-9]{0,5})(?:\.([0-9]{1,6}))?$/D', $value, $match) !== 1) {
            throw new ContractViolation('invalid_decimal');
        }
        return (int) $match[1] * 1000000 + (int) str_pad($match[2] ?? '', 6, '0');
    }

    private static function halfEven(int $numerator, int $denominator): int
    {
        $quotient = intdiv($numerator, $denominator);
        $remainder = $numerator % $denominator;
        if ($remainder * 2 > $denominator || ($remainder * 2 === $denominator && $quotient % 2 === 1)) {
            $quotient++;
        }
        return $quotient;
    }
}
