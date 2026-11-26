<?php
declare(strict_types=1);

namespace KnowKit\Application\Validation;

use KnowKit\Domain\{Chunk, DocumentId, DocumentRevisionRef, DomainFailure, IrDocument, Outcome, Span};
use KnowKit\Domain\Contract\{ContractViolation, Jcs, JsonObject, LocatorConversion};

/** Exact canonical slices, in declared order; never reconstruct from search snippets. */
final readonly class SpanAssembler
{
    /** @var array<string,JsonObject> */
    private array $units;
    /** @var array<string,JsonObject> */
    private array $blocks;
    /** Unit hash and UTF-8 flag. Span::slice repeats both for every subrange of the same immutable text. */
    private \stdClass $memo;

    public function __construct(
        private IrDocument $document,
        private DocumentRevisionRef $revision,
        private ?DocumentId $documentId = null,
    ) {
        $ir = $document->toWire();
        if ($ir->get('tenantId') !== $revision->sourceRef->tenantId->value) {
            self::fail('scope_mismatch', Outcome::Denied);
        }
        if (
            $ir->get('sourceVersionId') !== $revision->sourceRef->sourceVersionId->value
            || Jcs::canonicalize($ir->get('parserSignature')) !== Jcs::canonicalize($revision->parserSignature->toWire())
            || hash('sha256', $document->canonical()) !== $revision->irDigest->value
        ) {
            self::fail('immutable_conflict');
        }
        $units = [];
        $blocks = [];
        foreach (['pages' => 'pageId', 'segments' => 'segmentId'] as $collection => $key) {
            foreach ($ir->get($collection) as $unit) {
                $units[$key . ':' . $unit->get($key)] = $unit;
            }
        }
        foreach ($ir->get('blocks') as $block) {
            $blocks[$block->get('blockId')] = $block;
        }
        $this->units = $units;
        $this->blocks = $blocks;
        $this->memo = new \stdClass();
        $this->memo->hash = [];
        $this->memo->utf8 = [];
    }

    /** @param array<array-key,mixed> $spans Checked here as a nonempty bounded ordered list of Span. */
    public function assemble(array $spans, ?string $joiner): string
    {
        if (
            !array_is_list($spans)
            || $spans === []
            || count($spans) > 1024
            || ((count($spans) > 1) !== ($joiner !== null))
            || ($joiner !== null && (preg_match('//u', $joiner) !== 1 || mb_strlen($joiner, 'UTF-8') < 1
                || mb_strlen($joiner, 'UTF-8') > 16))
        ) {
            self::fail('invalid_span');
        }
        $parts = [];
        foreach ($spans as $span) {
            if (!$span instanceof Span) {
                self::fail('invalid_span');
            }
            $locator = $span->locator->toWire();
            $block = $this->blocks[$locator->get('blockId')] ?? null;
            $key = $locator->get('kind') === 'page' ? 'pageId' : 'segmentId';
            $unitKey = $key . ':' . $locator->get($key);
            $unit = $this->units[$unitKey] ?? null;
            if ($block === null || $unit === null) {
                self::fail('unknown_reference');
            }
            if (
                $key === 'pageId'
                && ($unit->get('physicalNo') !== $locator->get('physicalNo')
                    || $unit->get('printedLabel') !== $locator->get('printedLabel'))
            ) {
                self::fail('binding_mismatch');
            }
            // Chunking may take a subrange, but cannot fabricate a block's source range or geometry.
            $contained = false;
            foreach ($block->get('spans') as $original) {
                if (
                    $original->get('kind') === $locator->get('kind')
                    && $original->get($key) === $locator->get($key)
                    && $original->get('byteStart') <= $locator->get('byteStart')
                    && $original->get('byteEnd') >= $locator->get('byteEnd')
                    && (!$locator->has('bbox') || Jcs::canonicalize($locator->get('bbox')) === Jcs::canonicalize($original->get('bbox')))
                ) {
                    $contained = true;
                    break;
                }
            }
            if (!$contained) {
                self::fail('binding_mismatch');
            }
            try {
                $parts[] = $this->slice($span, $unit, $unitKey);
            } catch (ContractViolation $error) {
                self::fail($error->errorCode);
            }
        }
        return implode($joiner ?? '', $parts);
    }

    /**
     * Same checks as Span::slice then TextLocator::slice. The unit hash and UTF-8 scan are once per unit,
     * not once per subrange; digest is still compared before the UTF-8 check.
     */
    private function slice(Span $span, JsonObject $unit, string $unitKey): string
    {
        $text = $unit->get('text');
        if (!isset($this->memo->hash[$unitKey])) {
            $this->memo->hash[$unitKey] = hash('sha256', $text);
        }
        if (!hash_equals($span->textDigest->value, $this->memo->hash[$unitKey])) {
            throw new DomainFailure(Outcome::Incompatible, 'digest_mismatch');
        }
        if (!isset($this->memo->utf8[$unitKey])) {
            $this->memo->utf8[$unitKey] = preg_match('//u', $text) === 1;
        }
        if ($this->memo->utf8[$unitKey] !== true) {
            throw new \InvalidArgumentException('UTF8 required');
        }
        return LocatorConversion::resolveSpan($span->locator->toWire()->with('text', $text))->get('text');
    }

    public function assertChunk(Chunk $chunk, ?string $joiner): void
    {
        if (!$chunk->ref->revisionRef->sourceRef->tenantId->equals($this->revision->sourceRef->tenantId)) {
            self::fail('scope_mismatch', Outcome::Denied);
        }
        if (!$chunk->ref->revisionRef->equals($this->revision)) {
            self::fail('immutable_conflict');
        }
        if ($this->documentId === null) {
            self::fail('document_binding_required');
        }
        if (!$chunk->documentId->equals($this->documentId)) {
            self::fail('binding_mismatch');
        }
        if ($chunk->text() !== $this->assemble($chunk->spans, $joiner)) {
            self::fail('source_text_mismatch');
        }
    }

    private static function fail(string $code, Outcome $outcome = Outcome::Incompatible): never
    {
        throw new DomainFailure($outcome, $code);
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['revision' => $this->revision];
    }
}
