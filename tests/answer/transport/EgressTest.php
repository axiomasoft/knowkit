<?php

declare(strict_types=1);

namespace KnowKit\Tests\Answer\Transport;

use DateTimeImmutable;
use KnowKit\Answer\Application\CurrentModelEgress;
use KnowKit\Answer\Application\InvocationDispatch;
use KnowKit\Answer\Domain as A;
use KnowKit\Answer\Port as P;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\HashDomain;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Port\EgressPolicy;
use KnowKit\Tests\Answer\Contracts\Fixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

#[Group('answer:transport')]
final class EgressTest extends TestCase
{
    public function testPostMarkSendingQuestionOrHistoryDenialPreventsAnyTransportSend(): void
    {
        foreach (['QUESTION', 'HISTORY', 'Unknown', 'expired', 'exception'] as $denial) {
            $f = new TransportFixture();
            $request = TransportFixture::request();
            $manifest = TransportFixture::manifest($request->identity);
            $marked = false;
            $ledger = $this->ledger($request, $marked);
            $policy = $this->createMock(EgressPolicy::class);
            $policy->expects(self::once())->method('mayEgress')->willReturnCallback(
                function (D\Scope $scope, D\EgressRequest $outbound) use (&$marked, $manifest, $request, $denial, $f): D\DecisionReceipt {
                    self::assertTrue($marked, 'Fresh egress must follow markSending');
                    self::assertEquals($request->identity->binding->scope, $scope);
                    self::assertSame('lesson.read', $scope->purpose);
                    self::assertSame(str_repeat('a', 64), $scope->hostScopeHash->value);
                    self::assertEquals($manifest->toCore(TransportFixture::validator())->toWire(), $outbound->toWire());
                    $items = $outbound->toWire()->get('items');
                    self::assertSame(['SYSTEM_INSTRUCTION', 'HISTORY', 'EVIDENCE', 'QUESTION'], array_map(static fn (JsonObject $item) => $item->get('category'), $items));
                    if ($denial === 'exception') {
                        throw new \RuntimeException(TransportFixture::canary());
                    }
                    // Evidence is explicitly allowed; another category independently denies egress.
                    self::assertNotEmpty($items[2]->get('sourceVersionRefs'));

                    return $this->receipt($manifest, $f->clock, $denial === 'Unknown' ? 'Unknown' : ($denial === 'expired' ? 'Allow' : 'Deny'), $denial === 'expired');
                },
            );
            $authorization = $this->createMock(P\InvocationAuthorization::class);
            $guard = new CurrentModelEgress($policy, $f->clock, TransportFixture::validator());
            $authorization->expects(self::once())->method('beforeSend')->willReturnCallback(
                fn (D\Scope $scope, A\ModelCallIdentity $identity) => $guard->check($identity, $manifest, $this->policyDigest()),
            );
            $transport = $this->createMock(P\ModelTransport::class);
            $transport->method('descriptor')->willReturn($f->transport->descriptor());
            $transport->expects(self::never())->method('send');
            $result = (new InvocationDispatch($ledger, $authorization, $transport, $f->clock))->send($this->record($request)->claim, $request, Fixture::cancellation());
            self::assertInstanceOf(A\AnswerOutcome::class, $result);
            self::assertSame(in_array($denial, ['Unknown', 'exception'], true) ? A\OutcomeCode::PolicyUnknown : A\OutcomeCode::EgressDenied, $result->code);
            self::assertSame(A\DispatchCertainty::KnownNotSent, $result->certainty);
            self::assertNull($result->answeredContent());
            self::assertSame(0, $f->http->sendCount());
            self::assertSame(0, $f->http->outboundBytes());
        }
    }

    public function testCoreManifestPreservesExactDestinationCategoriesHashesAndSourceVersions(): void
    {
        $request = TransportFixture::request();
        $manifest = TransportFixture::manifest($request->identity);
        $wire = $manifest->toCore(TransportFixture::validator())->toWire();
        self::assertSame('model_egress_context', $wire->get('operation'));
        self::assertEquals(new JsonObject([
            'providerId' => 'host-provider',
            'accountId' => 'account:lesson',
            'region' => 'eu-test',
            'modelId' => 'chat-model',
        ]), $wire->get('destination'));
        foreach ($manifest->items as $index => $item) {
            self::assertEquals($item->toWire(), $wire->get('items')[$index]);
        }
        $other = TransportFixture::request(changes: ['binding' => TransportFixture::binding(['region' => 'other-region'])]);
        self::assertNotSame($wire->get('manifestDigest'), TransportFixture::manifest($other->identity)->toCore(TransportFixture::validator())->toWire()->get('manifestDigest'));
        $other = TransportFixture::request(TransportFixture::request()->bytes . ' ');
        self::assertNotSame($wire->get('manifestDigest'), TransportFixture::manifest($other->identity)->toCore(TransportFixture::validator())->toWire()->get('manifestDigest'));
        foreach ([0, 1, 2, 3] as $omit) {
            $items = $manifest->items;
            array_splice($items, $omit, 1);
            $this->rejects(static fn () => new A\EgressContentManifest($request->identity, $items));
        }
        $items = $manifest->items;
        $items[3] = new A\EgressContentItem(A\EgressCategory::Evidence, $items[3]->contentDigest, $items[2]->sources);
        $this->rejects(static fn () => new A\EgressContentManifest($request->identity, $items));
        $items = $manifest->items;
        $items[2] = new A\EgressContentItem(A\EgressCategory::Evidence, new D\Sha256(str_repeat('1', 64)), $items[2]->sources);
        $this->rejects(static fn () => new A\EgressContentManifest($request->identity, $items));
    }

