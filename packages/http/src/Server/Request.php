<?php

declare(strict_types=1);

namespace KnowKit\Http\Server;

/** Only HTTP data. The host authenticates the request separately; no trusted identity fields. */
final readonly class Request
{
    /** @param array<string, string|list<string>> $headers @param resource|string $body */
    public function __construct(
        public string $method,
        public string $target,
        private array $headers = [],
        private mixed $body = '',
    ) {}

    public function header(string $name): ?string
    {
        $found = [];
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                array_push($found, ...(is_array($value) ? $value : [$value]));
            }
        }
        if (count($found) > 1 || (isset($found[0]) && preg_match('/[\x00-\x1f\x7f]/', $found[0]))) {
            throw new InputFailure();
        }

        return $found[0] ?? null;
    }

    public function read(int $limit): string
    {
        $length = $this->header('Content-Length');
        if ($length !== null && preg_match('/^(0|[1-9][0-9]{0,18})$/D', $length) !== 1) {
            throw new InputFailure();
        }
        if ($length !== null && (strlen($length) > strlen((string) $limit) || (int) $length > $limit)) {
            throw new InputFailure(status: 413, errorCode: 'body_limit');
        }
        $bytes = $this->bytes($limit);
        if (strlen($bytes) > $limit) {
            throw new InputFailure(status: 413, errorCode: 'body_limit');
        }
        if ($length !== null && (int) $length !== strlen($bytes)) {
            throw new InputFailure();
        }

        return $bytes;
    }

    private function bytes(int $limit): string
    {
        if (is_string($this->body)) {
            return $this->body;
        }
        if (!is_resource($this->body) || get_resource_type($this->body) !== 'stream') {
            throw new InputFailure();
        }
        $bytes = '';
        while (!feof($this->body) && strlen($bytes) <= $limit) {
            $part = fread($this->body, min(8192, $limit + 1 - strlen($bytes)));
            if ($part === false || ($part === '' && !feof($this->body))) {
                throw new InputFailure();
            }
            $bytes .= $part;
        }

        return $bytes;
    }

    public function __debugInfo(): array
    {
        return ['transport' => 'http'];
    }
}
