<?php

declare(strict_types=1);

namespace KnowKit\Http\Server\Diagnostics;

use KnowKit\Application\Access\AccessContext;
use KnowKit\Application\Observability\Diagnostics;
use KnowKit\Application\Observability\Telemetry;
use KnowKit\Application\Observability\TelemetryFactory;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Observability as O;
use KnowKit\Http\Server\Command;
use KnowKit\Http\Server\HostApplication;
use KnowKit\Http\Server\Reply;

/** Explicit decorator inside the authenticated Server boundary, using the three approved routes. */
final readonly class Application implements HostApplication
{
    public function __construct(
        private HostApplication $next,
        private Diagnostics $diagnostics,
        private TelemetryFactory $telemetry,
    ) {}

    public function execute(Command $command, AccessContext $context): Reply
    {
        $context->assertCurrent();
        $telemetry = $this->telemetry->start(
            correlationId: $command->requestId, scope: $context->scope(), runRef: $command->disclosure?->runRef(),
        );
        $started = hrtime(true);
        try {
            $reply = $this->reply(command: $command, context: $context, telemetry: $telemetry);
        } catch (\Throwable $failure) {
            $this->observe(
                telemetry: $telemetry, command: $command,
                outcome: $failure instanceof D\DomainFailure ? $failure->outcome : D\Outcome::Unavailable,
                code: $failure instanceof D\DomainFailure ? $failure->errorCode : 'internal_error', started: $started,
            );
            throw $failure;
        }
        $usage = $reply->data()?->get('usageRef') ?? $reply->data()?->get('usageId');
        if (is_string($usage) && preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/D', $usage) === 1) {
            $telemetry = $telemetry->withUsage(new D\UsageId($usage));
        }
        $this->observe(
            telemetry: $telemetry, command: $command, outcome: $reply->result->outcome,
            code: $reply->result->errorCode, started: $started,
        );

        return $reply;
    }

    private function reply(Command $command, AccessContext $context, Telemetry $telemetry): Reply
    {
        if (!in_array($command->route->operation, ['capabilities', 'readiness', 'coverage'], true)) {
            return $this->next->execute(command: $command, context: $context);
        }
        $snapshot = $this->diagnostics->snapshot(scope: $context->scope(), telemetry: $telemetry);
        $context->assertCurrent();
        $data = match ($command->route->operation) {
            'capabilities' => new JsonObject([
                'schemaVersion' => 'diagnostics:1', 'capabilities' => $snapshot->get('capabilities'),
                'dependencies' => $snapshot->get('dependencies'), 'effectiveProfile' => $snapshot->get('effectiveProfile'),
            ]),
            'coverage' => new JsonObject([
                'schemaVersion' => 'diagnostics:1', 'coverage' => $snapshot->get('coverage'), 'index' => $snapshot->get('index'),
            ]),
            default => $snapshot,
        };

        $degraded = $snapshot->get('health')->get('degraded');

        return new Reply(
            result: D\Result::success(
                data: new D\FrozenJson($data), degraded: $degraded,
                effectiveProfile: $snapshot->get('effectiveProfile')->get('fingerprint'),
                warnings: $degraded ? ['optional_dependency_unavailable'] : [],
            ),
            data: $data,
        );
    }

    private function observe(Telemetry $telemetry, Command $command, D\Outcome $outcome, ?string $code, int $started): void
    {
        $reason = O\Reason::forError($code);
        $disclosure = $command->disclosure !== null || $command->read !== null || $command->route->operation === 'resolveCitation';
        $event = $disclosure ? O\Event::Disclosure : O\Event::Stage;
        if ($reason === O\Reason::QuotaExceeded) {
            $event = O\Event::Quota;
        }
        if ($disclosure && $outcome === D\Outcome::Denied) {
            $reason = O\Reason::AccessDenied;
        }
        $telemetry->emit(
            event: $event, stage: $disclosure ? O\Stage::Disclose : O\Stage::Request, outcome: $outcome, reason: $reason,
            measurements: new O\Measurements(durationMs: intdiv(hrtime(true) - $started, 1000000)),
        );
    }
}
