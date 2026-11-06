<?php
declare(strict_types=1);

namespace KnowKit\Tests\Contract\Schemas;

use KnowKit\Domain\Contract\ContractValidator;
use KnowKit\Domain\Contract\ContractViolation;
use KnowKit\Domain\Contract\Digests;
use KnowKit\Domain\Contract\HashDomain;
use KnowKit\Domain\Contract\Jcs;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Contract\LocatorConversion;
use KnowKit\Domain\Contract\SchemaSet;
use KnowKit\Domain\Contract\ScopeBinding;
use KnowKit\Domain\Contract\StrictJson;

/**
 * Test-side corpus loader and report builder. Produces the same structure as
 * tests/contract/schemas/knowkit_contract.py `report`, so PHP and Python outputs compare byte-for-byte
 * after JCS. Filesystem access stays here; KnowKit\Domain\Contract remains pure.
 */
final class ContractHarness
{
    public const MANIFEST = 'tests/fixtures/contracts/manifest.v1.json';
    public const HASH_VECTORS = 'tests/fixtures/contracts/v1/vectors/hash-vectors.json';
    public const LOCATOR_VECTORS = 'tests/fixtures/contracts/v1/vectors/locator-vectors.json';
    public const BINDING_VECTORS = 'tests/fixtures/contracts/v1/vectors/binding-vectors.json';

    public readonly SchemaSet $schemas;
    public readonly ContractValidator $validator;

    public function __construct(public readonly string $root)
    {
        $schemas = [];
        $files = glob($root . '/schemas/*.schema.json') ?: [];
        sort($files, SORT_STRING);
        foreach ($files as $file) {
            $schema = StrictJson::decode((string) file_get_contents($file), 4194304);
            $id = 'urn:knowkit:schema:' . basename($file, '.schema.json') . ':1.0';
            if (!$schema instanceof JsonObject || $schema->get('$id') !== $id) {
                throw new \LogicException('schema $id mismatch: ' . $file);
            }
            $schemas[$id] = $schema;
        }
        $this->schemas = new SchemaSet($schemas);
        $this->validator = new ContractValidator($this->schemas);
    }

    public function read(string $relative): string
    {
        $path = realpath($this->root . '/' . $relative);
        $root = realpath($this->root);
        if ($path === false || $root === false || !str_starts_with($path, $root . DIRECTORY_SEPARATOR) || !is_file($path)) {
            throw new ContractViolation('missing_file');
        }
        return (string) file_get_contents($path);
    }

    public function json(string $relative, int $limit = 67108864): JsonObject
    {
        $value = StrictJson::decode($this->read($relative), $limit);
        if (!$value instanceof JsonObject) {
            throw new ContractViolation('type');
        }
        return $value;
    }

    /** Same checks and order as knowkit_contract.load_manifest. */
    public function loadManifest(string $manifestPath): JsonObject
    {
        $bytes = (string) file_get_contents($manifestPath);
        $errors = $this->validator->validateBytes($bytes, 'urn:knowkit:schema:fixture-manifest:1.0');
        if ($errors !== []) {
            throw new ContractViolation($errors[0]['code'], $errors[0]['path']);
        }
        $manifest = StrictJson::decode($bytes, 4194304);
        $seen = [];
        foreach ($manifest->get('entries') as $index => $entry) {
            $prefix = '/entries/' . $index;
            if (isset($seen[$entry->get('id')])) {
                throw new ContractViolation('duplicate_identifier', $prefix . '/id');
            }
            $seen[$entry->get('id')] = true;
            if (!$this->schemas->has($entry->get('schemaId'))) {
                throw new ContractViolation('unknown_schema', $prefix . '/schemaId');
            }
            try {
                $content = $this->read($entry->get('path'));
            } catch (ContractViolation) {
                throw new ContractViolation('missing_file', $prefix . '/path');
            }
            if (Digests::sha256($content) !== $entry->get('sha256')) {
                throw new ContractViolation('digest_mismatch', $prefix . '/sha256');
            }
        }
        foreach ($manifest->get('requiredTags') as $index => $tag) {
            $found = false;
            foreach ($manifest->get('entries') as $entry) {
                $found = $found || in_array($tag, $entry->get('tags'), true);
            }
            if (!$found) {
                throw new ContractViolation('empty_case_group', '/requiredTags/' . $index);
            }
        }
        return $manifest;
    }

