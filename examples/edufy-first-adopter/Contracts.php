<?php

declare(strict_types=1);

namespace KnowKitExample\Edufy;

use KnowKit\Domain\SourceVersionId;

/** Standalone fixture equivalents of the scoped ContentStudio contract; no runtime host imports. */
interface KbResolverPort
{
    public function resolve(string $topicId, KbContext $context): KbResolution;
}

final readonly class KbContext
{
    /** @param list<string> $scopeIds */
    public function __construct(
        public array $scopeIds,
        public ?int $studentAge,
    ) {
        if ($studentAge !== null && ($studentAge < 0 || $studentAge > 150)) {
            throw new \InvalidArgumentException('Invalid fixture age');
        }
    }
}

enum BindingMode: string
{
    case Override = 'override';
    case Additive = 'additive';
}

enum Audience: string
{
    case StudentSafe = 'student_safe';
    case TeacherOnly = 'teacher_only';
}

final readonly class KbEntry
{
    public function __construct(
        public string $kb,
        public string $entry,
        public SourceVersionId $sourceVersion,
        public Audience $audience = Audience::StudentSafe,
    ) {
    }
}

final readonly class KbRule
{
    /**
     * @param list<KbEntry> $entries
     * @param list<string> $scopeIds
     * @param list<string> $exclude
     */
    public function __construct(
        public string $topic,
        public BindingMode $mode,
        public array $entries,
        public array $scopeIds = [],
        public ?int $minAge = null,
        public ?int $maxAge = null,
        public array $exclude = [],
    ) {
    }

    public function matches(string $topic, KbContext $context): bool
    {
        if ($this->topic !== $topic || array_diff($this->scopeIds, $context->scopeIds) !== []) {
            return false;
        }
        if (($this->minAge !== null || $this->maxAge !== null) && $context->studentAge === null) {
            return false;
        }

        return ($this->minAge === null || $context->studentAge >= $this->minAge)
            && ($this->maxAge === null || $context->studentAge <= $this->maxAge);
    }
}

final readonly class KbResolution
{
    /**
     * @param list<KbEntry> $entries
     * @param list<string> $excludedKbIds
     */
    public function __construct(
        public array $entries,
        public array $excludedKbIds,
    ) {
        foreach ($entries as $entry) {
            if (in_array($entry->kb, $excludedKbIds, true)) {
                throw new \InvalidArgumentException('Excluded KB cannot be reintroduced');
            }
        }
    }

    /** @return list<SourceVersionId> */
    public function sourceVersions(Audience $audience): array
    {
        $ids = [];
        foreach ($this->entries as $entry) {
            if ($entry->audience === Audience::TeacherOnly && $audience !== Audience::TeacherOnly) {
                continue;
            }
            $ids[$entry->sourceVersion->value] = $entry->sourceVersion;
        }

        return array_values($ids);
    }
}

/** AiCore -> Content consumer shape. Content never calls AiCore through this port. */
interface MaterialPassageReader
{
    public function read(
        ?string $learner,
        ?string $class,
        string $topic,
        string $entry,
        string $section,
        string $requestedRevision,
    ): PassageResult;
}

enum PassageStatus: string
{
    case Available = 'available';
    case Empty = 'empty';
    case Missing = 'missing';
    case Unpublished = 'unpublished';
    case Withdrawn = 'withdrawn';
    case Stale = 'stale';
    case Denied = 'denied';
    case Unavailable = 'unavailable';
    case Unknown = 'unknown';
}

final readonly class PassageResult
{
    public function __construct(
        public PassageStatus $status,
        public ?string $revision = null,
        public ?string $hash = null,
        public ?string $text = null,
    ) {
        if ($status !== PassageStatus::Available && $text !== null) {
            throw new \InvalidArgumentException('Unavailable passages carry no text');
        }
    }
}

final readonly class PassagePin
{
    public function __construct(
        public string $entry,
        public string $section,
        public string $revision,
        public string $hash,
    ) {
        if (!preg_match('/^[a-f0-9]{64}$/D', $hash) || $revision === '') {
            throw new \InvalidArgumentException('Immutable passage pin required');
        }
    }
}
