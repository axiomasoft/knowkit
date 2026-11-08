<?php

declare(strict_types=1);

namespace KnowKit\Tests\Answer\Conformance;

use KnowKit\Answer\Domain as A;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/answer/conformance/bootstrap.php';

#[Group('answer:conformance')]
final class ConcurrencyTest extends TestCase
{
    public function testIndependentDuplicateAdmissionsDispatchOnlyWinnerOnce(): void
    {
        ReplayFixture::run(function (ReplayFixture $f): void {
            $a = new Process($f, 'race', 'race:a');
            $b = new Process($f, 'race', 'race:b');
            self::assertSame('READY', $a->line());
            self::assertSame('READY', $b->line());
            $a->send();
            $b->send();
            self::assertSame('RESOLVED', $a->line());
            self::assertSame('RESOLVED', $b->line());
            $a->send();
            $b->send();
            self::assertSame('ADMITTED', $a->line());
            self::assertSame('ADMITTED', $b->line());
            $a->send();
            $b->send();
            $results = [$a->result(), $b->result()];
            self::assertNotSame($results[0]['pid'], $results[1]['pid']);
            self::assertNotSame($results[0]['backend'], $results[1]['backend']);
            self::assertSame(1, array_sum(array_column($results, 'sends')));
            self::assertGreaterThan(0, array_sum(array_column($results, 'bytes')));
            self::assertSame(2, array_sum(array_column($results, 'resolutions')));
            self::assertNotSame($results[0]['resolvedBindingDigest'], $results[1]['resolvedBindingDigest']);
            self::assertSame($results[0]['bindingDigest'], $results[1]['bindingDigest']);
            self::assertSame($results[0]['bindingDigest'], $f->row()['binding_digest']);
            self::assertContains('stale_claim', array_column($results, 'error'));
            self::assertSame('completed', $f->row()['state']);
            self::assertSame(1, (int) $f->db->pdo->query('SELECT count(*) FROM knowkit_answer_invocations')->fetchColumn());
            self::assertSame(0, $a->close());
            self::assertSame(0, $b->close());
            self::assertSame(A\OutcomeCode::Answered, $f->replay()->code);
            self::assertSame(0, $f->transport->sendCount());
        });
    }

    public function testCrashesAfterSendingOrReplyStayUnknownAndNeverResend(): void
    {
        foreach (['crash-sending', 'crash-reply'] as $window) {
            ReplayFixture::run(function (ReplayFixture $f) use ($window): void {
                $crashed = new Process($f, $window, 'crash:worker');
                self::assertSame('READY', $crashed->line());
                $crashed->send();
                if ($window === 'crash-reply') {
                    self::assertSame('REPLY 1', $crashed->line());
                    self::assertSame('AFTER_REPLY', $crashed->line());
                } else {
                    self::assertSame('SENDING 0', $crashed->line());
                }
                $before = $f->row();
                self::assertSame('sending', $before['state']);
                self::assertNull($before['response_payload_ref']);
                $crashed->close(true);
                $this->expireLease($f);
                $recovery = $this->runWorker($f, 'recover');
                self::assertSame('TransportUnknown', $recovery['outcome']['type']);
                self::assertSame('unknown', $recovery['outcome']['certainty']);
                self::assertTrue($recovery['outcome']['fixture']);
                self::assertSame(0, $recovery['sends']);
                self::assertSame(0, $recovery['bytes']);
                self::assertSame(0, $recovery['resolutions']);
                self::assertSame('unknown', $f->row()['state']);
                self::assertGreaterThan((int) $before['fencing_epoch'], (int) $f->row()['fencing_epoch']);
                $again = $this->runWorker($f, 'replay');
                self::assertSame('TransportUnknown', $again['outcome']['type']);
                self::assertSame(0, $again['sends']);
                self::assertNull($f->row()['response_payload_ref']);
            });
        }
    }

