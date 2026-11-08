<?php

declare(strict_types=1);

namespace KnowKitExample\Edufy;

use KnowKit\Answer\Domain as A;
use KnowKit\Answer\Port\ModelBindingResolver;
use KnowKit\Answer\Testing\ScriptedTransport;
use KnowKit\Domain as D;

interface TutorBindings
{
    public function modelFor(string $tutorInstance): A\ModelBindingRef;
}

interface LessonBindings
{
    public function conversationFor(string $lesson): LessonConversationBinding;
}

final readonly class LessonConversationBinding
{
    public function __construct(
        public string $lesson,
        public string $channel,
        public D\KbId $kb,
        public string $tutor,
        public string $route,
        public string $revision,
    ) {
        if ($route !== 'caller_managed') {
            throw new \InvalidArgumentException('This fixture has one caller-managed response owner');
        }
    }
}

final class FixtureBindings implements TutorBindings, LessonBindings, ModelBindingResolver
{
    public string $model = 'fixture-model:1';
    public int $resolutions = 0;

    public function __construct(
        public LessonConversationBinding $lessonBinding,
        private readonly string $project,
        private readonly D\TenantId $tenant,
    ) {
    }

    public function modelFor(string $tutorInstance): A\ModelBindingRef
    {
        if ($tutorInstance !== $this->lessonBinding->tutor) {
            throw new D\DomainFailure(D\Outcome::Denied, 'unknown_tutor');
        }

        return new A\ModelBindingRef('fixture:tutor:' . $tutorInstance);
    }

    public function conversationFor(string $lesson): LessonConversationBinding
    {
        if ($lesson !== $this->lessonBinding->lesson) {
            throw new D\DomainFailure(D\Outcome::Denied, 'unknown_lesson');
        }

        return $this->lessonBinding;
    }

    public function resolve(D\Scope $scope, A\ModelBindingRef $ref): A\ResolvedModelBinding
    {
        if ($ref != $this->modelFor($this->lessonBinding->tutor) || $scope->kbId != $this->lessonBinding->kb
            || $scope->tenantId != $this->tenant) {
            throw new D\DomainFailure(D\Outcome::Denied, 'binding_mismatch');
        }
        $this->resolutions++;

        return new A\ResolvedModelBinding(
            $scope, $ref, 'fixture', $this->project, 'local', $this->model, '1',
            ScriptedTransport::testDescriptor(),
            new A\ModelCapabilities(new D\Sha256(hash('sha256', 'fixture-capabilities:1')),
                new \DateTimeImmutable('2099-01-01T00:00:00Z'), true),
            new D\CredentialRef('fixture:no-secret', true), new A\GenerationSettings(),
            new D\Sha256(hash('sha256', $this->project . ':' . $this->model)),
        );
    }
}
