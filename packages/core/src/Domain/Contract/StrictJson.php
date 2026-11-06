<?php
declare(strict_types=1);

namespace KnowKit\Domain\Contract;

/**
 * Strict UTF-8 JSON decoder for wire documents (adapted contract of AgenTools Wire/CanonicalJson
 * 2054acda51eb69eca1579c78900858c5ff3de01e: never unserialize, reject duplicate names, keep {} vs []).
 * Rejects duplicate keys, lone surrogates, NaN/Infinity, non-integer numbers, integers outside the
 * IEEE-754 safe range, depth > 64 and oversized documents. Objects decode to JsonObject, arrays to lists.
 * Error precedence (size, UTF-8, depth, syntax/number/duplicate in reading order, surrogate) is the
 * cross-language order in specs/contracts/wire-contract.md.
 */
final class StrictJson
{
    public const MAX_DEPTH = 64;
    public const SAFE_INTEGER = 9007199254740991;
    public const DEFAULT_MAX_BYTES = 1048576;

    private int $offset = 0;
    private readonly int $length;
    private bool $surrogate = false;

    private function __construct(private readonly string $text)
    {
        $this->length = strlen($text);
    }

    public static function decode(string $bytes, int $maxBytes = self::DEFAULT_MAX_BYTES): mixed
    {
        if (strlen($bytes) > $maxBytes) {
            throw new ContractViolation('document_too_large');
        }
        if (preg_match('//u', $bytes) !== 1) {
            throw new ContractViolation('invalid_utf8');
        }
        if (self::maxDepth($bytes) > self::MAX_DEPTH) {
            throw new ContractViolation('depth_exceeded');
        }
        $parser = new self($bytes);
        $parser->whitespace();
        $value = $parser->value();
        $parser->whitespace();
        if ($parser->offset !== $parser->length) {
            throw new ContractViolation('invalid_json');
        }
        if ($parser->surrogate) {
            throw new ContractViolation('invalid_surrogate');
        }
        return $value;
    }

