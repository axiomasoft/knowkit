<?php

declare(strict_types=1);

namespace KnowKit\PgVector\Vector;

use KnowKit\Adapter\Lexical\CanonicalChunkSets;
use KnowKit\Adapter\Postgres\Catalog\Connection;
use KnowKit\Adapter\Postgres\IndexGenerations\ResourceInventory;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\ContractValidator;
use KnowKit\Domain\Contract\Jcs;
use KnowKit\Domain\Contract\StrictJson;
use KnowKit\PgVector\Domain\ManifestView;
use KnowKit\PgVector\Domain\VectorPayload;
use KnowKit\Port\GenerationReadBackend;

/** Complete persisted reload, used before seal and again under publication guards. */
final readonly class PdoGenerationBackend implements GenerationReadBackend
{
    private const MEMBERS_SQL = <<<'SQL'
        SELECT i.*, c.document_id, c.content_digest, c.byte_length, c.spans_json,
            v.space_digest, v.vector_digest, %s
        FROM %s i
        JOIN %s c ON c.tenant_id=i.tenant_id AND c.id=i.chunk_id AND c.chunk_set_id=i.chunk_set_id
        LEFT JOIN %s v ON v.tenant_id=i.tenant_id AND v.generation_id=i.generation_id AND v.chunk_id=i.chunk_id
        WHERE i.tenant_id=? AND i.generation_id=? ORDER BY i.chunk_id
        SQL;

    public function __construct(
        private Connection $db,
        private CanonicalChunkSets $canonical,
        private ContractValidator $validator,
        private D\SpaceRef $space,
    ) {}

    public function profile(): string
    {
        return SearchSql::profileForFormat($this->space->storageFormat);
    }

    public function view(D\IndexManifest $manifest): ManifestView
    {
        $sets = [];
        $tenant = new D\TenantId($manifest->toWire()->get('tenantId'));
        foreach ($manifest->toWire()->get('members') as $member) {
            $sets[] = $this->canonical->get($tenant, new D\ChunkSetId($member->get('chunkSetId')));
        }

        return new ManifestView($manifest, $sets, $this->space);
    }

    public function generation(ManifestView $view): array
    {
        $row = $this->db->row('SELECT * FROM ' . $this->db->table('index_generations')
            . ' WHERE tenant_id=? AND kb_id=? AND id=?',
            [$view->tenant->value, $view->kb->value, $view->generation->value]);
        if ($row === null || !in_array($row['state'], ['building', 'sealed', 'active', 'retired'], true)) {
            self::unreadable();
        }
        $view->assertGeneration($row);

        return $row;
    }

    public function assertSpaceReceipt(ManifestView $view): void
    {
        $row = $this->db->row('SELECT * FROM ' . $this->db->table('vector_generations')
            . ' WHERE tenant_id=? AND generation_id=?', [$view->tenant->value, $view->generation->value]);
        if ($row === null) {
            self::unreadable();
        }
        if ($row['manifest_digest'] !== $view->candidate->digest->value
            || $row['space_digest'] !== $this->space->digest()->value
            || ($row['storage_format'] ?? 'float32') !== $this->space->storageFormat
            || Jcs::canonicalize(StrictJson::decode($row['space_json'])) !== Jcs::canonicalize($this->space->toWire())) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'space_mismatch');
        }
    }

    public function inspect(D\IndexManifest $manifest): ManifestView
    {
        $view = $this->view($manifest);
        $generation = $this->generation($view);
        $this->assertSpaceReceipt($view);
        (new ResourceInventory($this->db))->assertExact($view->candidate);
        [$vectorTable, $vectorColumns] = $this->vectorStorage();
        $sql = sprintf(self::MEMBERS_SQL, $vectorColumns, $this->db->table('index_memberships'),
            $this->db->table('chunks'), $vectorTable);
        $rows = $this->db->execute($sql, [$view->tenant->value, $view->generation->value])->fetchAll(\PDO::FETCH_ASSOC);
        $vectors = (int) $this->db->execute('SELECT count(*) FROM ' . $vectorTable
            . ' WHERE tenant_id=? AND generation_id=?', [$view->tenant->value, $view->generation->value])->fetchColumn();
        if (count($rows) !== $view->count() || $vectors !== $view->count() || (int) $generation['chunk_count'] !== $view->count()) {
            self::unreadable();
        }
        foreach ($rows as $row) {
            $view->assertMembership($row);
            $this->assertVector($row);
        }

        return $view;
    }

    /** @return array{0: string, 1: string} */
    private function vectorStorage(): array
    {
        return match ($this->space->storageFormat) {
            'float32' => [$this->db->table('vector_documents'), 'v.embedding::text AS vector_literal'],
            'halfvec' => [$this->db->table('vector_documents_halfvec'), 'v.embedding::text AS vector_literal'],
            'binary_quantized' => [
                $this->db->table('vector_documents_binary'),
                'v.quantized::text AS quantized_bits, v.rescore_halfvec::text AS vector_literal',
            ],
            default => throw new D\DomainFailure(D\Outcome::Incompatible, 'storage_format_unsupported'),
        };
    }

    private function assertVector(array $row): void
    {
        if ($row['vector_literal'] === null || $row['space_digest'] !== $this->space->digest()->value) {
            self::unreadable();
        }
        $payload = VectorPayload::decode($row['vector_literal'], $this->space);
        if ($this->space->storageFormat === 'float32') {
            if ($payload->digest !== $row['vector_digest']) {
                throw new D\DomainFailure(D\Outcome::Incompatible, 'vector_digest_mismatch');
            }
            return;
        }

        if (!is_string($row['vector_digest']) || preg_match('/^[0-9a-f]{64}$/', $row['vector_digest']) !== 1) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'vector_digest_mismatch');
        }
        /** @var list<float|int> $values */
        $values = json_decode($payload->literal, true, 512, JSON_THROW_ON_ERROR);
        foreach ($values as $value) {
            $f = (float) $value;
            if (abs($f - self::roundToFloat16($f)) > 1e-4 * max(1.0, abs($f))) {
                throw new D\DomainFailure(D\Outcome::Incompatible, 'vector_digest_mismatch');
            }
        }
        if ($this->space->storageFormat === 'binary_quantized') {
            $bits = $row['quantized_bits'] ?? null;
            if (!is_string($bits) || strlen($bits) !== $this->space->dimensions || preg_match('/^[01]+$/', $bits) !== 1) {
                throw new D\DomainFailure(D\Outcome::Incompatible, 'vector_digest_mismatch');
            }
            foreach ($values as $index => $value) {
                if ((float) $value !== 0.0 && ($value > 0 ? '1' : '0') !== $bits[$index]) {
                    throw new D\DomainFailure(D\Outcome::Incompatible, 'vector_digest_mismatch');
                }
            }
        }
    }

    private static function roundToFloat16(float $value): float
    {
        if ($value == 0.0) {
            return 0.0;
        }
        $f32 = unpack('N', pack('G', $value));
        if ($f32 === false) {
            return $value;
        }
        $bits = $f32[1];
        $sign = ($bits >> 16) & 0x8000;
        $exp = (($bits >> 23) & 0xFF) - 127;
        $mant = $bits & 0x7FFFFF;
        if ($exp > 15) {
            $h = $sign | 0x7C00;
        } elseif ($exp < -14) {
            $shift = -14 - $exp;
            if ($shift > 10) {
                return 0.0;
            }
            $h = $sign | ((0x800000 | $mant) >> (13 + $shift));
        } else {
            $roundBit = ($mant >> 12) & 1;
            $mant16 = $mant >> 13;
            if ($roundBit === 1 && ((($mant & 0xFFF) !== 0) || (($mant16 & 1) === 1))) {
                ++$mant16;
                if ($mant16 === 0x400) {
                    $mant16 = 0;
                    ++$exp;
                }
            }
            $h = $sign | (($exp + 15) << 10) | $mant16;
        }
        $hExp = ($h >> 10) & 0x1F;
        $hMant = $h & 0x3FF;
        $hSign = ($h & 0x8000) !== 0 ? -1.0 : 1.0;
        if ($hExp === 0) {
            return $hSign * ($hMant / 1024.0) * (2.0 ** -14);
        }
        if ($hExp === 31) {
            return $hMant === 0 ? $hSign * INF : NAN;
        }

        return $hSign * (1.0 + $hMant / 1024.0) * (2.0 ** ($hExp - 15));
    }

    public function sealed(D\AuthorizedSearchScope $scope): ManifestView
    {
        if ($scope->embeddingSpaceDigest?->value !== $this->space->digest()->value) {
            throw new D\DomainFailure(D\Outcome::Incompatible, 'space_mismatch');
        }
        $row = $this->db->row($this->sealedSql(), [$scope->tenantId->value, $scope->kbId->value,
            $scope->snapshotId->value, $scope->generationId->value]);
        if ($row === null) {
            throw new D\DomainFailure(D\Outcome::Unavailable, 'sealed_generation_unavailable');
        }

        return $this->inspect(new D\IndexManifest(StrictJson::decode($row['manifest_json']), $this->validator));
    }

    private function sealedSql(): string
    {
        $snapshots = $this->db->table('publication_snapshots');
        $mapping = $this->db->table('snapshot_generations');
        $generations = $this->db->table('index_generations');

        return <<<SQL
            SELECT g.manifest_json FROM $snapshots s
            JOIN $mapping sg ON sg.tenant_id=s.tenant_id AND sg.kb_id=s.kb_id AND sg.snapshot_id=s.id
            JOIN $generations g ON g.tenant_id=sg.tenant_id AND g.kb_id=sg.kb_id
                AND g.id=sg.generation_id AND g.profile_id=sg.profile_id
            WHERE s.tenant_id=? AND s.kb_id=? AND s.id=? AND g.id=?
                AND s.sealed AND g.state IN ('sealed','active','retired')
            SQL;
    }

    public function reload(D\IndexManifest $manifest): D\PersistedIndexRead
    {
        $view = $this->inspect($manifest);

        return new D\PersistedIndexRead(new D\ProfileId($view->profile), $view->fingerprint, $view->refs());
    }

    private static function unreadable(): never
    {
        throw new D\DomainFailure(D\Outcome::Unavailable, 'vector_generation_unreadable');
    }
}
