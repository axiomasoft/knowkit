<?php
declare(strict_types=1);

namespace KnowKit\ContextCompilers\Codec;

use KnowKit\Domain\Contract\JsonObject;

final readonly class ToonCodec implements ContextFormatCodec
{
    public function formatId(): string
    {
        return 'toon';
    }

    public function encode(mixed $data): string
    {
        if (!is_array($data) || !array_is_list($data)) {
            throw new \InvalidArgumentException('ToonCodec requires a list of homogeneous records');
        }
        if ($data === []) {
            return 'items[0]{}:';
        }

        $rows = [];
        $columns = null;
        foreach ($data as $index => $item) {
            $row = $this->normalizeRow($item, $index);
            $keys = array_keys($row);
            sort($keys, SORT_STRING);
            if ($columns === null) {
                if ($keys === []) {
                    throw new \InvalidArgumentException('ToonCodec records must have at least one column');
                }
                foreach ($keys as $key) {
                    if (preg_match('/^[A-Za-z0-9_.-]+$/D', $key) !== 1) {
                        throw new \InvalidArgumentException('ToonCodec column names must be ASCII: ' . $key);
                    }
                }
                $columns = $keys;
            } elseif ($keys !== $columns) {
                throw new \InvalidArgumentException('ToonCodec requires homogeneous records with identical keys');
            }
            $rows[] = $row;
        }

        $lines = [sprintf('items[%d]{%s}:', count($rows), implode(',', $columns))];
        foreach ($rows as $row) {
            $cells = [];
            foreach ($columns as $column) {
                $cells[] = $this->encodeCell($row[$column]);
            }
            $lines[] = '  ' . implode(',', $cells);
        }

        return implode("\n", $lines);
    }

    /** @return list<array<string, string|int|bool|null>> */
    public function decode(string $encoded): array
    {
        $normalized = str_replace("\r\n", "\n", trim($encoded, "\r\n"));
        if (preg_match('/^items\[(\d+)\]\{([^}]*)\}:(?:\n(.*))?$/s', $normalized, $matches) !== 1) {
            throw new \InvalidArgumentException('Invalid TOON header');
        }

        $expectedCount = (int) $matches[1];
        $columnsRaw = $matches[2];
        $body = $matches[3] ?? '';

        if ($expectedCount === 0) {
            if ($body !== '') {
                throw new \InvalidArgumentException('Unexpected body for empty TOON table');
            }
            return [];
        }

        $columns = $columnsRaw === '' ? [] : explode(',', $columnsRaw);
        if ($columns === []) {
            throw new \InvalidArgumentException('TOON table with rows must declare columns');
        }

        $records = $this->parseRows($body, $columns);
        if (count($records) !== $expectedCount) {
            throw new \InvalidArgumentException(sprintf(
                'TOON row count mismatch: expected %d, got %d',
                $expectedCount,
                count($records),
            ));
        }

        return $records;
    }

    /** @return array<string, mixed> */
    private function normalizeRow(mixed $item, int $index): array
    {
        if ($item instanceof JsonObject) {
            /** @var array<string, mixed> $arr */
            $arr = iterator_to_array($item->getIterator());
            return $arr;
        }
        if (!is_array($item) || array_is_list($item)) {
            throw new \InvalidArgumentException('ToonCodec record #' . $index . ' must be an associative array');
        }
        $normalized = [];
        foreach ($item as $key => $value) {
            if (!is_string($key)) {
                throw new \InvalidArgumentException('ToonCodec record keys must be strings');
            }
            $normalized[$key] = $value;
        }

        return $normalized;
    }

    private function encodeCell(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            throw new \InvalidArgumentException('Floats are not supported in integer-only ToonCodec');
        }
        if (!is_string($value)) {
            throw new \InvalidArgumentException('ToonCodec supports only scalar and null values');
        }
        if (preg_match('//u', $value) !== 1) {
            throw new \InvalidArgumentException('ToonCodec requires valid UTF-8 strings');
        }

        $needsQuotes = $value === ''
            || preg_match('/[,"\r\n\s]/', $value) === 1
            || in_array($value, ['true', 'false', 'null'], true)
            || preg_match('/^-?(0|[1-9][0-9]*)$/', $value) === 1;

        if (!$needsQuotes) {
            return $value;
        }

        return '"' . str_replace('"', '""', $value) . '"';
    }

    /**
     * @param list<string> $columns
     * @return list<array<string, string|int|bool|null>>
     */
    private function parseRows(string $body, array $columns): array
    {
        if ($body === '') {
            return [];
        }

        $records = [];
        $len = strlen($body);
        $pos = 0;

        while ($pos < $len) {
            if (substr($body, $pos, 2) !== '  ') {
                throw new \InvalidArgumentException('TOON record must start with two spaces');
            }
            $pos += 2;

            $fields = [];
            while (true) {
                $wasQuoted = false;
                $value = '';

                if ($pos < $len && $body[$pos] === '"') {
                    $wasQuoted = true;
                    ++$pos;
                    while ($pos < $len) {
                        $ch = $body[$pos];
                        if ($ch === '"') {
                            if ($pos + 1 < $len && $body[$pos + 1] === '"') {
                                $value .= '"';
                                $pos += 2;
                                continue;
                            }
                            ++$pos;
                            break;
                        }
                        $value .= $ch;
                        ++$pos;
                    }
                    if ($pos < $len && $body[$pos] !== ',' && $body[$pos] !== "\n") {
                        throw new \InvalidArgumentException('Unexpected character after closing quote in TOON');
                    }
                } else {
                    while ($pos < $len && $body[$pos] !== ',' && $body[$pos] !== "\n") {
                        $value .= $body[$pos];
                        ++$pos;
                    }
                }

                $fields[] = $this->decodeCell($value, $wasQuoted);

                if ($pos >= $len) {
                    break;
                }
                if ($body[$pos] === ',') {
                    ++$pos;
                    continue;
                }
                if ($body[$pos] === "\n") {
                    ++$pos;
                    break;
                }
            }

            if (count($fields) !== count($columns)) {
                throw new \InvalidArgumentException(sprintf(
                    'TOON column count mismatch: expected %d, got %d',
                    count($columns),
                    count($fields),
                ));
            }

            $record = [];
            foreach ($columns as $i => $col) {
                $record[$col] = $fields[$i];
            }
            $records[] = $record;
        }

        return $records;
    }

    private function decodeCell(string $raw, bool $wasQuoted): string|int|bool|null
    {
        if ($wasQuoted) {
            return $raw;
        }

        return match (true) {
            $raw === 'null' => null,
            $raw === 'true' => true,
            $raw === 'false' => false,
            preg_match('/^-?(0|[1-9][0-9]*)$/', $raw) === 1 => (int) $raw,
            default => $raw,
        };
    }
}
