<?php

declare(strict_types=1);

namespace KnowKit\Answer\Adapter\Psr18;

use KnowKit\Answer\Domain as A;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Contract\StrictJson;
use Psr\Http\Message\ResponseInterface;

/** No provider error body, request ID, exception message or partial text escapes this parser. */
final class OpenAiResponse
{
    public static function parse(ResponseInterface $response, string $bytes, Deployment $deployment): A\TransportResult
    {
        $usage = new A\UsageObservation();
        $model = null;
        try {
            if ($response->getStatusCode() !== 200
                || strtolower(trim(explode(';', $response->getHeaderLine('Content-Type'))[0])) !== 'application/json') {
                return self::unknown($usage);
            }
            $body = StrictJson::decode($bytes, $deployment->http->responseBytes);
            if (!$body instanceof JsonObject) {
                return self::unknown($usage);
            }
            $model = self::reference($body->get('model'));
            $usage = self::usage($body->get('usage'));
            $choice = self::choice($body);
            $message = $choice?->get('message');
            $text = $message instanceof JsonObject ? $message->get('content') : null;
            if ($model !== $deployment->binding->model || $body->get('error') !== null
                || $body->get('object') !== 'chat.completion' || $choice?->get('finish_reason') !== 'stop'
                || !$message instanceof JsonObject || $message->get('role') !== 'assistant'
                || $message->get('tool_calls') !== null || $message->get('function_call') !== null
                || $message->get('refusal') !== null || !is_string($text)
                || strlen($text) > $deployment->limits->outputBytes
                || ($usage->outputTokens !== null && $usage->outputTokens > $deployment->binding->settings->maxOutputTokens)) {
                return self::unknown($usage, $model);
            }
            A\Guard::utf8($text, $deployment->limits->outputBytes, false);

            return new A\TransportResult(A\DispatchCertainty::Sent, $text, true, $usage, $model);
        } catch (\Throwable) {
            return self::unknown($usage, $model);
        }
    }

    public static function unknown(?A\UsageObservation $usage = null, ?string $model = null): A\TransportResult
    {
        return new A\TransportResult(A\DispatchCertainty::Unknown, null, false, $usage ?? new A\UsageObservation(), $model);
    }

    private static function reference(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        A\Guard::reference($value);

        return $value;
    }

    private static function usage(mixed $wire): A\UsageObservation
    {
        if ($wire === null) {
            return new A\UsageObservation();
        }
        if (!$wire instanceof JsonObject) {
            throw new \InvalidArgumentException('Invalid usage');
        }
        self::tokens($wire->get('total_tokens'));

        return new A\UsageObservation(self::tokens($wire->get('prompt_tokens')), self::tokens($wire->get('completion_tokens')));
    }

    private static function tokens(mixed $value): ?int
    {
        if ($value !== null && (!is_int($value) || $value < 0 || $value > 9007199254740991)) {
            throw new \InvalidArgumentException('Finite observed tokens required');
        }

        return $value;
    }

    private static function choice(JsonObject $body): ?JsonObject
    {
        $choices = $body->get('choices');
        if (!is_array($choices) || count($choices) !== 1 || !($choices[0] ?? null) instanceof JsonObject
            || $choices[0]->get('index') !== 0) {
            return null;
        }

        return $choices[0];
    }
}
