<?php
declare(strict_types=1);

namespace KnowKit\Conformance;

use KnowKit\Domain as D;
use KnowKit\Domain\Contract\{Jcs, JsonObject};
use KnowKit\Registry\{AdapterDescriptor, AdapterRegistry, Factory, HostProfile};

/** SDK self-checks are labelled separately from target port qualification. */
final class RegistryCorpus extends AdapterContract
{
    public static function document(): JsonObject
    {
        return new JsonObject(['schemaVersion'=>'host-profile:1','id'=>'sdk-self-check','version'=>'1',
            'adapterBindings'=>new JsonObject(['clock'=>new JsonObject(['adapterId'=>'fixture.clock','config'=>new JsonObject(),
                'capabilities'=>[], 'featureClaims'=>[]])]),'parserProfiles'=>new JsonObject(),'searchProfiles'=>new JsonObject(),
            'coveragePolicy'=>new JsonObject(['version'=>'1','state'=>'complete']),
            'quotas'=>new JsonObject(['maxBytes'=>4096,'maxFragments'=>4,'maxAssets'=>0])]);
    }
    public static function descriptor(array $caps = [], array $features = [], ?D\Fingerprint $package = null,
        ?D\Fingerprint $weights = null): AdapterDescriptor
    {
        return new AdapterDescriptor(new D\AdapterId('fixture.clock'), D\AdapterKind::Clock, '1',
            new JsonObject(['$schema'=>'https://json-schema.org/draft/2020-12/schema','$id'=>'urn:knowkit:sdk:empty-config:1',
                'type'=>'object','properties'=>new JsonObject(),'unevaluatedProperties'=>false]), $caps, [], $features, $package, $weights);
    }
    public static function factory(): Factory
    {
        return new class implements Factory {
            public int $calls = 0;
            public function create(D\EffectiveProfile $profile): object { $this->calls++; throw new \LogicException('Factory must stay lazy'); }
        };
    }
    public static function registry(?AdapterDescriptor $descriptor = null): AdapterRegistry
    {
        $registry = new AdapterRegistry(); $registry->register($descriptor ?? self::descriptor(), self::factory()); $registry->boot(); return $registry;
    }
    public function execute(string $caseId, AdapterHost $host, HostProfile $profile, Fixture $fixture): void
    {
        $registry = $host->registry();
        switch ($caseId) {
            case 'R36-A01':
                foreach (['misspelled.parser','misspelled.embedding'] as $name) {
                    self::failure(fn() => $registry->resolve(new D\AdapterId($name), $profile->effectiveProfile()), D\Outcome::Incompatible, 'unknown_adapter');
                }
                break;
            case 'R36-A02':
                $registry = self::registry(self::descriptor([], ['ocr.language.ru'=>false,'parser.geometry'=>false]));
                foreach (['ocr.language.ru','parser.geometry','storage.delete','parser.tables'] as $feature) {
                    $doc = self::document(); $binding = $doc->get('adapterBindings')->get('clock')->with('featureClaims', [$feature]);
                    self::failure(fn() => new HostProfile($doc->with('adapterBindings',new JsonObject(['clock'=>$binding])), $registry),
                        D\Outcome::Incompatible, 'unsupported_capability');
                }
                break;
            case 'DT-R02':
                $factory = self::factory(); $descriptor = self::descriptor(); $r = new AdapterRegistry(); $r->register($descriptor, $factory);
                self::failure(fn() => $r->register($descriptor, $factory), D\Outcome::Incompatible, 'duplicate_adapter');
                $r->describe($descriptor->id); $r->boot();
                self::failure(fn() => D\CapabilityDeclaration::named('parser.typo'), D\Outcome::Incompatible, 'unsupported_capability');
                $cyclic = new AdapterRegistry(); $cyclic->register(self::descriptor([
                    new D\CapabilityDeclaration(D\Capability::ParserText,[D\Capability::ParserOcr]),
                    new D\CapabilityDeclaration(D\Capability::ParserOcr,[D\Capability::ParserText])]), $factory);
                self::failure(fn() => $cyclic->boot(), D\Outcome::Incompatible, 'capability_cycle');
                self::check($factory->calls === 0, 'metadata_loaded_implementation');
                break;
            case 'DT-R03':
                $before = $profile->digest()->value;
                $quotas = $profile->toWire()->get('quotas');
                $changed = $profile->withQuotas($quotas->with('maxBytes', $quotas->get('maxBytes') + 1), $registry);
                self::check($before !== $changed->digest()->value && $profile->effectiveProfile()->digest()->value !== $changed->effectiveProfile()->digest()->value,
                    'changed_profile_reused_identity');
                foreach (['package', 'weights'] as $axis) {
                    $r1 = self::registry();
                    $fingerprint = new D\Fingerprint(str_repeat('c',64));
                    $r2 = self::registry(self::descriptor([], [], $axis === 'package' ? $fingerprint : null, $axis === 'weights' ? $fingerprint : null));
                    $p1 = new HostProfile(self::document(),$r1); $p2 = new HostProfile(self::document(),$r2);
                    self::check($p1->digest()->value !== $p2->digest()->value, 'artifact_change_reused_identity');
                    self::check(!$r2->readiness(new D\AdapterId('fixture.clock'),$p2,true,$p1->digest())->verified, 'stale_approval');
                }
                self::check($profile->digest()->value === $before, 'profile_mutated');
                break;
            case 'DT-R05':
                $digest = $profile->digest()->value;
                self::check(hash('sha256',Jcs::canonicalize($profile->toWire())) === $digest, 'noncanonical_digest');
                $other = $profile->withQuotas($profile->toWire()->get('quotas')->with('maxBytes', 0),$registry);
                $diff = $profile->diff($other); $paths = array_column($diff,'path'); $sorted = $paths; sort($sorted,SORT_STRING);
                self::check($paths === $sorted && $profile->digest()->value === $digest, 'mutating_unsorted_diff');
                foreach (['apiKey'=>'synthetic-private-value', 'machinePath'=>'/synthetic/private/file'] as $key => $value) {
                    $doc = self::document(); $binding = $doc->get('adapterBindings')->get('clock')->with('config',new JsonObject([$key=>$value]));
                    self::failure(fn() => new HostProfile($doc->with('adapterBindings', new JsonObject(['clock'=>$binding])), self::registry()),
                        D\Outcome::Incompatible, 'unsafe_profile_setting');
                }
                break;
            case 'DT-R06':
                $factory = self::factory(); $r = new AdapterRegistry(); $r->register(self::descriptor(),$factory); $r->boot();
                $p = new HostProfile(self::document(),$r); $id = new D\AdapterId('fixture.clock');
                $unverified = $r->readiness($id,$p);
                self::check($unverified->installed && $unverified->enabled && $unverified->configured && !$unverified->verified && !$unverified->ready, 'readiness_collapsed');
                self::check($r->readiness($id,$p,true,$p->digest())->ready, 'verified_not_ready');
                self::check(!$r->readiness($id,$p,false,$p->digest())->ready, 'disabled_ready');
                self::check(!$r->readiness($id,$p,true,$p->digest(),true)->ready, 'failed_ready');
                $unknown = $r->readiness(new D\AdapterId('missing'),$p);
                self::check(!$unknown->installed && $unknown->unknown && !$unknown->configured && $factory->calls === 0, 'readiness_probe_or_unknown');
                break;
            default: throw new \InvalidArgumentException('Unknown registry corpus case');
        }
    }
}
