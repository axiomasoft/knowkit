<?php

declare(strict_types=1);

namespace KnowKit\Adapter\Origins;

use KnowKit\Domain\Origins\FetchLimits;
use KnowKit\Domain\Origins\RemoteResponse;
use KnowKit\Domain\Origins\ResolvedOrigin;
use KnowKit\Domain\DomainFailure;
use KnowKit\Domain\Outcome;
use KnowKit\Port\OriginTransport;

/** Direct pinned TCP + TLS. PHP HTTP wrappers and environment proxies are never used. */
final class NativeHttps implements OriginTransport
{
    public function get(ResolvedOrigin $origin, FetchLimits $limits, int $deadline): RemoteResponse
    {
        $connectDeadline = min($deadline, hrtime(true) + $limits->connectSeconds * 1_000_000_000);
        $context = stream_context_create(['ssl' => [
            'verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $origin->host,
            'SNI_enabled' => true, 'disable_compression' => true,
            'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
        ]]);
        $address = str_contains($origin->address, ':') ? '[' . $origin->address . ']' : $origin->address;
        $stream = @stream_socket_client(
            'tcp://' . $address . ':443', $error, $message,
            self::remaining($connectDeadline), STREAM_CLIENT_CONNECT, $context,
        );
        if ($stream === false) {
            throw new DomainFailure(Outcome::Unavailable, 'origin_connect_failed');
        }
        try {
            $this->verifyPeer(stream: $stream, origin: $origin);
            self::timeout(stream: $stream, deadline: $connectDeadline);
            if (@stream_socket_enable_crypto($stream, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT) !== true) {
                throw new DomainFailure(Outcome::Unavailable, 'origin_tls_failed');
            }
            self::remaining($connectDeadline);
            $this->writeRequest(stream: $stream, origin: $origin, deadline: $deadline);

            return (new HttpResponseReader())->read(stream: $stream, limits: $limits, deadline: $deadline);
        } finally {
            fclose($stream);
        }
    }

    /** @param resource $stream */
    private function verifyPeer(mixed $stream, ResolvedOrigin $origin): void
    {
        $peer = @stream_socket_get_name($stream, true);
        $separator = $peer === false ? false : strrpos($peer, ':');
        if ($separator === false || substr($peer, $separator + 1) !== '443') {
            throw new DomainFailure(Outcome::Denied, 'origin_peer_mismatch');
        }
        $origin->assertPeer(trim(substr($peer, 0, $separator), '[]'));
    }

    /** @param resource $stream */
    private function writeRequest(mixed $stream, ResolvedOrigin $origin, int $deadline): void
    {
        // No API for caller headers: secrets, cookies and referrers cannot follow a redirect.
        $request = 'GET ' . $origin->target . " HTTP/1.0\r\nHost: " . $origin->host
            . "\r\nAccept: application/pdf, text/plain, text/markdown\r\nAccept-Encoding: identity\r\nConnection: close\r\n\r\n";
        while ($request !== '') {
            self::timeout(stream: $stream, deadline: $deadline);
            $written = @fwrite($stream, $request);
            if ($written === false || $written === 0) {
                throw new DomainFailure(Outcome::Unavailable, 'origin_io_failed');
            }
            $request = substr($request, $written);
        }
    }

    public static function remaining(int $deadline): float
    {
        $seconds = ($deadline - hrtime(true)) / 1_000_000_000;
        if ($seconds <= 0) {
            throw new DomainFailure(Outcome::Unavailable, 'origin_timeout');
        }

        return $seconds;
    }

    /** @param resource $stream */
    public static function timeout(mixed $stream, int $deadline): void
    {
        $seconds = self::remaining($deadline);
        stream_set_timeout($stream, (int) $seconds, max(1, (int) (($seconds - (int) $seconds) * 1_000_000)));
    }
}
