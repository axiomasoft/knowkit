<?php
declare(strict_types=1);

namespace KnowKit\Tests\Tooling;

use KnowKit\Tooling\Qa;
use PHPUnit\Framework\Attributes\Group;

#[Group('tooling:baseline')]
final class DiscoveryTest extends Support
{
    public function testNamedSuitesLoadOnlyTheirOwnTests(): void
    {
        $xml = new \DOMDocument();
        $xml->load($this->qa->file('phpunit.xml'));
        $names = [];
        foreach ($xml->getElementsByTagName('testsuite') as $suite) {
            $names[] = $suite->getAttribute('name');
        }
        foreach (['Core', 'Answer', 'Chatom', 'Bridge', 'Semantic', 'Worker', 'Evaluation', 'Ops'] as $name) {
            self::assertContains($name, $names);
        }
        $this->copy('phpunit.xml');
        $this->put('tests/tooling/baseline/bootstrap.php', '<?php require ' . var_export($this->qa->root . '/vendor/autoload.php', true) . ';');
        $canary = file_get_contents($this->qa->file('tests/tooling/baseline/fixtures/v1/DiscoveryTest.php.fixture'));
        foreach ($names as $name) {
            // Every other suite contains a top-level tripwire: accidental discovery is fatal.
            $candidate = new \DOMDocument();
            $candidate->load($this->temporary . '/phpunit.xml');
            foreach ($candidate->getElementsByTagName('testsuite') as $suite) {
                $suiteName = $suite->getAttribute('name');
                foreach ($suite->getElementsByTagName('directory') as $index => $directory) {
                    $path = trim($directory->textContent) . '/Canary' . $suiteName . $index . 'Test.php';
                    $content = $suiteName === $name
                        ? str_replace('DiscoveryCanaryTest', 'Canary' . $suiteName . $index . 'Test', $canary)
                        : '<?php throw new \\RuntimeException("wrong suite discovered");';
                    $this->put($path, $content);
                }
            }
            $result = $this->phpunit(['--testsuite', $name, '--list-tests'], new Qa($this->temporary));
            self::assertSame(0, $result['exit'], $name . ': ' . $result['stdout'] . $result['stderr']);
            self::assertStringContainsString('testCanary', $result['stdout']);
            self::assertStringNotContainsString('wrong suite', $result['stdout'] . $result['stderr']);
        }
    }

    public function testCoreDiscoveryDoesNotRequireOptionalPackages(): void
    {
        $result = $this->phpunit(['--testsuite', 'Core', '--list-tests']);
        self::assertSame(0, $result['exit'], $result['stdout'] . $result['stderr']);
        self::assertStringContainsString('RunnerTest', $result['stdout']);
        foreach (['ChatomTest', 'BridgeTest', 'LaravelTest'] as $optional) {
            self::assertStringNotContainsString($optional, $result['stdout']);
        }
    }
}
