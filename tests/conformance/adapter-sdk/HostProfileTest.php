<?php
declare(strict_types=1);

namespace KnowKit\Tests\Conformance\AdapterSdk;

use KnowKit\Conformance\{RegistryCorpus, SchemaLoader, AdapterHarness};
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\{Jcs, JsonObject};
use KnowKit\Examples\CustomAdapter\FakeHost;
use KnowKit\Registry\{AdapterDescriptor, AdapterRegistry, HostProfile};
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support.php';

#[Group('extension:conformance-sdk')]
final class HostProfileTest extends TestCase
{
    public function testEverySemanticAxisChangesFullAndFactoryIdentity(): void
    {
        $h = Support::harness(); $host = new FakeHost($h->fixture); $doc = Support::document();
        $profile = new HostProfile($doc,$host->registry());
        $mutations = [$doc->with('version','2'),
            $doc->with('parserProfiles',$doc->get('parserProfiles')->with('plain',new JsonObject(['version'=>'2','signature'=>str_repeat('e',64)]))),
            $doc->with('searchProfiles',$doc->get('searchProfiles')->with('simple',new JsonObject(['version'=>'2','signature'=>str_repeat('e',64)]))),
            $doc->with('coveragePolicy',new JsonObject(['version'=>'2','state'=>'partial'])),
            $doc->with('quotas',$doc->get('quotas')->with('maxBytes',1)),
            $doc->with('externalOwnerContract',null)];
        $bindings = $doc->get('adapterBindings');
        $mutations[] = $doc->with('adapterBindings',$bindings->with('parser',$bindings->get('parser')->with('config',new JsonObject(['mode'=>'alternate']))));
        $mutations[] = $doc->with('adapterBindings',$bindings->with('blob_store',$bindings->get('blob_store')->with('capabilities',['storage.immutable'])));
        foreach ($mutations as $changed) {
            $next = new HostProfile($changed,$host->registry());
            self::assertNotSame($profile->digest()->value,$next->digest()->value);
            self::assertNotSame($profile->effectiveProfile()->digest()->value,$next->effectiveProfile()->digest()->value);
            self::assertFalse($host->registry()->readiness(new D\AdapterId('fixture.parser'),$next,true,$profile->digest())->verified);
        }
        foreach ([new FakeHost($h->fixture,'','2'),new FakeHost($h->fixture,'','1','new-weights')] as $changedHost) {
            self::assertNotSame($profile->digest()->value,(new HostProfile($doc,$changedHost->registry()))->digest()->value);
        }
        $newVocabulary = new \KnowKit\Conformance\Fixture($h->fixture->data->with('vocabularyDigest',str_repeat('d',64)),$h->fixture->validator);
        $newHost = new FakeHost($newVocabulary);
        $newProfile = new HostProfile($doc->with('tokenizer',$doc->get('tokenizer')->with('vocabularyDigest',str_repeat('d',64))),$newHost->registry());
        self::assertNotSame($profile->digest()->value,$newProfile->digest()->value);
        $withNull = new HostProfile($doc->with('externalOwnerContract',null),$host->registry());
        self::assertNotEmpty($profile->diff($withNull));
        $export = $profile->toWire(); $digest = $profile->digest()->value;
        $copy = $export->with('id','changed-copy');
        self::assertNotSame(Jcs::canonicalize($copy),Jcs::canonicalize($profile->toWire()));
        self::assertSame($digest,$profile->digest()->value);
        self::assertSame(hash('sha256',Jcs::canonicalize($profile->toWire())),$digest);
        self::assertSame(0,$host->factoryCalls);
    }

    public function testCredentialRefsExportOnlyPresenceAndCannotGrantEgress(): void
    {
        $h = Support::harness(); $host = new FakeHost($h->fixture); $doc = Support::document();
        $bindings = $doc->get('adapterBindings');
        $doc = $doc->with('adapterBindings',$bindings->with('embedding',$bindings->get('embedding')->with('config',
            new JsonObject(['credentials'=>new JsonObject(['credentialRef'=>'host:model','present'=>true])]))));
        $profile = new HostProfile($doc,$host->registry());
        $export = Jcs::canonicalize($profile->toWire());
        self::assertStringNotContainsString('host:model',$export);
        self::assertTrue($profile->toWire()->get('adapterBindings')->get('embedding')->get('config')->get('credentials')->get('present'));
        $r = $host->registry()->readiness(new D\AdapterId('fixture.embedding'),$profile);
        self::assertTrue($r->configured); self::assertFalse($r->verified); self::assertFalse($r->ready);
        (new \KnowKit\Conformance\CorpusContract())->execute('SDK-EGRESS',$host,$profile,$h->fixture);
        self::assertSame(0,$host->factoryCalls);
    }

    public function testClosedSchemaUnknownIdsClaimsAndPinnedTokenizerReject(): void
    {
        $h = Support::harness(); $host = new FakeHost($h->fixture);
        foreach (['unknown-parser'=>'unknown_adapter','false-capability'=>'unsupported_capability','tokenizer-mismatch'=>'tokenizer_mismatch'] as $name => $expected) {
            try { Support::profile($host,$name); self::fail('Negative host profile accepted'); }
            catch (D\DomainFailure $failure) { self::assertSame($expected,$failure->errorCode); }
        }
        $doc = Support::document();
        $invalid = [$doc->with('unexpected',true),$doc->with('quotas',$doc->get('quotas')->with('maxBytes',-1)),
            $doc->without('tokenizer'),$doc->with('tokenizer',$doc->get('tokenizer')->with('vocabularyDigest',str_repeat('d',64))),
            $doc->with('externalOwnerContract',new JsonObject(['id'=>'owner','version'=>'1','maxAgeSeconds'=>6]))];
        foreach ($invalid as $value) {
            try { new HostProfile($value,$host->registry()); self::fail('Invalid profile accepted'); }
            catch (D\DomainFailure $failure) { self::assertSame(D\Outcome::Incompatible,$failure->outcome); }
        }
        self::assertSame(0,$host->factoryCalls);
    }

