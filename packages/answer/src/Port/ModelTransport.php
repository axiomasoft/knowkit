<?php

declare(strict_types=1);

namespace KnowKit\Answer\Port;

use KnowKit\Answer\Domain\CancellationToken;
use KnowKit\Answer\Domain\TransportDescriptor;
use KnowKit\Answer\Domain\TransportRequest;
use KnowKit\Answer\Domain\TransportResult;

/** Adapted single-exchange boundary from AgenTools ModelTransport; see P4.7-reuse.json.
 * One local dispatch, no retry, repair, fallback, tool execution or streaming publication.
 */
interface ModelTransport
{
    /** Metadata only: must not perform provider IO. */
    public function descriptor(): TransportDescriptor;

    public function send(TransportRequest $request, CancellationToken $cancellation): TransportResult;
}