    /** @return list<JsonObject> */
    public function vectors(string $relative, string $schemaId): array
    {
        $bytes = $this->read($relative);
        $errors = $this->validator->validateBytes($bytes, $schemaId);
        if ($errors !== []) {
            throw new ContractViolation($errors[0]['code'], $relative . '#' . $errors[0]['path']);
        }
        return StrictJson::decode($bytes, 4194304)->get('vectors');
    }

    /** @return array<string, mixed> */
    public function report(): array
    {
        $manifest = $this->loadManifest($this->root . '/' . self::MANIFEST);
        $corpus = $roundTrip = $valid = [];
        foreach ($manifest->get('entries') as $entry) {
            $errors = $this->validator->validateBytes($this->read($entry->get('path')), $entry->get('schemaId'));
            $corpus[] = ['id' => $entry->get('id'), 'errors' => $errors];
            if ($entry->get('expected') === 'valid') {
                $valid[$entry->get('id')] = StrictJson::decode($this->read($entry->get('path')), 67108864);
            }
        }
        foreach ($manifest->get('entries') as $entry) {
            if (!in_array('ir-roundtrip', $entry->get('tags'), true)) {
                continue;
            }
            $first = StrictJson::decode($this->read($entry->get('path')), 67108864);
            $canonical = Jcs::canonicalize($first);
            $second = StrictJson::decode($canonical, 67108864);
            $roundTrip[] = ['id' => $entry->get('id'), 'irDigest' => Digests::ir($first),
                'stable' => $canonical === Jcs::canonicalize($second) && SchemaSet::jsonEqual($first, $second)];
        }
        $domains = [];
        foreach (HashDomain::cases() as $case) {
            $domains[$case->key()] = $case->value;
        }
        return ['schemaVersion' => 'contract-report-1.0', 'corpusVersion' => $manifest->get('corpusVersion'),
            'schemas' => $this->schemas->ids(), 'corpus' => $corpus, 'hashVectors' => $this->hashReport(),
            'locatorVectors' => $this->locatorReport(), 'bindingVectors' => $this->bindingReport(),
            'irRoundTrip' => $roundTrip, 'hashDomains' => $domains, 'identityConflicts' => $this->identityConflicts($valid)];
    }

    /**
     * Cross-record immutable identity check over valid corpus documents (same walk as
     * knowkit_contract.identity_conflicts): every object that names a documentRevisionId with an
     * irDigest, or a sourceVersionId with a sourceDigest, must agree with every other record.
     *
     * @param array<string, mixed> $documents corpus id => decoded document
     * @return list<array{entry: string, path: string, field: string}>
     */
    public function identityConflicts(array $documents): array
    {
        $seen = ['irDigest' => [], 'sourceDigest' => []];
        $conflicts = [];
        $walk = function (mixed $node, string $path, string $id) use (&$walk, &$seen, &$conflicts): void {
            if ($node instanceof JsonObject) {
                foreach (['documentRevisionId' => 'irDigest', 'sourceVersionId' => 'sourceDigest'] as $key => $field) {
                    if (is_string($node->get($key)) && is_string($node->get($field))) {
                        $known = $seen[$field][$node->get($key)] ??= $node->get($field);
                        if ($known !== $node->get($field)) {
                            $conflicts[] = ['entry' => $id, 'path' => $path . '/' . $field, 'field' => $field];
                        }
                    }
                }
                foreach ($node as $name => $child) {
                    $walk($child, $path . SchemaSet::pointer((string) $name), $id);
                }
            } elseif (is_array($node)) {
                foreach ($node as $index => $child) {
                    $walk($child, $path . '/' . $index, $id);
                }
            }
        };
        foreach ($documents as $id => $document) {
            $walk($document, '', (string) $id);
        }
        return $conflicts;
    }

