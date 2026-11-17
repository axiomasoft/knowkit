<?php
declare(strict_types=1);

namespace KnowKit\Tests\Tooling;

use KnowKit\Tooling\Qa;
use PHPUnit\Framework\TestCase;

abstract class Support extends TestCase
{
    protected Qa $qa;
    protected string $temporary;

    protected function setUp(): void
    {
        $this->qa = new Qa(dirname(__DIR__, 3));
        $this->temporary = Qa::temporary();
    }

    protected function tearDown(): void
    {
        Qa::remove($this->temporary);
    }

    protected function put(string $relative, string $content): void
    {
        $path = $this->temporary . '/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }
        file_put_contents($path, $content);
    }

    protected function copy(string $relative): void
    {
        $content = file_get_contents($this->qa->file($relative));
        Qa::ensure($content !== false, 'cannot read file: ' . $relative);
        $this->put($relative, $content);
    }

    protected function candidate(): Qa
    {
        foreach (['composer.json', 'packages/core/composer.json', 'specs/architecture/manifest.v1.json'] as $path) {
            $this->copy($path);
        }
        return new Qa($this->temporary);
    }

    protected function rejects(callable $operation, string $message): void
    {
        try {
            $operation();
        } catch (\RuntimeException $error) {
            self::assertStringContainsString($message, $error->getMessage());
            return;
        }
        self::fail('Expected rejection: ' . $message);
    }

    protected function phpunit(array $args, ?Qa $qa = null): array
    {
        return ($qa ?? $this->qa)->process(array_merge([PHP_BINARY, $this->qa->root . '/vendor/bin/phpunit',
            '--configuration', 'phpunit.xml'], $args), ['KNOWKIT_QA_COMPOSER_PROJECT' => '.']);
    }
}
