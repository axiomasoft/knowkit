<?php

declare(strict_types=1);

namespace KnowKit\Application\Retrieval\Rerank;

use KnowKit\Application\Access\AccessContext;
use KnowKit\Application\Access\PolicyChecks;
use KnowKit\Application\Retrieval\Selection\BoundedSelector;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\ContractValidator;
use KnowKit\Domain\Contract\Jcs;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Port\RerankTransport;

/** Processing permission and provider consent are separate from later fresh disclosure. */
final readonly class ConstrainedRerank
{
    public function __construct(
        private RerankTransport $transport,
        private PolicyChecks $policy,
        private BoundedSelector $selector,
        private ContractValidator $validator,
    ) {}

    public function apply(
        AccessContext $context,
        D\SearchCorpus $corpus,
        D\CandidateList $input,
        D\QueryBinding $query,
        D\RerankProfile $profile,
        \Closure $currentBinding,
    ): D\CandidateList {
        $corpus->assertBinding($context->scope());
        $corpus->assertList($input, 512);
        $selected = $this->selector->choose($corpus->scope, $input->candidates, $corpus->inventory, $query->limits);
        $documents = array_map(static fn (D\ChunkRef $ref): D\RerankDocument =>
            new D\RerankDocument($ref, $corpus->inventory->unit($ref)->chunk->text()), $selected->refs);
        if ($documents === []) {
            return new D\CandidateList($input->scope, []);
        }
        $request = $this->request($query->original, $documents, $profile);
        $contents = [$query->original->text(), ...array_map(static fn (D\RerankDocument $doc): string => $doc->text(), $documents)];
        $output = $this->policy->transmit($context, $request, $contents, $currentBinding,
            fn (): array => $this->transport->rerank($query->original, $documents, $profile));

        return $this->subset(new D\CandidateList($input->scope, array_map(
            static fn (D\RerankDocument $doc, int $index): D\Candidate => new D\Candidate($doc->ref, $index + 1),
            $documents, array_keys($documents),
        )), $output);
    }

    /** @param list<D\RerankDocument> $documents */
    private function request(D\Query $query, array $documents, D\RerankProfile $profile): D\EgressRequest
    {
        $items = [new JsonObject(['category' => 'QUERY', 'contentDigest' => hash('sha256', $query->text()), 'sourceVersionRefs' => []])];
        foreach ($documents as $document) {
            $items[] = new JsonObject(['category' => 'EVIDENCE', 'contentDigest' => $document->ref->chunkDigest->value,
                'sourceVersionRefs' => [$document->ref->revisionRef->sourceRef->toWire()]]);
        }
        $wire = new JsonObject(['operation' => 'retrieve', 'destination' => $this->transport->destination($profile), 'items' => $items]);

        return new D\EgressRequest($wire->with('manifestDigest', hash('sha256', Jcs::canonicalize($wire))), $this->validator);
    }

    /** @param list<JsonObject> $output */
    private function subset(D\CandidateList $input, array $output): D\CandidateList
    {
        $known = [];
        foreach ($input->candidates as $candidate) {
            $known[$candidate->ref->chunkId->value] = $candidate->ref;
        }
        try {
            D\ValueGuard::objects($output, JsonObject::class, 512);
        } catch (\InvalidArgumentException) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'adapter_contract_violation');
        }
        $candidates = [];
        foreach ($output as $entry) {
            $keys = array_keys(iterator_to_array($entry));
            $id = $entry->get('candidateId');
            $score = $entry->get('score');
            if (array_diff($keys, ['candidateId', 'score']) !== [] || !is_string($id) || !isset($known[$id])
                || ($score !== null && ((!is_int($score) && !is_float($score)) || !is_finite((float) $score)))) {
                throw new D\DomainFailure(D\Outcome::Incompatible, 'adapter_contract_violation');
            }
            $candidates[] = new D\Candidate($known[$id], count($candidates) + 1, $score === null ? null : (float) $score);
        }
        $subset = new D\CandidateSubset($input, $candidates);

        return new D\CandidateList($input->scope, $subset->candidates);
    }
}
