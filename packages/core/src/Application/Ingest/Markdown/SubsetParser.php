<?php
declare(strict_types=1);

namespace KnowKit\Application\Ingest\Markdown;

use KnowKit\Application\Ingest\Text\{ParserSettings, TextParser};
use KnowKit\Domain\Contract\JsonObject;

/** Per-extraction state. Every block spans the original canonical syntax, including delimiters. */
final class SubsetParser
{
    /** @var list<JsonObject> */
    private array $blocks = [];
    /** @var list<JsonObject> */
    private array $structure = [];
    /** @var array<string, true> */
    private array $diagnostics = [];
    /** @var list<array{level:int,id:string}> */
    private array $headings = [];
    /** @var list<array{indent:int,id:string,ordered:bool,listId:string}> */
    private array $lists = [];
    private int $cells = 0;

    public function __construct(private readonly ParserSettings $settings) {}

    /** @return array{list<JsonObject>,list<JsonObject>,list<string>} */
    public function parse(string $text): array
    {
        $lines = explode("\n", $text); $offsets = []; $offset = 0;
        foreach ($lines as $line) { $offsets[] = $offset; $offset += strlen($line) + 1; }
        $n = count($lines);
        for ($i = 0; $i < $n;) {
            $line = $lines[$i];
            if (trim($line, " \t") === '') { $this->lists = []; $i++; continue; }
            if (count($this->blocks) >= $this->settings->maxBlocks) {
                // Keep the entire canonical segment; never pretend the parsed prefix is complete.
                $this->diagnostics['block_count_limit'] = true;
                break;
            }
            $start = $offsets[$i];
            $endAt = static fn(int $j): int => min(strlen($text), $offsets[$j] + strlen($lines[$j]) + 1);
            if (preg_match('/^ {0,3}(#{1,6})(?:[ \t]+(.*)|$)/D', $line, $m) === 1) {
                $level = strlen($m[1]);
                $this->diagnoseLiteral($m[2] ?? '');
                while ($this->headings !== [] && $this->headings[array_key_last($this->headings)]['level'] >= $level) { array_pop($this->headings); }
                $id = $this->add('heading', 'heading', $start, $endAt($i), ['level' => $level]);
                $this->headings[] = ['level' => $level, 'id' => $id];
                $this->lists = []; $i++; continue;
            }
            if (preg_match('/^ {0,3}(`{3,}|~{3,})([^\r\n]*)$/D', $line, $m) === 1
                && ($m[1][0] !== '`' || !str_contains($m[2], '`'))) {
                $j = $i + 1; $closed = false;
                $close = '/^ {0,3}' . preg_quote($m[1][0], '/') . '{' . strlen($m[1]) . ',}[ \t]*$/D';
                while ($j < $n) {
                    if (preg_match($close, $lines[$j]) === 1) { $closed = true; break; }
                    $j++;
                }
                if (!$closed) { $this->diagnostics['unclosed_code_fence'] = true; $j = $n - 1; }
                $this->add('code', 'code', $start, $endAt($j), [
                    'fence' => $m[1], 'info' => trim($m[2]), 'closed' => $closed,
                    'contentStart' => $endAt($i), 'contentEnd' => $closed ? $offsets[$j] : strlen($text)]);
                $this->lists = []; $i = $j + 1; continue;
            }
            $header = $this->row($line, $start);
            $separator = $i + 1 < $n ? $this->row($lines[$i + 1], $offsets[$i + 1]) : null;
            if ($header !== null && $separator !== null && count($header) === count($separator)
                && $this->separator($separator)) {
                $rows = [$header]; $j = $i + 2; $malformed = false;
                while ($j < $n && trim($lines[$j], " \t") !== '') {
                    $row = $this->row($lines[$j], $offsets[$j]);
                    if ($row === null) { break; }
                    if (count($row) !== count($header)) { $malformed = true; }
                    $rows[] = $row; $j++;
                }
                $count = count($rows) * count($header);
                if ($malformed || $this->cells + $count > $this->settings->maxTableCells) {
                    $this->diagnostics[$malformed ? 'malformed_pipe_table' : 'table_cell_limit'] = true;
                    $this->add('text', 'paragraph', $start, $endAt($j - 1));
                } else {
                    $id = $this->nextId(); $cells = [];
                    foreach ($rows as $r => $row) {
                        foreach ($row as $c => $cell) {
                            $this->diagnoseLiteral($cell['text']);
                            $cells[] = new JsonObject(['cellId' => $id . '.r' . $r . 'c' . $c,
                                'row' => $r, 'column' => $c, 'rowSpan' => 1, 'colSpan' => 1, 'header' => $r === 0,
                                'headerRefs' => $r === 0 ? [] : [$id . '.r0c' . $c], 'unit' => null, 'noteRefs' => [],
                                'spans' => $cell['start'] === $cell['end'] ? [] : [TextParser::span($id, $cell['start'], $cell['end'])]]);
                        }
                    }
                    $this->add('table', 'table', $start, $endAt($j - 1), ['alignment' => array_map(
                        static fn(array $cell): string => str_starts_with($cell['text'], ':')
                            ? (str_ends_with($cell['text'], ':') ? 'center' : 'left')
                            : (str_ends_with($cell['text'], ':') ? 'right' : 'none'), $separator)]);
                    $last = array_key_last($this->blocks);
                    $this->blocks[$last] = $this->blocks[$last]->with('table', new JsonObject([
                        'rows' => count($rows), 'columns' => count($header), 'cells' => $cells]));
                    $this->cells += $count;
                }
                $this->lists = []; $i = $j; continue;
            }
            if (preg_match('/^( *)([-+*]|[0-9]{1,9}[.)])[ \t]+(.*)$/D', $line, $m) === 1) {
                $indent = strlen($m[1]); $ordered = strlen($m[2]) > 1;
                $this->diagnoseLiteral($m[3]);
                while ($this->lists !== [] && $this->lists[array_key_last($this->lists)]['indent'] > $indent) { array_pop($this->lists); }
                $previous = $this->lists === [] ? null : $this->lists[array_key_last($this->lists)];
                $listId = $previous !== null && $previous['indent'] === $indent && $previous['ordered'] === $ordered
                    ? $previous['listId'] : 'list-' . $this->nextId();
                if ($previous !== null && $previous['indent'] === $indent) { array_pop($this->lists); }
                $parent = $this->lists === [] ? $this->headingParent() : $this->lists[array_key_last($this->lists)]['id'];
                $id = $this->add('text', 'list_item', $start, $endAt($i), ['listId' => $listId, 'indent' => $indent,
                    'ordered' => $ordered, 'marker' => $m[2], 'ordinal' => $ordered ? substr($m[2], 0, -1) : null], $parent);
                $this->lists[] = ['indent' => $indent, 'id' => $id, 'ordered' => $ordered, 'listId' => $listId];
                $i++; continue;
            }
            $j = $i + 1;
            while ($j < $n && trim($lines[$j], " \t") !== '' && !$this->startsBlock($lines, $j, $offsets)) { $j++; }
            for ($k = $i; $k < $j; $k++) { $this->diagnoseLiteral($lines[$k]); }
            $this->add('text', 'paragraph', $start, $endAt($j - 1));
            $this->lists = []; $i = $j;
        }
        return [$this->blocks, $this->structure, array_keys($this->diagnostics)];
    }

