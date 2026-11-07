<?php

declare(strict_types=1);

namespace KnowKit\Tests\Retrieval\Disclosure;

use KnowKit\Application\Disclosure;
use KnowKit\Application\Retrieval\Disclosure\FreshDisclosure;
use KnowKit\Application\Retrieval\Disclosure\HostDisclosureContext;
use KnowKit\Domain as D;
use KnowKit\Domain\Access\PolicyContext;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

#[Group('retrieval:disclosure')]
final class PublicBoundaryTest extends TestCase
{
    public function testApprovedPublicContractUsesTrustedBindingsAndReplayNeedsNoOldToken(): void
    {
        Fixture::run(function (Fixture $f): void {
            $selected = $f->select();
            $request = $f->request($selected);
            $host = new class($f, $selected->token) implements HostDisclosureContext {
                public bool $forget = false;
                public int $resolutions = 0;

                public function __construct(
                    private Fixture $fixture,
                    private D\SelectionToken $token,
                ) {}

                public function current(): ?PolicyContext
                {
                    return $this->fixture->host->identity;
                }

                public function selection(D\Scope $scope, D\SelectionId $selection): D\DisclosureBinding
                {
                    ++$this->resolutions;
                    if ($this->forget) {
                        throw new D\DomainFailure(D\Outcome::Denied, 'selection_invalid');
                    }

                    return new D\DisclosureBinding($this->token, $this->fixture->query(), $this->fixture->host->inventory->snapshot);
                }
            };
            $service = new FreshDisclosure($f->service, $f->usages, $host);
            self::assertInstanceOf(Disclosure::class, $service);
            $scope = $f->host->identity->scope;
            $first = $service->disclose($scope, $request);
            self::assertSame(D\Outcome::Success, $first->result->outcome);
            self::assertInstanceOf(D\EvidenceSet::class, $first->result->data);
            $host->forget = true;
            $f->revoke();
            $replay = $service->disclose($scope, $request);
            self::assertInstanceOf(D\DisclosureReplay::class, $replay->result->data);
            self::assertSame(1, $host->resolutions);
            $forged = new D\Scope($scope->tenantId, $scope->kbId, $scope->actorId, $scope->purpose,
                $scope->policyEpoch, new D\Sha256(str_repeat('b', 64)));
            $denied = $service->disclose($forged, $request);
            self::assertSame(D\Outcome::Denied, $denied->result->outcome);
            self::assertNull($denied->result->data);
            $newAttempt = $service->disclose($scope, $f->request($selected));
            self::assertSame(D\Outcome::Denied, $newAttempt->result->outcome);
            self::assertNull($newAttempt->result->data);
        });
    }
}
