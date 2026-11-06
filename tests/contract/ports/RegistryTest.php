<?php
declare(strict_types=1);

namespace KnowKit\Tests\Contract\Ports;

use KnowKit\Domain as D;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Port\Clock;
use KnowKit\Registry\{AdapterDescriptor, AdapterRegistry, Factory};
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

final class CountingFactory implements Factory
{
    public int $calls = 0;
    public function create(D\EffectiveProfile $profile): object
    {
        $this->calls++;
        return new class implements Clock {
            public function now(): D\UtcInstant { return new D\UtcInstant('2026-11-06T12:00:00Z'); }
        };
    }
}

#[Group('contract:ports')]
final class RegistryTest extends TestCase
{
    private function descriptor(array $capabilities = []): AdapterDescriptor
    {
        return new AdapterDescriptor(new D\AdapterId('clock'),D\AdapterKind::Clock,'1',
            new JsonObject(['$schema'=>'https://json-schema.org/draft/2020-12/schema','$id'=>'urn:knowkit:test:clock-config:1',
                'type'=>'object','properties'=>new JsonObject(),'unevaluatedProperties'=>false]),$capabilities);
    }
    private function profile(array $required = []): D\EffectiveProfile
    {
        return new D\EffectiveProfile('test','1',['clock'=>new D\AdapterId('clock')],[],['clock'=>$required]);
    }

    public function testDiscoveryHasNoFactorySideEffectsAndBootIsExplicit(): void
    {
        $registry = new AdapterRegistry(); $factory = new CountingFactory();
        $registry->register($this->descriptor(),$factory);
        self::assertSame('clock',$registry->describe(new D\AdapterId('clock'))->id->value);
        self::assertSame(0,$factory->calls);
        try { $registry->resolve(new D\AdapterId('clock'),$this->profile()); self::fail('Resolved before boot'); }
        catch (D\DomainFailure $failure) { self::assertSame('registry_not_booted',$failure->errorCode); }
        $registry->boot();
        self::assertInstanceOf(Clock::class,$registry->resolve(new D\AdapterId('clock'),$this->profile()));
        self::assertSame(1,$factory->calls);
        try { $registry->register($this->descriptor(),$factory); self::fail('Registered after boot'); }
        catch (D\DomainFailure) { self::assertSame(1,$factory->calls); }
    }

    public function testTypoDuplicateUnknownAndMissingCapabilitiesNeverFallback(): void
    {
        $cases = PortFixture::json('tests/fixtures/identity/v1/registry.json')->get('cases');
        foreach ($cases as $case) {
            $registry = new AdapterRegistry(); $factory = new CountingFactory();
            $declarations = $case->get('id') === 'cycle' ? [
                new D\CapabilityDeclaration(D\Capability::ParserText,[D\Capability::ParserOcr]),
                new D\CapabilityDeclaration(D\Capability::ParserOcr,[D\Capability::ParserText]),
            ] : [];
            $registry->register($this->descriptor($declarations),$factory);
            try {
                switch ($case->get('id')) {
                    case 'duplicate': $registry->register($this->descriptor(),$factory); break;
                    case 'unknown-capability': D\CapabilityDeclaration::named($case->get('capability')); break;
                    case 'cycle': $registry->boot(); break;
                    case 'typo':
                        $registry->boot(); $registry->resolve(new D\AdapterId($case->get('adapterId')),$this->profile()); break;
                    case 'missing-capability':
                        $registry->boot(); $registry->resolve(new D\AdapterId('clock'),$this->profile([D\Capability::ExactTokenizer])); break;
                    default: self::fail('Unexecuted registry fixture');
                }
                self::fail('Invalid adapter selection accepted: ' . $case->get('id'));
            } catch (D\DomainFailure $failure) {
                self::assertSame($case->get('expectedErrorCode'),$failure->errorCode);
                self::assertSame($case->get('expectedOutcome'),$failure->outcome->value);
            }
            self::assertSame(0,$factory->calls,$case->get('id'));
        }
    }

    public function testUnknownDependencyConfigurationAndUnboundAdapterAreRejected(): void
    {
        $registry = new AdapterRegistry(); $factory = new CountingFactory();
        $registry->register($this->descriptor([new D\CapabilityDeclaration(D\Capability::ParserText,[D\Capability::ParserOcr])]),$factory);
        try { $registry->boot(); self::fail('Missing dependency accepted'); } catch (D\DomainFailure) {}
        $registry = new AdapterRegistry(); $registry->register($this->descriptor(),$factory); $registry->boot();
        foreach ([
            new D\EffectiveProfile('test','1',['clock'=>new D\AdapterId('clock')],['clock'=>['unexpected'=>true]]),
            new D\EffectiveProfile('test','1',[]),
        ] as $invalid) {
            try { $registry->resolve(new D\AdapterId('clock'),$invalid); self::fail('Config/binding accepted'); }
            catch (D\DomainFailure) { self::assertSame(0,$factory->calls); }
        }
    }

    public function testEffectiveProfileOwnsFrozenRedactedConfig(): void
    {
        $id = new D\AdapterId('clock');
        $a = new D\EffectiveProfile('test','1',['clock'=>$id],['clock'=>['credentials'=>new D\CredentialRef('host:model',true),'mode'=>'local']]);
        $b = new D\EffectiveProfile('test','1',['clock'=>$id],['clock'=>['mode'=>'local','credentials'=>new D\CredentialRef('host:model',true)]]);
        self::assertSame($a->digest()->value,$b->digest()->value);
        self::assertSame(hash('sha256',D\Contract\Jcs::canonicalize($a->toWire())),$a->digest()->value);
        self::assertTrue($a->config($id)->get('credentials')->get('present'));
        self::assertSame('host:model',$a->config($id)->get('credentials')->get('handle'));
        $copy = $a->config($id)->with('mode','changed');
        self::assertSame('local',$a->config($id)->get('mode'));
        self::assertNotSame(D\Contract\Jcs::canonicalize($copy),D\Contract\Jcs::canonicalize($a->config($id)));
        $this->expectException(\InvalidArgumentException::class);
        new D\EffectiveProfile('test','1',['clock'=>$id],['clock'=>['apiKey'=>'forbidden raw value']]);
    }

    public function testFactoryMustImplementTheDeclaredPort(): void
    {
        $factory = new class implements Factory {
            public function create(D\EffectiveProfile $profile): object { return new \stdClass(); }
        };
        $registry = new AdapterRegistry(); $registry->register($this->descriptor(),$factory); $registry->boot();
        $this->expectException(D\DomainFailure::class);
        $registry->resolve(new D\AdapterId('clock'),$this->profile());
    }
}
