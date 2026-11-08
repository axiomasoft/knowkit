<?php

declare(strict_types=1);

namespace KnowKit\PgVector\Domain;

use KnowKit\Domain as D;

/** pgvector stores float32. Bind the digest to those bytes, independently of JSON formatting. */
final readonly class VectorPayload
{
    public string $literal;
    public string $digest;

    public function __construct(D\Vector $vector)
    {
        self::assertSpace($vector->space);
        $bytes = pack('G*', ...$vector->values);
        $values = array_values(unpack('G*', $bytes));
        foreach ($values as $index => $value) {
            if (!is_finite($value)) {
                throw new D\DomainFailure(D\Outcome::Incompatible, 'vector_float32_overflow');
            }
            if ($value == 0.0) {
                $values[$index] = 0.0;
            }
        }
        $bytes = pack('G*', ...$values);
        // Conversion must also preserve the space's normalization invariant.
        new D\Vector($values, $vector->space);
        $this->literal = json_encode($values, JSON_THROW_ON_ERROR);
        $this->digest = hash('sha256', "knowkit:pgvector-float32:v1\0" . $bytes);
    }

    public static function assertSpace(D\EmbeddingSpace $space): void
    {
        if ($space->dimensions > 2000) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'pgvector_dimensions_unsupported');
        }
    }

    public static function decode(string $literal, D\SpaceRef $space): self
    {
        try {
            $values = json_decode($literal, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($values)) {
                throw new \InvalidArgumentException('Vector list required');
            }

            return new self(new D\Vector($values, $space));
        } catch (\InvalidArgumentException | \JsonException) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'stored_vector_invalid');
        }
    }
}
