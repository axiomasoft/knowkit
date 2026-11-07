<?php
declare(strict_types=1);

namespace KnowKit\Application\Ingest\Markdown;

use KnowKit\Application\Ingest\Text\{CanonicalText, ExtractionResult, ParserSettings};
use KnowKit\Domain\{Capability, DomainFailure, IrDocument, ParserCapabilities, ParserProfile, SourceBytes};
use KnowKit\Domain\Contract\ContractValidator;
use KnowKit\Port\DocumentParser;

/** Bounded markdown-v1 subset, not a CommonMark implementation. See Text/README.md. */
final readonly class MarkdownParser implements DocumentParser
{
    public function __construct(private ContractValidator $validator) {}
    public function capabilities(): ParserCapabilities
    {
        return new ParserCapabilities([Capability::ParserMarkdown, Capability::ParserTables], ['ir-1.0']);
    }
    public function parse(SourceBytes $source, ParserProfile $profile): IrDocument { return $this->extract($source, $profile)->document; }

    public function extract(SourceBytes $source, ParserProfile $profile): ExtractionResult
    {
        $settings = ParserSettings::from($profile, 'markdown');
        try { $text = CanonicalText::from($source, $settings, 'markdown'); }
        catch (DomainFailure $failure) {
            return new ExtractionResult($source, $profile, null, [], [], [], $failure->errorCode, $this->validator);
        }
        [$blocks, $structure, $diagnostics] = (new SubsetParser($settings))->parse($text->text);
        return new ExtractionResult($source, $profile, $text, $blocks, $structure, $diagnostics, null, $this->validator);
    }
}