    private function nextId(): string { return 'b' . (count($this->blocks) + 1); }
    private function headingParent(): ?string { return $this->headings === [] ? null : $this->headings[array_key_last($this->headings)]['id']; }

    /** @param array<string, mixed> $meta */
    private function add(string $kind, string $syntax, int $start, int $end, array $meta = [], ?string $parent = null): string
    {
        $id = $this->nextId();
        $this->blocks[] = TextParser::block($id, $kind, $parent ?? $this->headingParent(), count($this->blocks), $start, $end);
        $this->structure[] = new JsonObject(['blockId' => $id, 'kind' => $syntax, 'byteStart' => $start, 'byteEnd' => $end] + $meta);
        return $id;
    }

    /** @param list<string> $lines @param list<int> $offsets */
    private function startsBlock(array $lines, int $i, array $offsets): bool
    {
        if (preg_match('/^(?: {0,3}#{1,6}(?:[ \t]|$)| {0,3}(?:`{3,}|~{3,})| *([-+*]|[0-9]{1,9}[.)])[ \t]+)/', $lines[$i]) === 1) { return true; }
        $row = $this->row($lines[$i], $offsets[$i]);
        $next = isset($lines[$i + 1]) ? $this->row($lines[$i + 1], $offsets[$i + 1]) : null;
        return $row !== null && $next !== null && count($row) === count($next) && $this->separator($next);
    }

    /** @return list<array{text:string,start:int,end:int}>|null */
    private function row(string $line, int $offset): ?array
    {
        // Unescaped pipes delimit cells; inline markup (including backticks) is literal in this subset.
        $positions = []; $slashes = 0;
        for ($i = 0, $n = strlen($line); $i < $n; $i++) {
            if ($line[$i] === '|' && $slashes % 2 === 0) {
                $positions[] = $i;
                if (count($positions) > $this->settings->maxTableCells + 1) {
                    $this->diagnostics['table_cell_limit'] = true;
                    return null;
                }
            }
            $slashes = $line[$i] === '\\' ? $slashes + 1 : 0;
        }
        if ($positions === []) { return null; }
        $bounds = array_merge([-1], $positions, [strlen($line)]); $cells = [];
        for ($i = 1; $i < count($bounds); $i++) {
            $a = $bounds[$i - 1] + 1; $b = $bounds[$i];
            $raw = substr($line, $a, $b - $a); $trimmed = trim($raw, " \t");
            if (($i === 1 || $i === count($bounds) - 1) && $trimmed === '') { continue; }
            $a += strlen($raw) - strlen(ltrim($raw, " \t"));
            $cells[] = ['text' => $trimmed, 'start' => $offset + $a, 'end' => $offset + $a + strlen($trimmed)];
        }
        return $cells === [] ? null : $cells;
    }

    /** @param list<array{text:string,start:int,end:int}> $cells */
    private function separator(array $cells): bool
    {
        foreach ($cells as $cell) { if (preg_match('/^:?-{3,}:?$/D', $cell['text']) !== 1) { return false; } }
        return true;
    }

    private function diagnoseLiteral(string $line): void
    {
        if (preg_match('/<\/?[A-Za-z!]|<!--/', $line) === 1) { $this->diagnostics['literal_html'] = true; }
        if (str_contains($line, '![')) { $this->diagnostics['unresolved_markdown_image'] = true; }
        if (preg_match('/^(?: {0,3}>| {4,}\S|\t| {0,3}(?:`{3,}|~{3,})| {0,3}(?:=+|-{3,}|\*{3,}|_{3,})[ \t]*$| *\[[^]]+\]:)/', $line) === 1) {
            $this->diagnostics['unsupported_markdown_construct'] = true;
        }
    }
}
