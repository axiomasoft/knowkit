<?php
declare(strict_types=1);

namespace KnowKit\ContextCompilers\Sanitizer;

/** Removes thinking/reasoning segments from previous assistant turns without touching answer text. */
final readonly class ThinkingBlockStripper
{
    private const PART_TYPES = ['thinking', 'redacted_thinking', 'reasoning'];
    private const CLOSED_TAG_PATTERN = '#<(think|thinking|reasoning)\b[^>]*>(?:(?!</?\1\b).)*?</\1\s*>#isu';
    private const UNCLOSED_TAG_PATTERN = '#<(think|thinking|reasoning)\b[^>]*>.*$#isu';

    /**
     * @param list<array<string, mixed>> $history
     * @return list<array<string, mixed>>
     */
    public function strip(array $history, bool $enabled): array
    {
        if (!$enabled) {
            return $history;
        }
        $result = [];
        foreach ($history as $message) {
            if (($message['role'] ?? null) === 'assistant') {
                unset($message['reasoning_content'], $message['thinking']);
                if (array_key_exists('content', $message)) {
                    $message['content'] = $this->stripContent($message['content']);
                }
            }
            $result[] = $message;
        }

        return $result;
    }

    private function stripContent(mixed $content): mixed
    {
        if (is_string($content)) {
            return ltrim($this->stripTags($content));
        }
        if (!is_array($content)) {
            return $content;
        }
        $parts = [];
        foreach ($content as $part) {
            if (is_array($part) && in_array($part['type'] ?? null, self::PART_TYPES, true)) {
                continue;
            }
            if (is_array($part)) {
                foreach (['text', 'content'] as $field) {
                    if (isset($part[$field]) && is_string($part[$field])) {
                        $part[$field] = $this->stripContent($part[$field]);
                    }
                }
            }
            $parts[] = $part;
        }

        return $parts;
    }

    /** Innermost closed pairs first, then an unclosed tag through the end of the turn. */
    private function stripTags(string $content): string
    {
        $previous = null;
        while ($content !== $previous) {
            $previous = $content;
            $clean = preg_replace(self::CLOSED_TAG_PATTERN, '', $content);
            if (!is_string($clean)) {
                return $content;
            }
            $content = $clean;
        }
        $clean = preg_replace(self::UNCLOSED_TAG_PATTERN, '', $content);

        return is_string($clean) ? $clean : $content;
    }
}
