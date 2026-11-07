<?php
declare(strict_types=1);
namespace KnowKit\Domain;

/** A replay carries its recorded receipt; an unresolved in-flight replay reports unknown. */
final readonly class DeletionStart
{
    public function __construct(public DeletionTarget $target, public ?DeletionReceipt $replay = null) {}
}
