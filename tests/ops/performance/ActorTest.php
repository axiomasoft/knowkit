<?php

declare(strict_types=1);

namespace KnowKit\Tests\Ops\Performance;

use KnowKit\Domain\Sha256;
use KnowKit\Load\Actor;
use KnowKit\Tooling\Qa;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

#[Group('ops:performance')]
final class ActorTest extends TestCase
{
    public function testLargeConfigurationRoundTripsWithoutConsumingTheGoBarrier(): void
    {
        $config = ['operation' => 'search', 'canonical' => str_repeat('large fixed search corpus ', 60000),
            'digest' => new Sha256(str_repeat('a', 64))];
        $frame = Actor::configurationFrame($config);
        self::assertGreaterThan(1048576, strlen($frame));
        $stream = fopen('php://temp', 'w+b');
        Qa::ensure($stream !== false, 'cannot open IPC fixture stream');
        try {
            fwrite($stream, $frame . "GO\n");
            rewind($stream);
            self::assertEquals($config, Actor::readConfiguration($stream));
            self::assertSame("GO\n", fgets($stream));
        } finally {
            fclose($stream);
        }
    }

    public function testTruncatedInvalidAndOversizedConfigurationsAreRefused(): void
    {
        $valid = Actor::configurationFrame(['operation' => 'search']);
        foreach ([substr($valid, 0, -1), "invalid-base64\n", base64_encode('invalid serialization') . "\n",
            str_repeat('A', Actor::CONFIG_MAX_BYTES + 1) . "\n"] as $frame) {
            $stream = fopen('php://temp', 'w+b');
            Qa::ensure($stream !== false, 'cannot open IPC fixture stream');
            try {
                fwrite($stream, $frame);
                rewind($stream);
                try {
                    Actor::readConfiguration($stream);
                    self::fail('Invalid configuration accepted');
                } catch (\RuntimeException $failure) {
                    self::assertNotEmpty($failure->getMessage());
                }
            } finally {
                fclose($stream);
            }
        }
        $this->expectException(\RuntimeException::class);
        Actor::configurationFrame(['oversized' => str_repeat('x', Actor::CONFIG_MAX_BYTES)]);
    }

    public function testOversizedAndUnterminatedResponsesAreRefused(): void
    {
        foreach (["{\"event\":\"ready\"}", str_repeat(' ', Actor::RESPONSE_MAX_BYTES + 1) . "\n"] as $frame) {
            $output = fopen('php://temp', 'w+b');
            $errors = fopen('php://temp', 'w+b');
            Qa::ensure($output !== false && $errors !== false, 'cannot open IPC fixture streams');
            try {
                fwrite($output, $frame);
                rewind($output);
                try {
                    Actor::readResponse($output, $errors);
                    self::fail('Invalid response frame accepted');
                } catch (\RuntimeException $failure) {
                    self::assertSame('incomplete or oversized load actor response', $failure->getMessage());
                }
            } finally {
                fclose($output);
                fclose($errors);
            }
        }
    }

    public function testOutputEofDoesNotWaitForOpenStderrAndDiagnosticsAreBounded(): void
    {
        $script = <<<'PHP'
            require 'tools/load/bootstrap.php';
            $output = fopen('php://temp', 'w+b');
            $errors = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            fwrite($errors[1], str_repeat('x', 10240));
            try {
                \KnowKit\Load\Actor::readResponse($output, $errors[0]);
                exit(2);
            } catch (RuntimeException $failure) {
                $prefix = 'Load actor exited without a result: ';
                echo json_encode([str_starts_with($failure->getMessage(), $prefix),
                    strlen($failure->getMessage()) <= strlen($prefix) + 8192,
                    is_resource($errors[1])], JSON_THROW_ON_ERROR);
            } finally {
                fclose($output);
                fclose($errors[0]);
                fclose($errors[1]);
            }
            PHP;
        $result = (new Qa(dirname(__DIR__, 3)))->process([PHP_BINARY, '-r', $script], timeout: 3);
        self::assertSame(0, $result['exit'], $result['stdout'] . $result['stderr']);
        self::assertSame([true, true, true], json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR));
    }
}
