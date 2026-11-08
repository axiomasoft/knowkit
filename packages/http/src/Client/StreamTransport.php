<?php

declare(strict_types=1);

namespace KnowKit\Http\Client;

use KnowKit\Http\Server\Request;
use KnowKit\Http\Server\Response;

/** Native PHP HTTP(S), verified TLS defaults, bounded buffers and no redirects. */
final readonly class StreamTransport implements Transport
{
    private const MAX_RESPONSE_BYTES = 8388608;

    /** Host-owned authentication headers only; operation headers belong to Client. */
    public function __construct(
        private string $baseUrl,
        private array $authenticationHeaders = [],
    ) {
        $parts = parse_url($baseUrl);
        if ($parts === false || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
            || preg_match('/[\x00-\x20\x7f]/', $baseUrl) || str_ends_with($baseUrl, '/')) {
            throw new \InvalidArgumentException('HTTP base URL required without credentials or trailing slash');
        }
        foreach ($authenticationHeaders as $name => $value) {
            if (!in_array(strtolower($name), ['authorization', 'cookie'], true)
                || !is_string($value) || preg_match('/[\x00-\x1f\x7f]/', $value)) {
                throw new \InvalidArgumentException('Host authentication headers required');
            }
        }
    }

    public function send(Request $request, int $timeoutMs): Response
    {
        if ($timeoutMs < 1 || !str_starts_with($request->target, '/v1/')) {
            throw new \InvalidArgumentException('Bounded versioned request required');
        }
        $headers = $this->headers($request);
        $deadline = hrtime(true) + $timeoutMs * 1000000;
        $context = stream_context_create(['http' => [
            'method' => $request->method, 'header' => implode("\r\n", $headers),
            'content' => $request->read(1403000), 'timeout' => $timeoutMs / 1000,
            'ignore_errors' => true, 'follow_location' => 0, 'max_redirects' => 0,
            'protocol_version' => 1.1,
        ]]);
        $stream = @fopen($this->baseUrl . $request->target, 'rb', false, $context);
        if ($stream === false) {
            throw new TransportFailure(timedOut: hrtime(true) >= $deadline);
        }
        try {
            $body = $this->body(stream: $stream, deadline: $deadline);
            $metadata = stream_get_meta_data($stream);
            [$status, $responseHeaders] = $this->responseHeaders($metadata['wrapper_data'] ?? []);

            return new Response(status: $status, body: $body, headers: $responseHeaders);
        } finally {
            fclose($stream);
        }
    }

    private function headers(Request $request): array
    {
        $headers = ['Connection: close'];
        foreach ($this->authenticationHeaders as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }
        foreach (['Content-Type', 'Accept', 'KnowKit-Schema-Version', 'Request-ID',
            'Idempotency-Key', 'Expected-Epoch', 'Expected-Revision'] as $name) {
            $value = $request->header($name);
            if ($value !== null) {
                $headers[] = $name . ': ' . $value;
            }
        }

        return $headers;
    }

    /** @param resource $stream */
    private function body(mixed $stream, int $deadline): string
    {
        $body = '';
        while (!feof($stream)) {
            $remaining = $deadline - hrtime(true);
            if ($remaining <= 0) {
                throw new TransportFailure(timedOut: true);
            }
            $microseconds = max(1, (int) ceil($remaining / 1000));
            stream_set_timeout($stream, intdiv($microseconds, 1000000), $microseconds % 1000000);
            $part = @fread($stream, min(8192, self::MAX_RESPONSE_BYTES + 1 - strlen($body)));
            $metadata = stream_get_meta_data($stream);
            if ($metadata['timed_out']) {
                throw new TransportFailure(timedOut: true);
            }
            if ($part === false || ($part === '' && !feof($stream))) {
                throw new TransportFailure();
            }
            $body .= $part;
            if (strlen($body) > self::MAX_RESPONSE_BYTES) {
                throw new ProtocolFailure();
            }
        }

        return $body;
    }

    private function responseHeaders(array $lines): array
    {
        $status = 0;
        $headers = [];
        foreach ($lines as $line) {
            if (preg_match('~^HTTP/\S+ ([1-5][0-9]{2})(?: |$)~', $line, $match)) {
                $status = (int) $match[1];
                $headers = [];
                continue;
            }
            $parts = explode(':', $line, 2);
            if (count($parts) !== 2 || preg_match('/^[!#$%&\x27*+.^_`|~0-9a-z-]+$/Di', $parts[0]) !== 1) {
                throw new ProtocolFailure();
            }
            $name = strtolower($parts[0]);
            if (isset($headers[$name])) {
                throw new ProtocolFailure();
            }
            $headers[$name] = trim($parts[1]);
        }
        if ($status < 200 || ($status >= 300 && $status < 400)) {
            throw new ProtocolFailure();
        }

        return [$status, $headers];
    }

    public function __debugInfo(): array
    {
        return ['transport' => 'http'];
    }
}
