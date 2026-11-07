<?php

declare(strict_types=1);

namespace KnowKit\Tests\Reading\Navigation;

use KnowKit\Tests\Contract\Ports\PortFixture;
use KnowKit\Tooling\Qa;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

#[Group('reading:navigation')]
final class ExampleTest extends TestCase
{
    public function testPlainPhpExampleRechecksRightsBeforeSecondDeliveryAndMetadata(): void
    {
        $result = (new Qa(PortFixture::root()))->process([PHP_BINARY, 'examples/plain-php/navigation.php']);
        self::assertSame(0, $result['exit'], $result['stderr']);
        $data = json_decode($result['stdout'], true, 32, JSON_THROW_ON_ERROR);
        self::assertSame('success', $data['structure']);
        self::assertSame('unique_exact', $data['located']);
        self::assertSame('success', $data['firstRead']);
        self::assertSame('denied', $data['afterRevoke']);
        self::assertSame('denied', $data['metadataAfterRevoke']);
    }

    public function testReadinessFollowsActualIntlAvailability(): void
    {
        $code = <<<'PHP'
            require 'examples/plain-php/bootstrap.php';
            $adapter = new KnowKit\Adapter\Intl\NfdNormalizerAdapter();
            $expected = extension_loaded('intl') && class_exists(Normalizer::class) && class_exists(IntlChar::class)
                ? 'ready' : 'failed';
            if ($adapter->readiness() !== $expected) {
                exit(8);
            }
            if ($expected === 'ready') {
                echo 'ready';
                exit(0);
            }
            echo 'failed';
            try {
                $adapter->project('café');
                exit(7);
            } catch (KnowKit\Domain\DomainFailure $failure) {
                echo ' ' . $failure->outcome->value . ' ' . $failure->errorCode;
            }
            PHP;
        $result = (new Qa(PortFixture::root()))->process([PHP_BINARY, '-n', '-r', $code]);
        self::assertSame(0, $result['exit'], $result['stderr']);
        self::assertContains($result['stdout'], ['ready', 'failed incompatible normalizer_unavailable']);
    }
}
