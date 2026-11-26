<?php

declare(strict_types=1);

// The checked-in OpenAPI document is JSON-compatible YAML. No network/tooling dependency.
$root = dirname(__DIR__, 3);
$bytes = file_get_contents($root . '/specs/http/openapi.yaml');
if ($bytes === false) {
    throw new RuntimeException('OpenAPI source missing');
}
$document = json_decode($bytes, true, 128, JSON_THROW_ON_ERROR);
if (!is_array($document) || ($document['openapi'] ?? null) !== '3.1.0') {
    throw new RuntimeException('OpenAPI 3.1 required');
}
if (file_put_contents(dirname(__DIR__) . '/resources/openapi.json', $bytes) === false) {
    throw new RuntimeException('Unable to write OpenAPI JSON');
}