    public function testRevokeAfterIntentBeforeSendStopsGenerationAndCompletesKnownNotSent(): void
    {
        ReplayFixture::run(function (ReplayFixture $f): void {
            $sender = new Process($f, 'revoke-before-send', 'guard:sender');
            self::assertSame('READY', $sender->line());
            $sender->send();
            self::assertSame('MARKED', $sender->line());
            self::assertSame('sending', $f->row()['state']);
            $revoke = new Process($f, 'revoke', 'guard:revoker');
            self::assertSame('READY', $revoke->line());
            $revoke->send();
            self::assertSame('MUTATED', $revoke->line());
            self::assertSame(0, $revoke->close());
            $sender->send();
            $result = $sender->result();
            self::assertSame('Denied', $result['outcome']['type']);
            self::assertSame('knownNotSent', $result['outcome']['certainty']);
            self::assertArrayNotHasKey('text', $result['outcome']);
            self::assertSame(0, $result['sends']);
            self::assertSame(0, $result['bytes']);
            self::assertSame('completed', $f->row()['state']);
            self::assertSame(0, $sender->close());
        });
    }

    public function testCrashBeforeSendingCanResumeWithFreshFenceAndRights(): void
    {
        ReplayFixture::run(function (ReplayFixture $f): void {
            $crashed = new Process($f, 'crash-reserved', 'crash:pre-send');
            self::assertSame('READY', $crashed->line());
            $crashed->send();
            self::assertSame('RESERVED 0', $crashed->line());
            $before = $f->row();
            self::assertSame('reserved', $before['state']);
            self::assertNull($before['model_call_identity_hash']);
            $crashed->close(true);
            $this->expireLease($f);
            $result = $this->runWorker($f, 'recover-send');
            self::assertSame(1, $result['sends']);
            self::assertSame('Answered', $result['outcome']['type']);
            self::assertGreaterThan((int) $before['fencing_epoch'], (int) $f->row()['fencing_epoch']);
        });
        ReplayFixture::run(function (ReplayFixture $f): void {
            $crashed = new Process($f, 'crash-reserved', 'crash:revoked');
            self::assertSame('READY', $crashed->line());
            $crashed->send();
            self::assertSame('RESERVED 0', $crashed->line());
            $crashed->close(true);
            $f->deny('history_allowed');
            $this->expireLease($f);
            $result = $this->runWorker($f, 'recover');
            self::assertSame('Denied', $result['outcome']['type']);
            self::assertSame(0, $result['sends']);
            self::assertSame('reserved', $f->row()['state']);
        });
    }

    public function testLostCallerAckReplaysExactPayloadAfterFreshRights(): void
    {
        ReplayFixture::run(function (ReplayFixture $f): void {
            $writer = new Process($f, 'lost-ack', 'lost:caller');
            self::assertSame('READY', $writer->line());
            $writer->send();
            self::assertSame('COMPLETED 1', $writer->line());
            $row = $f->row();
            self::assertSame('completed', $row['state']);
            $writer->close(true);
            $first = $this->runWorker($f, 'replay');
            $second = $this->runWorker($f, 'replay');
            self::assertSame($first['outcome'], $second['outcome']);
            $wire = \KnowKit\Domain\Contract\StrictJson::decode(json_encode($first['outcome'], JSON_THROW_ON_ERROR));
            self::assertSame($row['response_payload_hash'], hash('sha256', \KnowKit\Domain\Contract\Jcs::canonicalize($wire)));
            self::assertSame('Answered', $first['outcome']['type']);
            self::assertTrue($first['outcome']['fixture']);
            self::assertSame(0, $first['sends'] + $second['sends']);
            self::assertSame(0, $first['resolutions'] + $second['resolutions']);
            self::assertSame($row, $f->row());
            $f->deny('audience_allowed');
            $denied = $this->runWorker($f, 'replay');
            self::assertSame('Denied', $denied['outcome']['type']);
            self::assertArrayNotHasKey('text', $denied['outcome']);
            self::assertSame($row, $f->row());
        });
    }

    private function runWorker(ReplayFixture $f, string $mode): array
    {
        $worker = new Process($f, $mode, 'recovery:worker');
        self::assertSame('READY', $worker->line());
        $worker->send();
        $result = $worker->result();
        self::assertSame(0, $worker->close());

        return $result;
    }

    private function expireLease(ReplayFixture $f): void
    {
        // The lease uses PostgreSQL wall time, never a test-side timestamp rewrite.
        $deadline = microtime(true) + 3;
        while (!(bool) $f->db->pdo->query('SELECT lease_until<=clock_timestamp() FROM knowkit_answer_invocations')->fetchColumn()) {
            if (microtime(true) >= $deadline) {
                self::fail('Database lease did not expire');
            }
            usleep(20000);
        }
    }
}
