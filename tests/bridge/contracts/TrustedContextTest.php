<?php

declare(strict_types=1);

namespace KnowKit\Tests\Bridge\Contracts;

use AgenToolsPlatform\Core\Common\ContractViolation;
use AgenToolsPlatform\Core\Common\PayloadHash;
use AgenToolsPlatform\Core\Common\Ref;
use AgenToolsPlatform\Core\Common\RunId;
use AgenToolsPlatform\Core\Common\TenantId;
use AgenToolsPlatform\Core\Security\ExecutionContext;
use KnowKit\Bridge\AgenTools\Legacy\ContextMap;
use KnowKit\Domain\ActorId;
use KnowKit\Domain\DomainFailure;
use KnowKit\Domain\Outcome;
use KnowKit\Domain\Scope;
use KnowKit\Domain\Sha256;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support.php';

#[Group('bridge:contracts')]
final class TrustedContextTest extends TestCase
{
    public function testTrustedScopeAndRunMapWithoutChangingHostScopeHashOrUsageOwner(): void
    {
        [$host, $context, $hash, $scope] = Support::trusted();
        $map = new ContextMap($host);
        self::assertSame($scope, $map->scope(context: $context, scope: $scope, hostScopeHash: $hash));
        self::assertSame(substr($hash->value, strlen('sha-256:')), $scope->hostScopeHash->value);
        $usage = $map->usage(
            context: $context,
            scope: $scope,
            hostScopeHash: $hash,
            run: new RunId(tenant: $context->identity->tenant, value: 'run:golden'),
            application: new Ref(tenant: $context->identity->tenant, value: 'application:host'),
        );
        self::assertSame($scope, $usage->scope);
        self::assertSame($scope->hostScopeHash, $usage->scope->hostScopeHash);
        self::assertSame('run:golden', $usage->runRef->value);
        self::assertSame('application:host', $usage->applicationRef->value);
        self::assertSame('trace:golden', $usage->correlationId);
        self::assertEquals(Support::wire(['runRef' => 'run:golden']), $usage->disclosureWire());
        self::assertSame(3, $host->calls);
    }

    public function testModelScopeSpoofCannotChangeTrustedTenantActorPurposeOrHash(): void
    {
        [$host, $context, $hash, $scope] = Support::trusted();
        $map = new ContextMap($host);
        $variants = [
            ['tenantId' => new \KnowKit\Domain\TenantId(Support::OTHER_TENANT)],
            ['actorId' => new ActorId(Support::OTHER_TENANT)],
            ['purpose' => 'support.write'],
            ['hostScopeHash' => new Sha256(str_repeat('b', 64))],
        ];
        foreach ($variants as $change) {
            $args = array_merge(get_object_vars($scope), $change);
            try {
                $map->scope(context: $context, scope: new Scope(...$args), hostScopeHash: $hash);
                self::fail('Spoofed scope accepted');
            } catch (DomainFailure $failure) {
                self::assertSame(Outcome::Denied, $failure->outcome);
                self::assertSame('scope_mismatch', $failure->errorCode);
            }
        }
        self::assertFalse((new \ReflectionClass(ExecutionContext::class))->getConstructor()->isPublic());
        self::assertFalse((new \ReflectionClass(ExecutionContext::class))->hasMethod('fromArray'));
        $this->expectException(\TypeError::class);
        (new \ReflectionMethod($map, 'scope'))->invokeArgs($map, [
            ['tenant' => 'beta', 'authorized' => true], $scope, $hash,
        ]);
    }

    public function testHashDriftIsDeniedAndScopeBindingChanges(): void
    {
        [$host, $context, $hash, $scope] = Support::trusted();
        $changedHash = new PayloadHash('sha-256:' . str_repeat('b', 64));
        $changed = new Scope(...array_merge(get_object_vars($scope), ['hostScopeHash' => new Sha256(str_repeat('b', 64))]));
        self::assertNotEquals($scope->bindingDigest(new Sha256(str_repeat('a', 64))), $changed->bindingDigest(new Sha256(str_repeat('a', 64))));
        $this->expectException(DomainFailure::class);
        (new ContextMap($host))->scope(context: $context, scope: $scope, hostScopeHash: $changedHash);
    }

    public function testRunAndApplicationRefsCannotCrossTenant(): void
    {
        foreach (['run', 'application'] as $foreignField) {
            [$host, $context, $hash, $scope] = Support::trusted();
            $foreign = new TenantId(Support::OTHER_TENANT);
            try {
                (new ContextMap($host))->usage(
                    context: $context,
                    scope: $scope,
                    hostScopeHash: $hash,
                    run: new RunId(tenant: $foreignField === 'run' ? $foreign : $context->identity->tenant, value: 'run:golden'),
                    application: new Ref(tenant: $foreignField === 'application' ? $foreign : $context->identity->tenant, value: 'application:host'),
                );
                self::fail('Foreign usage owner accepted');
            } catch (ContractViolation $failure) {
                self::assertNotEmpty($failure->getMessage());
            }
        }
    }

    public function testTrustedMappingRechecksRevocationAndPolicyDrift(): void
    {
        foreach (['denied', 'revision'] as $change) {
            [$host, $context, $hash, $scope] = Support::trusted();
            $map = new ContextMap($host);
            self::assertSame($scope, $map->scope(context: $context, scope: $scope, hostScopeHash: $hash));
            $host->$change = $change === 'denied' ? true : 'policy-2';
            try {
                $map->scope(context: $context, scope: $scope, hostScopeHash: $hash);
                self::fail('Stale authorization accepted');
            } catch (ContractViolation $failure) {
                self::assertNotEmpty($failure->getMessage());
            }
        }
    }
}
