<?php
declare(strict_types=1);
namespace KnowKit\Adapter\Lexical;

/** Syntax compiler v1: original query is retained; no semantic rewrite or security filters in text.
 * Normalization adapts AgenTools Retrieval/Query (2054acda), (c) 2026 Dmitry Vostrikov,
 * private local reuse. See tests/retrieval/lexical/evidence/P2.8-reuse.json.
 */
final readonly class LiteralQuery
{
    private const IDENTIFIER = '~[\p{L}\p{N}]+(?:[-_./][\p{L}\p{N}]+)+~u';
    private const CJK = '~[\x{3400}-\x{9fff}\x{f900}-\x{faff}\x{3040}-\x{30ff}\x{ac00}-\x{d7af}]+~u';
    public array $identifiers;
    public array $phrases;
    public array $literals;
    public string $lexical;
    public bool $matchNone;
    public function __construct(public string $original)
    {
        $this->identifiers = self::identifiers($original);
        preg_match_all('/"([^"]+)"/u', $original, $phrases);
        $this->phrases = array_values(array_filter(array_map(self::normalize(...), $phrases[1]), static fn(string $s): bool => $s !== ''));
        preg_match_all(self::CJK, $original, $literals);
        $this->literals = array_values(array_unique($literals[0]));
        $remaining = preg_replace('/"[^"]*"/u', ' ', $original) ?? '';
        $remaining = preg_replace(self::IDENTIFIER, ' ', $remaining) ?? '';
        $remaining = preg_replace(self::CJK, ' ', $remaining) ?? '';
        $this->lexical = preg_match('/[\p{L}\p{N}]/u', $remaining) === 1 ? trim($remaining) : '';
        $this->matchNone = $this->lexical === '' && $this->identifiers === [] && $this->phrases === [] && $this->literals === [];
    }
    public static function normalize(string $text): string { return strtolower(trim(preg_replace('/\s+/u', ' ', $text) ?? '')); }
    /** @return list<string> */
    public static function identifiers(string $text): array
    {
        preg_match_all(self::IDENTIFIER, $text, $matches);
        return array_values(array_unique(array_map(self::normalize(...), $matches[0])));
    }
    /** Literal matching uses canonical buffers, never a tsvector reconstruction or backend text. */
    public function matchesCanonical(string $text): bool
    {
        $normalized = self::normalize($text);
        foreach ([...$this->phrases, ...$this->literals] as $literal) {
            if (!str_contains($normalized, $literal)) { return false; }
        }
        return true;
    }
    /** CJK has a literal channel, without promising tokenization or morphology. */
    public function ftsPhrases(): array
    {
        return array_values(array_filter($this->phrases, static fn(string $s): bool => preg_match(self::CJK, $s) !== 1));
    }
    public function __debugInfo(): array { return ['syntaxRevision' => 'literal-query:1', 'byteLength' => strlen($this->original)]; }
}
