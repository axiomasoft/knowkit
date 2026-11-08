<?php

declare(strict_types=1);

namespace KnowKit\Tests\Delivery\Http;

use KnowKit\Application\Access\AccessContext;
use KnowKit\Application\Retrieval;
use KnowKit\Application\Retrieval\Disclosure\HostDisclosureContext;
use KnowKit\Application\Retrieval\Queries\Normalizer;
use KnowKit\Domain as D;
use KnowKit\Domain\Access\PolicyContext;
use KnowKit\Tests\Retrieval\Disclosure\Fixture as DisclosureFixture;
use KnowKit\Tests\Retrieval\Selection\Fixture as SelectionFixture;

/** Test host keeps tokens/query/snapshot outside JSON, exercising the real selection and disclosure stores. */
final class SelectionBindings implements Retrieval, HostDisclosureContext
{
    private array $bindings = [];

    public function __construct(
        private readonly DisclosureFixture $fixture,
    ) {}

    public function current(): PolicyContext
    {
        return $this->fixture->host->current();
    }

    public function retrieve(D\Scope $scope, D\Query $query, D\Limit $limit): D\RetrievalOutcome
    {
        $f = $this->fixture;
        $binding = (new Normalizer())->bind(
            query: $query, profile: SelectionFixture::profile(), limits: D\SelectionLimits::profile('compact'),
        );
        $outcome = $f->selections->retrieve(
            context: AccessContext::fromHost($this), query: $binding, snapshot: $f->host->inventory->snapshot,
            generation: $f->host->inventory->generationId(), hostEligible: $f->host->eligible,
            policyBinding: $f->host->binding, topK: $limit->k,
        );
        if ($outcome->token !== null) {
            $this->bindings[$outcome->result->data->id()->value] = new D\DisclosureBinding(
                token: $outcome->token, query: $binding, snapshot: $f->host->inventory->snapshot,
            );
        }

        return $outcome;
    }

    public function selection(D\Scope $scope, D\SelectionId $selection): D\DisclosureBinding
    {
        if ($scope != $this->current()->scope || !isset($this->bindings[$selection->value])) {
            throw new D\DomainFailure(outcome: D\Outcome::Denied, errorCode: 'selection_invalid');
        }

        return $this->bindings[$selection->value];
    }
}
