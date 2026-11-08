<?php

declare(strict_types=1);

namespace KnowKit\Http\Client;

use KnowKit\Domain as D;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Http\Server\Contract;
use KnowKit\Http\Server\Request;
use KnowKit\Http\Server\Response as WireResponse;

final readonly class Client
{
    public function __construct(
        private Transport $transport,
        private Time $time = new SystemTime(),
        private Options $options = new Options(),
        private Contract $contract = new Contract(),
    ) {}

    public function execute(Call $call): Response
    {
        // Freeze the complete request once. A retry cannot replace the key, attempt, pins or preconditions.
        $request = $call->request(contract: $this->contract, version: $this->options->schemaVersion);
        $deadline = $this->time->milliseconds() + $this->options->timeoutMs;
        for ($attempt = 1; ; ++$attempt) {
            try {
                [$response, $delay] = $this->exchange(request: $request, call: $call, deadline: $deadline);
            } catch (TransportFailure $failure) {
                if (!$this->canRetry(call: $call, attempt: $attempt, deadline: $deadline, delay: $this->options->retryDelayMs)) {
                    throw $failure;
                }
                $this->time->sleep($this->options->retryDelayMs);
                continue;
            }
            if (!$this->retryResponse($response) || $delay === null
                || !$this->canRetry(call: $call, attempt: $attempt, deadline: $deadline, delay: $delay)) {
                return $response;
            }
            $this->time->sleep($delay);
        }
    }

    /** @return array{Response, ?int} */
    private function exchange(Request $request, Call $call, int $deadline): array
    {
        $remaining = $deadline - $this->time->milliseconds();
        if ($remaining <= 0) {
            throw new TransportFailure(timedOut: true);
        }
        $wire = $this->transport->send(request: $request, timeoutMs: $remaining);
        if ($this->time->milliseconds() >= $deadline) {
            throw new TransportFailure(timedOut: true);
        }
        $response = Response::decode(wire: $wire, contract: $this->contract, call: $call, version: $this->options->schemaVersion);
        if ($response->status === 409 && $response->errorCode === 'schema_mismatch') {
            throw new VersionMismatch();
        }

        return [$response, $this->retryDelay($wire)];
    }

    public function search(D\Query $query, D\Limit $limit, D\Ulid $requestId): Response
    {
        $filters = new JsonObject();
        if ($query->sourceVersionIds !== null) {
            $filters = $filters->with('sourceVersionIds', array_map(static fn (D\SourceVersionId $id): string => $id->value, $query->sourceVersionIds));
        }
        if ($query->collectionIds !== null) {
            $filters = $filters->with('collectionIds', array_map(static fn (D\CollectionId $id): string => $id->value, $query->collectionIds));
        }

        return $this->execute(Call::operation(
            operation: 'search', body: new JsonObject(['query' => $query->text(), 'limit' => $limit->k, 'filters' => $filters]),
            requestId: $requestId,
        ));
    }

    public function disclose(D\DisclosureRequest $request, D\Ulid $requestId): Response
    {
        return $this->execute(Call::operation(operation: 'disclose', body: $request->toWire(), requestId: $requestId));
    }

    public function resolveCitation(D\Ulid $citationId, D\Ulid $requestId): Response
    {
        return $this->execute(Call::operation(
            operation: 'resolveCitation', body: new JsonObject(), requestId: $requestId, parameters: ['opaque' => $citationId],
        ));
    }

    private function canRetry(Call $call, int $attempt, int $deadline, int $delay): bool
    {
        return $call->retrySafe() && $attempt < $this->options->maxAttempts
            && $this->time->milliseconds() + $delay < $deadline;
    }

    private function retryResponse(Response $response): bool
    {
        return $response->status === 429 || ($response->status === 503 && $response->retryable);
    }

    private function retryDelay(WireResponse $response): ?int
    {
        $after = Response::header(wire: $response, name: 'Retry-After');
        if ($after === null) {
            return $this->options->retryDelayMs;
        }
        if (preg_match('/^[0-9]{1,5}$/D', $after) !== 1 || (int) $after > 60) {
            return null;
        }

        return max($this->options->retryDelayMs, (int) $after * 1000);
    }
}
