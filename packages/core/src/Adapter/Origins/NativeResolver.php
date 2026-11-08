<?php

declare(strict_types=1);

namespace KnowKit\Adapter\Origins;

use KnowKit\Domain\DomainFailure;
use KnowKit\Domain\Outcome;
use KnowKit\Port\OriginResolver;

final class NativeResolver implements OriginResolver
{
    public function __construct(private readonly string $phpBinary = PHP_BINARY) {}

    public function addresses(string $host, ?int $deadline = null): array
    {
        if (!function_exists('proc_open') || !is_executable($this->phpBinary)) {
            throw new DomainFailure(Outcome::Unavailable, 'origin_dns_unavailable');
        }
        $script = <<<'PHP'
            $records = @dns_get_record($argv[1], DNS_A | DNS_AAAA);
            if ($records === false) {
                exit(1);
            }
            $ips = [];
            foreach ($records as $record) {
                if (isset($record['ip']) || isset($record['ipv6'])) {
                    $ips[] = $record['ip'] ?? $record['ipv6'];
                }
            }
            echo json_encode(array_values(array_unique($ips)));
            PHP;
        $process = @proc_open([$this->phpBinary, '-n', '-r', $script, '--', $host],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'a']], $pipes, null, []);
        if (!is_resource($process)) {
            throw new DomainFailure(Outcome::Unavailable, 'origin_dns_unavailable');
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        try {
            return $this->collect(process: $process, pipe: $pipes[1], deadline: min($deadline ?? PHP_INT_MAX, hrtime(true) + 5_000_000_000));
        } finally {
            proc_terminate($process, 9);
            fclose($pipes[1]);
            proc_close($process);
        }
    }

    /** @param resource $process @param resource $pipe @return list<string> */
    private function collect(mixed $process, mixed $pipe, int $deadline): array
    {
        $bytes = '';
        do {
            $bytes .= stream_get_contents($pipe, 8193 - strlen($bytes));
            if (strlen($bytes) > 8192 || hrtime(true) >= $deadline) {
                throw new DomainFailure(Outcome::Unavailable, 'origin_dns_unavailable');
            }
            $status = proc_get_status($process);
            if ($status['running']) {
                usleep(1000);
            }
        } while ($status['running'] || !feof($pipe));
        $addresses = json_decode($bytes, true);
        if (!is_array($addresses) || !array_is_list($addresses) || count($addresses) > 64
            || count(array_filter($addresses, 'is_string')) !== count($addresses)) {
            throw new DomainFailure(Outcome::Unavailable, 'origin_dns_unavailable');
        }

        return $addresses;
    }
}
