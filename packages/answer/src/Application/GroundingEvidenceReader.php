<?php

declare(strict_types=1);

namespace KnowKit\Answer\Application;

use KnowKit\Answer\Domain as A;
use KnowKit\Application\Disclosure;
use KnowKit\Application\Retrieval;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\ContractValidator;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Port\IdGenerator;

/** Host binds these core use cases to this exact immutable retrieval profile. */
final readonly class GroundingEvidenceReader
{
    public function __construct(
        private Retrieval $retrieval,
        private Disclosure $disclosure,
        private IdGenerator $ids,
        private ContractValidator $validator,
        private A\ProfileRef $profile,
    ) {
    }

    public function read(A\AnswerRequest $request): ?A\GroundingEvidence
    {
        if (!$request->retrievalProfileRef->equals($this->profile)) {
            throw new D\DomainFailure(D\Outcome::Unavailable, 'retrieval_profile_unavailable');
        }
        $result = $this->retrieval->retrieve($request->scope, new D\Query($request->question), new D\Limit(4))->result;
        if ($result->outcome === D\Outcome::Empty) {
            return null;
        }
        $this->requireSuccess($result);
        if (!$result->data instanceof D\Selection || $result->data->candidateRefs() === []) {
            return null;
        }

        return $this->disclose($request, $result->data);
    }

    private function disclose(A\AnswerRequest $request, D\Selection $selection): A\GroundingEvidence
    {
        $wire = new JsonObject([
            'selectionId' => $selection->id()->value,
            'chunkRefs' => array_map(static fn (D\ChunkRef $ref) => $ref->toWire(), $selection->candidateRefs()),
            'disclosureAttemptId' => $this->ids->next()->value,
        ]);
        if ($selection->runRef() !== null) {
            $wire = $wire->with('usageContext', new JsonObject(['runRef' => $selection->runRef()]));
        }
        $disclosure = new D\DisclosureRequest($wire, $this->validator);
        $result = $this->disclosure->disclose($request->scope, $disclosure)->result;
        $this->requireSuccess($result);
        if (!$result->data instanceof D\EvidenceSet) {
            throw new D\DomainFailure(D\Outcome::Unavailable, 'fresh_disclosure_required');
        }
        $evidence = new A\GroundingEvidence($result->data, $selection, $request->scope, $this->validator);
        if (!$evidence->disclosureAttemptId()->equals($disclosure->attemptId())) {
            throw new D\DomainFailure(D\Outcome::Unavailable, 'fresh_disclosure_required');
        }

        return $evidence;
    }

    private function requireSuccess(D\Result $result): void
    {
        if ($result->outcome !== D\Outcome::Success) {
            throw new D\DomainFailure($result->outcome, $result->errorCode ?? 'disclosure_unavailable');
        }
    }
}
