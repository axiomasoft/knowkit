<?php

declare(strict_types=1);

namespace KnowKit\Tests\Answer\Contracts;

use DateTimeImmutable;
use KnowKit\Answer\Domain as A;
use KnowKit\Answer\Port\ModelTransport;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\ContractValidator;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Contract\SchemaSet;
use KnowKit\Domain\Contract\StrictJson;
use KnowKit\Port\Tokenizer;

final class Fixture
{
    public static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    public static function corpus(): array
    {
        return json_decode((string) file_get_contents(self::root() . '/tests/fixtures/answer/contracts/v1/corpus.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    public static function scope(array $changes = []): D\Scope
    {
        $s = array_replace(self::corpus()['scope'], $changes);

        return new D\Scope(
            new D\TenantId($s['tenantId']),
            new D\KbId($s['kbId']),
            new D\ActorId($s['actorId']),
            $s['purpose'],
            new D\Decimal($s['policyEpoch']),
            new D\Sha256($s['hostScopeHash']),
        );
    }

    public static function profile(): A\ProfileRef
    {
        $p = self::corpus()['retrievalProfile'];

        return new A\ProfileRef($p['id'], $p['revision'], new D\Sha256($p['digest']));
    }

    public static function turns(): A\BoundedTurns
    {
        return new A\BoundedTurns(array_map(
            static fn (array $turn) => new A\PriorTurn(A\MessageRole::from($turn['role']), $turn['text']),
            self::corpus()['turns'],
        ));
    }

    public static function request(array $changes = []): A\AnswerRequest
    {
        return new A\AnswerRequest(...array_replace([
            'scope' => self::scope(),
            'question' => self::corpus()['question'],
            'priorTurns' => self::turns(),
            'modelBindingRef' => new A\ModelBindingRef('tutor:lesson'),
            'retrievalProfileRef' => self::profile(),
            'invocationKey' => new A\InvocationKey(self::corpus()['invocationKey']),
            'limits' => new A\AnswerLimits(),
        ], $changes));
    }

    public static function binding(array $changes = []): A\ResolvedModelBinding
    {
        return new A\ResolvedModelBinding(...array_replace([
            'scope' => self::scope(),
            'requestedRef' => new A\ModelBindingRef('tutor:lesson'),
            'provider' => 'host-provider',
            'account' => 'account:lesson',
            'region' => 'eu-test',
            'model' => 'chat-model',
            'modelRevision' => 'revision-2026-11',
            'transport' => new A\TransportDescriptor('host-http', '1.0', true),
            'capabilities' => new A\ModelCapabilities(new D\Sha256(str_repeat('1', 64)), new DateTimeImmutable('2030-01-01T00:00:00Z'), true),
            'credentialRef' => new D\CredentialRef('host:model-key', true),
            'settings' => new A\GenerationSettings(),
            'bindingDigest' => new D\Sha256(self::corpus()['bindingDigest']),
        ], $changes));
    }

    public static function citation(): D\CitationRef
    {
        $schemas = [];
        foreach (['common', 'citation-ref'] as $name) {
            $schema = StrictJson::decode((string) file_get_contents(self::root() . '/schemas/' . $name . '.schema.json'));
            $schemas[$schema->get('$id')] = $schema;
        }
        $wire = StrictJson::decode(json_encode(self::corpus()['citation'], JSON_THROW_ON_ERROR));

        return new D\CitationRef($wire, new ContractValidator(new SchemaSet($schemas)));
    }

    public static function content(): A\AnsweredContent
    {
        return new A\AnsweredContent(
            'Grounded synthetic answer',
            [self::citation()],
            self::binding()->bindingDigest,
            new D\Sha256(str_repeat('2', 64)),
        );
    }

    public static function outcome(A\OutcomeCode $code): A\AnswerOutcome
    {
        $certainty = match ($code) {
            A\OutcomeCode::Answered, A\OutcomeCode::Refused => A\DispatchCertainty::Sent,
            A\OutcomeCode::TransportUnknown => A\DispatchCertainty::Unknown,
            default => A\DispatchCertainty::KnownNotSent,
        };

        return new A\AnswerOutcome(
            $code,
            self::request()->invocationRef(),
            $certainty,
            observedModelRef: 'observed:revision-1',
            usageRef: 'usage:001',
            fixture: true,
            content: $code === A\OutcomeCode::Answered ? self::content() : null,
            reason: match ($code) {
                A\OutcomeCode::Refused => A\RefusalReason::InvalidCitation,
                A\OutcomeCode::Denied => A\DenialReason::Revoked,
                default => null,
            },
            component: $code === A\OutcomeCode::Unavailable ? A\UnavailableComponent::Transport : null,
            sent: $code === A\OutcomeCode::Cancelled ? false : null,
        );
    }

    public static function call(array $changes = []): A\ModelCallIdentity
    {
        return new A\ModelCallIdentity(...array_replace([
            'invocationRef' => self::request()->invocationRef(),
            'completeRequestBytes' => '{"messages":[{"role":"user","content":"synthetic question"}]}',
            'template' => new A\ProfileRef('grounding', '1', new D\Sha256(str_repeat('3', 64))),
            'messages' => [new A\MessageDigest(A\MessageRole::User, new D\Sha256(hash('sha256', 'synthetic question')))],
            'binding' => self::binding(),
            'egressDecision' => new A\EgressDecisionRef('egress:001', 'policy-42', new DateTimeImmutable('2026-11-08T00:00:00Z'), new DateTimeImmutable('2026-11-08T00:02:00Z')),
            'dependencies' => new A\DependencyManifest(new D\Sha256(hash('sha256', 'synthetic question')), [self::citation()]),
            'deadline' => new DateTimeImmutable('2026-11-08T00:02:00Z'),
        ], $changes));
    }

    public static function cancellation(bool $cancelled = false): A\CancellationToken
    {
        return new class ($cancelled) implements A\CancellationToken {
            public function __construct(
                private readonly bool $cancelled,
            ) {
            }

            public function isCancelled(): bool
            {
                return $this->cancelled;
            }
        };
    }
}

/** Toy exact code-point vocabulary, expressly not a production model tokenizer. */
final readonly class ExactTokenizer implements Tokenizer
{
    public function __construct(
        public bool $exact = true,
    ) {
    }

    public static function identity(): D\TokenizerIdentity
    {
        return new D\TokenizerIdentity(new D\AdapterId('fixture-codepoints'), '1', new D\Sha256(str_repeat('4', 64)));
    }

    public function capabilities(): D\TokenizerCapabilities
    {
        return new D\TokenizerCapabilities(self::identity(), $this->exact);
    }

    public function count(string $utf8, D\TokenizerIdentity $identity): int
    {
        $this->capabilities()->requireExact($identity);
        A\Guard::utf8($utf8, 65536);

        return mb_strlen($utf8, 'UTF-8');
    }
}

final class SpyTransport implements ModelTransport
{
    public int $sends = 0;
    public int $bytes = 0;

    public function descriptor(): A\TransportDescriptor
    {
        return new A\TransportDescriptor('host-http', '1.0', true);
    }

    public function send(A\TransportRequest $request, A\CancellationToken $cancellation): A\TransportResult
    {
        $this->sends++;
        $this->bytes += strlen($request->bytes);

        throw new \LogicException('Readiness must never dispatch');
    }
}
