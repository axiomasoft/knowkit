<?php

declare(strict_types=1);

namespace KnowKitExampleHttp;

use KnowKit\Domain as D;
use KnowKit\Domain\Contract\ContractValidator;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Http\Client\Client;
use KnowKit\Http\Client\Response;
use KnowKit\Port\IdGenerator;

/** The host supplies identity through transport credentials and generates a fresh attempt for each send. */
final readonly class SearchAndDisclose
{
    public function __construct(
        private Client $client,
        private ContractValidator $validator,
        private IdGenerator $ids,
        private D\Limit $limit = new D\Limit(8),
    ) {}

    public function execute(D\Query $query): Response
    {
        $found = $this->client->search(query: $query, limit: $this->limit, requestId: $this->ids->next());
        if ($found->outcome !== D\Outcome::Success) {
            return $found;
        }
        // Success without a selection object is not a disclosure. Do not invent chunk refs.
        $selection = $found->selection($this->validator);
        if ($selection === null) {
            return $found;
        }
        $request = new D\DisclosureRequest(value: new JsonObject([
            'selectionId' => $selection->id()->value,
            'chunkRefs' => array_map(static fn (D\ChunkRef $ref): JsonObject => $ref->toWire(), $selection->candidateRefs()),
            'disclosureAttemptId' => $this->ids->next()->value,
        ]), validator: $this->validator);

        return $this->client->disclose(request: $request, requestId: $this->ids->next());
    }
}
