<?php
declare(strict_types=1);

namespace KnowKit\ContextCompilers\Codec;

use KnowKit\Domain\Contract\JsonObject;

final readonly class CompactMarkdownCodec implements ContextFormatCodec
{
    public function formatId(): string
    {
        return 'compact_markdown';
    }

    public function encode(mixed $data): string
    {
        if (is_string($data)) {
            return trim($data);
        }
        if (is_int($data) || is_bool($data) || $data === null) {
            return $this->formatScalar($data);
        }
        if ($data instanceof JsonObject) {
            /** @var array<string, mixed> $arr */
            $arr = iterator_to_array($data->getIterator());
            return $this->encodeNode($arr, 0);
        }
        if (is_array($data)) {
            return $this->encodeNode($data, 0);
        }

        throw new \InvalidArgumentException('Unsupported data type for CompactMarkdownCodec');
    }

    /** @return list<string>|string */
    public function decode(string $encoded): array|string
    {
        $trimmed = trim(str_replace("\r\n", "\n", $encoded));
        if ($trimmed === '') {
            return '';
        }

        $lines = explode("\n", $trimmed);
        $isBulletList = true;
        $items = [];
        foreach ($lines as $line) {
            if (!str_starts_with($line, '- ')) {
                $isBulletList = false;
                break;
            }
            $items[] = substr($line, 2);
        }

        return $isBulletList ? $items : $trimmed;
    }

    /** @param array<array-key, mixed> $node */
    private function encodeNode(array $node, int $depth): string
    {
        if ($node === []) {
            return '';
        }

        $indent = str_repeat('  ', $depth);
        $lines = [];

        if (array_is_list($node)) {
            foreach ($node as $item) {
                if ($item instanceof JsonObject) {
                    /** @var array<string, mixed> $arr */
                    $arr = iterator_to_array($item->getIterator());
                    $item = $arr;
                }
                if (is_array($item)) {
                    $label = $item['title'] ?? $item['id'] ?? null;
                    $children = $item['children'] ?? null;
                    if (is_string($label)) {
                        $lines[] = $indent . '- ' . $label;
                        if (is_array($children) && $children !== []) {
                            $lines[] = $this->encodeNode($children, $depth + 1);
                        }
                        continue;
                    }
                    $lines[] = $indent . '- ' . $this->inlineSummary($item);
                    continue;
                }
                $lines[] = $indent . '- ' . $this->formatScalar($item);
            }

            return implode("\n", array_filter($lines, static fn (string $line): bool => $line !== ''));
        }

        foreach ($node as $key => $value) {
            if ($value instanceof JsonObject) {
                /** @var array<string, mixed> $arr */
                $arr = iterator_to_array($value->getIterator());
                $value = $arr;
            }
            if (is_array($value)) {
                $lines[] = $indent . '- **' . (string) $key . '**:';
                $nested = $this->encodeNode($value, $depth + 1);
                if ($nested !== '') {
                    $lines[] = $nested;
                }
                continue;
            }
            $lines[] = $indent . '- **' . (string) $key . '**: ' . $this->formatScalar($value);
        }

        return implode("\n", $lines);
    }

    /** @param array<array-key, mixed> $item */
    private function inlineSummary(array $item): string
    {
        $parts = [];
        foreach ($item as $key => $value) {
            $parts[] = (string) $key . '=' . $this->formatScalar($value);
        }

        return implode(' ', $parts);
    }

    private function formatScalar(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_string($value)) {
            return (string) $value;
        }

        throw new \InvalidArgumentException('Unsupported scalar value in CompactMarkdownCodec');
    }
}
