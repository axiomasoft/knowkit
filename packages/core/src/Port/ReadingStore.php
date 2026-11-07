<?php

declare(strict_types=1);

namespace KnowKit\Port;

use Closure;
use KnowKit\Domain as D;
use KnowKit\Domain\Reading as R;

interface ReadingStore
{
    public function citation(D\Scope $scope, D\Ulid $id): R\CitationRecord;

    public function replay(D\Scope $scope, R\ReadRequest $request): ?D\DisclosureReplay;

    /** Current authoritative sources, memberships, ancestors and policy binding; no blob I/O. */
    public function plan(D\Scope $scope, R\CitationRecord $record): D\DisclosurePlan;

    public function object(R\CitationRecord $record, R\ReadTarget $target): R\BlobObject;

    /**
     * Recheck exact identity/lifecycle, the plan, object state and DB-clock freshness under ordered guards.
     * Persist Usage/outbox and return only after confirmed COMMIT. Closure does local validation only.
     * @param Closure(D\DisclosurePlan,D\UtcInstant):D\DisclosureDeadline $authorize
     * @return D\Result<R\ReadResponse>|D\Result<D\DisclosureReplay>
     */
    public function commit(
        D\Scope $scope,
        R\CitationRecord $record,
        R\ReadRequest $request,
        D\DisclosurePlan $plan,
        R\ReadBody $body,
        ?R\BlobObject $object,
        Closure $authorize,
    ): D\Result;
}