    public function testFreshAllowChecksFullHostScopeAndEveryExactResourceWithoutChangingIdentity(): void
    {
        $f = new TransportFixture();
        $request = TransportFixture::request();
        $manifest = TransportFixture::manifest($request->identity);
        $policy = $this->createMock(EgressPolicy::class);
        $policy->expects(self::once())->method('mayEgress')->willReturn($this->receipt($manifest, $f->clock));
        $guard = new CurrentModelEgress($policy, $f->clock, TransportFixture::validator());
        self::assertNull($guard->check($request->identity, $manifest, $this->policyDigest()));
        $other = TransportFixture::request(changes: ['binding' => TransportFixture::binding(['model' => 'changed-model'])]);
        $this->rejects(fn () => $guard->check($other->identity, $manifest, $this->policyDigest()));
    }

    public function testMissingConfigurationCompletesUnavailableWithoutAnyNetwork(): void
    {
        $request = TransportFixture::request();
        $marked = false;
        $ledger = $this->ledger($request, $marked, true);
        $authorization = $this->createMock(P\InvocationAuthorization::class);
        $authorization->expects(self::once())->method('beforeSend')->willReturn(null);
        $clock = new TransportClock();
        $transport = new \KnowKit\Answer\Adapter\OpenAiCompatibleTransport($clock);
        $result = (new InvocationDispatch($ledger, $authorization, $transport, $clock))->send($this->record($request)->claim, $request, Fixture::cancellation());
        self::assertInstanceOf(A\AnswerOutcome::class, $result);
        self::assertSame(A\OutcomeCode::Unavailable, $result->code);
        self::assertSame(A\DispatchCertainty::KnownNotSent, $result->certainty);
        self::assertSame(A\UnavailableComponent::Transport, $result->component);
    }

    private function ledger(A\TransportRequest $request, bool &$marked, bool $live = false): P\InvocationLedger
    {
        $ledger = $this->createMock(P\InvocationLedger::class);
        $ledger->expects(self::once())->method('markSending')->willReturnCallback(
            function (A\LedgerClaim $claim, A\ModelCallIdentity $identity) use ($request, &$marked): A\InvocationRecord {
                self::assertEquals($request->identity->digest(), $identity->digest());
                $marked = true;

                return $this->record($request, A\InvocationState::Sending);
            },
        );
        $ledger->expects(self::once())->method('complete')->willReturn($this->record($request, A\InvocationState::Completed));
        $ledger->expects(self::never())->method('markUnknown');
        $ledger->expects($live ? self::once() : self::never())->method('assertLive');

        return $ledger;
    }

    private function record(A\TransportRequest $request, A\InvocationState $state = A\InvocationState::Reserved): A\InvocationRecord
    {
        $digest = new D\Sha256(str_repeat('1', 64));
        $identity = $request->identity;
        $claim = new A\LedgerClaim($identity->invocationRef, new D\Decimal('2'), 'fixture-owner', new DateTimeImmutable('2030-01-01T00:00:00Z'), new D\Decimal('1'));

        return new A\InvocationRecord(
            $identity->invocationRef,
            $digest,
            $digest,
            new A\InvocationPins($identity->binding, Fixture::profile(), $digest),
            $state,
            $claim,
            $state === A\InvocationState::Sending ? $identity->digest() : null,
        );
    }

    private function policyBinding(): JsonObject
    {
        return new JsonObject([
            'tenantPolicyEpoch' => '1',
            'kbPolicyEpoch' => Fixture::scope()->policyEpoch->value,
            'sources' => [new JsonObject([
                'sourceId' => Fixture::citation()->toWire()->get('sourceId'),
                'sourcePolicyEpoch' => '1',
                'lifecycleEpoch' => '1',
            ])],
        ]);
    }

    private function policyDigest(): D\Sha256
    {
        return new D\Sha256(HashDomain::PolicyBinding->digest($this->policyBinding()));
    }

    private function receipt(A\EgressContentManifest $manifest, TransportClock $clock, string $decision = 'Allow', bool $expired = false): D\DecisionReceipt
    {
        $now = new DateTimeImmutable($clock->now()->value);
        $wire = new JsonObject([
            'decision' => $decision,
            'operation' => 'model_egress_context',
            'resourceRefs' => array_map(static fn (D\ResourceRef $ref) => $ref->toWire(), $manifest->resources()),
            'binding' => $this->policyBinding(),
            'ownerRevision' => 'host:1',
            'checkedAt' => $now->modify('-1 second')->format('Y-m-d\TH:i:s.u\Z'),
            'expiresAt' => $now->modify($expired ? '-1 second' : '+4 seconds')->format('Y-m-d\TH:i:s.u\Z'),
            'hostScopeHash' => Fixture::scope()->hostScopeHash->value,
        ]);

        return new D\DecisionReceipt($wire->with('receiptDigest', HashDomain::DecisionReceipt->digest($wire)), TransportFixture::validator());
    }

    private function rejects(callable $action): void
    {
        try {
            $action();
        } catch (\InvalidArgumentException $error) {
            self::assertNotEmpty($error->getMessage());

            return;
        }
        self::fail('Incomplete or substituted egress manifest accepted');
    }
}
