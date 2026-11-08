<?php

declare(strict_types=1);

namespace KnowKitExample\Edufy;

use KnowKit\Adapter\Postgres\PdoResourceLedger;
use KnowKit\Answer\Adapter\PostgresInvocationLedger;
use KnowKit\Answer\Adapter\PostgresInvocationPayloads;
use KnowKit\Answer\Adapter\StaticCeilingBudget;
use KnowKit\Answer\Application as App;
use KnowKit\Answer\Domain as A;
use KnowKit\Answer\Profile\ProfileMode;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\JsonObject;
use KnowKitExample\Host;
use KnowKitExample\Runtime;
use KnowKitExample\Walkthrough;

/** Composition only: the same core and answer services serve school and non-school hosts. */
final class FixtureHost
{
    public readonly HostAuthority $authority;
    public readonly FixturePassageReader $reader;
    public readonly FixtureBindings $bindings;
    public readonly CoreEvidence $core;
    public readonly FixtureTransport $transport;
    public readonly PostgresInvocationLedger $ledger;
    public readonly App\GroundedAnswerService $answering;
    public readonly FixtureReplyOwner $replies;

    public function __construct(
        public readonly Runtime $runtime,
        HostScope $inputs,
    ) {
        $host = $runtime->host;
        $text = (string) file_get_contents(self::textFixture($inputs->audienceKind));
        $this->reader = new FixturePassageReader($text);
        $entry = new KbEntry('student', 'entry:1', new D\SourceVersionId(Host::id('demo-version')));
        $resolver = new FixtureResolver([new KbRule($inputs->topic, BindingMode::Additive, [$entry])]);
        $this->bindings = new FixtureBindings(new LessonConversationBinding($inputs->lesson ?? 'workspace:1',
            'channel:1', $host->scope()->kbId, 'tutor:1', 'caller_managed', '1'), $inputs->project, $host->scope()->tenantId);
        $this->authority = new HostAuthority($runtime, $inputs, $resolver,
            new KbContext($inputs->dataScopes, $inputs->learner === null ? null : 14),
            new MaterialAdapter($this->reader), new PassagePin('entry:1', 'section:1', 'passage:1', hash('sha256', $text)), $this->bindings);
        $this->core = new CoreEvidence($this->authority);
        $this->transport = new FixtureTransport($inputs->audienceKind === Audience::TeacherOnly
            ? 'Teacher-only answer: discuss the support assessment privately.'
            : 'Fixture answer: support is available.');
        $resources = new PdoResourceLedger($host->db, $runtime->tx, $host->scope()->tenantId, 'local', $host);
        $payloads = new PostgresInvocationPayloads($host->db, $runtime->tx, $resources, $runtime->storage->blobs,
            $host, $host, $host->scope()->tenantId, new D\SourceId(Host::id('payload-owner')), 'local', $host->validator);
        $this->ledger = new PostgresInvocationLedger($host->db, $runtime->tx, $payloads, $host,
            $host->scope()->tenantId, 'fixture:worker', 120);
        $budget = new StaticCeilingBudget();
        $this->answering = new App\GroundedAnswerService(
            new App\InvocationAdmission($this->ledger, $this->bindings), $this->ledger,
            new App\InvocationReplay($this->ledger, $payloads, $this->authority, $host->validator),
            new App\GroundingEvidenceReader($this->core, $this->core, $host, $host->validator, self::profile()),
            new App\GroundingPromptComposer(), $this->authority,
            new App\CurrentModelEgress($this->authority, $host, $host->validator), $budget, $this->transport,
            new App\AnswerOutcomeMapper(new App\CitationValidator($host->validator)),
            new App\AnswerSettlement($payloads, $budget, $host), $host, ProfileMode::Test,
        );
        $this->replies = new FixtureReplyOwner($this->authority, $this->bindings, $this->ledger);
    }

