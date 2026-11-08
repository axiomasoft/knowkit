<?php

declare(strict_types=1);

namespace KnowKitExample\Edufy;

use KnowKit\Domain\Contract\Jcs;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Sha256;

final readonly class PolicyRevisions
{
    public function __construct(
        public string $identity = '1',
        public string $school = '1',
        public string $membership = '1',
        public string $lesson = '1',
        public string $topic = '1',
        public string $audience = '1',
        public string $consent = '1',
        public string $session = '1',
        public string $dataScope = '1',
        public string $sourceRights = '1',
        public string $publication = '1',
        public string $egress = '1',
    ) {
    }
}

/** Trusted host data only; never hydrated from a tool/model request. */
final readonly class HostScope
{
    /**
     * @param list<string> $audience
     * @param list<string> $dataScopes
     */
    public function __construct(
        public string $project,
        public string $tenant,
        public string $actor,
        public ?string $actingAs,
        public string $subject,
        public ?string $learner,
        public ?string $class,
        public ?string $lesson,
        public string $topic,
        public Audience $audienceKind,
        public array $audience,
        public string $consent,
        public string $session,
        public array $dataScopes,
        public PolicyRevisions $revisions,
        public string $purpose,
    ) {
    }

    public function hash(): Sha256
    {
        $wire = get_object_vars($this);
        $wire['audienceKind'] = $this->audienceKind->value;
        $wire['revisions'] = new JsonObject(get_object_vars($this->revisions));
        sort($wire['audience'], SORT_STRING);
        sort($wire['dataScopes'], SORT_STRING);

        return new Sha256(hash('sha256', "example:edufy:host-scope:v1\0" . Jcs::canonicalize(new JsonObject($wire))));
    }

    /** AgenTools owns this trusted hash. Preserve it verbatim, never hash it again. */
    public static function agentoolsHash(Sha256 $trustedExecutionScopeHash): Sha256
    {
        return $trustedExecutionScopeHash;
    }
}
