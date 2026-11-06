<?php
declare(strict_types=1);

namespace KnowKit\Domain\Contract;

/**
 * Validation pipeline for one wire document: strict decode (bounded by the schema's
 * x-knowkit-maxDocumentBytes) -> schemaVersion negotiation (unsupported -> incompatible, no further
 * checks) -> schema subset -> semantic rules. Returns the sorted error list; [] means valid.
 */
final class ContractValidator
{
    public function __construct(private readonly SchemaSet $schemas)
    {
    }

    /** @return list<array{code: string, path: string}> */
    public function validateBytes(string $bytes, string $schemaId): array
    {
        $schema = $this->schemas->root($schemaId);
        $limit = $schema->get('x-knowkit-maxDocumentBytes');
        try {
            $document = StrictJson::decode($bytes, is_int($limit) ? $limit : StrictJson::DEFAULT_MAX_BYTES);
        } catch (ContractViolation $violation) {
            return [['code' => $violation->errorCode, 'path' => '']];
        }
        return $this->validateValue($document, $schemaId);
    }

    /** @return list<array{code: string, path: string}> */
    public function validateValue(mixed $document, string $schemaId): array
    {
        $schema = $this->schemas->root($schemaId);
        $version = $schema->get('x-knowkit-schemaVersion');
        if (is_string($version) && $document instanceof JsonObject && is_string($document->get('schemaVersion'))
            && $document->get('schemaVersion') !== $version) {
            return [['code' => 'unsupported_schema_version', 'path' => '/schemaVersion']];
        }
        $errors = $this->schemas->validate($document, $schemaId);
        if ($errors === [] && $document instanceof JsonObject) {
            $errors = SchemaSet::sortErrors(SemanticRules::check($schemaId, $document));
        }
        return $errors;
    }

    /** Decode and require validity; the first sorted error becomes the typed violation. */
    public function decodeValid(string $bytes, string $schemaId): JsonObject
    {
        $errors = $this->validateBytes($bytes, $schemaId);
        if ($errors !== []) {
            throw new ContractViolation($errors[0]['code'], $errors[0]['path']);
        }
        $limit = $this->schemas->root($schemaId)->get('x-knowkit-maxDocumentBytes');
        $document = StrictJson::decode($bytes, is_int($limit) ? $limit : StrictJson::DEFAULT_MAX_BYTES);
        if (!$document instanceof JsonObject) {
            throw new ContractViolation('type');
        }
        return $document;
    }
}
