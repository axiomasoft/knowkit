<?php

declare(strict_types=1);

namespace KnowKit\Laravel\Http;

use Closure;
use Illuminate\Http\Request;
use KnowKit\Adapter\Cli\Response as Envelope;
use KnowKit\Domain\DomainFailure;
use KnowKit\Domain\Outcome;
use KnowKit\Domain\Result;
use Symfony\Component\HttpFoundation\Response;

final readonly class AuthenticateContext
{
    public function __construct(
        private ContextFactory $contexts,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $context = $this->contexts->fromRequest($request);
        } catch (DomainFailure $failure) {
            $status = match ($failure->outcome) {
                Outcome::Denied => $failure->errorCode === 'unauthenticated' ? 401 : 403,
                Outcome::Unknown, Outcome::Unavailable => 503,
                Outcome::Incompatible => 409,
                default => 500,
            };

            return new Response(
                content: Envelope::result(Result::failure(
                    failure: $failure,
                    retryable: in_array($failure->outcome, [Outcome::Unknown, Outcome::Unavailable], true),
                ))->json(),
                status: $status,
                headers: ['Content-Type' => 'application/json'],
            );
        }
        $request->attributes->set(AccessContextKey::ATTRIBUTE, $context);

        return $next($request);
    }
}
