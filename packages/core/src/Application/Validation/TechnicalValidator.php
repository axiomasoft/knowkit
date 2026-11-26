<?php
declare(strict_types=1);

namespace KnowKit\Application\Validation;

use KnowKit\Domain\{IrDocument, SourceVersionRef, ValidationReport};
use KnowKit\Domain\Contract\Jcs;
use KnowKit\Port\ExtractionValidator;

/**
 * Adapts AgenTools GenericValidator source/hash checks (2054acda, proprietary;
 * (c) 2026 Dmitry Vostrikov). IR construction already runs schema then semantic
 * checks, including refs, UTF-8 boundaries, cycles, geometry and object limits.
 * Confirmed-empty units and incomplete extraction are technically valid IR.
 */
final readonly class TechnicalValidator implements ExtractionValidator
{
    public function __construct(private ValidationInput $input)
    {
    }

    public function validate(IrDocument $document, SourceVersionRef $source): ValidationReport
    {
        $canonical = $document->canonical();
        if (strlen($canonical) > 67108864) {
            return new ValidationReport('invalid', ['document_too_large']);
        }
        $input = $this->input;
        $ir = $document->toWire();
        $revision = $input->revision;
        $errors = [];
        if (
            !$source->tenantId->equals($input->source->ref->tenantId)
            || !$source->tenantId->equals($revision->sourceRef->tenantId)
            || $ir->get('tenantId') !== $source->tenantId->value
        ) {
            $errors[] = 'scope_mismatch';
        }
        if (
            !$source->equals($input->source->ref)
            || !$source->equals($revision->sourceRef)
            || $ir->get('sourceVersionId') !== $source->sourceVersionId->value
        ) {
            $errors[] = 'immutable_conflict';
        }
        if (
            !hash_equals($source->sourceDigest->value, hash('sha256', $input->source->bytes()))
            || !hash_equals($revision->irDigest->value, hash('sha256', $canonical))
        ) {
            $errors[] = 'digest_mismatch';
        }
        if (Jcs::canonicalize($ir->get('parserSignature')) !== Jcs::canonicalize($revision->parserSignature->toWire())) {
            $errors[] = 'binding_mismatch';
        }
        $processing = $input->processing;
        if (
            !$processing->sourceVersionId->equals($source->sourceVersionId)
            || !$processing->sourceDigest->equals($source->sourceDigest)
            || !$processing->parserSignature->equals($revision->parserSignature)
            || $processing->irSchema !== $ir->get('schemaVersion')
        ) {
            $errors[] = 'binding_mismatch';
        }
        $inventory = [];
        foreach ($ir->get('assets') as $asset) {
            $id = $asset->get('assetId');
            $inventory[(string) $id] = true;
            if (!array_key_exists($id, $input->assets)) {
                $errors[] = 'asset_missing';
                continue;
            }
            $bytes = $input->assets[$id];
            if (
                !hash_equals($asset->get('digest'), hash('sha256', $bytes))
                || $asset->get('byteLength') !== (string) strlen($bytes)
            ) {
                $errors[] = 'digest_mismatch';
            }
        }
        if (array_diff_key($input->assets, $inventory) !== []) {
            $errors[] = 'asset_inventory_mismatch';
        }
        $errors = array_values(array_unique($errors));
        sort($errors, SORT_STRING);
        return new ValidationReport($errors === [] ? 'valid' : 'invalid', $errors);
    }
}
