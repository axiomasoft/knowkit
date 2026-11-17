<?php

declare(strict_types=1);

namespace KnowKit\Answer\Application;

use KnowKit\Answer\Domain as A;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\ContractValidator;
use KnowKit\Port\Clock;
use KnowKit\Port\EgressPolicy;

/** Called by host InvocationAuthorization::beforeSend AFTER markSending.
 * Host still owns current lifecycle/history/access and authoritative policy binding checks.
 */
final readonly class CurrentModelEgress
{
    public function __construct(
        private EgressPolicy $policy,
        private Clock $clock,
        private ContractValidator $validator,
    ) {
    }

    public function check(A\ModelCallIdentity $call, A\EgressContentManifest $manifest, D\Sha256 $currentPolicyBinding): ?A\AnswerOutcome
    {
        $decision = $this->authorize($call, $manifest, $currentPolicyBinding);

        return $decision instanceof A\AnswerOutcome ? $decision : null;
    }

    public function authorize(A\ModelCallIdentity $call, A\EgressContentManifest $manifest, D\Sha256 $currentPolicyBinding): D\DecisionReceipt|A\AnswerOutcome
    {
        if (!$call->digest()->equals($manifest->identity->digest())) {
            throw new \InvalidArgumentException('Egress must bind the exact admitted call');
        }
        try {
            $receipt = $this->policy->mayEgress($call->binding->scope, $manifest->toCore($this->validator));
            foreach ($manifest->resources() as $resource) {
                $receipt->requireAllow($call->binding->scope, $resource, D\Operation::ModelEgressContext, $this->clock->now(), $currentPolicyBinding);
            }

            return $receipt;
        } catch (D\DomainFailure $failure) {
            $code = $failure->outcome === D\Outcome::Denied ? A\OutcomeCode::EgressDenied : A\OutcomeCode::PolicyUnknown;
        } catch (\Throwable) {
            $code = A\OutcomeCode::PolicyUnknown;
        }

        return new A\AnswerOutcome($code, $call->invocationRef, A\DispatchCertainty::KnownNotSent);
    }
}
