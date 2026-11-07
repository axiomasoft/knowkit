<?php
declare(strict_types=1);
namespace KnowKit\Adapter\Postgres\Documents;

use KnowKit\Domain\{DocumentRevisionRef, Parents};

/** Exact internal metadata, not a disclosure grant or a latest resolver. */
final readonly class StoredDocument
{
    public function __construct(public DocumentRevisionRef $revision, public DocumentStorage $storage, public Parents $parents) {}
}
