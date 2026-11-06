<?php
declare(strict_types=1);

namespace KnowKit\Examples\CustomAdapter;

use KnowKit\Conformance\{AdapterHost, AdapterRig, Fixture};
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Registry\{AdapterDescriptor, AdapterRegistry, Factory};

/** Local contract double only. Registering/describing never loads ContractDoubles.php. */
final class FakeHost implements AdapterHost
{
    private AdapterRegistry $registry;
    public int $factoryCalls = 0;
    public function __construct(private readonly Fixture $fixture, private readonly string $fault = '',
        string $package = '1', ?string $weights = null)
    {
        $this->registry = new AdapterRegistry();
        $entries = [
            'policy'=>[D\AdapterKind::AccessPolicy,[]], 'egress'=>[D\AdapterKind::EgressPolicy,[]],
            'blob'=>[D\AdapterKind::BlobStore,[D\Capability::ImmutableStorage,D\Capability::DeleteStorage]],
            'parser'=>[D\AdapterKind::Parser,[D\Capability::ParserText]],
            'search'=>[D\AdapterKind::Lexical,[D\Capability::LexicalPrefilter]],
            'vector'=>[D\AdapterKind::Vector,[D\Capability::VectorPrefilter]],
            'embedding'=>[D\AdapterKind::Embedding,[D\Capability::EmbeddingDocuments,D\Capability::EmbeddingQuery]],
            'codepoints'=>[D\AdapterKind::Tokenizer,[D\Capability::ExactTokenizer]],
            'jobs'=>[D\AdapterKind::Jobs,[D\Capability::JobFencing]],
            'outbox'=>[D\AdapterKind::Outbox,[D\Capability::OutboxDelivery]],
        ];
        foreach ($entries as $name => [$kind,$caps]) {
            $schema = new JsonObject(['$schema'=>'https://json-schema.org/draft/2020-12/schema',
                '$id'=>'urn:knowkit:example:' . $name . '-config:1','type'=>'object',
                'properties'=>new JsonObject(['mode'=>new JsonObject(['enum'=>['local','alternate']]),
                    'credentials'=>new JsonObject(['type'=>'object','required'=>['handle','present'],
                        'properties'=>new JsonObject(['handle'=>new JsonObject(['type'=>'string']), 'present'=>new JsonObject(['type'=>'boolean'])]),
                        'unevaluatedProperties'=>false])]), 'unevaluatedProperties'=>false]);
            $features = $name === 'parser' ? ['ocr.language.ru'=>false,'parser.geometry'=>false] : [];
            $descriptor = new AdapterDescriptor(new D\AdapterId('fixture.' . $name),$kind,'1',$schema,
                array_map(static fn(D\Capability $c): D\CapabilityDeclaration => new D\CapabilityDeclaration($c),$caps), [],$features,
                new D\Fingerprint(hash('sha256','synthetic-package:' . $package)), $weights === null ? null : new D\Fingerprint(hash('sha256',$weights)),
                $kind === D\AdapterKind::Tokenizer ? $fixture->tokenizer() : null);
            $factory = new class($this,$name) implements Factory {
                public function __construct(private FakeHost $host, private string $name) {}
                public function create(D\EffectiveProfile $profile): object { return $this->host->create($this->name); }
            };
            $this->registry->register($descriptor,$factory);
        }
        $this->registry->boot();
    }
    public function registry(): AdapterRegistry { return $this->registry; }
    public function create(string $name): object
    {
        $this->factoryCalls++;
        $rig = $this->prepare($this->fixture,'factory');
        $mapping = ['policy'=>\KnowKit\Port\AccessPolicy::class,'egress'=>\KnowKit\Port\EgressPolicy::class,'blob'=>\KnowKit\Port\BlobStore::class,
            'parser'=>\KnowKit\Port\DocumentParser::class,'search'=>\KnowKit\Port\LexicalIndex::class,'vector'=>\KnowKit\Port\VectorIndex::class,
            'embedding'=>\KnowKit\Port\EmbeddingProvider::class,'codepoints'=>\KnowKit\Port\Tokenizer::class,'jobs'=>\KnowKit\Port\JobQueue::class,'outbox'=>\KnowKit\Port\Outbox::class];
        return $rig->adapter($name === 'codepoints' ? 'tokenizer' : $name,$mapping[$name]);
    }
    public function prepare(Fixture $fixture, string $caseId): AdapterRig
    {
        require_once __DIR__ . '/ContractDoubles.php';
        return ExampleAdapters::prepare($fixture,$this->fault,$caseId === 'R41-A02');
    }
}
