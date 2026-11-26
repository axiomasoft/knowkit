<?php

declare(strict_types=1);

namespace KnowKit\Laravel\Http;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use KnowKit\Application\Access\AccessContext;
use KnowKit\Application\Access\RequestBoundary;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Contract\StrictJson;
use KnowKit\Domain\DomainFailure;
use KnowKit\Domain\Outcome;

final readonly class ContextFactory
{
    public function __construct(
        private HttpIdentity $identity,
    ) {}

    public function fromRequest(Request $request): AccessContext
    {
        $user = $request->user();
        if (!$user instanceof Authenticatable) {
            throw new DomainFailure(outcome: Outcome::Denied, errorCode: 'unauthenticated');
        }

        try {
            $host = $this->identity->forUser($user);
        } catch (DomainFailure $failure) {
            throw $failure;
        } catch (\Throwable) {
            throw new DomainFailure(outcome: Outcome::Unavailable, errorCode: 'identity_unavailable');
        }
        $body = $this->body($request);
        $context = (new RequestBoundary($host))->context($body);
        $context->assertCurrent();

        return $context;
    }

    private function body(Request $request): JsonObject
    {
        try {
            $raw = (string) $request->getContent();
            if ($raw === '') {
                $parameters = $request->request->all();
                if ($parameters === []) {
                    return new JsonObject();
                }
                $raw = json_encode((object) $parameters, JSON_THROW_ON_ERROR);
            }
            $decoded = StrictJson::decode($raw);
        } catch (\Throwable) {
            throw new DomainFailure(outcome: Outcome::Denied, errorCode: 'untrusted_context');
        }
        if (!$decoded instanceof JsonObject) {
            throw new DomainFailure(outcome: Outcome::Denied, errorCode: 'untrusted_context');
        }

        return $decoded;
    }
}
