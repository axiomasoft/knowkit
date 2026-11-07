<?php
declare(strict_types=1);
namespace KnowKit\Domain;

use KnowKit\Domain\Contract\{HashDomain, Jcs, JsonObject};

/** Internal durable checkpoint protocol approved by P2.7 Q1=a; no paths or provider inputs. */
final readonly class JobCheckpointManifest
{
    public function __construct(private JsonObject $wire)
    {
        $keys = ['schemaVersion','tenantId','kbId','sourceId','sourceVersionId','jobId','sourceEpoch',
            'processingDigest','stage','inputDigest','outputDigest','uncertainExternalEffect','resources'];
        $actual = array_keys(iterator_to_array($wire)); sort($actual); sort($keys);
        if ($actual !== $keys || $wire->get('schemaVersion') !== 'job-checkpoint-1.0'
            || !is_bool($wire->get('uncertainExternalEffect')) || !is_string($wire->get('stage'))
            || preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D', $wire->get('stage')) !== 1
            || strlen(Jcs::canonicalize($wire)) > 65536) { self::invalid(); }
        foreach (['tenantId','kbId','sourceId','sourceVersionId','jobId'] as $key) { new Ulid($wire->get($key)); }
        new Decimal($wire->get('sourceEpoch'));
        foreach (['processingDigest','inputDigest','outputDigest'] as $key) { new Sha256($wire->get($key)); }
        $resources = $wire->get('resources');
        if (!is_array($resources) || !array_is_list($resources) || count($resources) > 128) { self::invalid(); }
        $previous = '';
        foreach ($resources as $resource) {
            if (!$resource instanceof JsonObject) { self::invalid(); }
            $names = array_keys(iterator_to_array($resource)); sort($names);
            if ($names !== ['byteLength','digest','resourceId']) { self::invalid(); }
            $id = new ResourceId($resource->get('resourceId')); new Sha256($resource->get('digest'));
            new Uint64($resource->get('byteLength'));
            if (strcmp($id->value, $previous) <= 0) { self::invalid(); } $previous = $id->value;
        }
        if ((!$wire->get('uncertainExternalEffect') && $resources===[])
            || $wire->get('outputDigest')!==HashDomain::ResourceInventory->digest($resources)) { self::invalid(); }
    }
    public function toWire(): JsonObject { return $this->wire; }
    public function digest(): Sha256
    { return new Sha256(hash('sha256', "knowkit:job-checkpoint:v1\x00" . Jcs::canonicalize($this->wire))); }
    public function checkpoint(): Checkpoint { return new Checkpoint($this->digest(), $this->wire->get('uncertainExternalEffect')); }
    private static function invalid(): never { throw new DomainFailure(Outcome::Incompatible, 'invalid_checkpoint'); }
}
