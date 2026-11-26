<?php
declare(strict_types=1);

namespace KnowKit\Tests\Security\Policy;

use KnowKit\Application\Access as A;
use KnowKit\Domain as D;
use KnowKit\Domain\Access\PolicyContext;
use KnowKit\Domain\Contract\JsonObject;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
require_once __DIR__.'/bootstrap.php';

#[Group('security:policy')]
final class ContextTest extends TestCase
{
    private function denied(callable $call, string $code): void
    {
        try { $call(); self::fail('Untrusted context accepted'); }
        catch (D\DomainFailure $e) { self::assertSame(D\Outcome::Denied,$e->outcome); self::assertSame($code,$e->errorCode); }
    }
    public function testModelIdentityCannotReplaceTrustedHostScope(): void
    {
        $host = PolicyFixture::host(); $boundary = new A\RequestBoundary($host);
        foreach (PolicyFixture::cases()['forgedFields'] as $field) {
            $this->denied(fn()=>$boundary->context(new JsonObject([$field=>'model-supplied'])),'untrusted_context');
        }
        $context = $boundary->context(new JsonObject(['query'=>'untrusted search text']));
        self::assertSame($host->identity->scope,$context->scope());
        self::assertSame(str_repeat('a',64),$context->scope()->hostScopeHash->value);
        self::assertSame('lesson.read',$context->scope()->purpose);
        self::assertNotContains($context->scope()->purpose,array_column(D\Operation::cases(),'value'));
    }
    public function testUnauthenticatedOrMismatchedBodyEmitsNoMetadata(): void
    {
        // Shared core transport guard, not qualification of an optional HTTP adapter.
        $host = new Host(null); $boundary = new A\RequestBoundary($host); $emitted = [];
        $body = new JsonObject(['scope'=>PolicyFixture::scope(tenant:9)->toWire()]);
        $this->denied(function() use ($boundary,$body,&$emitted) { $context=$boundary->context($body); $emitted[]=$context->scope()->toWire(); },'unauthenticated');
        $host->identity = PolicyFixture::host()->identity;
        $this->denied(function() use ($boundary,$body,&$emitted) { $context=$boundary->context($body); $emitted[]=$context->scope()->toWire(); },'untrusted_context');
        self::assertSame([],$emitted);
    }
    public function testAudiencePurposeAndScopeResetInvalidatePreviousContext(): void
    {
        $host = PolicyFixture::host(); $pinned = A\AccessContext::fromHost($host); $original = $host->identity;
        foreach ([new PolicyContext($original->scope,'teachers',$original->grants,$original->egress),
            PolicyFixture::host(PolicyFixture::scope('lesson.other'))->identity,
            PolicyFixture::host(PolicyFixture::scope(actor:102))->identity,
            PolicyFixture::host(PolicyFixture::scope(hash:'b'))->identity,
            new PolicyContext($original->scope,'students',[],[])] as $current) {
            $host->identity = $current; $this->denied($pinned->assertCurrent(...),'scope_mismatch');
        }
        $host->identity = null; $this->denied($pinned->assertCurrent(...),'scope_mismatch');
        $this->denied(fn()=>A\AccessContext::fromHost($host),'unauthenticated');
        $host->identity = PolicyFixture::host(PolicyFixture::scope(epoch:'43'))->identity;
        $this->denied($pinned->assertCurrent(...),'POLICY_CHANGED');
        $host->identity = PolicyFixture::host(PolicyFixture::scope(tenant:9))->identity;
        $next = A\AccessContext::fromHost($host); $next->assertCurrent();
        self::assertNotEquals($pinned->scope()->tenantId,$next->scope()->tenantId);
        $this->denied($pinned->assertCurrent(...),'scope_mismatch');
    }
    public function testHostIdentityFailureIsTypedWithoutRawInputs(): void
    {
        $host = new class implements A\HostIdentity {
            public function current(): ?PolicyContext { throw new \RuntimeException('raw host input'); }
        };
        try { A\AccessContext::fromHost($host); self::fail('Unavailable identity accepted'); }
        catch (D\DomainFailure $failure) {
            self::assertSame(D\Outcome::Unavailable,$failure->outcome);
            self::assertSame('identity_unavailable',$failure->getMessage());
            self::assertNull($failure->getPrevious());
        }
    }
    public function testMalformedHostEligibilityAndUnboundedOwnerWindowAreRejected(): void
    {
        $scope=PolicyFixture::scope();
        foreach ([
            [fn()=>new PolicyContext($scope,''), 'Bounded host policy identifier required'],
            [fn()=>new PolicyContext($scope,'students',['read','read']), 'Bounded unique host eligibility required'],
            [fn()=>new PolicyContext($scope,'students',['*']), 'Bounded host policy identifier required'],
            [fn()=>new PolicyContext($scope,'students',array_map(static fn(int $i): string=>'grant:'.$i,range(0,256))), 'Bounded unique host eligibility required'],
            [fn()=>new D\Access\OwnerFreshness('',1), 'Explicit bounded owner freshness contract required'],
            [fn()=>new D\Access\OwnerFreshness('owner:1',0), 'Explicit bounded owner freshness contract required'],
            [fn()=>new D\Access\OwnerFreshness('owner:1',6), 'Explicit bounded owner freshness contract required'],
        ] as [$invalid, $message]) {
            try { $invalid(); self::fail('Invalid trusted policy composition accepted'); }
            catch (\InvalidArgumentException $failure) { self::assertSame($message, $failure->getMessage()); }
        }
    }
}
