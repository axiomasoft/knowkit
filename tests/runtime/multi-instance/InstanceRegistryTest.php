<?php
declare(strict_types=1);

namespace KnowKit\Tests\Runtime\MultiInstance;

use KnowKit\Application\Runtime\{InstanceRegistry, KnowledgeFacade, KnowledgeInstance};
use KnowKit\Domain\{DomainFailure, InstanceId, IsolationPreset, Outcome};
use KnowKit\Registry\{AdapterRegistry, HostProfile, ReadinessReport};
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/packages/core/conformance/autoload.php';
require_once dirname(__DIR__, 3) . '/examples/custom-adapter/FakeHost.php';

#[Group('runtime:multi-instance')]
final class InstanceRegistryTest extends TestCase
{
    public function testDuplicateIdentifierAndFrozenRegistryRejectRegistration(): void
    {
        $registry = new InstanceRegistry();
        $id = InstanceId::fromString('support_faq');
        $profile = $this->profile();
        $called = 0;

        $registry->register(
            id: $id,
            profile: $profile,
            preset: IsolationPreset::SingleLocal,
            factory: static function () use (&$called): KnowledgeInstance {
                $called++;
                throw new \RuntimeException('should_not_be_called_at_register');
            },
        );
        self::assertSame(0, $called);

        try {
            $registry->register($id, $profile, IsolationPreset::SingleLocal, static fn (): never => throw new \RuntimeException('dup'));
            self::fail('Duplicate InstanceId must fail');
        } catch (DomainFailure $failure) {
            self::assertSame(Outcome::Incompatible, $failure->outcome);
            self::assertSame('duplicate_identifier', $failure->errorCode);
        }

        try {
            $registry->get($id);
            self::fail('get() before boot must fail');
        } catch (DomainFailure $failure) {
            self::assertSame(Outcome::Incompatible, $failure->outcome);
            self::assertSame('registry_not_booted', $failure->errorCode);
        }

        $registry->boot();

        try {
            $registry->register(
                InstanceId::fromString('second_kb'),
                $profile,
                IsolationPreset::MultiTenantStrict,
                static fn (): never => throw new \RuntimeException('frozen'),
            );
            self::fail('register() after boot must fail');
        } catch (DomainFailure $failure) {
            self::assertSame(Outcome::Incompatible, $failure->outcome);
            self::assertSame('registry_frozen', $failure->errorCode);
        }

        try {
            $registry->get(InstanceId::fromString('unknown_instance'));
            self::fail('Unknown InstanceId must not fall back to a default instance');
        } catch (DomainFailure $failure) {
            self::assertSame(Outcome::Incompatible, $failure->outcome);
            self::assertSame('instance_not_found', $failure->errorCode);
        }

        try {
            InstanceId::fromString('Invalid_Upper');
            self::fail('Invalid InstanceId must be rejected');
        } catch (DomainFailure $failure) {
            self::assertSame(Outcome::Incompatible, $failure->outcome);
            self::assertSame('invalid_identifier', $failure->errorCode);
        }
    }

    public function testFaultContainmentIsolatesBrokenInstanceReadiness(): void
    {
        $registry = new InstanceRegistry();
        $supportId = InstanceId::fromString('support_faq');
        $enterpriseId = InstanceId::fromString('enterprise_kb');
        $supportProfile = $this->profile();
        $enterpriseProfile = $this->profile();
        $healthyFacade = (new \ReflectionClass(KnowledgeFacade::class))->newInstanceWithoutConstructor();

        $registry->register(
            id: $supportId,
            profile: $supportProfile,
            preset: IsolationPreset::SingleLocal,
            factory: static fn (): KnowledgeInstance => new KnowledgeInstance(
                id: $supportId,
                profile: $supportProfile,
                preset: IsolationPreset::SingleLocal,
                facade: $healthyFacade,
                readiness: new ReadinessReport(true, true, true, true, false, false),
            ),
        );
        $registry->register(
            id: $enterpriseId,
            profile: $enterpriseProfile,
            preset: IsolationPreset::MultiTenantStrict,
            factory: static function (): KnowledgeInstance {
                throw new DomainFailure(Outcome::Incompatible, 'adapter_configuration_invalid');
            },
        );
        $registry->boot();

        $described = $registry->describeAll();
        self::assertArrayHasKey('support_faq', $described);
        self::assertArrayHasKey('enterprise_kb', $described);

        $reports = $registry->readinessAll();
        self::assertTrue($reports['support_faq']->ready);
        self::assertFalse($reports['support_faq']->failed);
        self::assertFalse($reports['enterprise_kb']->ready);
        self::assertTrue($reports['enterprise_kb']->failed);

        self::assertSame($supportId->value, $registry->get($supportId)->id->value);
    }

    private function profile(): HostProfile
    {
        $harness = new \KnowKit\Conformance\AdapterHarness(
            \KnowKit\Conformance\SchemaLoader::load(dirname(__DIR__, 3) . '/schemas'),
        );
        $host = new \KnowKit\Examples\CustomAdapter\FakeHost($harness->fixture);
        $doc = \KnowKit\Domain\Contract\StrictJson::decode(
            (string) file_get_contents(dirname(__DIR__, 3) . '/tests/fixtures/host-profiles/project-a.json'),
        );
        self::assertInstanceOf(\KnowKit\Domain\Contract\JsonObject::class, $doc);
        $registry = $host->registry();
        self::assertInstanceOf(AdapterRegistry::class, $registry);

        return new HostProfile($doc, $registry);
    }
}
