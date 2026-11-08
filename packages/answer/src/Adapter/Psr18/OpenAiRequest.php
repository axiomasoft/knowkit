<?php

declare(strict_types=1);

namespace KnowKit\Answer\Adapter\Psr18;

use KnowKit\Answer\Domain as A;

/** The exact v1 chat-completions body is composed BEFORE admission and egress approval. */
final class OpenAiRequest
{
    /** @param list<ChatMessage> $messages */
    public static function bytes(A\ResolvedModelBinding $binding, array $messages): string
    {
        A\Guard::objects($messages, ChatMessage::class, 64);
        if ($messages === []) {
            throw new \InvalidArgumentException('Ordered messages required');
        }
        $body = [
            'model' => $binding->model,
            'messages' => array_map(static fn (ChatMessage $message): array => [
                'role' => $message->role->value,
                'content' => $message->text,
            ], $messages),
            'max_tokens' => $binding->settings->maxOutputTokens,
            'stream' => false,
            'n' => 1,
        ];
        $settings = $binding->settings;
        foreach (['temperature' => $settings->temperature, 'top_p' => $settings->topP] as $key => $decimal) {
            if ($decimal !== null) {
                $body[$key] = (float) $decimal;
            }
        }
        if ($settings->seed !== null) {
            $body['seed'] = $settings->seed;
        }
        if ($settings->stop !== []) {
            $body['stop'] = $settings->stop;
        }
        $bytes = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        A\Guard::utf8($bytes, 32768, false);

        return $bytes;
    }

    public static function requireExact(A\TransportRequest $request): void
    {
        $request->identity->requireBytes($request->bytes);
        $body = json_decode($request->bytes, true, 32, JSON_THROW_ON_ERROR);
        $wire = is_array($body) ? ($body['messages'] ?? null) : null;
        if (!is_array($wire) || !array_is_list($wire) || count($wire) !== count($request->identity->messages)) {
            throw new \InvalidArgumentException('Exact ordered messages required');
        }
        $messages = [];
        foreach ($wire as $index => $message) {
            $messages[] = self::message($message, $request->identity->messages[$index]);
        }
        // Also rejects duplicate JSON keys, injected tools/settings and streaming requests.
        if ($request->bytes !== self::bytes($request->identity->binding, $messages)) {
            throw new \InvalidArgumentException('Exact pinned wire body required');
        }
    }

    private static function message(mixed $message, A\MessageDigest $digest): ChatMessage
    {
        if (!is_array($message) || array_keys($message) !== ['role', 'content']
            || ($message['role'] ?? null) !== $digest->role->value || !is_string($message['content'])
            || !hash_equals($digest->contentDigest->value, hash('sha256', $message['content']))) {
            throw new \InvalidArgumentException('Message identity mismatch');
        }

        return new ChatMessage($digest->role, $message['content']);
    }
}
