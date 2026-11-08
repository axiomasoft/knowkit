<?php

declare(strict_types=1);

namespace KnowKit\Tests\Worker\E2e;

use KnowKit\Domain as D;
use PHPUnit\Framework\Assert;

final class Child
{
    private mixed $process;
    private array $pipes = [];

    public function __construct(Harness $h, Attempt $attempt, string $barrier, string $mode = 'extract',
        ?D\ActivationRequest $request = null, ?string $extractionCache = null)
    {
        $role = $h->jobs->catalog->db->pdo->query('SELECT current_user')->fetchColumn();
        $this->process = proc_open([PHP_BINARY, __DIR__ . '/crash-worker.php', $h->jobs->catalog->db->schema,
            $role, $h->jobs->directory, $h->root, $barrier, $mode, $extractionCache ?? ''],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $this->pipes);
        Assert::assertIsResource($this->process);
        stream_set_timeout($this->pipes[1], 60);
        fwrite($this->pipes[0], base64_encode(serialize([$attempt, $request])) . "\n");
        fflush($this->pipes[0]);
    }

    public function barrier(): void
    {
        $line = fgets($this->pipes[1]);
        if ($line !== "BARRIER\n") {
            $error = stream_get_contents($this->pipes[2]);
            throw new \RuntimeException('Missing process barrier: ' . $error);
        }
    }

    public function kill(): void
    {
        Assert::assertTrue(proc_terminate($this->process, 9));
        fclose($this->pipes[0]);
        $error = stream_get_contents($this->pipes[2]);
        fclose($this->pipes[2]);
        fclose($this->pipes[1]);
        $exit = proc_close($this->process);
        Assert::assertNotSame(0, $exit, 'Injected SIGKILL must not look like success');
        Assert::assertSame('', $error);
    }
}
