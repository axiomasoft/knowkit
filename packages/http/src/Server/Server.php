<?php

declare(strict_types=1);

namespace KnowKit\Http\Server;

use KnowKit\Application\Access\AccessContext;
use KnowKit\Application\Access\RequestBoundary;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\ContractViolation;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Contract\StrictJson;
use KnowKit\Port\IdGenerator;

final readonly class Server
{
    public function __construct(
        private Authenticator $authenticator,
        private HostApplication $application,
        private Contract $contract,
        private CommandFactory $commands,
        private IdGenerator $ids,
    ) {}

    public function handle(Request $request): Response
    {
        $requestId = $this->requestId($request);
        $level = ob_get_level();
        ob_start();
        try {
            return $this->authenticated(request: $request, requestId: $requestId);
        } catch (InputFailure $failure) {
            return Response::error(status: $failure->status, code: $failure->errorCode, requestId: $requestId);
        } catch (D\DomainFailure $failure) {
            return Response::reply(
                reply: new Reply(D\Result::failure(failure: $failure, retryable: $failure->outcome === D\Outcome::Unavailable)),
                route: new Route(
                    method: 'GET', path: '', operation: '', schema: '', mutation: false,
                    epoch: false, revision: false, successStatus: 200,
                ),
                requestId: $requestId,
            );
        } catch (\Throwable) {
            return Response::error(status: 500, code: 'internal_error', requestId: $requestId);
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }
    }

    private function authenticated(Request $request, D\Ulid $requestId): Response
    {
        $host = $this->authenticate($request);
        // Authenticate before route/schema/body processing. Never construct Scope from request JSON.
        AccessContext::fromHost($host)->assertCurrent();
        [$route, $parameters] = $this->contract->match($request);
        $version = $request->header('KnowKit-Schema-Version');
        if ($version !== null && $version !== '1.0') {
            throw new InputFailure(status: 409, errorCode: 'schema_mismatch');
        }
        [$command, $context] = $this->command(
            request: $request, route: $route, parameters: $parameters, requestId: $requestId, host: $host,
        );
        $context->assertCurrent();
        $reply = $this->execute(command: $command, context: $context);

        return Response::reply(reply: $reply, route: $route, requestId: $requestId);
    }

    /** @return array{Command, AccessContext} */
    private function command(
        Request $request,
        Route $route,
        array $parameters,
        D\Ulid $requestId,
        \KnowKit\Application\Access\HostIdentity $host,
    ): array {
        try {
            $body = $this->body(request: $request, route: $route);
            $context = (new RequestBoundary($host))->context($body);
            if (isset($parameters['kb']) && $parameters['kb']->value !== $context->scope()->kbId->value) {
                throw new InputFailure(status: 404, errorCode: 'not_found');
            }
            $this->contract->validate(schema: $route->schema, body: $body);
            $command = $this->commands->create(
                request: $request, route: $route, parameters: $parameters, body: $body,
                requestId: $requestId, context: $context,
            );
        } catch (ContractViolation|\InvalidArgumentException) {
            throw new InputFailure();
        }

        return [$command, $context];
    }

    private function execute(Command $command, AccessContext $context): Reply
    {
        try {
            return $this->application->execute(command: $command, context: $context);
        } catch (D\DomainFailure $failure) {
            return new Reply(D\Result::failure(failure: $failure, retryable: $failure->outcome === D\Outcome::Unavailable));
        }
    }

    private function authenticate(Request $request): \KnowKit\Application\Access\HostIdentity
    {
        try {
            $host = $this->authenticator->authenticate($request);
        } catch (D\DomainFailure $failure) {
            throw $failure;
        } catch (\Throwable) {
            throw new D\DomainFailure(outcome: D\Outcome::Unavailable, errorCode: 'identity_unavailable');
        }

        return $host ?? throw new D\DomainFailure(outcome: D\Outcome::Denied, errorCode: 'unauthenticated');
    }

    private function body(Request $request, Route $route): JsonObject
    {
        $limit = $route->operation === 'addVersion' ? 1403000 : 262144;
        $bytes = $request->read($limit);
        if ($route->method === 'GET') {
            if ($bytes !== '') {
                throw new InputFailure();
            }

            return new JsonObject();
        }
        if (preg_match('/^application\/json(?:;\s*charset=utf-8)?$/Di', $request->header('Content-Type') ?? '') !== 1) {
            throw new InputFailure();
        }
        $body = StrictJson::decode($bytes, $limit);
        if (!$body instanceof JsonObject) {
            throw new InputFailure();
        }
        if ($body->has('usageContext')) {
            throw new D\DomainFailure(outcome: D\Outcome::Denied, errorCode: 'untrusted_context');
        }

        return $body;
    }

    private function requestId(Request $request): D\Ulid
    {
        try {
            $value = $request->header('Request-ID');
            if ($value !== null) {
                return new D\Ulid($value);
            }
        } catch (InputFailure|\InvalidArgumentException) {
            // Untrusted correlation cannot inject response headers; replace it with a server ULID.
        }

        return $this->ids->next();
    }
}
