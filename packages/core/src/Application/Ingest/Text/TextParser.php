<?php
declare(strict_types=1);

namespace KnowKit\Application\Ingest\Text;

use KnowKit\Domain\{Capability, DomainFailure, IrDocument, LocalId, ParserCapabilities, ParserProfile, SourceBytes, TextLocator};
use KnowKit\Domain\Contract\{ContractValidator, JsonObject};
use KnowKit\Port\DocumentParser;

/** AgenTools PlainTextExtractor adaptation: one logical segment; confirmed empty input is valid. */
final readonly class TextParser implements DocumentParser
{
    public function __construct(private ContractValidator $validator) {}
    public function capabilities(): ParserCapabilities { return new ParserCapabilities([Capability::ParserText], ['ir-1.0']); }
    public function parse(SourceBytes $source, ParserProfile $profile): IrDocument { return $this->extract($source, $profile)->document; }

    public function extract(SourceBytes $source, ParserProfile $profile): ExtractionResult
    {
        $settings = ParserSettings::from($profile, 'text');
        try { $text = CanonicalText::from($source, $settings, 'text'); }
        catch (DomainFailure $failure) {
            return new ExtractionResult($source, $profile, null, [], [], [], $failure->errorCode, $this->validator);
        }
        $blocks = $text->text === '' ? [] : [self::block('b1', 'text', null, 0, 0, strlen($text->text))];
        return new ExtractionResult($source, $profile, $text, $blocks, [], [], null, $this->validator);
    }

    public static function block(string $id, string $kind, ?string $parent, int $order, int $start, int $end): JsonObject
    {
        return new JsonObject(['blockId' => $id, 'kind' => $kind, 'parentId' => $parent, 'order' => $order,
            'origin' => 'source', 'spans' => [self::span($id, $start, $end)],
            'provenance' => new JsonObject(['kind' => 'verbatim', 'supportingRefs' => []])]);
    }
    public static function span(string $blockId, int $start, int $end): JsonObject
    {
        return TextLocator::segment(new LocalId('s1'), new LocalId($blockId), $start, $end)->toWire();
    }
}
