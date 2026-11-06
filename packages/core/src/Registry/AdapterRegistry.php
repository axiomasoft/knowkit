<?php
declare(strict_types=1);

namespace KnowKit\Registry;

use KnowKit\Domain\{AdapterId, AdapterKind, Capability, DomainFailure, EffectiveProfile, Outcome};
use KnowKit\Domain\Contract\{ContractValidator, SchemaSet};
use KnowKit\Port\{DocumentParser, Chunker, LexicalIndex, VectorIndex, EmbeddingProvider, Reranker,
    SourceConnector, SourceReader, BlobStore, AccessPolicy, EgressPolicy, JobQueue, Outbox, Clock};

/** Explicit register -> boot -> resolve; no scans, implicit defaults or paid verification. */
final class AdapterRegistry
{
    /** @var array<string, array{AdapterDescriptor, Factory}> */
    private array $entries = [];
    private bool $booted = false;

    public function register(AdapterDescriptor $descriptor, Factory $factory): void
    {
        if ($this->booted || isset($this->entries[$descriptor->id->value])) {
            throw new DomainFailure(Outcome::Incompatible, 'duplicate_adapter');
        }
        $this->entries[$descriptor->id->value] = [$descriptor, $factory];
    }
    public function describe(AdapterId $id): AdapterDescriptor { return $this->entry($id)[0]; }

    public function boot(): void
    {
        foreach ($this->entries as [$descriptor]) {
            $graph = [];
            foreach ($descriptor->capabilities as $capability) {
                if (isset($graph[$capability->id->value])) { throw new DomainFailure(Outcome::Incompatible, 'duplicate_capability'); }
                $graph[$capability->id->value] = array_map(static fn(Capability $c): string => $c->value, $capability->requires);
            }
            $visiting = $visited = [];
            $visit = function (string $name) use (&$visit, &$visiting, &$visited, $graph): void {
                if (!array_key_exists($name, $graph)) { throw new DomainFailure(Outcome::Incompatible, 'unsupported_capability'); }
                if (isset($visiting[$name])) { throw new DomainFailure(Outcome::Incompatible, 'capability_cycle'); }
                if (isset($visited[$name])) { return; }
                $visiting[$name] = true;
                foreach ($graph[$name] as $dependency) { $visit($dependency); }
                unset($visiting[$name]); $visited[$name] = true;
            };
            foreach (array_keys($graph) as $name) { $visit($name); }
        }
        $this->booted = true;
    }

    public function resolve(AdapterId $id, EffectiveProfile $profile): object
    {
        [$descriptor, $factory] = $this->entry($id); // A typo errors before touching another factory.
        if (!$this->booted) { throw new DomainFailure(Outcome::Incompatible, 'registry_not_booted'); }
        $config = $profile->config($id);
        foreach ($descriptor->requiresExt as $ext) {
            if (!extension_loaded($ext)) { throw new DomainFailure(Outcome::Unavailable, 'extension_unavailable'); }
        }
        $provided = array_map(static fn($cap): Capability => $cap->id, $descriptor->capabilities);
        foreach ($profile->requiredCapabilities[$id->value] ?? [] as $required) {
            if (!in_array($required, $provided, true)) { throw new DomainFailure(Outcome::Incompatible, 'unsupported_capability'); }
        }
        $schema = $descriptor->configSchema->toWire();
        $validator = new ContractValidator(new SchemaSet([$schema->get('$id')=>$schema]));
        if ($validator->validateValue($config, $schema->get('$id')) !== []) {
            throw new DomainFailure(Outcome::Incompatible, 'adapter_configuration_invalid');
        }
        $instance = $factory->create($profile);
        $contract = match ($descriptor->kind) {
            AdapterKind::Parser => DocumentParser::class, AdapterKind::Chunker => Chunker::class,
            AdapterKind::Lexical => LexicalIndex::class, AdapterKind::Vector => VectorIndex::class,
            AdapterKind::Embedding => EmbeddingProvider::class, AdapterKind::Reranker => Reranker::class,
            AdapterKind::SourceConnector => SourceConnector::class, AdapterKind::SourceReader => SourceReader::class,
            AdapterKind::BlobStore => BlobStore::class, AdapterKind::AccessPolicy => AccessPolicy::class,
            AdapterKind::EgressPolicy => EgressPolicy::class, AdapterKind::Jobs => JobQueue::class,
            AdapterKind::Outbox => Outbox::class, AdapterKind::Clock => Clock::class,
        };
        if (!$instance instanceof $contract) { throw new DomainFailure(Outcome::Incompatible, 'adapter_contract_violation'); }
        return $instance;
    }
    /** @return array{AdapterDescriptor, Factory} */
    private function entry(AdapterId $id): array
    {
        return $this->entries[$id->value] ?? throw new DomainFailure(Outcome::Incompatible, 'unknown_adapter');
    }
}
