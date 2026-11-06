<?php
declare(strict_types=1);

namespace KnowKit\Registry;

use KnowKit\Domain\{AdapterId, Capability, CapabilityDeclaration, CredentialRef, DomainFailure, EffectiveProfile, Fingerprint, FrozenJson, Outcome, Sha256, TokenizerIdentity};
use KnowKit\Domain\Contract\{ContractValidator, Jcs, JsonObject, SchemaSet, StrictJson};

/** Frozen host customization. Metadata is explicit; validation never constructs adapters. */
final readonly class HostProfile
{
    private FrozenJson $value;
    private FrozenJson $input;
    private EffectiveProfile $effective;
    public ?TokenizerIdentity $tokenizer;

    public static function fromJson(string $json, AdapterRegistry $registry): self
    {
        $value = StrictJson::decode($json);
        if (!$value instanceof JsonObject) { throw new \InvalidArgumentException('Host profile object required'); }
        return new self($value, $registry);
    }

    public function __construct(JsonObject $document, AdapterRegistry $registry)
    {
        $schema = StrictJson::decode((string) file_get_contents(__DIR__ . '/host-profile.schema.json'));
        $validator = new ContractValidator(new SchemaSet([$schema->get('$id')=>$schema]));
        if ($validator->validateValue($document, $schema->get('$id')) !== []) {
            throw new DomainFailure(Outcome::Incompatible, 'host_profile_invalid');
        }
        self::safe($document);
        $this->input = new FrozenJson($document);
        $bindings = $configuration = $required = $metadata = $redacted = [];
        foreach ($document->get('adapterBindings') as $port => $binding) {
            $id = new AdapterId($binding->get('adapterId'));
            $descriptor = $registry->describe($id);
            if ($descriptor->kind->value !== $port) { throw new DomainFailure(Outcome::Incompatible, 'adapter_kind_mismatch'); }
            if (isset($configuration[$id->value])) { throw new DomainFailure(Outcome::Incompatible, 'duplicate_adapter_binding'); }
            $bindings[$port] = $id;
            $configuration[$id->value] = self::configuration($binding->get('config'));
            $required[$id->value] = [];
            foreach ($binding->get('capabilities') as $name) { $required[$id->value][] = CapabilityDeclaration::named($name)->id; }
            foreach ($binding->get('featureClaims') as $name) {
                if (($descriptor->features[$name] ?? false) !== true) {
                    throw new DomainFailure(Outcome::Incompatible, 'unsupported_capability');
                }
            }
            $metadata[$id->value] = new JsonObject(['kind'=>$port, 'version'=>$descriptor->version,
                'packageFingerprint'=>$descriptor->packageFingerprint?->value,
                'weightsFingerprint'=>$descriptor->weightsFingerprint?->value,
                'configSchemaDigest'=>hash('sha256', $descriptor->configSchema->canonical()),
                'capabilities'=>array_map(static fn(CapabilityDeclaration $c): JsonObject => new JsonObject([
                    'id'=>$c->id->value, 'requires'=>array_map(static fn(Capability $r): string => $r->value, $c->requires)]), $descriptor->capabilities),
                'features'=>new JsonObject($descriptor->features), 'requiresExt'=>$descriptor->requiresExt,
                'tokenizerIdentity'=>$descriptor->tokenizerIdentity?->toWire()]);
            $redacted[$port] = $binding->with('config', self::redacted($binding->get('config')));
        }
        $tokenizer = $document->get('tokenizer');
        $this->tokenizer = $tokenizer instanceof JsonObject ? new TokenizerIdentity(new AdapterId($tokenizer->get('tokenizerId')),
            $tokenizer->get('tokenizerVersion'), new Sha256($tokenizer->get('vocabularyDigest'))) : null;
        if ($this->tokenizer !== null) {
            $id = $bindings['tokenizer'] ?? null;
            if ($id?->value !== $this->tokenizer->id->value || $metadata[$id->value]->get('version') !== $this->tokenizer->version
                || !in_array(Capability::ExactTokenizer, $required[$id->value], true)) {
                throw new DomainFailure(Outcome::Incompatible, 'tokenizer_mismatch');
            }
            $declared = $registry->describe($id)->tokenizerIdentity;
            if ($declared === null) { throw new DomainFailure(Outcome::Incompatible, 'tokenizer_mismatch'); }
            $declared->assertCompatible($this->tokenizer);
        }
        if ($document->get('quotas')->get('maxTokens') !== null && $this->tokenizer === null) {
            throw new DomainFailure(Outcome::Incompatible, 'tokenizer_required');
        }
        $this->value = new FrozenJson($document->with('adapterBindings', new JsonObject($redacted))
            ->with('adapters', new JsonObject($metadata)));
        // Preserve the P1.5 Factory API, while binding its digest to ALL host semantic inputs.
        $this->effective = new EffectiveProfile('host:' . $this->digest()->value, $document->get('version'),
            $bindings, $configuration, $required);
        foreach ($bindings as $id) { $registry->validate($id, $this->effective); }
    }

    public function toWire(): JsonObject { return $this->value->toWire(); }
    public function digest(): Fingerprint { return new Fingerprint(hash('sha256', $this->value->canonical())); }
    public function effectiveProfile(): EffectiveProfile { return $this->effective; }
    public function withQuotas(JsonObject $quotas, AdapterRegistry $registry): self
    { return new self($this->input->toWire()->with('quotas', $quotas), $registry); }
    public function __debugInfo(): array { return ['digest'=>$this->digest()->value]; }

    /** Sorted JSON-pointer diff of safe exports; neither profile is mutated. */
    public function diff(self $other): array
    {
        $changes = [];
        $visit = function (mixed $a, mixed $b, string $path) use (&$visit, &$changes): void {
            if (SchemaSet::jsonEqual($a, $b)) { return; }
            if ($a instanceof JsonObject && $b instanceof JsonObject) {
                $keys = array_unique([...$a->keys(), ...$b->keys()]); sort($keys, SORT_STRING);
                foreach ($keys as $key) {
                    $childPath = $path . SchemaSet::pointer($key);
                    if ($a->has($key) !== $b->has($key)) {
                        $changes[] = ['path'=>$childPath,'before'=>$a->get($key),'after'=>$b->get($key),
                            'beforePresent'=>$a->has($key),'afterPresent'=>$b->has($key)];
                    } else { $visit($a->get($key), $b->get($key), $childPath); }
                }
            } else { $changes[] = ['path'=>$path, 'before'=>$a, 'after'=>$b]; }
        };
        $visit($this->toWire(), $other->toWire(), '');
        usort($changes, static fn(array $a, array $b): int => strcmp($a['path'], $b['path']));
        return $changes;
    }

    private static function safe(mixed $value): void
    {
        if ($value instanceof JsonObject) {
            foreach ($value as $key => $child) {
                if (preg_match('/secret|password|api.?key|path|signed.?url|temporary.?url/i', $key) === 1) {
                    throw new DomainFailure(Outcome::Incompatible, 'unsafe_profile_setting');
                }
                self::safe($child);
            }
        } elseif (is_array($value)) { foreach ($value as $child) { self::safe($child); } }
        elseif (is_string($value) && preg_match('~^(?:/|\\\\|[A-Za-z]:|\x7e/)|https?://[^/]*@~', $value) === 1) {
            throw new DomainFailure(Outcome::Incompatible, 'unsafe_profile_setting');
        }
    }

    private static function configuration(JsonObject $object): array
    {
        $out = [];
        foreach ($object as $key => $value) {
            if ($value instanceof JsonObject && $value->has('credentialRef')) {
                if (count($value) !== 2 || !is_string($value->get('credentialRef')) || !is_bool($value->get('present'))) {
                    throw new DomainFailure(Outcome::Incompatible, 'unsafe_profile_setting');
                }
                $value = new CredentialRef($value->get('credentialRef'), $value->get('present'));
            } elseif ($value instanceof JsonObject) { $value = self::configuration($value); }
            $out[$key] = $value;
        }
        return $out;
    }

    private static function redacted(JsonObject $object): JsonObject
    {
        $out = [];
        foreach ($object as $key => $value) {
            if ($value instanceof JsonObject) {
                $value = $value->has('credentialRef') ? new JsonObject(['present'=>$value->get('present')]) : self::redacted($value);
            }
            $out[$key] = $value;
        }
        return new JsonObject($out);
    }
}
