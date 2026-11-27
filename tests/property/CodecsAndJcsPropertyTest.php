<?php

declare(strict_types=1);

namespace KnowKit\Tests\Property;

use KnowKit\ContextCompilers\Codec\ToonCodec;
use KnowKit\Domain\Contract\ContractViolation;
use KnowKit\Domain\Contract\Jcs;
use KnowKit\Domain\Contract\StrictJson;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

#[Group('property:invariants')]
final class CodecsAndJcsPropertyTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!class_exists(ToonCodec::class)) {
            $base = dirname(__DIR__, 2) . '/packages/context-compilers/src/';
            spl_autoload_register(static function (string $class) use ($base): void {
                $prefix = 'KnowKit\\ContextCompilers\\';
                if (str_starts_with($class, $prefix)) {
                    $file = $base . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                    if (is_file($file)) {
                        require_once $file;
                    }
                }
            });
        }
    }

    private function seed(): int
    {
        $env = getenv('KNOWKIT_FUZZ_SEED');

        return ($env !== false && ctype_digit($env)) ? (int) $env : 20261127;
    }

    public function testToonRoundTripsThreeHundredRandomHomogeneousTables(): void
    {
        $rng = new Randomizer(new Mt19937($this->seed()));
        $codec = new ToonCodec();
        $tokens = [
            'plain',
            'a,b,c',
            'say "hi"',
            "line1\nline2",
            "crlf\r\nend",
            '',
            'Закон сохранения',
            '123',
            'true',
            'null',
            '🚀',
        ];

        for ($t = 0; $t < 300; $t++) {
            $rowCount = $rng->getInt(1, 15);
            $rows = [];
            for ($r = 0; $r < $rowCount; $r++) {
                $row = [
                    'alpha' => $tokens[$rng->getInt(0, count($tokens) - 1)],
                    'beta' => $rng->getInt(-1000, 1000),
                    'gamma' => ($rng->getInt(0, 1) === 1),
                    'delta' => ($rng->getInt(0, 3) === 0) ? null : $tokens[$rng->getInt(0, count($tokens) - 1)],
                ];
                ksort($row, SORT_STRING);
                $rows[] = $row;
            }
            $encoded = $codec->encode($rows);
            $decoded = $codec->decode($encoded);
            self::assertSame($rows, $decoded);
        }
    }

    public function testFloatInjectionAlwaysTriggersNonIntegerNumber(): void
    {
        $rng = new Randomizer(new Mt19937($this->seed() + 2));

        for ($i = 0; $i < 100; $i++) {
            $float = ($rng->getInt(1, 999) / 100.0) + 0.125;
            $payload = match ($i % 3) {
                0 => ['nested' => ['score' => $float]],
                1 => ['list' => [1, 2, $float]],
                2 => ['top' => $float],
            };

            $caughtJcs = false;
            try {
                Jcs::canonicalize($payload);
            } catch (ContractViolation $e) {
                $caughtJcs = true;
                self::assertSame('non_integer_number', $e->errorCode);
            }
            self::assertTrue($caughtJcs, 'Expected Jcs::canonicalize to reject float');

            $caughtJson = false;
            try {
                StrictJson::decode(json_encode($payload, JSON_THROW_ON_ERROR));
            } catch (ContractViolation $e) {
                $caughtJson = true;
                self::assertSame('non_integer_number', $e->errorCode);
            }
            self::assertTrue($caughtJson, 'Expected StrictJson::decode to reject float');
        }
    }
}
