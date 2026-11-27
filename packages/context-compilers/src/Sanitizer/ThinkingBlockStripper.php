<?php
declare(strict_types=1);

namespace KnowKit\ContextCompilers\Sanitizer;

/** Removes thinking/reasoning segments from previous assistant turns without touching answer text. */
final readonly class ThinkingBlockStripper
{
    private const PART_TYPES = ['thinking', 'redacted_thinking', 'reasoning'];
    private const TAG_PATTERN = '#<(?:(think|thinking|reasoning)\b[^>]*>|/(think|thinking|reasoning)\s*>)#i';

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
        $openTags = [];
        if (is_string($content)) {
            return ltrim($this->stripTags($content, $openTags));
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
                        $part[$field] = $this->stripTags($part[$field], $openTags);
                    }
                }
            }
            $parts[] = $part;
        }

        return $parts;
    }

    /** @param list<string> $openTags Open tags persist across text parts within the same turn. */
    private function stripTags(string $content, array &$openTags): string
    {
        $visible = '';
        $offset = 0;
        while (true) {
            $found = preg_match(self::TAG_PATTERN, $content, $match, PREG_OFFSET_CAPTURE, $offset);
            if ($found === false) {
                throw new \RuntimeException('Could not parse thinking tags');
            }
            if ($found === 0) {
                return $openTags === [] ? $visible . substr($content, $offset) : $visible;
            }
            [$tag, $start] = $match[0];
            if ($openTags === []) {
                $visible .= substr($content, $offset, $start - $offset);
            }
            if ($match[1][0] !== '') {
                $openTags[] = strtolower($match[1][0]);
            } elseif ($openTags === []) {
                $visible .= $tag;
            } elseif (end($openTags) === strtolower($match[2][0])) {
                array_pop($openTags);
            }
            $offset = $start + strlen($tag);
        }
    }
}
