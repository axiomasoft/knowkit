<?php
declare(strict_types=1);

namespace KnowKit\Port;

use KnowKit\Domain\{ParserCapabilities, SourceBytes, ParserProfile, IrDocument};

interface DocumentParser
{
    public function capabilities(): ParserCapabilities;
    public function parse(SourceBytes $source, ParserProfile $profile): IrDocument;
}
