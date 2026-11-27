<?php

declare(strict_types=1);

namespace KnowKit\Application;

use Closure;
use KnowKit\Application\Access\AccessContext;
use KnowKit\Application\Access\HostIdentity;
use KnowKit\Application\Access\PolicyChecks;
use KnowKit\Application\SourceNavigation\CanonicalDocument;
use KnowKit\Application\SourceNavigation\QuoteLocator;
use KnowKit\Application\SourceNavigation\TextQuoteSelector;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\ContractViolation;
use KnowKit\Domain\Navigation as N;
use KnowKit\Port as P;

/** Canonical public service. Each call is a new host-authenticated boundary, without cached rights. */
final readonly class SourceNavigation
{
    public function __construct(
        private HostIdentity $host,
        private P\AccessPolicy $access,
        private PolicyChecks $checks,
        private P\NavigationStore $store,
        private P\CanonicalDocuments $documents,
        private P\Clock $clock,
        private P\IdGenerator $ids,
        private N\Limits $limits = new N\Limits(),
        private ?P\NfdNormalizer $normalizer = null,
    ) {}

    public function listStructure(D\Scope $scope, D\DocumentRevisionRef $revision, int $depth, int $limit): N\StructureOutline
    {
        try {
            if ($depth < 1 || $depth > 24 || $limit < 1 || $limit > $this->limits->maxUnits) {
                throw new D\DomainFailure(D\Outcome::Incompatible, 'limit_exceeded');
            }
            [$plan, $document, $authorize] = $this->load($scope, $revision, D\Operation::Navigate, D\Layers\ResolutionTier::R0Outline);
            $outline = $document->outline($revision, $depth, $limit, $this->limits);
            $this->store->check($scope, $revision, $plan, $document->sourceUnits(), $authorize);

            return $outline;
        } catch (\Throwable $error) {
            $failure = $this->failure($error);

            return new N\StructureOutline($failure->outcome, $revision, errorCode: $failure->errorCode);
        }
    }

    public function readUnits(D\Scope $scope, D\DocumentRevisionRef $revision, N\UnitRange $range, int $maxBytes): D\DisclosureOutcome
    {
        try {
            $range->requireLimits($this->limits);
            if ($maxBytes < 1 || $maxBytes > $this->limits->maxTextBytes) {
                throw new D\DomainFailure(D\Outcome::Incompatible, 'limit_exceeded');
            }
            [$plan, $document, $authorize] = $this->load($scope, $revision, D\Operation::Disclose, null);
            $units = $document->read($range, $maxBytes, $this->limits);
            $result = $this->store->disclose($scope, $revision, $plan, $units, $this->ids->next(), $authorize);

            return new D\DisclosureOutcome(D\Result::success($result));
        } catch (\Throwable $error) {
            return new D\DisclosureOutcome(D\Result::failure($this->failure($error)));
        }
    }

    public function locateQuote(D\Scope $scope, D\DocumentRevisionRef $revision, string $quote, N\MatchMode $mode): N\QuoteLocation
    {
        try {
            if ($quote === '' || strlen($quote) > $this->limits->maxTextBytes || preg_match('//u', $quote) !== 1) {
                throw new D\DomainFailure(D\Outcome::Incompatible, 'invalid_quote');
            }
            [$plan, $document, $authorize] = $this->load($scope, $revision, D\Operation::Navigate, null);
            $location = (new QuoteLocator($this->normalizer))->locate($document, $revision,
                new TextQuoteSelector($quote), $mode, $this->limits);
            $this->store->check($scope, $revision, $plan, $document->sourceUnits(), $authorize);

            return $location;
        } catch (\Throwable $error) {
            $failure = $this->failure($error);

            return new N\QuoteLocation($failure->outcome->value, $revision, errorCode: $failure->errorCode);
        }
    }

    /** @return array{N\NavigationPlan,CanonicalDocument,Closure(N\NavigationPlan,D\UtcInstant):D\DisclosureDeadline} */
    private function load(D\Scope $scope, D\DocumentRevisionRef $revision, D\Operation $operation, ?D\Layers\ResolutionTier $requiredTier): array
    {
        $context = AccessContext::fromHost($this->host);
        $requested = new D\Access\PolicyContext($scope, $context->policy->audience,
            $context->policy->grants, $context->policy->egress);
        $requested->assertSame($context->policy);
        $scope->assertTenant($revision->sourceRef->tenantId);
        $plan = $this->store->plan($scope, $revision);
        $authorize = $this->authorize($context, $revision, $plan, $operation, $requiredTier);
        $ir = $this->documents->read($revision, 8388608);
        $context->assertCurrent();
        $document = new CanonicalDocument($ir, $revision, $plan->documentId);
        if ($document->coverage->canonical() !== $plan->coverage->canonical()) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'immutable_conflict');
        }

        return [$plan, $document, $authorize];
    }

    /** @return Closure(N\NavigationPlan,D\UtcInstant):D\DisclosureDeadline */
    private function authorize(AccessContext $context, D\DocumentRevisionRef $revision,
        N\NavigationPlan $plan, D\Operation $operation, ?D\Layers\ResolutionTier $requiredTier): Closure
    {
        $scope = $context->scope();
        $grant = $this->grant($scope, $revision);
        $decision = $this->checks->prepare($context, $plan->guards->resources, $operation, $plan->guards->binding());
        if ($requiredTier !== null) {
            $decision->requireTier($requiredTier);
        } else {
            $decision->requireDirectRead();
        }

        return static function (N\NavigationPlan $current, D\UtcInstant $now) use ($context, $scope, $revision, $grant, $decision): D\DisclosureDeadline {
            $context->assertCurrent();
            $grant->requireNavigation($scope, $revision, $now);
            $decision->requireAllow($context, $current->guards->binding(), $now);
            $deadline = $decision->disclosureDeadline();
            $grantDeadline = new D\DisclosureDeadline($grant->expiresAt(), false);

            return $deadline->expiresAt->compare($grantDeadline->expiresAt) <= 0 ? $deadline : $grantDeadline;
        };
    }

    private function grant(D\Scope $scope, D\DocumentRevisionRef $revision): D\QueryGrant
    {
        try {
            $grant = $this->access->authorizeQuery($scope);
        } catch (D\DomainFailure $error) {
            throw $error;
        } catch (\Throwable) {
            throw new D\DomainFailure(D\Outcome::Unavailable, 'policy_unavailable');
        }
        $grant->requireNavigation($scope, $revision, $this->clock->now());

        return $grant;
    }

    private function failure(\Throwable $error): D\DomainFailure
    {
        if ($error instanceof D\DomainFailure) {
            return $error;
        }
        if ($error instanceof ContractViolation) {
            return new D\DomainFailure(D\Outcome::Incompatible, $error->errorCode);
        }

        return new D\DomainFailure(D\Outcome::Unavailable, 'navigation_unavailable');
    }
}
