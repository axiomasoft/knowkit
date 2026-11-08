<?php

declare(strict_types=1);

namespace KnowKit\Tests\Ops\Resilience;

use KnowKit\Domain as D;
use KnowKit\Domain\Contract\HashDomain;
use KnowKit\Domain\Contract\StrictJson;
use KnowKit\Tests\Contract\Ports\PortFixture;
use KnowKit\Tests\Lifecycle\Commands\Fixture as Lifecycle;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

#[Group('ops:resilience')]
final class OutboxTest extends TestCase
{
    public function testConcurrentDuplicateOutboxDeliveryAndConflictingReplayNeverReactivate(): void
    {
        Lifecycle::run(function (Lifecycle $f): void {
            $db = $f->catalog->db;
            self::assertSame('committed', Fixture::run(db: $db, operation: 'revoke', options: ['scope' => $f->scope()])['status']);
            $wire = StrictJson::decode($db->execute("SELECT event_json FROM outbox WHERE event_json->>'type'='source.revoked'")->fetchColumn());
            $event = new D\DomainEvent($wire, PortFixture::validator());
            $db->execute("INSERT INTO event_consumers(tenant_id,consumer_id) VALUES (?,'resilience')", [$f->tenant->value]);
            $a = new Process(db: $db, operation: 'consume', options: ['event' => $event]);
            $b = new Process(db: $db, operation: 'consume', options: ['event' => $event]);
            self::assertNotSame($a->pid, $b->pid);
            self::assertNotSame($a->backend, $b->backend);
            $db->pdo->beginTransaction();
            try {
                $db->execute("SELECT consumer_id FROM event_consumers WHERE tenant_id=? AND consumer_id='resilience' FOR UPDATE", [$f->tenant->value]);
                $a->go();
                $b->go();
                Fixture::waiting(db: $db, waiters: [$a, $b], blocker: (int) $db->execute('SELECT pg_backend_pid()')->fetchColumn());
            } finally {
                $db->pdo->commit();
            }
            $statuses = [$a->result()['status'], $b->result()['status']];
            sort($statuses);
            Fixture::expect(case: 'DUPLICATE-OUTBOX', actual: [
                'statuses' => $statuses,
                'inbox' => $f->catalog->count('inbox'),
                'sequences' => $f->catalog->count('event_sequences'),
                'epoch' => (string) Fixture::reconnect($db)->execute('SELECT lifecycle_epoch FROM event_projections')->fetchColumn(),
            ]);
            self::assertSame('duplicate', Fixture::run(db: $db, operation: 'consume', options: ['event' => $event])['status']);
            $payload = $wire->get('payload')->with('reason', 'consent_revoked');
            $conflict = new D\DomainEvent($wire->with('payload', $payload)->with('payloadDigest', HashDomain::DomainEventPayload->digest($payload)), PortFixture::validator());
            self::assertSame('blocked', Fixture::run(db: $db, operation: 'consume', options: ['event' => $conflict])['status']);
            self::assertSame(1, $f->catalog->count('inbox'));
            self::assertSame('Deny', Fixture::decision(db: $db, ref: $f->ref(kind: 'chunk', id: 11)));
            $foreign = new D\DomainEvent($wire->with('tenantId', Fixture::id(99)), PortFixture::validator());
            $denied = Fixture::run(db: $db, operation: 'consume', options: ['event' => $foreign]);
            self::assertSame('denied', $denied['outcome']);
            self::assertSame('scope_mismatch', $denied['code']);
            self::assertSame(1, $f->catalog->count('event_projections'));
        });
    }
}
