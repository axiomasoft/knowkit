<?php
declare(strict_types=1);

namespace KnowKit\Tests\Security\Policy;

use KnowKit\Application\Access as A;
use KnowKit\Domain as D;
use KnowKit\Domain\Access\{OwnerFreshness, PolicyContext};
use KnowKit\Domain\Contract\{HashDomain, JsonObject};
use KnowKit\Tests\Contract\Ports\PortFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
require_once __DIR__.'/bootstrap.php';

#[Group('security:policy')]
final class PolicyTest extends TestCase
{
    private function rejects(callable $call, D\Outcome $outcome, ?string $code = null): void
    {
        try { $call(); self::fail('Non-current Allow accepted'); }
        catch (D\DomainFailure $e) {
            self::assertSame($outcome,$e->outcome);
            if ($code !== null) { self::assertSame($code,$e->errorCode); }
        }
    }
    private function external(StaticAllowList $owner, Clock $clock, ?OwnerFreshness $freshness = new OwnerFreshness('owner-contract:1',5)): A\ExternalOwnerPolicy
    { return new A\ExternalOwnerPolicy($owner,$owner,$clock,$freshness,PortFixture::validator()); }

    public function testBuiltInPolicyAndEgressDenyByDefault(): void
    {
        $clock = new Clock(); $deny = new A\DenyAll(new A\ReceiptFactory(PortFixture::validator(),$clock));
        $scope = PolicyFixture::scope(); $resource = PolicyFixture::resources()[2];
        $receipt = $deny->decide($scope,$resource,D\Operation::Disclose);
        $wire = $receipt->toWire();
        $this->rejects(fn()=>$receipt->requireAllow($scope,$resource,D\Operation::Disclose,$clock->now(),new D\Sha256(HashDomain::PolicyBinding->digest($wire->get('binding')))),D\Outcome::Denied,'policy_denied');
        self::assertSame('builtin:deny-all:v1',$wire->get('ownerRevision'));
        self::assertSame('2026-11-06T12:00:05.000000Z',$wire->get('expiresAt'));
        self::assertSame([],$deny->authorizeQuery($scope)->toWire()->get('allowedSourceVersionIds'));
        self::assertSame('Deny',$deny->mayEgress($scope,PolicyFixture::request())->toWire()->get('decision'));
    }
    public function testCurrentExternalOwnerReplacesStaleAllowWithDenyUnknownOrError(): void
    {
        $clock = new Clock(); $owner = PolicyFixture::owner($clock); $external = $this->external($owner,$clock);
        $context = A\AccessContext::fromHost(PolicyFixture::host()); $checks = new A\PolicyChecks($external,$external,$clock);
        $checks->prepare($context,PolicyFixture::resources(),D\Operation::Disclose,$owner->binding);
        self::assertSame('Allow',$external->projection()->toWire()->get('decision'));
        foreach ([D\Decision::Deny,D\Decision::Unknown] as $decision) {
            $owner->decision = $decision;
            $this->rejects(fn()=>$checks->prepare($context,PolicyFixture::resources(),D\Operation::Disclose,$owner->binding),
                $decision === D\Decision::Deny ? D\Outcome::Denied : D\Outcome::Unknown);
            self::assertSame($decision->value,$external->projection()->toWire()->get('decision'));
        }
        $owner->fail = true;
        $this->rejects(fn()=>$checks->prepare($context,PolicyFixture::resources(),D\Operation::Disclose,$owner->binding),D\Outcome::Unavailable,'policy_unavailable');
        self::assertNull($external->projection());
        self::assertGreaterThan(4,$owner->calls);
    }
    public function testExpiredOwnerCacheAndUnknownFreshnessCannotAuthorize(): void
    {
        $clock = new Clock(); $owner = PolicyFixture::owner($clock); $scope = PolicyFixture::scope();
        $resource = new D\ResourceRef($scope->tenantId,'knowledge_base',$scope->kbId);
        $owner->cachedReceipt = $owner->decide($scope,$resource,D\Operation::Retrieve);
        $external = $this->external($owner,$clock,new OwnerFreshness('owner-contract:short',2));
        self::assertSame('2026-11-06T12:00:02.000000Z',$external->authorizeQuery($scope)->toWire()->get('expiresAt'));
        $clock->value = '2026-11-06T12:00:02Z';
        $this->rejects(fn()=>$external->authorizeQuery($scope),D\Outcome::Unknown,'owner_freshness_unknown');
        self::assertNull($external->projection());
        $clock->value = '2026-11-06T12:00:05Z';
        $this->rejects(fn()=>$this->external($owner,$clock)->decide($scope,$resource,D\Operation::Retrieve),D\Outcome::Unknown,'owner_freshness_unknown');
        $clock->value = '2026-11-06T12:00:00Z'; $owner->cachedReceipt = null;
        $this->rejects(fn()=>$this->external($owner,$clock,null)->decide($scope,$resource,D\Operation::Retrieve),D\Outcome::Unknown,'owner_freshness_unknown');
    }
    public function testUnknownBusinessPurposeCannotBorrowSameAudienceReadGrant(): void
    {
        $clock = new Clock(); $owner = PolicyFixture::owner($clock); $external = $this->external($owner,$clock);
        $checks = new A\PolicyChecks($external,$external,$clock); $host = PolicyFixture::host();
        $a = A\AccessContext::fromHost($host);
        $checks->prepare($a,PolicyFixture::resources(),D\Operation::Disclose,$owner->binding);
        $host->identity = PolicyFixture::host(PolicyFixture::scope('lesson.other'))->identity;
        $b = A\AccessContext::fromHost($host); $owner->rules['purpose:lesson.other'] = D\Decision::Unknown;
        $this->rejects(fn()=>$checks->prepare($b,PolicyFixture::resources(),D\Operation::Disclose,$owner->binding),D\Outcome::Unknown);
        $this->rejects(fn()=>$checks->authorizeQuery($b,PortFixture::snapshot(),new D\GenerationId(PortFixture::id(14)),[PolicyFixture::source()->sourceVersionId],$owner->binding),D\Outcome::Unknown);
        self::assertSame($a->policy->audience,$b->policy->audience);
        self::assertSame($a->policy->grants,$b->policy->grants);
    }
    public function testSourceDenySurvivesAllowedCollectionMembershipForSearchReadAndPreview(): void
    {
        $clock = new Clock(); $owner = PolicyFixture::owner($clock); $context = A\AccessContext::fromHost(PolicyFixture::host());
        $checks = new A\PolicyChecks($owner,$owner,$clock);
        $owner->rules['source:'.PolicyFixture::source()->sourceId->value] = D\Decision::Deny;
        foreach ([D\Operation::Retrieve,D\Operation::Disclose,D\Operation::Navigate] as $operation) {
            $this->rejects(fn()=>$checks->prepare($context,PolicyFixture::resources(),$operation,$owner->binding),D\Outcome::Denied,'policy_denied');
        }
        $search = $checks->authorizeQuery($context,PortFixture::snapshot(),new D\GenerationId(PortFixture::id(14)),
            [PolicyFixture::source()->sourceVersionId,new D\SourceVersionId(PortFixture::id(999))],$owner->binding);
        // The backend sees its authorized universe before ranking. No source-denied/foreign IDs enter it.
        $backendSeen = $search->allowedSourceVersionIds;
        self::assertSame([],$backendSeen); self::assertSame($context->scope()->hostScopeHash,$search->hostScopeHash);
        unset($owner->rules['source:'.PolicyFixture::source()->sourceId->value]);
        $none = $checks->authorizeQuery($context,PortFixture::snapshot(),new D\GenerationId(PortFixture::id(14)),[],$owner->binding);
        self::assertSame([],$none->allowedSourceVersionIds);
    }
    public function testReadAllowIsNotProcessingOrEgressPermissionAndProviderSeesNoBytes(): void
    {
        $clock = new Clock(); $owner = PolicyFixture::owner($clock); $context = A\AccessContext::fromHost(PolicyFixture::host());
        $checks = new A\PolicyChecks($owner,$owner,$clock); $observed = [];
        $provider = static function(array $bytes) use (&$observed): string { $observed = [...$observed,...$bytes]; return 'sent'; };
        $binding = static fn(): JsonObject => $owner->binding;
        $checks->prepare($context,PolicyFixture::resources(),D\Operation::Disclose,$owner->binding);
        foreach ([D\Decision::Deny,D\Decision::Unknown] as $decision) {
            $owner->egressDecision = $decision;
            $this->rejects(fn()=>$checks->transmit($context,PolicyFixture::request(),['provider input'],$binding,$provider),
                $decision === D\Decision::Deny ? D\Outcome::Denied : D\Outcome::Unknown);
            self::assertSame([],$observed);
        }
        $owner->egressDecision = D\Decision::Allow;
        $owner->rules['lesson.read:ingest:source:'.PolicyFixture::source()->sourceId->value] = D\Decision::Deny;
        $this->rejects(fn()=>$checks->transmit($context,PolicyFixture::request(),['provider input'],$binding,$provider),D\Outcome::Denied);
        self::assertSame(2,$owner->egressCalls); self::assertSame([],$observed);
        $owner->rules = [];
        self::assertSame('sent',$checks->transmit($context,PolicyFixture::request(),['provider input'],$binding,$provider));
        self::assertSame(['provider input'],$observed); self::assertSame(3,$owner->egressCalls);
    }
    public function testAdmissionAllowCannotBeReusedAfterRightsRevokeBeforePublication(): void
    {
        $clock = new Clock(); $owner = PolicyFixture::owner($clock); $context = A\AccessContext::fromHost(PolicyFixture::host());
        $checks = new A\PolicyChecks($owner,$owner,$clock); $published = [];
        $checks->admission($context,PolicyFixture::resources(),D\Operation::Ingest,$owner->binding);
        $owner->decision = D\Decision::Deny;
        $this->rejects(function() use ($checks,$context,$owner,&$published) {
            $checks->prepare($context,PolicyFixture::resources(),D\Operation::Publish,$owner->binding);
            $published[] = 'accepted';
        },D\Outcome::Denied);
        self::assertSame([],$published);
    }
    public function testPreparedDecisionBindsAudiencePurposeHostScopeEpochAndSingleAttempt(): void
    {
        $clock = new Clock(); $owner = PolicyFixture::owner($clock); $host = PolicyFixture::host(); $context = A\AccessContext::fromHost($host);
        $external = $this->external($owner,$clock,new OwnerFreshness('owner-contract:short',2));
        $checks = new A\PolicyChecks($external,$external,$clock);
        foreach (['audience','purpose','host','contextEpoch','epoch','expiry','once'] as $variant) {
            $token = $checks->prepare($context,PolicyFixture::resources(),D\Operation::Disclose,$owner->binding); $calls = $owner->calls;
            $current = $context; $binding = $owner->binding; $now = $clock->now();
            if ($variant === 'audience') { $current=A\AccessContext::fromHost(PolicyFixture::host(audience:'teachers')); }
            if ($variant === 'purpose') { $current=A\AccessContext::fromHost(PolicyFixture::host(PolicyFixture::scope('lesson.other'))); }
            if ($variant === 'host') { $current=A\AccessContext::fromHost(PolicyFixture::host(PolicyFixture::scope(hash:'b'))); }
            if ($variant === 'contextEpoch') { $current=A\AccessContext::fromHost(PolicyFixture::host(PolicyFixture::scope(epoch:'43'))); }
            if ($variant === 'epoch') { $binding=$binding->with('kbPolicyEpoch','43'); }
            if ($variant === 'expiry') { $now=$now->plusSeconds(2); }
            if ($variant === 'once') { $token->requireAllow($current,$binding,$now); }
            $this->rejects(fn()=>$token->requireAllow($current,$binding,$now),$variant==='expiry'?D\Outcome::Unknown:D\Outcome::Denied,
                in_array($variant,['epoch','contextEpoch'],true)?'POLICY_CHANGED':null);
            self::assertSame($calls,$owner->calls,'Owner callback occurred during the guarded recheck');
        }
    }
    public function testActualEgressBytesAndFreshBindingMustMatchBeforeProvider(): void
    {
        $clock = new Clock(); $owner = PolicyFixture::owner($clock); $context = A\AccessContext::fromHost(PolicyFixture::host());
        $checks = new A\PolicyChecks($owner,$owner,$clock); $observed = [];
        $provider = static function(array $bytes) use (&$observed): void { $observed = $bytes; };
        $binding = static fn(): JsonObject => $owner->binding;
        $this->rejects(fn()=>$checks->transmit($context,PolicyFixture::request(),['substituted bytes'],$binding,$provider),D\Outcome::Incompatible,'egress_manifest_mismatch');
        self::assertSame(0,$owner->calls);
        $reads = 0;
        $changed = static function() use ($owner,&$reads): JsonObject { return ++$reads === 1 ? $owner->binding : $owner->binding->with('tenantPolicyEpoch','8'); };
        $this->rejects(fn()=>$checks->transmit($context,PolicyFixture::request(),['provider input'],$changed,$provider),D\Outcome::Denied,'POLICY_CHANGED');
        self::assertSame([],$observed);
    }
    public function testContextChangeDuringEgressCannotSendAndEmptyChecksCannotAllow(): void
    {
        $clock = new Clock(); $owner = PolicyFixture::owner($clock); $host = PolicyFixture::host();
        $context = A\AccessContext::fromHost($host); $checks = new A\PolicyChecks($owner,$owner,$clock);
        $observed=[]; $calls=0;
        $owner->beforeCall=static function() use ($host,&$calls): void {
            if (++$calls===4) { $host->identity=PolicyFixture::host(audience:'teachers')->identity; }
        };
        $provider=static function(array $bytes) use (&$observed): void { $observed=$bytes; };
        $this->rejects(fn()=>$checks->transmit($context,PolicyFixture::request(),['provider input'],static fn()=>$owner->binding,$provider),D\Outcome::Denied,'scope_mismatch');
        self::assertSame([],$observed); self::assertSame(4,$calls);
        foreach ([
            [fn()=>$checks->prepare($context,[],D\Operation::Disclose,$owner->binding), 'Exact authorization resources required'],
            [fn()=>new A\PreparedDecision($context,D\Operation::Disclose,[],null), 'Nonempty owner receipt set required'],
        ] as [$empty, $message]) {
            try { $empty(); self::fail('Empty check authorized'); }
            catch (\InvalidArgumentException $failure) { self::assertSame($message, $failure->getMessage()); }
        }
    }
    public function testProcessingAndEgressOwnerWindowsAreRecheckedAtActualTransmission(): void
    {
        foreach (['processing','egress'] as $expires) {
            $clock=new Clock(); $owner=PolicyFixture::owner($clock); $context=A\AccessContext::fromHost(PolicyFixture::host());
            $processing=$this->external($owner,$clock,new OwnerFreshness('processing:1',$expires==='processing'?2:5));
            $egress=$this->external($owner,$clock,new OwnerFreshness('egress:1',$expires==='egress'?2:5));
            $checks=new A\PolicyChecks($processing,$egress,$clock); $reads=0; $observed=[];
            $current=static function() use ($clock,$owner,&$reads): JsonObject {
                if (++$reads===2) { $clock->value='2026-11-06T12:00:02Z'; }
                return $owner->binding;
            };
            $provider=static function(array $bytes) use (&$observed): void { $observed=$bytes; };
            $this->rejects(fn()=>$checks->transmit($context,PolicyFixture::request(),['provider input'],$current,$provider),D\Outcome::Unknown,'owner_freshness_unknown');
            self::assertSame([],$observed);
        }
    }
}
