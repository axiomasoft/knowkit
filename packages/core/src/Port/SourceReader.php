<?php
declare(strict_types=1);

namespace KnowKit\Port;

use KnowKit\Domain\{Scope, SourceVersionRef, SourceBytes};

interface SourceReader
{
    public function openExact(Scope $scope, SourceVersionRef $ref): SourceBytes;
}
