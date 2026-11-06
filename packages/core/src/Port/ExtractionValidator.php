<?php
declare(strict_types=1);

namespace KnowKit\Port;

use KnowKit\Domain\{IrDocument, SourceVersionRef, ValidationReport};

interface ExtractionValidator
{
    public function validate(IrDocument $document, SourceVersionRef $source): ValidationReport;
}
