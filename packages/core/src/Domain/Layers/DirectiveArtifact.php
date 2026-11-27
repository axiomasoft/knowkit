<?php
declare(strict_types=1);

namespace KnowKit\Domain\Layers;

use KnowKit\Domain\{ActorId, Fingerprint, Sha256, SourceVersionRef, TenantId, Ulid, UtcInstant, ValueGuard};
use KnowKit\Domain\Contract\{ContractViolation, HashDomain, JsonObject};

final readonly class DirectiveArtifact
{
    private const RULE_KEY_PATTERN = '/^[a-z][a-z0-9_]{0,31}(\.[a-z][a-z0-9_]{0,31}){0,3}$/D';

    /**
     * @param list<SourceVersionRef> $derivedFrom
     */
    public function __construct(
        public TenantId $tenantId,
        public Ulid $directiveId,
        public int $version,
        public string $title,
        public string $contentFormat,
        public string $body,
        public Sha256 $bodyDigest,
        public JsonObject $lockedRules,
        public ActorId $promotedBy,
        public UtcInstant $promotedAt,
        public array $derivedFrom,
        public Sha256 $approvalDigest,
    ) {
        if ($version < 1 || $version > 1000000) {
            throw new \InvalidArgumentException('Directive version must be 1..1000000');
        }
        ValueGuard::text($title, 256);
        if ($contentFormat !== 'compact_markdown' && $contentFormat !== 'canonical_json') {
            throw new \InvalidArgumentException('Unsupported directive contentFormat');
        }
        if ($body === '' || preg_match('//u', $body) !== 1 || mb_strlen($body, 'UTF-8') > 131072) {
            throw new \InvalidArgumentException('Bounded nonempty UTF-8 body required');
        }
        if ($lockedRules->count() > 64) {
            throw new \InvalidArgumentException('lockedRules exceeds 64 entries');
        }
        foreach ($lockedRules as $key => $value) {
            if (preg_match(self::RULE_KEY_PATTERN, $key) !== 1) {
                throw new \InvalidArgumentException('Invalid lockedRules key');
            }
            if (is_float($value)) {
                throw new ContractViolation('non_integer_number');
            }
            if (!is_string($value) && !is_int($value) && !is_bool($value)) {
                throw new \InvalidArgumentException('lockedRules values must be string, int, or bool');
            }
            if (is_string($value) && mb_strlen($value, 'UTF-8') > 256) {
                throw new \InvalidArgumentException('lockedRules string exceeds 256 chars');
            }
        }
        ValueGuard::objects($derivedFrom, SourceVersionRef::class, 64);
    }

    public function toWire(): JsonObject
    {
        return new JsonObject([
            'schemaVersion' => 'directive-artifact:1',
            'tenantId' => $this->tenantId->value,
            'directiveId' => $this->directiveId->value,
            'version' => $this->version,
            'title' => $this->title,
            'contentFormat' => $this->contentFormat,
            'body' => $this->body,
            'bodyDigest' => $this->bodyDigest->value,
            'lockedRules' => $this->lockedRules,
            'promotedBy' => $this->promotedBy->value,
            'promotedAt' => $this->promotedAt->value,
            'derivedFrom' => array_map(
                static fn(SourceVersionRef $ref): JsonObject => $ref->toWire(),
                $this->derivedFrom,
            ),
            'approvalDigest' => $this->approvalDigest->value,
        ]);
    }

    public function artifactDigest(): Fingerprint
    {
        return new Fingerprint(HashDomain::DirectiveArtifact->digest($this->toWire()));
    }
}
