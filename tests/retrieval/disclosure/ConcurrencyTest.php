<?php

declare(strict_types=1);

namespace KnowKit\Tests\Retrieval\Disclosure;

use KnowKit\Adapter\Postgres\Usages\PdoCanonicalDocuments;
use KnowKit\Application\Retrieval\Disclosure\CanonicalBuffer;
use KnowKit\Domain as D;
use KnowKit\Tests\Contract\Ports\PortFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

#[Group('retrieval:disclosure')]
final class ConcurrencyTest extends TestCase
{
    public function testRevokeCommitsWhileDisclosureWaitsAndWaiterRechecksEpochs(): void
    {
        Fixture::run(function (Fixture $f): void {
            $selected = $f->select();
            $worker = new RevokeWorker($f, 'waiter');
            try {
                $f->objects->beforeOpen = function () use ($worker): void {
                    $worker->go();
                    self::assertSame('ATTEMPT', $worker->line());
                    self::assertSame('LOCKED', $worker->line());
                };
                $result = $f->disclose($selected);
                self::assertSame(D\Outcome::Denied, $result->result->outcome);
                self::assertSame('POLICY_CHANGED', $result->result->errorCode);
                self::assertNull($result->result->data);
                self::assertSame('COMMITTED', $worker->line());
                self::assertSame(0, $worker->finish());
                self::assertSame(0, $f->lexical->catalog->count('evidence_usages'));
            } finally {
                $worker->close();
            }
        });
    }

    public function testDisclosureCommitWinsThenRevokeCannotRewriteHistoricalEvidence(): void
    {
        Fixture::run(function (Fixture $f): void {
            $selected = $f->select();
            $request = $f->request($selected);
            $context = $f->host->context();
            $record = $f->selections->requireBound($context, $selected->token, $f->query(), $f->host->inventory->snapshot, 'run-1');
            $plan = $f->usages->plan($context->scope(), $record, $request);
            $policy = $f->checks()->prepare($context, $plan->resources, D\Operation::Disclose, $plan->binding());
            $buffer = (new CanonicalBuffer($f->catalog, new PdoCanonicalDocuments($f->db(), $f->objects, PortFixture::validator()), $f->host))
                ->preload($record, $request);
            $worker = new RevokeWorker($f, 'after');
            try {
                $outcome = $f->usages->commit($context->scope(), $record, $request, $plan,
                    function (D\DisclosurePlan $current, D\UtcInstant $now) use ($f, $worker, $context, $record, $request, $policy, $buffer): D\PreparedDisclosure {
                        $policy->requireAllow($context, $current->binding(), $now);
                        $worker->go();
                        self::assertSame('ATTEMPT', $worker->line());
                        $worker->waitForLock($f);

                        return $buffer->prepare($record, $request, new D\UsageId($f->host->next()->value), $now,
                            PortFixture::validator(), $policy->disclosureDeadline());
                    });
                $evidence = $outcome->result->data;
                self::assertInstanceOf(D\EvidenceSet::class, $evidence);
                self::assertSame('LOCKED', $worker->line());
                self::assertSame('COMMITTED', $worker->line());
                self::assertSame(0, $worker->finish());
                $hash = $evidence->canonical();
                $denied = $f->disclose($selected);
                self::assertSame(D\Outcome::Denied, $denied->result->outcome);
                self::assertNull($denied->result->data);
                self::assertSame($hash, $evidence->canonical());
                self::assertSame(1, $f->lexical->catalog->count('evidence_usages'));
            } finally {
                $worker->close();
            }
        });
    }

    public function testLockTimeoutIsUnavailableWithoutUsageOrText(): void
    {
        Fixture::run(function (Fixture $f): void {
            $selected = $f->select();
            $worker = new RevokeWorker($f, 'hold');
            try {
                $worker->go();
                self::assertSame('ATTEMPT', $worker->line());
                self::assertSame('LOCKED', $worker->line());
                $result = $f->disclose($selected);
                self::assertSame(D\Outcome::Unavailable, $result->result->outcome);
                self::assertSame('RETRYABLE_LOCK_TIMEOUT', $result->result->errorCode);
                self::assertNull($result->result->data);
                $worker->release();
                self::assertSame('COMMITTED', $worker->line());
                self::assertSame(0, $worker->finish());
                self::assertSame(0, $f->lexical->catalog->count('evidence_usages'));
            } finally {
                $worker->close();
            }
        });
    }
}

final class RevokeWorker
{
    private mixed $process;
    private array $pipes = [];
    private int $pid;

    public function __construct(Fixture $f, string $mode)
    {
        $role = $f->db()->execute('SELECT current_user')->fetchColumn();
        $parent = $f->db()->execute('SELECT pg_backend_pid()')->fetchColumn();
        $this->process = proc_open([PHP_BINARY, __DIR__ . '/worker.php', $f->db()->schema, $role,
            $f->ref()->revisionRef->sourceRef->sourceId->value, $mode, (string) $parent],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $this->pipes, Fixture::root(), getenv());
        if (!is_resource($this->process)) {
            throw new \RuntimeException('Worker unavailable');
        }
        stream_set_timeout($this->pipes[1], 5);
        if (preg_match('/^READY ([0-9]+)$/D', $this->line(), $match) !== 1) {
            throw new \RuntimeException('Worker missing PID');
        }
        $this->pid = (int) $match[1];
    }

    public function go(): void
    {
        fwrite($this->pipes[0], "GO\n");
        fflush($this->pipes[0]);
    }

    public function release(): void
    {
        fwrite($this->pipes[0], "RELEASE\n");
        fflush($this->pipes[0]);
    }

    public function line(): string
    {
        $line = fgets($this->pipes[1]);
        if ($line === false) {
            throw new \RuntimeException('Missing worker result: ' . stream_get_contents($this->pipes[2]));
        }

        return trim($line);
    }

    public function waitForLock(Fixture $f): void
    {
        for ($i = 0; $i < 300; ++$i) {
            if ($f->db()->execute('SELECT EXISTS (SELECT 1 FROM pg_locks WHERE pid=? AND NOT granted)', [$this->pid])->fetchColumn()) {
                return;
            }
            usleep(1000);
        }
        throw new \RuntimeException('Revoke never waited for disclosure');
    }

    public function finish(): int
    {
        fclose($this->pipes[0]);
        fclose($this->pipes[1]);
        $error = stream_get_contents($this->pipes[2]);
        fclose($this->pipes[2]);
        $this->pipes = [];
        $exit = proc_close($this->process);
        $this->process = null;
        if ($error !== '') {
            throw new \RuntimeException($error);
        }

        return $exit;
    }

    public function close(): void
    {
        if (!is_resource($this->process)) {
            return;
        }
        proc_terminate($this->process, 9);
        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        proc_close($this->process);
        $this->process = null;
    }
}