    public function testReadinessHasIndependentInstalledEnabledConfiguredVerifiedReady(): void
    {
        $r = new AdapterRegistry(); $factory = RegistryCorpus::factory(); $descriptor = RegistryCorpus::descriptor();
        $r->register($descriptor,$factory);
        $r->register(new AdapterDescriptor(new D\AdapterId('unbound'),D\AdapterKind::Clock,'1',$descriptor->configSchema->toWire(),[]),$factory);
        $r->boot(); $p = new HostProfile(RegistryCorpus::document(),$r);
        $unbound = $r->readiness(new D\AdapterId('unbound'),$p);
        self::assertTrue($unbound->installed); self::assertFalse($unbound->configured); self::assertFalse($unbound->ready);
        $normal = $r->readiness($descriptor->id,$p,true,$p->digest());
        self::assertTrue($normal->ready); self::assertFalse($normal->unknown);
        $disabled = $r->readiness($descriptor->id,$p,false,$p->digest());
        self::assertTrue($disabled->verified); self::assertFalse($disabled->enabled); self::assertFalse($disabled->ready);
        self::assertSame(0,$factory->calls);
    }

    public function testDiscoveryInFreshProcessNeverLoadsHeavyImplementations(): void
    {
        $script = 'require "packages/core/conformance/autoload.php"; require "examples/custom-adapter/FakeHost.php"; '
            . '$h=new KnowKit\\Conformance\\AdapterHarness(KnowKit\\Conformance\\SchemaLoader::load("schemas")); '
            . '$host=new KnowKit\\Examples\\CustomAdapter\\FakeHost($h->fixture); '
            . '$p=KnowKit\\Registry\\HostProfile::fromJson(file_get_contents("examples/custom-adapter/profile.json"),$host->registry()); '
            . '$host->registry()->describe(new KnowKit\\Domain\\AdapterId("fixture.embedding")); '
            . '$host->registry()->readiness(new KnowKit\\Domain\\AdapterId("fixture.embedding"),$p); '
            . 'echo json_encode([$host->factoryCalls,class_exists("KnowKit\\\\Examples\\\\CustomAdapter\\\\FakeProvider",false)]);';
        $child = Support::child(['-r',$script]);
        self::assertSame(0,$child['exit'],$child['stderr']); self::assertSame('[0,false]',$child['stdout']);
    }

    public function testTokenizerIdentityBindsEveryAxisAndFalseExactRejects(): void
    {
        $identity = Support::harness()->fixture->tokenizer();
        foreach ([new D\TokenizerIdentity(new D\AdapterId('different'),'1',$identity->vocabularyDigest),
            new D\TokenizerIdentity($identity->id,'2',$identity->vocabularyDigest),
            new D\TokenizerIdentity($identity->id,'1',new D\Sha256(str_repeat('c',64)))] as $changed) {
            try { $identity->assertCompatible($changed); self::fail('Tokenizer identity axis ignored'); }
            catch (D\DomainFailure $f) { self::assertSame('tokenizer_mismatch',$f->errorCode); }
        }
        $this->expectException(D\DomainFailure::class);
        (new D\TokenizerCapabilities($identity,false))->requireExact($identity);
    }

    public function testIndependentGoldenSourceIrAndProfileDigests(): void
    {
        $h = Support::harness(); $f = $h->fixture;
        self::assertSame($f->data->get('sourceDigest'),hash('sha256',$f->text()));
        self::assertSame($f->data->get('irDigest'),hash('sha256',$f->ir()->canonical()));
        $golden = json_decode((string) file_get_contents(__DIR__ . '/../../fixtures/host-profiles/fingerprints.v1.json'),true,512,JSON_THROW_ON_ERROR);
        $host = new FakeHost($f);
        self::assertSame($golden['projectA'],Support::profile($host)->digest()->value);
        self::assertSame($golden['projectB'],Support::profile($host,'project-b')->digest()->value);
    }

    public function testDescriptorSchemaMatchesStaticDiscoveryAndRejectsUnknownKinds(): void
    {
        foreach ([['parser.geometry'=>'true'],[0=>true]] as $invalidFeatures) {
            try { RegistryCorpus::descriptor([],$invalidFeatures); self::fail('Untyped feature accepted'); }
            catch (\InvalidArgumentException) { $this->addToAssertionCount(1); }
        }
        $schema = D\Contract\StrictJson::decode((string) file_get_contents(Support::root() . '/packages/core/src/Registry/adapter-descriptor.schema.json'));
        $validator = new D\Contract\ContractValidator(new D\Contract\SchemaSet([$schema->get('$id')=>$schema]));
        $host = new FakeHost(Support::harness()->fixture);
        foreach (Support::document()->get('adapterBindings') as $binding) {
            $descriptor = $host->registry()->describe(new D\AdapterId($binding->get('adapterId')));
            self::assertSame([],$validator->validateValue($descriptor->toWire(),$schema->get('$id')));
            self::assertNotEmpty($validator->validateValue($descriptor->toWire()->with('kind','unknown'),$schema->get('$id')));
        }
        self::assertSame(0,$host->factoryCalls);
    }
}
