<?php

declare(strict_types=1);

namespace KnowKit\Http\Server;

use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Contract\SchemaSet;
use KnowKit\Domain\Contract\StrictJson;

/** Generated from the local OpenAPI contract. No network schema resolution or input-selected path. */
final readonly class Contract
{
    private const ID = 'urn:knowkit:http:1.0';

    public JsonObject $document;
    private SchemaSet $schemas;

    public function __construct()
    {
        $this->document = StrictJson::decode(
            (string) file_get_contents(dirname(__DIR__, 2) . '/resources/openapi.json'),
            4194304,
        );
        $definitions = $this->definitions($this->document->get('components')->get('schemas'));
        $schemas = [];
        foreach ($definitions as $name => $definition) {
            $id = self::ID . ':' . $name;
            $schemas[$id] = new JsonObject([
                '$schema' => SchemaSet::DIALECT, '$id' => $id,
                '$defs' => $definitions, '$ref' => '#/$defs/' . $name,
            ]);
        }
        $this->schemas = new SchemaSet($schemas);
    }

    /** @return list<Route> */
    public function routes(): array
    {
        $routes = [];
        foreach ($this->document->get('paths') as $path => $methods) {
            foreach ($methods as $method => $operation) {
                $routes[] = new Route(
                    method: strtoupper($method), path: '/v1' . $path,
                    operation: $operation->get('operationId'), schema: $operation->get('x-knowkit-schema'),
                    mutation: $operation->get('x-knowkit-mutation'), epoch: $operation->get('x-knowkit-epoch'),
                    revision: $operation->get('x-knowkit-revision'), successStatus: $operation->get('x-knowkit-success'),
                    revisionAllowed: $operation->get('x-knowkit-revision-allowed') === true,
                );
            }
        }

        return $routes;
    }

    /** @return array{Route, array<string, \KnowKit\Domain\Ulid>} */
    public function match(Request $request): array
    {
        if (strlen($request->target) > 2048 || preg_match('~^[a-zA-Z0-9/{._}-]+$~D', $request->target) !== 1) {
            throw new InputFailure();
        }
        foreach ($this->routes() as $route) {
            $pattern = preg_replace('/\\\{[a-z]+\\\}/', '([0-7][0-9A-HJKMNP-TV-Z]{25})', preg_quote($route->path, '~'));
            if ($route->method !== $request->method || preg_match('~^' . $pattern . '$~D', $request->target, $matches) !== 1) {
                continue;
            }
            preg_match_all('/\{([a-z]+)\}/', $route->path, $names);
            $parameters = [];
            foreach ($names[1] as $index => $name) {
                $parameters[$name] = new \KnowKit\Domain\Ulid($matches[$index + 1]);
            }

            return [$route, $parameters];
        }
        throw new InputFailure(status: 404, errorCode: 'not_found');
    }

    public function validate(string $schema, JsonObject $body): void
    {
        if ($this->schemas->validate(instance: $body, schemaId: self::ID . ':' . $schema) !== []) {
            throw new InputFailure();
        }
    }

    private function definitions(mixed $value): mixed
    {
        if ($value instanceof JsonObject) {
            $copy = [];
            foreach ($value as $key => $child) {
                $copy[$key] = $this->definitions($child);
            }

            return new JsonObject($copy);
        }
        if (is_array($value)) {
            return array_map($this->definitions(...), $value);
        }

        return is_string($value) ? str_replace('#/components/schemas/', '#/$defs/', $value) : $value;
    }
}
