<?php
declare(strict_types=1);

namespace KnowKit\Bridge\Mcp\Server;

use KnowKit\Bridge\Mcp\Server\Tools\DiscloseEvidenceTool;
use KnowKit\Bridge\Mcp\Server\Tools\NavigateOutlineTool;
use KnowKit\Bridge\Mcp\Server\Tools\ReadUnitsTool;
use KnowKit\Bridge\Mcp\Server\Tools\SearchLayeredTool;
use KnowKit\Bridge\Mcp\Server\Tools\VerifyGroundingTool;
use KnowKit\Domain\Contract\Jcs;
use KnowKit\Port\AccessPolicy;

final class CanonicalToolRegistry
{
    private const FORBIDDEN_BOUNDARY_FIELDS = [
        'tenantId',
        'actorId',
        'scope',
        'grants',
        'hostScopeHash',
    ];

    /** @var array<string, McpTool> */
    private array $tools = [];

    /** @var array<string, array{name: string, description: string, inputSchema: array<string, mixed>}> */
    private array $canonicalDefinitions = [];

    /**
     * @param list<McpTool> $tools
     */
    public function __construct(array $tools)
    {
        foreach ($tools as $tool) {
            $name = $tool->name();
            if (isset($this->tools[$name])) {
                throw new \InvalidArgumentException('Duplicate MCP tool: ' . $name);
            }
            $schema = $tool->inputSchema();
            $this->assertBoundaryFreeSchema($name, $schema);
            $canonicalSchemaJson = Jcs::canonicalize($schema);
            /** @var array<string, mixed> $canonicalSchema */
            $canonicalSchema = json_decode($canonicalSchemaJson, true, 512, JSON_THROW_ON_ERROR);

            $definition = [
                'description' => $tool->description(),
                'inputSchema' => $canonicalSchema,
                'name' => $name,
            ];
            $canonicalDefJson = Jcs::canonicalize($definition);
            /** @var array{name: string, description: string, inputSchema: array<string, mixed>} $canonicalDef */
            $canonicalDef = json_decode($canonicalDefJson, true, 512, JSON_THROW_ON_ERROR);

            $this->tools[$name] = $tool;
            $this->canonicalDefinitions[$name] = $canonicalDef;
        }
        ksort($this->tools, SORT_STRING);
        ksort($this->canonicalDefinitions, SORT_STRING);
    }

    public static function withDefaultTools(McpBackend $backend, ?AccessPolicy $accessPolicy = null): self
    {
        return new self([
            new NavigateOutlineTool($backend, $accessPolicy),
            new SearchLayeredTool($backend, $accessPolicy),
            new DiscloseEvidenceTool($backend, $accessPolicy),
            new ReadUnitsTool($backend, $accessPolicy),
            new VerifyGroundingTool($backend, $accessPolicy),
        ]);
    }

    /**
     * @return list<array{name: string, description: string, inputSchema: array<string, mixed>}>
     */
    public function listTools(): array
    {
        return array_values($this->canonicalDefinitions);
    }

    public function schemaDigest(string $toolName): string
    {
        $def = $this->canonicalDefinitions[$toolName] ?? throw new McpValidationException('unknown_tool', [
            'knowkitCode' => 'unknown_tool',
            'tool' => $toolName,
        ]);

        return hash('sha256', Jcs::canonicalize($def['inputSchema']));
    }

    /**
     * @param array<array-key, mixed> $arguments
     * @return array<string, mixed>
     */
    public function call(AuthenticatedMcpContext $context, string $toolName, array $arguments): array
    {
        $tool = $this->tools[$toolName] ?? throw new McpValidationException('unknown_tool', [
            'knowkitCode' => 'unknown_tool',
            'tool' => $toolName,
        ]);
        $schema = $this->canonicalDefinitions[$toolName]['inputSchema'];
        $validated = $this->validateArguments($schema, $arguments);

        return $tool->execute($context, $validated);
    }

    /**
     * @param array<string, mixed> $schema
     * @param array<array-key, mixed> $arguments
     * @return array<string, mixed>
     */
    private function validateArguments(array $schema, array $arguments): array
    {
        /** @var array<string, array<string, mixed>> $properties */
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        /** @var list<string> $required */
        $required = is_array($schema['required'] ?? null) ? $schema['required'] : [];
        $additionalProperties = $schema['additionalProperties'] ?? true;

        foreach ($arguments as $key => $value) {
            $keyStr = (string) $key;
            if ($additionalProperties === false && !array_key_exists($keyStr, $properties)) {
                throw new McpValidationException('unknown_field', [
                    'knowkitCode' => 'unknown_field',
                    'field' => $keyStr,
                ]);
            }
            if (is_float($value)) {
                throw new McpValidationException('non_integer_number', [
                    'knowkitCode' => 'non_integer_number',
                    'field' => $keyStr,
                ]);
            }
        }

        foreach ($required as $reqField) {
            if (!array_key_exists($reqField, $arguments)) {
                throw new McpValidationException('missing_required_field', [
                    'knowkitCode' => 'missing_required_field',
                    'field' => $reqField,
                ]);
            }
        }

        $validated = [];
        foreach ($arguments as $key => $value) {
            $keyStr = (string) $key;
            if (isset($properties[$keyStr])) {
                $this->validateValue($keyStr, $value, $properties[$keyStr]);
            }
            $validated[$keyStr] = $value;
        }

        return $validated;
    }

