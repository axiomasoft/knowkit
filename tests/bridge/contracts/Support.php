<?php

declare(strict_types=1);

namespace KnowKit\Tests\Bridge\Contracts;

use AgenToolsPlatform\Core\Common\CanonicalHasher;
use AgenToolsPlatform\Core\Common\CanonicalJson;
use AgenToolsPlatform\Core\Common\ContractViolation;
use AgenToolsPlatform\Core\Common\ErrorCode;
use AgenToolsPlatform\Core\Common\Ref;
use AgenToolsPlatform\Core\Common\SessionId;
use AgenToolsPlatform\Core\Common\TenantId as HostTenant;
use AgenToolsPlatform\Core\Security\Correlation;
use AgenToolsPlatform\Core\Security\ExecutionContext;
use AgenToolsPlatform\Core\Security\HostAuthorization;
use AgenToolsPlatform\Core\Security\HostIdentity;
use KnowKit\Bridge\AgenTools\Legacy\CanonicalTarget;
use KnowKit\Bridge\AgenTools\Legacy\CitationBinding;
use KnowKit\Bridge\AgenTools\Legacy\LimitedEvidence;
use KnowKit\Bridge\AgenTools\Legacy\Locator;
use KnowKit\Bridge\AgenTools\Legacy\Mapping;
use KnowKit\Bridge\AgenTools\Legacy\ReleaseIdentity;
use KnowKit\Domain\ActorId;
use KnowKit\Domain\CitationRef;
use KnowKit\Domain\Contract\ContractValidator;
use KnowKit\Domain\Contract\Jcs;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Contract\SchemaSet;
use KnowKit\Domain\Contract\StrictJson;
use KnowKit\Domain\Decimal;
use KnowKit\Domain\DocumentRevisionId;
use KnowKit\Domain\DocumentRevisionRef;
use KnowKit\Domain\Fingerprint;
use KnowKit\Domain\IrDigest;
use KnowKit\Domain\KbId;
use KnowKit\Domain\ParserSignature;
use KnowKit\Domain\Scope;
use KnowKit\Domain\Sha256;
use KnowKit\Domain\SnapshotId;
use KnowKit\Domain\SnapshotRef;
use KnowKit\Domain\SourceDigest;
use KnowKit\Domain\SourceId;
use KnowKit\Domain\SourceVersionId;
use KnowKit\Domain\SourceVersionRef;
use KnowKit\Domain\TenantId;

/** Only tests load the pinned legacy extension; the bridge imports platform-core types. */
spl_autoload_register(static function (string $class): void {
    $prefix = 'AgenToolsPlatform\\Knowledge\\';
    if (str_starts_with($class, $prefix)) {
        $path = Support::donorRoot() . '/extensions/knowledge/src/'
            . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    }
});

final class JcsPort implements CanonicalJson
{
    public function canonicalize(mixed $value): string
    {
        return Jcs::canonicalizeJson(json_encode($value, JSON_THROW_ON_ERROR));
    }

    public function canonicalizeJson(string $json): string
    {
        return Jcs::canonicalizeJson($json);
    }
}

/** Adapted from the pinned AgenTools TestHost; no permanent grant is cached. */
final class Host implements HostAuthorization
{
    public bool $denied = false;
    public string $revision = 'policy-1';
    public int $calls = 0;

    public function authorize(SessionId $session, string $purpose): HostIdentity
    {
        $this->calls++;
        if ($this->denied) {
            throw new ContractViolation(ErrorCode::AccessDenied);
        }

        return new HostIdentity(
            tenant: $session->tenant,
            actorRef: new Ref(tenant: $session->tenant, value: Support::ACTOR),
            actingAs: null,
            authorizationRef: new Ref(tenant: $session->tenant, value: 'authorization:golden'),
            policyRevision: $this->revision,
        );
    }
}

final class Support
{
    public const PIN = '2054acda51eb69eca1579c78900858c5ff3de01e';
    public const TENANT = '01JC0000000000000000000001';
    public const OTHER_TENANT = '01JC0000000000000000000003';
    public const ACTOR = '01JC0000000000000000000004';
    public const KB = '01JC0000000000000000000002';

    public static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    public static function donorRoot(): string
    {
        return self::root() . '/packages/agentools-bridge/vendor/agentools/platform-core';
    }

    public static function installedBytes(string $relative): string
    {
        if ($relative === '' || str_starts_with($relative, '/') || str_contains($relative, '..')) {
            throw new \RuntimeException('Installed AgenTools host is absent: ' . $relative);
        }
        $path = self::donorRoot() . '/' . $relative;
        $bytes = is_file($path) ? file_get_contents($path) : false;
        if (!is_string($bytes)) {
            throw new \RuntimeException('Installed AgenTools host is absent: ' . $relative);
        }

        return $bytes;
    }

