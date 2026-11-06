<?php
declare(strict_types=1);

namespace KnowKit\Application;

use KnowKit\Domain\{Usage, Scope, DisclosureRequest, DisclosureOutcome};

/** Public use-case contract; delivery adapters and persistence implementations belong to their owning items. */
interface Disclosure
{
    /** Fresh checks and confirmed Usage COMMIT before any buffer; attempt replay returns metadata only. */
    public function disclose(Scope $scope, DisclosureRequest $request): DisclosureOutcome;
}
