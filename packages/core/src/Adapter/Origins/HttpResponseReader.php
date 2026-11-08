<?php

declare(strict_types=1);

namespace KnowKit\Adapter\Origins;

use KnowKit\Domain\Origins\FetchLimits;
use KnowKit\Domain\Origins\RemoteResponse;
use KnowKit\Domain\DomainFailure;
use KnowKit\Domain\Outcome;

/** Bounded HTTP/1.x identity framing. Compression and chunked transfer are refused, never decoded. */
final class HttpResponseReader
{
    /** @param resource $stream */
    public function read(mixed $stream, FetchLimits $limits, int $deadline): RemoteResponse
    {
        $head = '';
        while (!str_ends_with($head, "\r\n\r\n")) {
            NativeHttps::timeout(stream: $stream, deadline: $deadline);
            $part = @fgets($stream, 8194);
            if ($part === false || !str_ends_with($part, "\r\n") || strlen($head) + strlen($part) > 32768) {
                throw new DomainFailure(Outcome::Incompatible, 'origin_headers');
            }
            $head .= $part;
        }
        [$status, $headers] = $this->headers($head);
        if (isset($headers['transfer-encoding'])
            || (isset($headers['content-encoding']) && strtolower($headers['content-encoding']) !== 'identity')) {
            throw new DomainFailure(Outcome::Incompatible, 'origin_encoding');
        }
        if (in_array($status, [301, 302, 303, 307, 308], true)) {
            return new RemoteResponse(status: $status, headers: $headers, bytes: '');
        }
        $length = $headers['content-length'] ?? null;
        if ($length !== null && (preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D', $length) !== 1
            || (int) $length > $limits->maxBytes)) {
            throw new DomainFailure(Outcome::Incompatible, 'origin_transfer_limit');
        }
        $bytes = $this->body(stream: $stream, limit: $limits->maxBytes, deadline: $deadline);
        if ($length !== null && (int) $length !== strlen($bytes)) {
            throw new DomainFailure(Outcome::Incompatible, 'origin_length_mismatch');
        }

        return new RemoteResponse(status: $status, headers: $headers, bytes: $bytes);
    }

    /** @return array{int, array<string, string>} */
    private function headers(string $head): array
    {
        $lines = explode("\r\n", substr($head, 0, -4));
        $statusLine = array_shift($lines);
        if (preg_match('~\AHTTP/1\.[01] ([2-5][0-9]{2})(?: [\x20-\x7e]*)?\z~D', $statusLine, $match) !== 1) {
            throw new DomainFailure(Outcome::Incompatible, 'origin_headers');
        }
        $headers = [];
        foreach ($lines as $line) {
            if (preg_match('/\A([A-Za-z0-9-]+):[ \t]*([\x20-\x7e\t]*)\z/D', $line, $parts) !== 1) {
                throw new DomainFailure(Outcome::Incompatible, 'origin_headers');
            }
            $name = strtolower($parts[1]);
            // Cookies are discarded and never exposed to the next request.
            if ($name === 'set-cookie') {
                continue;
            }
            if (isset($headers[$name])) {
                throw new DomainFailure(Outcome::Incompatible, 'origin_headers');
            }
            $headers[$name] = trim($parts[2]);
        }

        return [(int) $match[1], $headers];
    }

    /** @param resource $stream */
    private function body(mixed $stream, int $limit, int $deadline): string
    {
        $bytes = '';
        while (!feof($stream)) {
            NativeHttps::timeout(stream: $stream, deadline: $deadline);
            $part = @fread($stream, min(65536, $limit + 1 - strlen($bytes)));
            if ($part === false || ($part === '' && !feof($stream))) {
                throw new DomainFailure(Outcome::Unavailable, 'origin_io_failed');
            }
            $bytes .= $part;
            if (strlen($bytes) > $limit) {
                throw new DomainFailure(Outcome::Incompatible, 'origin_transfer_limit');
            }
        }

        return $bytes;
    }
}
