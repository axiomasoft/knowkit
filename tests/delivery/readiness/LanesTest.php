<?php

declare(strict_types=1);

namespace KnowKit\Tests\Delivery\Readiness;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Fixture.php';

#[Group('delivery:readiness')]
final class LanesTest extends TestCase
{
    public function testEveryDeclaredPhpAndPostgresTextLaneExecutes(): void
    {
        $profile = Fixture::json('deploy/profiles/delivery.v1.json');
        foreach ($profile['phpLanes'] as $php) {
            foreach ($profile['postgresLanes'] as $postgres) {
                $prefix = 'KNOWKIT_DELIVERY_PG' . $postgres['major'];
                $environment = [
                    'TEST_PG_DSN' => getenv($prefix . '_DSN') ?: $postgres['testDsn'],
                    'TEST_PG_USER' => getenv($prefix . '_USER') ?: 'knowkit_test',
                    'TEST_PG_PASSWORD' => getenv($prefix . '_PASSWORD') ?: '',
                ];
                $probe = Fixture::process(argv: [$php, '-r',
                    '$p=new PDO(getenv("TEST_PG_DSN"),getenv("TEST_PG_USER"),getenv("TEST_PG_PASSWORD"));echo PHP_MAJOR_VERSION,".",PHP_MINOR_VERSION,"/",intdiv((int)$p->query("SHOW server_version_num")->fetchColumn(),10000);'], env: $environment);
                self::assertSame(0, $probe['exit'], $probe['stderr']);
                self::assertSame(substr($php, 3) . '/' . $postgres['major'], $probe['stdout']);
                $run = Fixture::process(argv: [$php, 'examples/plain-php/run.php'], env: $environment);
                self::assertSame(0, $run['exit'], $run['stderr']);
                $report = json_decode($run['stdout'], true, flags: JSON_THROW_ON_ERROR);
                self::assertSame('success', $report['outcome']);
                self::assertSame('denied', $report['afterRevoke']['outcome']);
            }
        }
    }
}
