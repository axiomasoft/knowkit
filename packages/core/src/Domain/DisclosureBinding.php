<?php

declare(strict_types=1);

namespace KnowKit\Domain;

/** Original host-held search inputs, never supplied by model/tool JSON. */
final readonly class DisclosureBinding
{
    public function __construct(
        public SelectionToken $token,
        public QueryBinding $query,
        public SnapshotRef $snapshot,
    ) {}
}
