<?php

declare(strict_types=1);

namespace KnowKit\Port;

use KnowKit\Domain\Reading\BlobObject;

interface PreviewObjects
{
    /** Private bounded, digest-verified buffer. Caller still owns fresh disclosure and Usage COMMIT. */
    public function read(BlobObject $object, int $maxBytes): string;
}
