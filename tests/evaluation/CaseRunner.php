<?php

declare(strict_types=1);

namespace KnowKit\Tests\Evaluation;

use KnowKit\Domain as D;
use KnowKit\Domain\Contract\Jcs;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Tests\Contract\Ports\PortFixture;
use KnowKit\Tests\Retrieval\Disclosure\Fixture;

/** Runs production selection and fresh disclosure against the local PostgreSQL fixture. */
final readonly class CaseRunner
{
    public function __construct(
        private Fixture $fixture,
    ) {}

    public function execute(array $case, string $phase): array
    {
        $f = $this->fixture;
        $this->configure($case);
        $query = $f->host->query(text: $case['query']);
        $started = hrtime(true);
        $selected = $f->host->retrieve($query, $f->selections);
        $selectionMs = (hrtime(true) - $started) / 1e6;
        $disclosureStarted = hrtime(true);
        $fragments = [];
        $outcome = $selected->result->outcome;
        $errorCode = $selected->result->errorCode;
        if ($outcome === D\Outcome::Success) {
            $result = $this->disclose($selected, $query, $case);
            $outcome = $result->result->outcome;
            $errorCode = $result->result->errorCode;
            if ($result->result->data instanceof D\EvidenceSet) {
                $fragments = json_decode(Jcs::canonicalize($result->result->data->toWire()->get('fragments')), true, 512, JSON_THROW_ON_ERROR);
            }
        }

        return ['id' => $case['id'], 'phase' => $phase, 'outcome' => $outcome->value, 'errorCode' => $errorCode,
            'fragments' => $fragments, 'queryFingerprint' => $query->digest()->value,
            'latencyMs' => (hrtime(true) - $started) / 1e6,
            'stages' => ['selectionMs' => $selectionMs, 'disclosureMs' => (hrtime(true) - $disclosureStarted) / 1e6],
            'cost' => ['providerCalls' => 0, 'inputTokens' => 0, 'outputTokens' => 0,
                'currencyCost' => null, 'note' => 'Local PostgreSQL/CPU only; infrastructure monetary cost is not instrumented.']];
    }

    private function configure(array $case): void
    {
        $f = $this->fixture;
        $f->host->eligible = [];
        $sources = [];
        foreach ($case['securityFixture']['allowed'] as $name) {
            $source = $f->lexical->sets[$name]->chunkSet->revisionRef->sourceRef;
            $f->host->eligible[] = $source->sourceVersionId;
            $sources[$source->sourceId->value] = new JsonObject(['sourceId' => $source->sourceId->value,
                'sourcePolicyEpoch' => '0', 'lifecycleEpoch' => '0']);
        }
        ksort($sources, SORT_STRING);
        $f->host->binding = new JsonObject(['tenantPolicyEpoch' => '0', 'kbPolicyEpoch' => '0', 'sources' => array_values($sources)]);
        $f->host->decision = D\Decision::Allow;
    }

    private function disclose(D\RetrievalOutcome $selected, D\QueryBinding $query, array $case): D\DisclosureOutcome
    {
        $f = $this->fixture;
        $refs = $selected->result->data->candidateRefs();
        $request = new D\DisclosureRequest(new JsonObject(['selectionId' => $selected->result->data->id()->value,
            'chunkRefs' => array_map(static fn (D\ChunkRef $ref): JsonObject => $ref->toWire(), $refs),
            'disclosureAttemptId' => $f->host->next()->value, 'usageContext' => new JsonObject(['runRef' => 'run-1'])]),
            PortFixture::validator());
        $bodies = $f->objects->bodies;
        try {
            $this->inject($case['scenario'], $refs);

            return $f->service->disclose($f->host->context(), $selected->token, $query, $f->host->inventory->snapshot, $request);
        } finally {
            $f->objects->bodies = $bodies;
            $f->host->decision = D\Decision::Allow;
        }
    }

    private function inject(string $scenario, array $refs): void
    {
        $f = $this->fixture;
        if ($scenario === 'missing-object') {
            foreach ($refs as $ref) {
                unset($f->objects->bodies[$ref->revisionRef->irDigest->value]);
            }
        }
        if ($scenario === 'unknown') {
            $f->host->decision = D\Decision::Unknown;
        }
        if ($scenario === 'denied') {
            $f->host->decision = D\Decision::Deny;
        }
        if ($scenario === 'revoke') {
            $f->revoke();
        }
    }
}
