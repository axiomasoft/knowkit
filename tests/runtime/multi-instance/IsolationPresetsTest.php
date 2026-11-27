<?php
declare(strict_types=1);

namespace KnowKit\Tests\Runtime\MultiInstance;

use KnowKit\Application\Access\{DenyAll, ReceiptFactory, ScopedAllowPolicy};
use KnowKit\Application\Runtime\SingleLocalScopeFactory;
use KnowKit\Conformance\SchemaLoader;
use KnowKit\Domain\{AdapterId, Capability, CapabilityDeclaration, Decision, DomainFailure, InstanceId, IsolationPreset, KbId, Operation, Outcome, ResourceRef, Scope, Sha256, TenantId, UtcInstant};
use KnowKit\Domain\Contract\{ContractValidator, JsonObject, SchemaSet};
use KnowKit\Domain\Layers\DisclosureMode;
use KnowKit\Port\{AdapterDescriptor, Clock};
use KnowKit\Registry\{AdapterRegistry, Factory};
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/packages/core/conformance/autoload.php';

#[Group('runtime:multi-instance')]
final class IsolationPresetsTest extends TestCase
{
    public function testIsolationPresetCasesAndSingleLocalScopeDeterminism(): void
    {
        self::assertSame(
            ['single_local', 'audience_scoped', 'multi_tenant_strict', 'hierarchical_with_mounts'],
            array_map(static fn (IsolationPreset $p): string => $p->value, IsolationPreset::cases()),
        );

        $id = InstanceId::fromString('support_faq');
        $factoryA = new SingleLocalScopeFactory($id, 'support_kb', 'support.answer');
        $factoryB = new SingleLocalScopeFactory($id, 'support_kb', 'support.answer');
        $factoryOther = new SingleLocalScopeFactory(InstanceId::fromString('other_faq'), 'support_kb', 'support.answer');

        $scopeA = $factoryA->scope(policyEpoch: 3);
        $scopeB = $factoryB->scope(policyEpoch: 3);
        $scopeOther = $factoryOther->scope(policyEpoch: 3);

        self::assertTrue($scopeA->equals($scopeB));
        self::assertFalse($scopeA->tenantId->equals($scopeOther->tenantId));
        self::assertFalse($scopeA->kbId->equals($scopeOther->kbId));
        self::assertFalse($scopeA->hostScopeHash->equals($scopeOther->hostScopeHash));
        self::assertSame('3', $scopeA->policyEpoch->value);
    }

    public function testScopedAllowPolicyAllowsExactScopeAndDeniesForeignScope(): void
    {
        $validator = $this->validator();
        $clock = new class implements Clock {
            public function now(): UtcInstant
            {
                return new UtcInstant('2026-11-27T12:00:00Z');
            }
        };

        $instanceId = InstanceId::fromString('support_faq');
        $factory = new SingleLocalScopeFactory($instanceId, 'support_kb', 'support.answer');
        $scope = $factory->scope(policyEpoch: 1);

        $policy = new ScopedAllowPolicy(
            instanceId: $instanceId,
            tenantId: $factory->tenantId(),
            kbId: $factory->kbId(),
            expectedHostScopeHash: $factory->hostScopeHash(),
            clock: $clock,
            validator: $validator,
            defaultDisclosureMode: DisclosureMode::DerivedOnly,
        );

        $kbResource = new ResourceRef($scope->tenantId, 'knowledge_base', $scope->kbId);
        $grant = $policy->authorizeQuery($scope);
        self::assertSame('1', $grant->toWire()->get('grantEpoch'));

        $allowReceipt = $policy->decide($scope, $kbResource, Operation::Disclose);
        self::assertSame(Decision::Allow->value, $allowReceipt->toWire()->get('decision'));
        self::assertSame(DisclosureMode::DerivedOnly, $allowReceipt->maxDisclosureMode());

        // Foreign tenantId
        $foreignTenantScope = new Scope(
            new TenantId('01JFA000000000000000000099'),
            $scope->kbId,
            $scope->actorId,
            $scope->purpose,
            $scope->policyEpoch,
            $scope->hostScopeHash,
        );
        $this->assertAuthorizeDenied($policy, $foreignTenantScope);
        $foreignTenantResource = new ResourceRef($foreignTenantScope->tenantId, 'knowledge_base', $foreignTenantScope->kbId);
        self::assertSame(
            Decision::Deny->value,
            $policy->decide($foreignTenantScope, $foreignTenantResource, Operation::Disclose)->toWire()->get('decision'),
        );

        // Foreign kbId
        $foreignKbScope = new Scope(
            $scope->tenantId,
            new KbId('01JFA000000000000000000088'),
            $scope->actorId,
            $scope->purpose,
            $scope->policyEpoch,
            $scope->hostScopeHash,
        );
        $this->assertAuthorizeDenied($policy, $foreignKbScope);
        self::assertSame(
            Decision::Deny->value,
            $policy->decide($foreignKbScope, $kbResource, Operation::Disclose)->toWire()->get('decision'),
        );

        // Forged hostScopeHash
        $forgedHashScope = new Scope(
            $scope->tenantId,
            $scope->kbId,
            $scope->actorId,
            $scope->purpose,
            $scope->policyEpoch,
            new Sha256(str_repeat('f', 64)),
        );
        $this->assertAuthorizeDenied($policy, $forgedHashScope);
        self::assertSame(
            Decision::Deny->value,
            $policy->decide($forgedHashScope, $kbResource, Operation::Disclose)->toWire()->get('decision'),
        );

        // DenyAll remains the built-in default in AdapterRegistry; ScopedAllowPolicy is never registered as a default
        $denyAll = new DenyAll(new ReceiptFactory($validator, $clock));
        self::assertSame(
            Decision::Deny->value,
            $denyAll->decide($scope, $kbResource, Operation::Disclose)->toWire()->get('decision'),
        );
    }

    private function assertAuthorizeDenied(ScopedAllowPolicy $policy, Scope $scope): void
    {
        try {
            $policy->authorizeQuery($scope);
            self::fail('Foreign scope must be denied');
        } catch (DomainFailure $failure) {
            self::assertSame(Outcome::Denied, $failure->outcome);
            self::assertSame('scope_mismatch', $failure->errorCode);
        }
    }

    private function validator(): ContractValidator
    {
        return SchemaLoader::load(dirname(__DIR__, 3) . '/schemas');
    }
}