    public static function fixture(string $name): array
    {
        return json_decode(
            file_get_contents(self::root() . '/tests/fixtures/agentools/v1/' . $name . '.json'),
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        );
    }

    public static function wire(array $value): JsonObject
    {
        return StrictJson::decode(json_encode($value, JSON_THROW_ON_ERROR));
    }

    public static function validator(): ContractValidator
    {
        static $validator = null;
        if ($validator instanceof ContractValidator) {
            return $validator;
        }
        $schemas = [];
        foreach (['common', 'citation-ref', 'legacy-evidence-ref'] as $name) {
            $wire = StrictJson::decode(file_get_contents(self::root() . '/schemas/' . $name . '.schema.json'));
            $schemas[$wire->get('$id')] = $wire;
        }

        return $validator = new ContractValidator(new SchemaSet($schemas));
    }

    public static function identity(array $data): ReleaseIdentity
    {
        return new ReleaseIdentity(
            tenantId: new TenantId($data['tenantId']),
            corpus: $data['corpus'],
            releaseId: $data['releaseId'],
            legacyHashKind: $data['legacyHashKind'],
            legacyHash: $data['legacyHash'],
        );
    }

    public static function locator(ReleaseIdentity $identity, array $data): Locator
    {
        return new Locator(
            release: $identity,
            material: $data['material'],
            version: $data['version'],
            fragment: $data['fragment'],
            page: $data['page'],
            start: $data['start'],
            end: $data['end'],
            contentHash: new Sha256($data['content_hash']),
        );
    }

    public static function mapping(array $data): Mapping
    {
        $identity = self::identity($data['identity']);
        $locator = self::locator(identity: $identity, data: $data['legacyLocator']);
        if ($data['originalBytes'] === null) {
            return Mapping::withoutOriginals(
                evidence: new LimitedEvidence(release: $identity, resolverRef: $data['resolverRef']),
                locators: [$locator],
            );
        }
        $source = new SourceVersionRef(
            tenantId: new TenantId($data['revision']['tenantId']),
            sourceId: new SourceId($data['revision']['sourceId']),
            sourceVersionId: new SourceVersionId($data['revision']['sourceVersionId']),
            sourceDigest: new SourceDigest($data['revision']['sourceDigest']),
        );
        $source->assertBytes($data['originalBytes']);

        return Mapping::fromOriginals(
            release: $identity,
            target: self::target(data: $data, source: $source, locator: $locator),
        );
    }

    private static function target(array $data, SourceVersionRef $source, Locator $locator): CanonicalTarget
    {
        $parser = $data['revision']['parserSignature'];

        return new CanonicalTarget(
            revision: new DocumentRevisionRef(
                sourceRef: $source,
                documentRevisionId: new DocumentRevisionId($data['revision']['documentRevisionId']),
                irDigest: new IrDigest($data['revision']['irDigest']),
                parserSignature: new ParserSignature(
                    name: $parser['name'],
                    version: $parser['version'],
                    profileDigest: new Fingerprint($parser['profileDigest']),
                ),
            ),
            snapshot: new SnapshotRef(
                tenantId: new TenantId($data['snapshot']['tenantId']),
                kbId: new KbId($data['snapshot']['kbId']),
                snapshotId: new SnapshotId($data['snapshot']['snapshotId']),
                manifestDigest: new Fingerprint($data['snapshot']['manifestDigest']),
            ),
            citations: [new CitationBinding(
                legacy: $locator,
                canonical: new CitationRef(value: self::wire($data['citation']), validator: self::validator()),
            )],
        );
    }

    public static function scope(string $tenant = self::TENANT): Scope
    {
        return new Scope(
            tenantId: new TenantId($tenant),
            kbId: new KbId(self::KB),
            actorId: new ActorId(self::ACTOR),
            purpose: 'support.read',
            policyEpoch: new Decimal('1'),
            hostScopeHash: new Sha256(str_repeat('a', 64)),
        );
    }

    /** @return array{Host, ExecutionContext, \AgenToolsPlatform\Core\Common\PayloadHash, Scope} */
    public static function trusted(): array
    {
        $host = new Host();
        $context = ExecutionContext::fromHost(
            host: $host,
            session: new SessionId(tenant: new HostTenant(self::TENANT), value: 'session:golden'),
            purpose: 'support.read',
            correlation: new Correlation(traceId: 'trace:golden', requestId: 'request:golden'),
        );
        $hash = $context->scopeHash(new CanonicalHasher(new JcsPort()));
        $base = self::scope();
        $scope = new Scope(
            tenantId: $base->tenantId,
            kbId: $base->kbId,
            actorId: $base->actorId,
            purpose: $base->purpose,
            policyEpoch: $base->policyEpoch,
            hostScopeHash: new Sha256(substr($hash->value, strlen('sha-256:'))),
        );

        return [$host, $context, $hash, $scope];
    }
}
