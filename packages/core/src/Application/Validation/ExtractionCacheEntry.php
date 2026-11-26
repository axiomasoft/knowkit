<?php
declare(strict_types=1);

namespace KnowKit\Application\Validation;

use KnowKit\Domain\{DocumentRevisionRef, DomainFailure, Fingerprint, IrDocument, Outcome, ValidationReport};
use KnowKit\Domain\Contract\HashDomain;

/** PHP-only verified cache manifest. A cache hit is never an authorization grant. */
final readonly class ExtractionCacheEntry
{
    private function __construct(private DocumentRevisionRef $revision, private Fingerprint $processingDigest,
        private Fingerprint $inventoryDigest) {}

    public static function accept(ValidationInput $input, IrDocument $document): self
    {
        $report = (new TechnicalValidator($input))->validate($document, $input->source->ref);
        if ($report->status !== 'valid') {
            if (in_array('scope_mismatch', $report->errorCodes, true)) { throw new DomainFailure(Outcome::Denied, 'scope_mismatch'); }
            throw new DomainFailure(Outcome::Incompatible, $report->errorCodes[0]);
        }
        return new self($input->revision, $input->processing->digest(), self::inventory($input));
    }

    public function reuse(ValidationInput $input, IrDocument $document): ValidationReport
    {
        $report = (new TechnicalValidator($input))->validate($document, $input->source->ref);
        if ($report->status !== 'valid') {
            return $report;
        }
        if (!$input->source->ref->tenantId->equals($this->revision->sourceRef->tenantId)) {
            return new ValidationReport('invalid', ['scope_mismatch']);
        }
        if (
            !$input->revision->equals($this->revision)
            || !$input->processing->digest()->equals($this->processingDigest)
            || !self::inventory($input)->equals($this->inventoryDigest)
        ) {
            return new ValidationReport('invalid', ['cache_identity_mismatch']);
        }
        return $report;
    }

    private static function inventory(ValidationInput $input): Fingerprint
    {
        $entries = [];
        foreach ($input->assets as $id => $bytes) { $entries[(string) $id] = ['digest' => hash('sha256', $bytes), 'byteLength' => (string) strlen($bytes)]; }
        ksort($entries, SORT_STRING);
        // An array of tuples avoids PHP's integer-like JSON object key coercion.
        $tuples = [];
        foreach ($entries as $id => $entry) { $tuples[] = [(string) $id, $entry['digest'], $entry['byteLength']]; }
        return new Fingerprint(HashDomain::ResourceInventory->digest($tuples));
    }
}
