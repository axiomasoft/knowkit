<?php
declare(strict_types=1);

namespace KnowKit\Bridge\Mcp\Server;

use KnowKit\Domain\Contract\ContractViolation;
use KnowKit\Domain\Contract\Jcs;
use KnowKit\Domain\Contract\StrictJson;
use KnowKit\Domain\DomainFailure;
use KnowKit\Domain\Outcome;

final readonly class StatelessMcpServer
{
    public const PROTOCOL_VERSION = '2026-03-26';

    public function __construct(
        private McpScopeAuthenticator $authenticator,
        private CanonicalToolRegistry $tools,
        private ResourceTemplateHandler $resources,
        private DirectivePromptHandler $prompts,
        private int $cacheTtlMs = 300000,
        private string $cacheScope = 'tenant',
    ) {
        if ($this->cacheTtlMs < 0) {
            throw new \InvalidArgumentException('cacheTtlMs must be non-negative');
        }
        if ($this->cacheScope !== 'tenant' && $this->cacheScope !== 'catalog') {
            throw new \InvalidArgumentException('cacheScope must be tenant or catalog');
        }
    }

    /**
     * @param array<string, string|list<string>> $transportHeaders
     */
    public function handleJson(string $rawJson, array $transportHeaders = []): string
    {
        $id = null;
        try {
            $decoded = StrictJson::decode($rawJson);
            if (!is_object($decoded)) {
                return Jcs::canonicalize($this->errorResponse(null, -32600, 'invalid_request'));
            }
            /** @var array<string, mixed> $request */
            $request = json_decode(Jcs::canonicalize($decoded), true, 512, JSON_THROW_ON_ERROR);
            $id = $request['id'] ?? null;
            if (!is_string($id) && !is_int($id) && $id !== null) {
                $id = null;
            }

            return Jcs::canonicalize($this->handle($request, $transportHeaders));
        } catch (ContractViolation $violation) {
            return Jcs::canonicalize($this->errorResponse($id, -32700, $violation->getMessage(), [
                'knowkitCode' => $violation->getMessage(),
            ]));
        }
    }

    /**
     * @param array<string, mixed> $request
     * @param array<string, string|list<string>> $transportHeaders
     * @return array<string, mixed>
     */
    public function handle(array $request, array $transportHeaders = []): array
    {
        $rawId = $request['id'] ?? null;
        $id = (is_string($rawId) || is_int($rawId)) ? $rawId : null;

        if (($request['jsonrpc'] ?? null) !== '2.0' || !is_string($request['method'] ?? null)) {
            return $this->errorResponse($id, -32600, 'invalid_request');
        }

        $method = $request['method'];
        /** @var array<string, mixed> $params */
        $params = is_array($request['params'] ?? null) ? $request['params'] : [];

        try {
            // Authenticate BEFORE parsing business arguments on every request (stateless boundary).
            $context = $this->authenticator->authenticate($transportHeaders);

            $result = match ($method) {
                'server/discover' => $this->discover($context),
                'tools/list' => [
                    'tools' => $this->tools->listTools(),
                    'cache' => $this->cacheHint(),
                ],
                'tools/call' => $this->callTool($context, $params),
                'resources/list' => [
                    'resources' => $this->resources->list($context),
                    'cache' => $this->cacheHint(),
                ],
                'resources/templates/list' => [
                    'resourceTemplates' => $this->resources->templates(),
                ],
                'resources/read' => $this->readResource($context, $params),
                'prompts/list' => [
                    'prompts' => $this->prompts->list($context),
                    'cache' => $this->cacheHint(),
                ],
                'prompts/get' => $this->getPrompt($context, $params),
                default => throw new McpValidationException('method_not_found', ['method' => $method], -32601),
            };

            return [
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => $result,
            ];
        } catch (McpValidationException $e) {
            return $this->errorResponse($id, $e->getCode(), $e->knowkitCode, $e->data);
        } catch (DomainFailure $e) {
            $code = match ($e->outcome) {
                Outcome::Denied => -32003,
                Outcome::Incompatible => -32602,
                default => -32000,
            };

            return $this->errorResponse($id, $code, $e->errorCode, [
                'outcome' => $e->outcome->value,
                'knowkitCode' => $e->errorCode,
            ]);
        } catch (ContractViolation $e) {
            return $this->errorResponse($id, -32602, $e->getMessage(), [
                'knowkitCode' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function discover(AuthenticatedMcpContext $context): array
    {
        return [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'stateless' => true,
            'cache' => $this->cacheHint(),
            'tools' => $this->tools->listTools(),
            'resourceTemplates' => $this->resources->templates(),
            'prompts' => $this->prompts->list($context),
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function callTool(AuthenticatedMcpContext $context, array $params): array
    {
        $name = $params['name'] ?? null;
        if (!is_string($name) || $name === '') {
            throw new McpValidationException('invalid_params', ['knowkitCode' => 'invalid_params', 'field' => 'name']);
        }
        $arguments = $params['arguments'] ?? [];
        if (!is_array($arguments)) {
            throw new McpValidationException('invalid_params', ['knowkitCode' => 'invalid_params', 'field' => 'arguments']);
        }

        $payload = $this->tools->call($context, $name, $arguments);

        return [
            'content' => [
                [
                    'type' => 'text',
                    'text' => Jcs::canonicalize($payload),
                ],
            ],
            'structuredContent' => $payload,
            'isError' => false,
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function readResource(AuthenticatedMcpContext $context, array $params): array
    {
        $uri = $params['uri'] ?? null;
        if (!is_string($uri) || $uri === '') {
            throw new McpValidationException('invalid_params', ['knowkitCode' => 'invalid_params', 'field' => 'uri']);
        }

        return $this->resources->read($context, $uri);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function getPrompt(AuthenticatedMcpContext $context, array $params): array
    {
        $name = $params['name'] ?? null;
        if (!is_string($name) || $name === '') {
            throw new McpValidationException('invalid_params', ['knowkitCode' => 'invalid_params', 'field' => 'name']);
        }

        return $this->prompts->get($context, $name);
    }

    /**
     * @return array{cacheScope: string, ttlMs: int}
     */
    private function cacheHint(): array
    {
        return [
            'cacheScope' => $this->cacheScope,
            'ttlMs' => $this->cacheTtlMs,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function errorResponse(string|int|null $id, int $code, string $message, array $data = []): array
    {
        $error = [
            'code' => $code,
            'message' => $message,
        ];
        if ($data !== []) {
            $error['data'] = $data;
        }

        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => $error,
        ];
    }
}
