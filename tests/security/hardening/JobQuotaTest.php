<?php

declare(strict_types=1);

namespace KnowKit\Tests\Security\Hardening;

use KnowKit\Domain as D;
use KnowKit\Tests\Jobs\Leases\Fixture;
use PHPUnit\Framework\Attributes\Group;

require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/jobs/leases/Fixture.php';

#[Group('security:hardening')]
final class JobQuotaTest extends HardeningCase
{
    public function testFifthDurableTenantIngestLeaseIsDeniedWithoutMutation(): void
    {
        Fixture::run(function (Fixture $fixture): void {
            for ($index = 0; $index < 5; $index++) {
                $fixture->enqueue(key: 'bounded-' . $index, type: 'ingest');
            }
            $leases = [];
            for ($index = 0; $index < 4; $index++) {
                $leases[] = $fixture->lease(type: 'ingest', worker: 900 + $index);
            }
            $before = $fixture->catalog->db->execute('SELECT id, state, fence, attempt_count FROM jobs ORDER BY id')->fetchAll();
            $this->failure(fn () => $fixture->lease(type: 'ingest', worker: 999),
                code: 'quota_exceeded', outcome: D\Outcome::Unavailable);
            self::assertSame($before, $fixture->catalog->db->execute('SELECT id, state, fence, attempt_count FROM jobs ORDER BY id')->fetchAll());
            // Expired leases no longer occupy logical ingest admission. Worker permits still
            // remain held until actual process death/quiescence, independently of lease expiry.
            $fixture->catalog->db->execute("UPDATE jobs SET lease_until = clock_timestamp() - interval '1 second' WHERE id = ?", [$leases[0]->jobId->value]);
            $next = $fixture->lease(type: 'ingest', worker: 999);
            self::assertNotEquals($leases[0]->jobId, $next->jobId);
            self::assertSame(4, (int) $fixture->catalog->db->execute("SELECT count(*) FROM jobs WHERE state = 'leased' AND lease_until > clock_timestamp()")->fetchColumn());
        });
    }
}
