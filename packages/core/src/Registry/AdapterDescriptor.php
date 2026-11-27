<?php
declare(strict_types=1);

namespace KnowKit\Registry;

use KnowKit\Domain\{AdapterId, AdapterKind, CapabilityDeclaration, DomainFailure, Fingerprint, FrozenJson, Outcome, Readiness, TokenizerIdentity, ValueGuard};
use KnowKit\Domain\Contract\JsonObject;

/** Static discovery metadata. Describing it never runs factory code. */
final readonly class AdapterDescriptor
{
    public FrozenJson $configSchema;
    /** @var array<string, bool> */
    public array $features;
    /**
     * @param list<CapabilityDeclaration> $capabilities
     * @param list<string> $requiresExt
     * @param array<array-key, mixed> $features Native boundary validated to named boolean declarations.
     */
    public function __construct(public AdapterId $id, public AdapterKind $kind, public string $version,
        JsonObject $configSchema, public array $capabilities, public array $requiresExt = [],
        array $features = [], public ?Fingerprint $packageFingerprint = null,
        public ?Fingerprint $weightsFingerprint = null, public ?TokenizerIdentity $tokenizerIdentity = null)
    {
        ValueGuard::text($version, 64); ValueGuard::objects($capabilities, CapabilityDeclaration::class, 64);
        if (!is_string($configSchema->get('$id')) || $configSchema->get('type') !== 'object'
            || $configSchema->get('unevaluatedProperties') !== false) {
            throw new \InvalidArgumentException('Closed offline configuration schema required');
        }
        ValueGuard::strings($requiresExt, true, true);
        if (($kind === AdapterKind::Tokenizer) !== ($tokenizerIdentity !== null)
            || ($tokenizerIdentity !== null && ($tokenizerIdentity->id->value !== $id->value || $tokenizerIdentity->version !== $version))) {
            throw new \InvalidArgumentException('Static pinned tokenizer identity required only for tokenizer descriptors');
        }
        $validatedFeatures = [];
        foreach ($features as $name => $supported) {
            if (!is_string($name) || preg_match('/^[a-z][a-z0-9_.-]{0,127}$/D', $name) !== 1 || !is_bool($supported)) {
                throw new \InvalidArgumentException('Named boolean feature declarations required');
            }
            $validatedFeatures[$name] = $supported;
        }
        $this->features = $validatedFeatures;
        $this->configSchema = new FrozenJson($configSchema);
    }

    /** @return list<string> */
    public function missingExtensions(): array
    {
        $missing = [];
        foreach ($this->requiresExt as $ext) {
            if (!extension_loaded($ext)) {
                $missing[] = $ext;
            }
        }

        return $missing;
    }

    /** @return array{readiness: Readiness, reason: ?string} */
    public function extensionReadiness(): array
    {
        $missing = $this->missingExtensions();
        if ($missing !== []) {
            return ['readiness' => Readiness::Failed, 'reason' => 'missing_extension:' . $missing[0]];
        }

        return ['readiness' => Readiness::Ready, 'reason' => null];
    }

    public function assertExtensionsLoaded(): void
    {
        $missing = $this->missingExtensions();
        if ($missing !== []) {
            throw new DomainFailure(Outcome::Unavailable, 'missing_extension_' . $missing[0]);
        }
    }

    public function toWire(): JsonObject
    {
        return new JsonObject(['id'=>$this->id->value,'kind'=>$this->kind->value,'version'=>$this->version,
            'configSchema'=>$this->configSchema->toWire(),
            'capabilities'=>array_map(static fn(CapabilityDeclaration $c): JsonObject => new JsonObject([
                'id'=>$c->id->value,'requires'=>array_map(static fn(\KnowKit\Domain\Capability $r): string => $r->value,$c->requires)]),$this->capabilities),
            'requiresExt'=>$this->requiresExt,'features'=>new JsonObject($this->features),
            'packageFingerprint'=>$this->packageFingerprint?->value,'weightsFingerprint'=>$this->weightsFingerprint?->value,
            'tokenizerIdentity'=>$this->tokenizerIdentity?->toWire()]);
    }
}
