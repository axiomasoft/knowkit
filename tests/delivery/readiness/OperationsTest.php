<?php

declare(strict_types=1);

namespace KnowKit\Tests\Delivery\Readiness;

use KnowKit\Domain as D;
use KnowKit\Registry\AdapterRegistry;
use KnowKit\Tests\Lifecycle\Purge\Fixture as Purge;
use KnowKit\Tests\Lifecycle\Restore\Fixture as Restore;
use KnowKit\Tests\Retrieval\Disclosure\Fixture as Disclosure;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Fixture.php';
require_once dirname(__DIR__, 2) . '/lifecycle/purge/Fixture.php';
require_once dirname(__DIR__, 2) . '/lifecycle/restore/Fixture.php';
require_once dirname(__DIR__, 2) . '/retrieval/disclosure/bootstrap.php';

#[Group('delivery:readiness')]
final class OperationsTest extends TestCase
{
    public function testWrongAdapterFailsWithoutImplicitFallback(): void
    {
        $registry = new AdapterRegistry();
        $registry->boot();
        $id = new D\AdapterId('misspelled-parser');
        $profile = new D\EffectiveProfile(id: 'delivery', version: '1', adapterBindings: ['parser' => $id]);
        try {
            $registry->resolve(id: $id, profile: $profile);
            self::fail('Unknown adapter accepted');
        } catch (D\DomainFailure $failure) {
            self::assertSame(D\Outcome::Incompatible, $failure->outcome);
            self::assertSame('unknown_adapter', $failure->errorCode);
        }
    }

    public function testRestoreAfterDeleteReplaysJournalAndMissingTailStaysClosed(): void
    {
        Restore::run(function (Restore $fixture): void {
            $fixture->mark();
            $plan = $fixture->plan();
            $fixture->runner()->start($plan);
            self::assertSame('blocked', $fixture->serving());
            self::assertSame('ready', $fixture->finish($plan)['stage']);
            Restore::failure(call: fn () => $fixture->blobs->store->open($fixture->ref),
                code: 'blob_not_accepted', outcome: D\Outcome::Denied);
            self::assertSame('1', $fixture->store->checkpoint($plan)['sequence']);
            self::assertSame(1, $fixture->blobs->catalog->count('tombstones'));
        });
        Restore::run(function (Restore $fixture): void {
            $fixture->mark();
            $plan = $fixture->plan();
            $fixture->marks = [];
            $fixture->runner()->start($plan);
            try {
                $state = $fixture->finish($plan);
                self::assertSame('blocked', $state['stage']);
            } catch (D\DomainFailure $failure) {
                self::assertNotSame(D\Outcome::Success, $failure->outcome);
            }
            self::assertSame('blocked', $fixture->serving());
        });
    }

    public function testUnsupportedPurgeIsNeverReportedDone(): void
    {
        Purge::run(function (Purge $fixture): void {
            $fixture->purge();
            $fixture->transport->result = 'unsupported';
            $receipt = $fixture->attempt(resource: 3003);
            self::assertSame('unsupported', $receipt->toWire()->get('result'));
            self::assertNotSame('done', $fixture->status());
            $fixture->transport->result = 'deleted';
            foreach ([4004, 5011] as $resource) {
                $fixture->attempt(resource: $resource);
            }
            self::assertNotSame('done', $fixture->status());
            self::assertSame('unsupported', $fixture->c()->db->execute(
                'SELECT result FROM deletion_attempts WHERE resource_id=? ORDER BY attempt_sequence LIMIT 1',
                [Purge::id(3003)],
            )->fetchColumn());
        });
    }

    public function testExternalOwnerRaceWindowAndLocalSerializationHaveDifferentGuarantees(): void
    {
        foreach ([D\Decision::Deny, D\Decision::Unknown] as $decision) {
            Disclosure::run(function (Disclosure $fixture) use ($decision): void {
                $selected = $fixture->select();
                $fixture->host->decision = $decision;
                $result = $fixture->disclose($selected);
                self::assertSame($decision === D\Decision::Deny ? D\Outcome::Denied : D\Outcome::Unknown, $result->result->outcome);
                self::assertNull($result->result->data);
            });
        }
        Disclosure::run(function (Disclosure $fixture): void {
            $selected = $fixture->select();
            $fixture->objects->beforeOpen = function () use ($fixture): void {
                $fixture->objects->beforeOpen = null;
                $fixture->revoke();
            };
            self::assertSame(D\Outcome::Denied, $fixture->disclose($selected)->result->outcome);
            self::assertSame(0, $fixture->lexical->catalog->count('evidence_usages'));
        });
        Disclosure::run(function (Disclosure $fixture): void {
            $selected = $fixture->select();
            $fixture->objects->beforeOpen = function () use ($fixture): void {
                $fixture->objects->beforeOpen = null;
                // Remote owner changes after issuing its last bounded receipt.
                // There is no synchronized local epoch/tombstone in this schedule.
                $fixture->host->decision = D\Decision::Deny;
            };
            self::assertSame(D\Outcome::Success, $fixture->disclose($selected)->result->outcome);
            self::assertSame(D\Outcome::Denied, $fixture->disclose($selected)->result->outcome);
        });
    }
}
