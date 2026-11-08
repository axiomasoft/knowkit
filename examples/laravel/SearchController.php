<?php

declare(strict_types=1);

namespace KnowKit\Examples\Laravel;

use Illuminate\Http\Request;
use KnowKit\Adapter\Cli\Command;
use KnowKit\Adapter\Cli\Kernel;
use KnowKit\Adapter\Cli\Response;
use KnowKit\Application\Access\AccessContext;
use KnowKit\Domain\DomainFailure;
use KnowKit\Domain\Outcome;
use KnowKit\Laravel\HostApplication;
use KnowKit\Laravel\Http\AccessContextKey;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

final readonly class SearchController
{
    public function __construct(
        private HostApplication $application,
    ) {}

    public function __invoke(Request $request): HttpResponse
    {
        $kernel = new Kernel(function (Command $command) use ($request): Response {
            $context = $request->attributes->get(AccessContextKey::ATTRIBUTE);
            if (!$context instanceof AccessContext) {
                throw new DomainFailure(outcome: Outcome::Denied, errorCode: 'unauthenticated');
            }
            $context->assertCurrent();

            return $this->application->execute(command: $command, context: $context);
        });
        $response = $kernel->run(arguments: ['search', '--input=-'], stdin: $request->getContent());
        $status = match ($response->exit) {
            0 => 200,
            2 => 400,
            3 => 403,
            4 => 503,
            5 => 409,
            default => 500,
        };

        return new HttpResponse(
            content: $response->json(),
            status: $status,
            headers: ['Content-Type' => 'application/json'],
        );
    }
}
