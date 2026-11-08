<?php

declare(strict_types=1);

namespace KnowKit\Adapter\Observability;

use KnowKit\Domain\Contract\Jcs;
use KnowKit\Domain\Observability\Observation;
use KnowKit\Port\ObservationSink;

/** Host-owned stream; constructing this sink opens no file and starts no process. */
final readonly class JsonLines implements ObservationSink
{
    /** @param resource $stream */
    public function __construct(
        private mixed $stream,
    ) {
        if (!is_resource($stream) || get_resource_type($stream) !== 'stream') {
            throw new \InvalidArgumentException('Writable host stream required');
        }
    }

    public function emit(Observation $observation): void
    {
        $bytes = Jcs::canonicalize($observation->toWire()->with('metrics', $observation->metrics())) . "\n";
        $offset = 0;
        while ($offset < strlen($bytes)) {
            $written = @fwrite($this->stream, substr($bytes, $offset));
            if ($written === false || $written === 0) {
                throw new \RuntimeException('observation_sink_unavailable');
            }
            $offset += $written;
        }
    }
}
