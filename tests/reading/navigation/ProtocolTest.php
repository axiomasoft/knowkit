<?php

declare(strict_types=1);

namespace KnowKit\Tests\Reading\Navigation;

use KnowKit\Domain as D;
use KnowKit\Domain\Contract\HashDomain;
use KnowKit\Domain\Contract\StrictJson;
use KnowKit\Domain\Navigation as N;
use KnowKit\Tests\Contract\Ports\PortFixture;
use KnowKit\Tests\Storage\Catalog\CatalogFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

#[Group('reading:navigation')]
final class ProtocolTest extends TestCase
{
    public function testReplayIsMetadataOnlyAndCannotCrossHostScope(): void
    {
        Fixture::run(function (Fixture $f): void {
            $first = $f->service->readUnits($f->scope(), $f->revision, N\UnitRange::pages(1), 8192)->result;
            self::assertSame(D\Outcome::Success, $first->outcome, $first->errorCode ?? 'success');
            $read = $first->data;
            $plan = $f->store->plan($f->scope(), $f->revision);
            $never = static fn () => throw new \RuntimeException('Replay performed a new disclosure');
            $replay = $f->store->disclose($f->scope(), $f->revision, $plan, $read->units, $read->attemptId, $never);
            self::assertInstanceOf(D\DisclosureReplay::class, $replay);
            self::assertSame($read->usageRef->value, $replay->usageRef->value);
            self::assertSame(1, $f->count('navigation_usages'));
            $s = $f->scope();
            $foreign = new D\Scope($s->tenantId, $s->kbId, $s->actorId, $s->purpose, $s->policyEpoch, new D\Sha256(str_repeat('f', 64)));
            try {
                $f->store->disclose($foreign, $f->revision, $plan, $read->units, $read->attemptId, $never);
                self::fail('Scope mismatch accepted');
            } catch (D\DomainFailure $error) {
                self::assertSame(D\Outcome::Denied, $error->outcome);
                self::assertSame('scope_mismatch', $error->errorCode);
            }
        });
    }

    public function testNavigationEventPayloadIsClosedAndTenantBound(): void
    {
        Fixture::run(function (Fixture $f): void {
            $result = $f->service->readUnits($f->scope(), $f->revision, N\UnitRange::pages(4), 8192)->result;
            self::assertSame(D\Outcome::Success, $result->outcome, $result->errorCode ?? 'success');
            $wire = StrictJson::decode($f->upstream->db()->row('SELECT event_json FROM ' . $f->upstream->db()->table('outbox')
                . ' WHERE tenant_id=? AND id=?', [$f->scope()->tenantId->value, $result->data->usageRef->value])['event_json']);
            $validator = PortFixture::validator();
            self::assertSame([], $validator->validateValue($wire, 'urn:knowkit:schema:domain-event:1.0'));
            $payload = $wire->get('payload');
            foreach ([$payload->with('documentText', 'secret'), $payload->without('hostScopeHash'),
                $payload->with('revisionRef', $payload->get('revisionRef')->with('tenantId', PortFixture::id(2)))] as $invalid) {
                $event = $wire->with('payload', $invalid)->with('payloadDigest', HashDomain::DomainEventPayload->digest($invalid));
                self::assertNotEmpty($validator->validateValue($event, 'urn:knowkit:schema:domain-event:1.0'));
            }
        });
    }

    public function testSqlRejectsMutableReceiptsAndCrossTenantIdentity(): void
    {
        Fixture::run(function (Fixture $f): void {
            $result = $f->service->readUnits($f->scope(), $f->revision, N\UnitRange::pages(1), 8192)->result;
            self::assertSame(D\Outcome::Success, $result->outcome, $result->errorCode ?? 'success');
            $table = $f->upstream->db()->table('navigation_usages');
            $pdo = $f->upstream->db()->pdo;
            CatalogFixture::sqlReject($pdo, 'UPDATE ' . $table . " SET host_scope_hash=repeat('f',64)", [], ['23514']);
            CatalogFixture::sqlReject($pdo, 'DELETE FROM ' . $table, [], ['23514']);
            CatalogFixture::sqlReject($pdo, 'INSERT INTO ' . $table . ' SELECT ?,id,disclosure_attempt_id,kb_id,actor_id,purpose,host_scope_hash,'
                . 'source_id,source_version_id,revision_id,ir_digest,document_id,policy_binding_digest,prepared_at,payload_digest,reference_json FROM ' . $table,
                [PortFixture::id(2)], ['23503']);
        });
    }

    public function testDatabaseClockRejectsStaleReceiptsWithoutWritingUsage(): void
    {
        Fixture::run(function (Fixture $f): void {
            $f->upstream->host->now = new D\UtcInstant('2000-01-01T00:00:00Z');
            $result = $f->service->readUnits($f->scope(), $f->revision, N\UnitRange::pages(1), 8192)->result;
            self::assertSame(D\Outcome::Denied, $result->outcome);
            self::assertSame('POLICY_CHANGED', $result->errorCode);
            self::assertNull($result->data);
            self::assertSame(0, $f->count('navigation_usages'));
        });
    }
}
