<?php

declare(strict_types=1);

namespace KnowKit\Http\Server;

/** Explicit entry point for a host front controller. Loading this file starts nothing. */
final class Sapi
{
    public static function serve(Server $server): void
    {
        $headers = function_exists('getallheaders') ? getallheaders() : self::headers($_SERVER);
        $stream = fopen('php://input', 'rb');
        try {
            $response = $server->handle(new Request(
                method: $_SERVER['REQUEST_METHOD'] ?? '', target: $_SERVER['REQUEST_URI'] ?? '',
                headers: $headers, body: $stream,
            ));
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
        http_response_code($response->status);
        foreach ($response->headers as $name => $value) {
            header("{$name}: {$value}");
        }
        echo $response->body;
    }

    private static function headers(array $server): array
    {
        $headers = [];
        foreach ($server as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headers[str_replace('_', '-', substr($key, 5))] = $value;
            }
        }
        foreach (['CONTENT_TYPE', 'CONTENT_LENGTH'] as $key) {
            if (isset($server[$key])) {
                $headers[str_replace('_', '-', $key)] = $server[$key];
            }
        }

        return $headers;
    }
}