    /** @return list<array<string, mixed>> */
    public function hashReport(): array
    {
        $results = [];
        foreach ($this->vectors(self::HASH_VECTORS, 'urn:knowkit:schema:hash-vectors:1.0') as $vector) {
            $entry = ['id' => $vector->get('id')];
            switch ($vector->get('algorithm')) {
                case 'jcs':
                    $canonical = Jcs::canonicalize($vector->get('payload'));
                    $entry += ['canonical' => $canonical, 'sha256' => Digests::sha256($canonical)];
                    break;
                case 'domain':
                    $payload = $vector->get('payload');
                    if ($vector->has('excludeField')) {
                        $payload = Digests::withoutSelf($payload, $vector->get('excludeField'));
                    }
                    $entry += ['canonical' => Jcs::canonicalize($payload),
                        'sha256' => HashDomain::fromKey($vector->get('domain'))->digest($payload)];
                    break;
                case 'ir':
                    $entry += ['sha256' => Digests::ir($this->json($vector->get('payloadPath')))];
                    break;
                case 'raw':
                    $entry += ['sha256' => Digests::sha256($vector->get('payloadUtf8'))];
                    break;
                case 'decode':
                    try {
                        StrictJson::decode((string) base64_decode($vector->get('inputBase64'), true));
                        $entry += ['error' => null];
                    } catch (ContractViolation $violation) {
                        $entry += ['error' => $violation->errorCode];
                    }
                    break;
                case 'legacy':
                    $entry += ['sha256' => Digests::sha256(Jcs::canonicalize($vector->get('payload')))];
                    break;
            }
            $results[] = $entry;
        }
        return $results;
    }

    /** @return list<array<string, mixed>> */
    public function locatorReport(): array
    {
        $results = [];
        foreach ($this->vectors(self::LOCATOR_VECTORS, 'urn:knowkit:schema:locator-vectors:1.0') as $vector) {
            try {
                $result = match ($vector->get('op')) {
                    'page_index_to_locator' => LocatorConversion::pageIndexToLocator($vector->get('input')),
                    'normalize_bbox' => LocatorConversion::normalizeBbox($vector->get('input')),
                    'resolve_span' => LocatorConversion::resolveSpan($vector->get('input')),
                    default => throw new \LogicException('unknown locator op'),
                };
                $results[] = ['id' => $vector->get('id'), 'result' => $result];
            } catch (ContractViolation $violation) {
                $results[] = ['id' => $vector->get('id'), 'error' => $violation->errorCode];
            }
        }
        return $results;
    }

    /** @return list<array<string, mixed>> */
    public function bindingReport(): array
    {
        $results = [];
        foreach ($this->vectors(self::BINDING_VECTORS, 'urn:knowkit:schema:binding-vectors:1.0') as $vector) {
            $input = $vector->get('input');
            $scopeOp = $vector->get('op') === 'scope_selection';
            $inputs = $scopeOp ? ['scope' => 'scope', 'selection' => 'selection'] : ['request' => 'disclosure-request', 'selection' => 'selection'];
            $invalid = [];
            foreach ($inputs as $field => $name) {
                foreach ($this->validator->validateValue($input->get($field), 'urn:knowkit:schema:' . $name . ':1.0') as $error) {
                    $invalid[] = ['field' => $field, 'code' => $error['code'], 'path' => $error['path']];
                }
            }
            if ($invalid !== []) {
                $results[] = ['id' => $vector->get('id'), 'inputErrors' => $invalid];
                continue;
            }
            $outcome = $scopeOp
                ? ScopeBinding::checkSelection($input->get('scope'), $input->get('selection'))
                : ScopeBinding::checkDisclosure($input->get('request'), $input->get('selection'));
            $results[] = ['id' => $vector->get('id')] + $outcome;
        }
        return $results;
    }
}