    private static function maxDepth(string $text): int
    {
        $depth = $deepest = 0;
        $inString = $escaped = false;
        $length = strlen($text);
        for ($index = 0; $index < $length; $index++) {
            $char = $text[$index];
            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $inString = false;
                }
            } elseif ($char === '"') {
                $inString = true;
            } elseif ($char === '[' || $char === '{') {
                $deepest = max($deepest, ++$depth);
            } elseif ($char === ']' || $char === '}') {
                $depth--;
            }
        }
        return $deepest;
    }

    private function whitespace(): void
    {
        $this->offset += strspn($this->text, " \t\n\r", $this->offset);
    }

    private function value(): mixed
    {
        $char = $this->text[$this->offset] ?? '';
        if ($char === '{') {
            return $this->object();
        }
        if ($char === '[') {
            return $this->array();
        }
        if ($char === '"') {
            return $this->string();
        }
        foreach (['true' => true, 'false' => false, 'null' => null] as $literal => $value) {
            if (substr_compare($this->text, $literal, $this->offset, strlen($literal)) === 0) {
                $this->offset += strlen($literal);
                return $value;
            }
        }
        return $this->number();
    }

    private function object(): JsonObject
    {
        $this->offset++;
        $pairs = [];
        $this->whitespace();
        if (($this->text[$this->offset] ?? '') === '}') {
            $this->offset++;
            return new JsonObject();
        }
        while (true) {
            if (($this->text[$this->offset] ?? '') !== '"') {
                throw new ContractViolation('invalid_json');
            }
            $name = $this->string();
            $this->whitespace();
            if (($this->text[$this->offset] ?? '') !== ':') {
                throw new ContractViolation('invalid_json');
            }
            $this->offset++;
            $this->whitespace();
            $pairs[] = [$name, $this->value()];
            $this->whitespace();
            $char = $this->text[$this->offset] ?? '';
            $this->offset++;
            if ($char === '}') {
                break;
            }
            if ($char !== ',') {
                throw new ContractViolation('invalid_json');
            }
            $this->whitespace();
        }
        $members = [];
        foreach ($pairs as [$name, $value]) {
            if (array_key_exists($name, $members)) {
                throw new ContractViolation('duplicate_key');
            }
            $members[$name] = $value;
        }
        return new JsonObject($members);
    }

    /** @return list<mixed> */
    private function array(): array
    {
        $this->offset++;
        $items = [];
        $this->whitespace();
        if (($this->text[$this->offset] ?? '') === ']') {
            $this->offset++;
            return $items;
        }
        while (true) {
            $items[] = $this->value();
            $this->whitespace();
            $char = $this->text[$this->offset] ?? '';
            $this->offset++;
            if ($char === ']') {
                return $items;
            }
            if ($char !== ',') {
                throw new ContractViolation('invalid_json');
            }
            $this->whitespace();
        }
    }

    private function string(): string
    {
        $this->offset++;
        $out = '';
        while (true) {
            $span = strcspn($this->text, "\"\\", $this->offset);
            $chunk = substr($this->text, $this->offset, $span);
            if (preg_match('/[\x00-\x1f]/', $chunk) === 1) {
                throw new ContractViolation('invalid_json');
            }
            $out .= $chunk;
            $this->offset += $span;
            if ($this->offset >= $this->length) {
                throw new ContractViolation('invalid_json');
            }
            if ($this->text[$this->offset] === '"') {
                $this->offset++;
                return $out;
            }
            $escape = $this->text[$this->offset + 1] ?? '';
            $this->offset += 2;
            $simple = ['"' => '"', '\\' => '\\', '/' => '/', 'b' => "\x08", 'f' => "\x0c", 'n' => "\n", 'r' => "\r", 't' => "\t"];
            if (isset($simple[$escape])) {
                $out .= $simple[$escape];
            } elseif ($escape === 'u') {
                $out .= $this->unicode();
            } else {
                throw new ContractViolation('invalid_json');
            }
        }
    }

    private function hex(): int
    {
        $hex = substr($this->text, $this->offset, 4);
        if (preg_match('/^[0-9A-Fa-f]{4}$/', $hex) !== 1) {
            throw new ContractViolation('invalid_json');
        }
        $this->offset += 4;
        return (int) hexdec($hex);
    }

    private function unicode(): string
    {
        $code = $this->hex();
        if ($code >= 0xD800 && $code <= 0xDBFF) {
            if (substr($this->text, $this->offset, 2) === '\\u') {
                $saved = $this->offset;
                $this->offset += 2;
                $low = $this->hex();
                if ($low >= 0xDC00 && $low <= 0xDFFF) {
                    return mb_chr(0x10000 + (($code - 0xD800) << 10) + ($low - 0xDC00), 'UTF-8');
                }
                $this->offset = $saved;
            }
            return $this->loneSurrogate($code);
        }
        if ($code >= 0xDC00 && $code <= 0xDFFF) {
            return $this->loneSurrogate($code);
        }
        return mb_chr($code, 'UTF-8');
    }

    /** Keep lone surrogates distinct (as for Python) until the deferred invalid_surrogate rejection. */
    private function loneSurrogate(int $code): string
    {
        $this->surrogate = true;
        return chr(0xE0 | ($code >> 12)) . chr(0x80 | (($code >> 6) & 0x3F)) . chr(0x80 | ($code & 0x3F));
    }

    private function number(): int
    {
        if (preg_match('/\G-?(?:0|[1-9][0-9]*)(\.[0-9]+)?([eE][+-]?[0-9]+)?/', $this->text, $match, 0, $this->offset) !== 1) {
            throw new ContractViolation('invalid_json');
        }
        $this->offset += strlen($match[0]);
        if (($match[1] ?? '') !== '' || ($match[2] ?? '') !== '') {
            throw new ContractViolation('non_integer_number');
        }
        $digits = ltrim($match[0], '-');
        if (strlen($digits) > 16 || (strlen($digits) === 16 && strcmp($digits, (string) self::SAFE_INTEGER) > 0)) {
            throw new ContractViolation('unsafe_integer');
        }
        return (int) $match[0];
    }
}
