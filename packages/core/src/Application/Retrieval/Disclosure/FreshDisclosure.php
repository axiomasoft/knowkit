<?php

declare(strict_types=1);

namespace KnowKit\Application\Retrieval\Disclosure;

use KnowKit\Application\Access\AccessContext;
use KnowKit\Application\Disclosure;
use KnowKit\Domain as D;
use KnowKit\Port\DisclosureStore;

/** Implements the approved public use case without accepting host identity/bindings in request JSON. */
final readonly class FreshDisclosure implements Disclosure
{
    public function __construct(
        private Disclosures $disclosures,
        private DisclosureStore $store,
        private HostDisclosureContext $host,
    ) {}

    public function disclose(D\Scope $scope, D\DisclosureRequest $request): D\DisclosureOutcome
    {
        try {
            $context = AccessContext::fromHost($this->host);
            if ($scope != $context->scope()) {
                throw new D\DomainFailure(D\Outcome::Denied, 'scope_mismatch');
            }
            $replay = $this->store->replay($scope, $request);
            if ($replay !== null) {
                return new D\DisclosureOutcome(D\Result::success($replay));
            }
            $binding = $this->host->selection($scope, $request->selectionId());

            return $this->disclosures->disclose($context, $binding->token, $binding->query, $binding->snapshot, $request);
        } catch (D\DomainFailure $failure) {
            return new D\DisclosureOutcome(D\Result::failure($failure));
        } catch (\Throwable) {
            return new D\DisclosureOutcome(D\Result::failure(new D\DomainFailure(D\Outcome::Unavailable, 'disclosure_context_unavailable')));
        }
    }
}