    public static function profile(): A\ProfileRef
    {
        return new A\ProfileRef('postgres-fts-simple-v1', '1', new D\Sha256(hash('sha256', 'example:lexical:1')));
    }

    public static function textFixture(Audience $audience = Audience::StudentSafe): string
    {
        $file = $audience === Audience::TeacherOnly ? 'teacher.txt' : 'student.txt';

        return dirname(__DIR__, 2) . '/tests/fixtures/adoption/edufy/v1/' . $file;
    }

    public function request(string $key = 'native:turn:1', string $question = 'support', bool $withHistory = false): A\AnswerRequest
    {
        $scope = $this->authority->current()->scope;
        $turns = new A\BoundedTurns([]);
        if ($withHistory) {
            $prior = new A\BoundedTurns([new A\PriorTurn(A\MessageRole::User, 'Earlier protected support question')]);
            $attestation = new A\HistoryReadAttestation($scope, $prior->digest(), 'fixture:history:1',
                new \DateTimeImmutable($this->runtime->host->now()->plusSeconds(30)->value));
            $turns = new A\BoundedTurns($prior->turns, $attestation);
        }
        $binding = $this->bindings->conversationFor($this->authority->inputs->lesson ?? 'workspace:1');

        return new A\AnswerRequest($scope, $question, $turns, $this->bindings->modelFor($binding->tutor),
            self::profile(), new A\InvocationKey($key), new A\AnswerLimits());
    }

    public static function cancellation(): A\CancellationToken
    {
        return new class implements A\CancellationToken {
            public function isCancelled(): bool
            {
                return false;
            }
        };
    }

    public function answer(?A\AnswerRequest $request = null): A\AnswerOutcome
    {
        $request ??= $this->request();
        try {
            return $this->answering->answer($request, self::cancellation());
        } catch (D\DomainFailure $failure) {
            if ($failure->outcome === D\Outcome::Incompatible) {
                throw $failure;
            }

            $code = match ($failure->outcome) {
                D\Outcome::Denied => A\OutcomeCode::Denied,
                D\Outcome::Unknown => A\OutcomeCode::PolicyUnknown,
                default => A\OutcomeCode::Unavailable,
            };

            return new A\AnswerOutcome($code, $request->invocationRef(), A\DispatchCertainty::KnownNotSent,
                fixture: true, reason: $code === A\OutcomeCode::Denied ? A\DenialReason::AccessDenied : null,
                component: $code === A\OutcomeCode::Unavailable ? A\UnavailableComponent::Ledger : null);
        }
    }

    public static function seed(Runtime $runtime, Audience $audience): void
    {
        $walk = new Walkthrough($runtime);
        $walk->require($walk->call('kb create', ['kbId' => Host::id('demo-kb'), 'name' => 'fixture=true'], 'kb'));
        $walk->require($walk->call('source register', ['sourceId' => Host::id('demo-source'),
            'ownerRef' => 'fixture:content', 'origin' => 'fixture:private'], 'source'));
        $walk->require($walk->call('source upload', Walkthrough::uploadInput(), 'upload', '0', '0', self::textFixture($audience)));
        $walk->require($walk->call('ingest start', ['sourceVersionId' => Host::id('demo-version'),
            'generationId' => Host::id('demo-generation')], 'ingest', '0'));
        $walk->require($walk->call('publication validate', ['generationId' => Host::id('demo-generation')], 'validate', '0'));
        $walk->require($walk->call('publication activate', ['generationId' => Host::id('demo-generation')], 'publish', '0', '0'));
        // Payload custody has its own owner. Revoking evidence must still permit a metadata-only outcome.
        $runtime->sources->register($runtime->host->scope(),
            new D\RegisterSource(new D\SourceId(Host::id('payload-owner')), 'fixture:payload-owner', 'fixture:private'),
            new D\MutationContext(new JsonObject(['idempotencyKey' => 'payload-owner',
                'requestId' => $runtime->host->next()->value]), $runtime->host->validator));
    }
}
