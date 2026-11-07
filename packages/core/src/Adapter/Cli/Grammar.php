<?php

declare(strict_types=1);

namespace KnowKit\Adapter\Cli;

use KnowKit\Domain as D;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Contract\StrictJson;

final readonly class Grammar
{
    /** Required fields precede optional fields (suffix ?). The input is a closed object. */
    private const INPUTS = [
        'kb create' => ['kbId' => 'id', 'name' => 'text'],
        'collection create' => ['collectionId' => 'id', 'parentId?' => 'id'],
        'source register' => ['sourceId' => 'id', 'ownerRef' => 'text', 'origin' => 'text'],
        'source upload' => ['sourceId' => 'id', 'sourceVersionId' => 'id', 'rightsRef' => 'text'],
        'ingest start' => ['sourceVersionId' => 'id', 'generationId' => 'id'],
        'ingest status' => ['jobId' => 'id'],
        'ingest cancel' => ['jobId' => 'id'],
        'ingest reprocess' => ['sourceVersionId' => 'id', 'generationId' => 'id'],
        'publication validate' => ['generationId' => 'id'],
        'publication activate' => ['generationId' => 'id'],
        'search' => ['query' => 'text', 'profile?' => 'text'],
        'disclose' => ['selectionId' => 'id', 'chunkRefs' => 'list', 'disclosureAttemptId' => 'id', 'token' => 'text'],
        'read source' => ['citationId' => 'id', 'attemptId' => 'id'],
        'read page' => ['citationId' => 'id', 'attemptId' => 'id', 'pageId' => 'text'],
        'read region' => ['citationId' => 'id', 'attemptId' => 'id', 'pageId' => 'text', 'bbox' => 'object'],
        'read table' => ['citationId' => 'id', 'attemptId' => 'id', 'tableId' => 'text'],
        'read asset' => ['citationId' => 'id', 'attemptId' => 'id', 'assetId' => 'text'],
        'lifecycle correct' => ['oldVersion' => 'object', 'replacement?' => 'object'],
        'lifecycle revoke' => ['sourceId' => 'id', 'reason' => 'text'],
        'lifecycle delete' => ['sourceId' => 'id'],
        'lifecycle hold' => ['sourceId' => 'id', 'enabled' => 'bool'],
        'purge status' => ['taskId' => 'id'],
        'capabilities' => [],
        'restore verify' => ['runId' => 'id'],
        'reconcile' => ['runId' => 'id'],
    ];

    private const READS = ['ingest status', 'search', 'disclose', 'purge status', 'capabilities'];
    private const REVISIONS = ['kb create', 'collection create', 'source register', 'source upload', 'publication activate'];
    private const EPOCHS = [
        'source upload', 'ingest start', 'ingest reprocess', 'ingest cancel',
        'publication validate', 'publication activate', 'lifecycle correct',
        'lifecycle revoke', 'lifecycle delete', 'lifecycle hold',
    ];

    /** @return list<string> */
    public static function commands(): array
    {
        return array_keys(self::INPUTS);
    }

    /** @param list<string> $arguments */
    public function parse(array $arguments, string $stdin): Command
    {
        $words = [];
        while ($arguments !== [] && !str_starts_with($arguments[0], '--')) {
            $words[] = array_shift($arguments);
        }
        $name = implode(' ', $words);
        if (!array_key_exists($name, self::INPUTS)) {
            throw new UsageError();
        }
        $options = $this->options($arguments);
        $mutation = !in_array($name, self::READS, true) && !str_starts_with($name, 'read ');
        $this->mutationOptions($name, $options, $mutation);
        $input = $this->input($options['input'] ?? null, $stdin);
        $this->validate($name, $input);
        $upload = $options['file'] ?? null;
        if (($name === 'source upload') !== ($upload !== null)) {
            throw new UsageError();
        }

        return new Command($name, $input,
            isset($options['idempotency-key']) ? new D\IdempotencyKey($options['idempotency-key']) : null,
            isset($options['expected-epoch']) ? new D\Decimal($options['expected-epoch']) : null,
            isset($options['expected-revision']) ? new D\Decimal($options['expected-revision']) : null, $upload);
    }

    private function options(array $arguments): array
    {
        $options = [];
        while ($arguments !== []) {
            $flag = array_shift($arguments);
            $parts = explode('=', $flag, 2);
            $key = substr($parts[0], 2);
            if (!str_starts_with($flag, '--') || array_key_exists($key, $options)) {
                throw new UsageError();
            }
            if ($key === 'json' && count($parts) === 1) {
                $options[$key] = true;
                continue;
            }
            if (!in_array($key, ['input', 'file', 'idempotency-key', 'expected-epoch', 'expected-revision'], true)) {
                throw new UsageError();
            }
            $value = $parts[1] ?? array_shift($arguments);
            if (!is_string($value) || $value === '' || str_starts_with($value, '--') || str_contains($value, "\0")) {
                throw new UsageError();
            }
            $options[$key] = $value;
        }

        return $options;
    }

    private function mutationOptions(string $name, array $options, bool $mutation): void
    {
        if ($mutation && !isset($options['idempotency-key'])) {
            throw new UsageError();
        }
        if (!$mutation && (isset($options['idempotency-key']) || isset($options['expected-epoch']) || isset($options['expected-revision']))) {
            throw new UsageError();
        }
        if (in_array($name, self::EPOCHS, true) && !isset($options['expected-epoch'])) {
            throw new UsageError();
        }
        if ($name === 'publication activate' && !isset($options['expected-revision'])) {
            throw new UsageError();
        }
        if (isset($options['expected-revision']) && !in_array($name, self::REVISIONS, true)) {
            throw new UsageError();
        }
    }

    private function input(?string $path, string $stdin): JsonObject
    {
        if ($path === null) {
            return new JsonObject([]);
        }
        if ($path !== '-') {
            // Plain files only: never let a PHP stream wrapper become network/credential authority.
            if (str_contains($path, '://') || !is_file($path)) {
                throw new UsageError();
            }
            $stdin = @file_get_contents($path, false, null, 0, 65537);
        }
        if (!is_string($stdin) || strlen($stdin) > 65536) {
            throw new UsageError();
        }
        $input = StrictJson::decode($stdin, 65536);
        if (!$input instanceof JsonObject) {
            throw new UsageError();
        }

        return $input;
    }

    private function validate(string $name, JsonObject $input): void
    {
        $allowed = [];
        foreach (self::INPUTS[$name] as $field => $type) {
            $key = rtrim($field, '?');
            $allowed[] = $key;
            if (!$input->has($key)) {
                if (!str_ends_with($field, '?')) {
                    throw new UsageError();
                }
                continue;
            }
            $this->value($type, $input->get($key));
        }
        if (array_diff($input->keys(), $allowed) !== []) {
            throw new UsageError();
        }
        $this->nested($name, $input);
    }

    private function nested(string $name, JsonObject $input): void
    {
        $source = ['tenantId' => 'id', 'sourceId' => 'id', 'sourceVersionId' => 'id', 'sourceDigest' => 'sha256'];
        if ($name === 'lifecycle correct') {
            $this->shape($input->get('oldVersion'), $source);
            if ($input->has('replacement')) {
                $this->shape($input->get('replacement'), $source);
            }
        }
        if ($name === 'read region') {
            $this->shape($input->get('bbox'), ['x0' => 'coordinate', 'y0' => 'coordinate', 'x1' => 'coordinate', 'y1' => 'coordinate']);
        }
        if ($name === 'disclose') {
            foreach ($input->get('chunkRefs') as $ref) {
                $this->shape($ref, [...$source, 'documentRevisionId' => 'id', 'irDigest' => 'sha256',
                    'parserSignature' => 'object', 'chunkSetId' => 'id', 'chunkId' => 'id', 'chunkDigest' => 'sha256']);
                $this->shape($ref->get('parserSignature'), ['name' => 'text', 'version' => 'text', 'profileDigest' => 'sha256']);
            }
        }
    }

    private function shape(mixed $wire, array $fields): void
    {
        if (!$wire instanceof JsonObject || count($wire) !== count($fields) || array_diff($wire->keys(), array_keys($fields)) !== []) {
            throw new UsageError();
        }
        foreach ($fields as $field => $type) {
            $this->value($type, $wire->get($field));
        }
    }

    private function value(string $type, mixed $value): void
    {
        $valid = match ($type) {
            'id', 'text' => is_string($value) && $value !== '' && strlen($value) <= 4096 && !str_contains($value, "\0"),
            'bool' => is_bool($value),
            'object' => $value instanceof JsonObject,
            'list' => is_array($value) && array_is_list($value) && $value !== [] && count($value) <= 64,
            'sha256' => is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1,
            'coordinate' => is_int($value) && $value >= 0 && $value <= 1000000,
            default => false,
        };
        if (!$valid) {
            throw new UsageError();
        }
        if ($type === 'id') {
            new D\Ulid($value);
        }
    }
}
