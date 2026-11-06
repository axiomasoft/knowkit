<?php
declare(strict_types=1);

namespace KnowKit\Registry;

use KnowKit\Domain\{AdapterId, AdapterKind, CapabilityDeclaration, FrozenJson, ValueGuard};
use KnowKit\Domain\Contract\JsonObject;

/** Static discovery metadata. Describing it never runs factory code. */
final readonly class AdapterDescriptor
{
    public FrozenJson $configSchema;
    /**
     * @param list<CapabilityDeclaration> $capabilities
     * @param list<string> $requiresExt
     */
    public function __construct(public AdapterId $id, public AdapterKind $kind, public string $version,
        JsonObject $configSchema, public array $capabilities, public array $requiresExt = [])
    {
        ValueGuard::text($version, 64); ValueGuard::objects($capabilities, CapabilityDeclaration::class, 64);
        if (!is_string($configSchema->get('$id')) || $configSchema->get('type') !== 'object'
            || $configSchema->get('unevaluatedProperties') !== false) {
            throw new \InvalidArgumentException('Closed offline configuration schema required');
        }
        ValueGuard::strings($requiresExt, true, true);
        $this->configSchema = new FrozenJson($configSchema);
    }
}
