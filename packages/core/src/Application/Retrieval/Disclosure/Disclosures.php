<?php

declare(strict_types=1);

namespace KnowKit\Application\Retrieval\Disclosure;

use KnowKit\Application\Access\AccessContext;
use KnowKit\Application\Access\PolicyChecks;
use KnowKit\Application\Retrieval\Selection\Selections;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\ContractValidator;
use KnowKit\Domain\Contract\ContractViolation;
use KnowKit\Port\DisclosureStore;
use KnowKit\Port\IdGenerator;

/** Call at each actual host send boundary. EvidenceSet is history, never a new capability. */
final readonly class Disclosures
{
    public function __construct(
        private Selections $selections,
        private PolicyChecks $policy,
        private CanonicalBuffer $canonical,
        private DisclosureStore $store,
        private IdGenerator $ids,
        private ContractValidator $validator,
    ) {}

    public function disclose(
        AccessContext $context,
        D\SelectionToken $token,
        D\QueryBinding $query,
        D\SnapshotRef $snapshot,
        D\DisclosureRequest $request,
    ): D\DisclosureOutcome {
        try {
            $context->assertCurrent();
            $replay = $this->store->replay($context->scope(), $request);
            if ($replay !== null) {
                return new D\DisclosureOutcome(D\Result::success($replay));
            }
            $record = $this->selections->requireBound($context, $token, $query, $snapshot, $request->runRef());
            $request->refs($record);
            $plan = $this->store->plan($context->scope(), $record, $request);
            $prepared = $this->policy->prepare($context, $plan->resources, D\Operation::Disclose, $plan->binding());
            $buffer = $this->canonical->preload($record, $request);
            $usageId = new D\UsageId($this->ids->next()->value);
            $context->assertCurrent();
            $result = $this->store->commit($context->scope(), $record, $request, $plan,
                function (D\DisclosurePlan $current, D\UtcInstant $now) use ($context, $record, $request, $query, $snapshot, $prepared, $buffer, $usageId): D\PreparedDisclosure {
                    $record->requireBinding($context->policy, $query, $snapshot, $request->runRef(), $now);
                    $prepared->requireAllow($context, $current->binding(), $now);

                    return $buffer->prepare($record, $request, $usageId, $now, $this->validator, $prepared->disclosureDeadline());
                });

            return $result;
        } catch (D\DomainFailure $failure) {
            return new D\DisclosureOutcome(D\Result::failure($failure));
        } catch (ContractViolation $failure) {
            return new D\DisclosureOutcome(D\Result::failure(new D\DomainFailure(D\Outcome::Incompatible, $failure->errorCode)));
        } catch (\Throwable) {
            return new D\DisclosureOutcome(D\Result::failure(new D\DomainFailure(D\Outcome::Unavailable, 'disclosure_unavailable')));
        }
    }
}
