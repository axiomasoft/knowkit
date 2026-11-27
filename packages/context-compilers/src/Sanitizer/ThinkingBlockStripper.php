<?php
declare(strict_types=1);

namespace KnowKit\ContextCompilers\Sanitizer;

/** Removes thinking/reasoning segments from previous assistant turns without touching answer text. */
final readonly class ThinkingBlockStripper
{
    private const PART_TYPES = ['thinking', 'redacted_thinking', 'reasoning'];
    // An unclosed tag runs to the end of the turn. A closed tag removes only its body.
    private const TAG_PATTERN = '#<(think|thinking|reasoning)\b[^>]*>.*?(?:</\1\s*>|$)#isu';

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
            $clean = preg_replace(self::TAG_PATTERN, '', $content);

            return is_string($clean) ? ltrim($clean) : $content;
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
}
