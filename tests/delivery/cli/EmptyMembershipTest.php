<?php

declare(strict_types=1);

namespace KnowKit\Tests\Delivery\Cli;

use KnowKit\Domain as D;
use KnowKit\Domain\Contract\HashDomain;
use KnowKit\Domain\Contract\Jcs;
use KnowKit\Domain\Contract\StrictJson;
use KnowKitExample\Host;
use KnowKitExample\Runtime;
use KnowKitExample\Sandbox;
use KnowKitExample\Walkthrough;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/examples/plain-php/bootstrap.php';

#[Group('delivery:cli')]
final class EmptyMembershipTest extends TestCase
{
    public function testMissingNonemptyIndexProjectionCannotMasqueradeAsEmptyMember(): void
    {
        Sandbox::run(function (Runtime $runtime): void {
            (new Walkthrough($runtime))->prepare();
            $scope = $runtime->host->scope();
            $generation = Host::id('forged-empty-generation');
            $snapshot = Host::id('forged-empty-snapshot');
            $row = $runtime->host->db->row('SELECT manifest_json FROM index_generations WHERE tenant_id=? AND id=?',
                [$scope->tenantId->value, Host::id('demo-generation')]);
            $manifest = StrictJson::decode($row['manifest_json']);
            $member = $manifest->get('members')[0];
            $wire = $manifest->with('generationId', $generation)->with('members', [$member->with('chunkCount', 0)])
                ->with('counts', $manifest->get('counts')->with('chunks', 0));
            $runtime->tx->run(function () use ($runtime, $scope, $generation, $snapshot, $wire): void {
                $runtime->tx->lockTenant($scope->tenantId, D\LockMode::Update);
                $runtime->tx->lockKb($scope->kbId, D\LockMode::Update);
                $runtime->host->db->execute("INSERT INTO index_generations(tenant_id,id,kb_id,profile_id,state,manifest_digest,manifest_json)
                    VALUES (?,?,?,'postgres-fts-simple-v1','sealed',?,?)", [$scope->tenantId->value, $generation,
                        $scope->kbId->value, HashDomain::IndexManifest->digest($wire), Jcs::canonicalize($wire)]);
                $runtime->host->db->execute('INSERT INTO publication_snapshots(tenant_id,id,kb_id,manifest_digest,created_at) VALUES (?,?,?,?,clock_timestamp())',
                    [$scope->tenantId->value, $snapshot, $scope->kbId->value, str_repeat('a', 64)]);
            });
            try {
                $runtime->tx->run(function () use ($runtime, $scope, $generation, $snapshot, $member): void {
                    $runtime->tx->lockTenant($scope->tenantId, D\LockMode::Update);
                    $runtime->host->db->execute('INSERT INTO snapshot_memberships(tenant_id,id,snapshot_id,kb_id,source_id,source_version_id,revision_id,chunk_set_id,generation_id,profile_id)
                        VALUES (?,?,?,?,?,?,?,?,?,?)', [$scope->tenantId->value, $runtime->host->next()->value, $snapshot,
                            $scope->kbId->value, $member->get('sourceId'), $member->get('sourceVersionId'),
                            $member->get('documentRevisionId'), $member->get('chunkSetId'), $generation, 'postgres-fts-simple-v1']);
                });
                self::fail('Nonempty chunkset accepted as an empty snapshot member');
            } catch (D\DomainFailure $failure) {
                self::assertSame(D\Outcome::Incompatible, $failure->outcome);
                self::assertSame('storage_conflict', $failure->errorCode);
            }
            self::assertSame(0, (int) $runtime->host->db->execute('SELECT count(*) FROM snapshot_memberships WHERE tenant_id=? AND snapshot_id=?',
                [$scope->tenantId->value, $snapshot])->fetchColumn());
        });
    }
}
