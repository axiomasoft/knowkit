<?php

declare(strict_types=1);

namespace KnowKitExample\Edufy;

/** The owning host's AiCore consumer adapter. This is not a Content -> AiCore callback. */
final readonly class MaterialAdapter
{
    public function __construct(
        private MaterialPassageReader $reader,
    ) {
    }

    public function current(HostScope $scope, PassagePin $pin): PassageResult
    {
        try {
            $result = $this->reader->read($scope->learner, $scope->class, $scope->topic,
                $pin->entry, $pin->section, $pin->revision);
        } catch (\Throwable) {
            return new PassageResult(PassageStatus::Unavailable);
        }
        if ($result->status === PassageStatus::Unknown) {
            return new PassageResult(PassageStatus::Unavailable);
        }
        if ($result->status !== PassageStatus::Available) {
            return new PassageResult($result->status);
        }
        if ($result->revision !== $pin->revision || $result->hash !== $pin->hash || $result->text === null
            || hash('sha256', $result->text) !== $pin->hash) {
            return new PassageResult(PassageStatus::Stale);
        }

        return $result;
    }
}

final class FixturePassageReader implements MaterialPassageReader
{
    public PassageStatus $status = PassageStatus::Available;
    public bool $throws = false;
    /** @var list<array{?string,?string,string,string,string,string}> */
    public array $reads = [];

    public function __construct(
        public string $text,
        public string $revision = 'passage:1',
        public ?string $reportedHash = null,
    ) {
    }

    public function read(
        ?string $learner,
        ?string $class,
        string $topic,
        string $entry,
        string $section,
        string $requestedRevision,
    ): PassageResult {
        $this->reads[] = [$learner, $class, $topic, $entry, $section, $requestedRevision];
        if ($this->throws) {
            throw new \RuntimeException('Fixture Content owner unavailable');
        }
        if ($this->status !== PassageStatus::Available) {
            return new PassageResult($this->status);
        }

        return new PassageResult($this->status, $this->revision,
            $this->reportedHash ?? hash('sha256', $this->text), $this->text);
    }
}
