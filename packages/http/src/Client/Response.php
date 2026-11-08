<?php

declare(strict_types=1);

namespace KnowKit\Http\Client;

use KnowKit\Domain as D;
use KnowKit\Domain\Contract\ContractValidator;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Contract\StrictJson;
use KnowKit\Http\Server\Contract;
use KnowKit\Http\Server\Response as WireResponse;

/** A wire success is untrusted data, never a local authorization or committed Usage. */
final readonly class Response
{
    private D\FrozenJson $envelope;
    public ?D\Outcome $outcome;
    public ?string $errorCode;
    public bool $retryable;
    public bool $degraded;
    public ?string $effectiveProfile;
    public array $warnings;

    public function __construct(
        public int $status,
        public D\Ulid $requestId,
        JsonObject $envelope,
    ) {
        $this->envelope = new D\FrozenJson($envelope);
        $this->outcome = D\Outcome::tryFrom($envelope->get('outcome'));
        $this->errorCode = $envelope->get('error')?->get('code');
        $this->retryable = $envelope->get('error')?->get('retryable') ?? false;
        $this->degraded = $envelope->get('degraded');
        $this->effectiveProfile = $envelope->get('effectiveProfile');
        $this->warnings = $envelope->get('warnings');
    }

    public static function decode(WireResponse $wire, Contract $contract, Call $call, string $version): self
    {
        if (self::header(wire: $wire, name: 'KnowKit-Schema-Version') !== $version) {
            throw new VersionMismatch();
        }
        try {
            if (preg_match('~^application/json(?:;\s*charset=utf-8)?$~Di', self::header(wire: $wire, name: 'Content-Type') ?? '') !== 1) {
                throw new ProtocolFailure();
            }
            $id = new D\Ulid(self::header(wire: $wire, name: 'Request-ID') ?? '');
            if ($id->value !== $call->requestId->value) {
                throw new ProtocolFailure();
            }
            $body = StrictJson::decode($wire->body, 8388608);
            $contract->validate(schema: 'Envelope', body: $body);
            $response = new self(status: $wire->status, requestId: $id, envelope: $body);
            $response->assertStatus($call);

            return $response;
        } catch (\Throwable) {
            throw new ProtocolFailure();
        }
    }

    private function assertStatus(Call $call): void
    {
        $expected = match ($this->outcome) {
            D\Outcome::Success => [$call->route->successStatus],
            D\Outcome::Empty => [200],
            D\Outcome::Denied => $this->errorCode === 'resource_missing' ? [404]
                : ($this->errorCode === 'unauthenticated' ? [401]
                    : ($call->route->mutation && $this->errorCode === 'POLICY_CHANGED' ? [409] : [403])),
            D\Outcome::Unknown, D\Outcome::Unavailable => [503],
            D\Outcome::Incompatible => [409],
            null => [400, 401, 404, 409, 413, 429, 500, 503],
        };
        if (!in_array($this->status, $expected, true)
            || ($this->degraded && ($this->effectiveProfile === null || $this->warnings === []))) {
            throw new ProtocolFailure();
        }
    }

    public static function header(WireResponse $wire, string $name): ?string
    {
        $found = [];
        foreach ($wire->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                $found[] = $value;
            }
        }
        if (count($found) > 1 || (isset($found[0]) && preg_match('/[\x00-\x1f\x7f]/', $found[0]))) {
            throw new ProtocolFailure();
        }

        return $found[0] ?? null;
    }

    public function data(): ?JsonObject
    {
        $data = $this->envelope->toWire()->get('data');

        return $data instanceof JsonObject ? $data : null;
    }

    public function selection(ContractValidator $validator): ?D\Selection
    {
        $selection = $this->data()?->get('selection');

        return $selection instanceof JsonObject ? new D\Selection(value: $selection, validator: $validator) : null;
    }

    public function evidence(ContractValidator $validator): ?D\EvidenceSet
    {
        $data = $this->data();

        return $data?->has('fragments') ? new D\EvidenceSet(value: $data, validator: $validator) : null;
    }

    public function citation(ContractValidator $validator): ?D\CitationRef
    {
        $citation = $this->data()?->get('citation');

        return $citation instanceof JsonObject ? new D\CitationRef(value: $citation, validator: $validator) : null;
    }

    public function trustClass(): string
    {
        return 'untrusted';
    }

    public function memoryActivation(): bool
    {
        return false;
    }

    public function __debugInfo(): array
    {
        return ['status' => $this->status, 'outcome' => $this->outcome?->value, 'requestId' => $this->requestId->value];
    }
}
