<?php

declare(strict_types=1);

namespace KnowKit\Application\Reading;

use KnowKit\Application\Access\AccessContext;
use KnowKit\Application\Access\PolicyChecks;
use KnowKit\Domain as D;
use KnowKit\Domain\Reading as R;
use KnowKit\Port\Clock;
use KnowKit\Port\ReadingStore;

/** Authenticated gateway boundary. Returning a committed buffer is the disclosure linearization point. */
final readonly class Readings
{
    public function __construct(
        private ReadingStore $store,
        private PolicyChecks $checks,
        private CanonicalReads $canonical,
        private Clock $clock,
    ) {}

    /** @return D\Result<R\ReadResponse>|D\Result<D\DisclosureReplay> */
    public function read(AccessContext $context, R\ReadRequest $request): D\Result
    {
        try {
            $context->assertCurrent();
            $scope = $context->scope();
            $replay = $this->store->replay($scope, $request);
            if ($replay !== null) {
                return D\Result::success($replay);
            }

            return $this->fresh($context, $request);
        } catch (D\DomainFailure $failure) {
            return D\Result::failure($failure);
        } catch (\Throwable) {
            return D\Result::failure(new D\DomainFailure(D\Outcome::Unavailable, 'reading_unavailable'));
        }
    }

    /** @return D\Result<R\ReadResponse>|D\Result<D\DisclosureReplay> */
    private function fresh(AccessContext $context, R\ReadRequest $request): D\Result
    {
        $scope = $context->scope();
        $request->requireFresh($this->clock->now());
        $record = $this->store->citation($scope, $request->citationId);
        $record->requireScope($scope);
        $record->requirePayload();
        $plan = $this->store->plan($scope, $record);
        // Authorize before preloading, and prepare a fresh receipt after all object I/O.
        $this->checks->prepare($context, $plan->resources, D\Operation::Disclose, $plan->binding());
        [$body, $object] = $this->canonical->preload($record, $request);
        $resources = $plan->resources;
        if ($object !== null) {
            $resources[] = new D\ResourceRef($scope->tenantId, 'resource', $object->resourceId);
        }
        $decision = $this->checks->prepare($context, $resources, D\Operation::Disclose, $plan->binding());

        return $this->store->commit($scope, $record, $request, $plan, $body, $object,
            static function (D\DisclosurePlan $current, D\UtcInstant $now) use ($context, $decision): D\DisclosureDeadline {
                $decision->requireAllow($context, $current->binding(), $now);

                return $decision->disclosureDeadline();
            });
    }
}
