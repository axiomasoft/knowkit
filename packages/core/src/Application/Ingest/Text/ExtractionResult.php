<?php
declare(strict_types=1);

namespace KnowKit\Application\Ingest\Text;

use KnowKit\Domain\{DomainFailure, FrozenJson, IrDocument, Outcome, ParserProfile, SourceBytes};
use KnowKit\Domain\Contract\{ContractValidator, Jcs, JsonObject};

/** Q1=a: IR 1.0 stays unchanged; extract() returns IR and its immutable bound sidecar together. */
final readonly class ExtractionResult
{
    private FrozenJson $sidecar;

    /**
     * @param list<JsonObject> $blocks
     * @param list<JsonObject> $structure
     * @param list<string> $diagnostics
     */
    public function __construct(SourceBytes $source, ParserProfile $profile, public ?CanonicalText $normalization,
        array $blocks, array $structure, array $diagnostics, ?string $rejection, ContractValidator $validator)
    {
        if (($normalization === null) !== ($rejection !== null)
            || ($rejection !== null && ($blocks !== [] || $structure !== [] || $diagnostics !== []))) {
            throw new \InvalidArgumentException('Consistent extraction state required');
        }
        $normalization?->assertSource($source);
        $text = $normalization === null ? '' : $normalization->text;
        $status = $rejection !== null ? (in_array($rejection, ['invalid_utf8', 'unsupported_encoding'], true) ? 'failed' : 'skipped')
            : ($text === '' ? 'empty' : 'ok');
        $partial = $rejection !== null || $diagnostics !== [];
        $this->outcome = $partial ? Outcome::Incompatible : ($text === '' ? Outcome::Empty : Outcome::Success);
        $this->errorCode = $rejection ?? ($partial ? 'incomplete_extraction' : null);
        $codes = array_values(array_unique($rejection === null ? $diagnostics : [$rejection]));
        $coverage = new JsonObject(['state' => $partial ? 'partial' : 'complete',
            'counts' => new JsonObject(['expected' => 1, 'processed' => in_array($status, ['ok','empty'], true) ? 1 : 0,
                'empty' => $status === 'empty' ? 1 : 0, 'failed' => $status === 'failed' ? 1 : 0, 'skipped' => $status === 'skipped' ? 1 : 0]),
            'missingFeatures' => $partial ? [$rejection !== null ? 'canonical_text' : 'markdown_structure'] : [],
            'diagnostics' => array_map(static fn(string $code): JsonObject => new JsonObject([
                'segmentId' => 's1', 'code' => $code, 'severity' => $rejection !== null ? 'error' : 'warning']), $codes)]);
        $this->document = new IrDocument(new JsonObject(['schemaVersion' => 'ir-1.0',
            'tenantId' => $source->ref->tenantId->value, 'sourceVersionId' => $source->ref->sourceVersionId->value,
            'parserSignature' => $profile->signature->toWire(), 'pages' => [],
            'segments' => [new JsonObject(['segmentId' => 's1', 'text' => $text, 'textDigest' => hash('sha256', $text), 'status' => $status])],
            'blocks' => $blocks, 'assets' => [], 'coverage' => $coverage]), $validator);
        $this->sidecar = new FrozenJson(new JsonObject(['schemaVersion' => 'text-extraction-sidecar:1',
            'sourceRef' => $source->ref->toWire(), 'parserSignature' => $profile->signature->toWire(),
            'irDigest' => hash('sha256', $this->document->canonical()), 'sourceByteLength' => $source->byteLength(),
            'sourceMediaType' => $source->mediaType,
            'normalization' => new JsonObject(['policy' => 'text-v1', 'state' => $normalization === null ? 'rejected' : 'applied',
                'encoding' => $normalization === null ? null : 'UTF-8', 'bomRemoved' => $normalization?->bom,
                'segmentId' => 's1', 'canonicalDigest' => $normalization === null ? null : hash('sha256', $text),
                'transforms' => $normalization === null ? [] : $normalization->transforms]),
            'structure' => $structure, 'outcome' => $this->outcome->value, 'errorCode' => $this->errorCode]));
    }

    public IrDocument $document;
    public Outcome $outcome;
    public ?string $errorCode;
    public function sidecar(): JsonObject { return $this->sidecar->toWire(); }
    public function canonicalSidecar(): string { return $this->sidecar->canonical(); }

    /** Recheck the complete pinned binding before reusing a result; no latest lookup is involved. */
    public function assertBoundTo(SourceBytes $source, ParserProfile $profile, IrDocument $document): void
    {
        $sidecar = $this->sidecar();
        if ($sidecar->get('sourceRef')->get('tenantId') !== $source->ref->tenantId->value) {
            throw new DomainFailure(Outcome::Denied, 'scope_mismatch');
        }
        if (Jcs::canonicalize($sidecar->get('sourceRef')) !== Jcs::canonicalize($source->ref->toWire())
            || $sidecar->get('sourceMediaType') !== $source->mediaType
            || Jcs::canonicalize($sidecar->get('parserSignature')) !== Jcs::canonicalize($profile->signature->toWire())
            || !hash_equals($sidecar->get('irDigest'), hash('sha256', $document->canonical()))) {
            throw new DomainFailure(Outcome::Incompatible, 'immutable_conflict');
        }
    }

    /** Display only. Its markup/suffix is never included in canonical text or source locators. */
    public function display(int $maxBytes = 4096): string
    {
        if ($maxBytes < 1) { throw new \InvalidArgumentException('Positive display limit required'); }
        $text = $this->normalization === null ? '' : $this->normalization->text;
        $part = mb_strcut($text, 0, $maxBytes, 'UTF-8');
        return '<pre>' . htmlspecialchars($part, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre>'
            . (strlen($part) < strlen($text) ? '<p>[truncated]</p>' : '');
    }
    /** @return array<string, mixed> */
    public function __debugInfo(): array { return ['outcome' => $this->outcome->value, 'errorCode' => $this->errorCode]; }
}