    /**
     * @param array<string, mixed> $propSchema
     */
    private function validateValue(string $field, mixed $value, array $propSchema): void
    {
        $type = $propSchema['type'] ?? null;
        if ($type === 'string') {
            if (!is_string($value)) {
                throw new McpValidationException('invalid_type', ['knowkitCode' => 'invalid_type', 'field' => $field]);
            }
            if (preg_match('//u', $value) !== 1) {
                throw new McpValidationException('invalid_utf8', ['knowkitCode' => 'invalid_utf8', 'field' => $field]);
            }
            $len = mb_strlen($value, 'UTF-8');
            if (isset($propSchema['minLength']) && is_int($propSchema['minLength']) && $len < $propSchema['minLength']) {
                throw new McpValidationException('invalid_length', ['knowkitCode' => 'invalid_length', 'field' => $field]);
            }
            if (isset($propSchema['maxLength']) && is_int($propSchema['maxLength']) && $len > $propSchema['maxLength']) {
                throw new McpValidationException('invalid_length', ['knowkitCode' => 'invalid_length', 'field' => $field]);
            }
            if (isset($propSchema['pattern']) && is_string($propSchema['pattern'])) {
                if (preg_match('/' . str_replace('/', '\/', $propSchema['pattern']) . '/D', $value) !== 1) {
                    throw new McpValidationException('invalid_pattern', ['knowkitCode' => 'invalid_pattern', 'field' => $field]);
                }
            }
            if (isset($propSchema['enum']) && is_array($propSchema['enum']) && !in_array($value, $propSchema['enum'], true)) {
                throw new McpValidationException('invalid_enum', ['knowkitCode' => 'invalid_enum', 'field' => $field]);
            }

            return;
        }

        if ($type === 'integer') {
            if (!is_int($value)) {
                throw new McpValidationException('invalid_type', ['knowkitCode' => 'invalid_type', 'field' => $field]);
            }
            if (isset($propSchema['minimum']) && is_int($propSchema['minimum']) && $value < $propSchema['minimum']) {
                throw new McpValidationException('out_of_range', ['knowkitCode' => 'out_of_range', 'field' => $field]);
            }
            if (isset($propSchema['maximum']) && is_int($propSchema['maximum']) && $value > $propSchema['maximum']) {
                throw new McpValidationException('out_of_range', ['knowkitCode' => 'out_of_range', 'field' => $field]);
            }

            return;
        }

        if ($type === 'array') {
            if (!is_array($value) || !array_is_list($value)) {
                throw new McpValidationException('invalid_type', ['knowkitCode' => 'invalid_type', 'field' => $field]);
            }
            $count = count($value);
            if (isset($propSchema['minItems']) && is_int($propSchema['minItems']) && $count < $propSchema['minItems']) {
                throw new McpValidationException('invalid_items_count', ['knowkitCode' => 'invalid_items_count', 'field' => $field]);
            }
            if (isset($propSchema['maxItems']) && is_int($propSchema['maxItems']) && $count > $propSchema['maxItems']) {
                throw new McpValidationException('invalid_items_count', ['knowkitCode' => 'invalid_items_count', 'field' => $field]);
            }
            if (isset($propSchema['items']) && is_array($propSchema['items'])) {
                /** @var array<string, mixed> $itemSchema */
                $itemSchema = $propSchema['items'];
                foreach ($value as $idx => $item) {
                    $this->validateValue($field . '[' . $idx . ']', $item, $itemSchema);
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function assertBoundaryFreeSchema(string $toolName, array $schema): void
    {
        if (($schema['additionalProperties'] ?? true) !== false) {
            throw new \InvalidArgumentException('Tool schema must set additionalProperties=false: ' . $toolName);
        }
        $properties = $schema['properties'] ?? [];
        if (is_array($properties)) {
            foreach (self::FORBIDDEN_BOUNDARY_FIELDS as $forbidden) {
                if (array_key_exists($forbidden, $properties)) {
                    throw new \InvalidArgumentException(sprintf(
                        'ARCH-MCP-BOUNDARY violation: tool %s must not expose %s',
                        $toolName,
                        $forbidden,
                    ));
                }
            }
        }
    }
}
