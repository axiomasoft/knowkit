<?php
declare(strict_types=1);

namespace KnowKit\Domain\Contract;

/**
 * Error code registry mirrored by specs/contracts/error-codes.v1.json.
 * Class input_error = transport rejection (HTTP 400, CLI 2); incompatible and denied are outcomes.
 */
final class ErrorCatalog
{
    public const CODES = [
        'invalid_json' => 'input_error', 'invalid_utf8' => 'input_error', 'duplicate_key' => 'input_error',
        'invalid_surrogate' => 'input_error', 'non_integer_number' => 'input_error', 'unsafe_integer' => 'input_error',
        'depth_exceeded' => 'input_error', 'document_too_large' => 'input_error',
        'unsupported_schema_version' => 'incompatible',
        'type' => 'input_error', 'const' => 'input_error', 'enum' => 'input_error', 'required' => 'input_error',
        'unknown_field' => 'input_error', 'forbidden_field' => 'input_error', 'pattern' => 'input_error',
        'min_length' => 'input_error', 'max_length' => 'input_error', 'minimum' => 'input_error', 'maximum' => 'input_error',
        'min_items' => 'input_error', 'max_items' => 'input_error', 'unique_items' => 'input_error', 'contains' => 'input_error',
        'min_properties' => 'input_error', 'max_properties' => 'input_error', 'property_name' => 'input_error',
        'decimal_out_of_range' => 'input_error', 'invalid_bbox' => 'input_error', 'invalid_span' => 'input_error',
        'limit_exceeded' => 'input_error', 'duplicate_identifier' => 'input_error', 'unsorted_members' => 'input_error',
        'unknown_reference' => 'input_error', 'binding_mismatch' => 'input_error', 'utf8_boundary' => 'input_error',
        'span_out_of_range' => 'input_error', 'page_status_mismatch' => 'input_error', 'coverage_mismatch' => 'input_error',
        'count_mismatch' => 'input_error', 'digest_mismatch' => 'input_error', 'self_reference' => 'input_error',
        'reference_cycle' => 'input_error', 'order_violation' => 'input_error', 'table_cell_out_of_range' => 'input_error',
        'status_mismatch' => 'input_error',
        'scope_mismatch' => 'denied', 'selection_ref_mismatch' => 'denied', 'POLICY_CHANGED' => 'denied',
        'missing_coordinate_system' => 'input_error', 'invalid_decimal' => 'input_error', 'invalid_page_index' => 'input_error',
        'unknown_schema' => 'input_error', 'missing_file' => 'input_error', 'empty_case_group' => 'input_error',
    ];

    public static function classOf(string $code): string
    {
        if (!isset(self::CODES[$code])) {
            throw new \LogicException('unregistered contract error code: ' . $code);
        }
        return self::CODES[$code];
    }
}
