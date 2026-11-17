<?php

declare(strict_types=1);

namespace KnowKit\Tests\Tooling;

use PHPUnit\Framework\Attributes\Group;

#[Group('tooling:baseline')]
final class CandidateAutoloadTest extends Support
{
    public function testCandidateSourcesWinAcrossComposerLoadOrderAndClassmaps(): void
    {
        $this->put('packages/core/src/Domain/CandidateCanary.php', '<?php namespace KnowKit\\Domain; final class CandidateCanary { public const SOURCE = "candidate"; }');
        $this->put('packages/answer/src/Domain/CandidateCanary.php', '<?php namespace KnowKit\\Answer\\Domain; final class CandidateCanary { public const SOURCE = "candidate"; }');
        $this->put('copied/core/Domain/CandidateCanary.php', '<?php namespace KnowKit\\Domain; final class CandidateCanary { public const SOURCE = "stale"; }');
        $this->put('copied/answer/Domain/CandidateCanary.php', '<?php namespace KnowKit\\Answer\\Domain; final class CandidateCanary { public const SOURCE = "stale"; }');
        $this->put('check.php', <<<'PHP'
            <?php
            require $argv[1] . '/vendor/autoload.php';
            require $argv[1] . '/tests/tooling/baseline/CandidateAutoload.php';
            $root = $argv[2];
            if ($argv[3] === 'core-first') {
                \KnowKit\Tests\Tooling\CandidateAutoload::register($root);
            }
            $copied = new \Composer\Autoload\ClassLoader($root . '/copied/vendor');
            $copied->addPsr4('KnowKit\\', $root . '/copied/core');
            $copied->addPsr4('KnowKit\\Answer\\', $root . '/copied/answer');
            $copied->addClassMap(['KnowKit\\Domain\\CandidateCanary' => $root . '/copied/core/Domain/CandidateCanary.php']);
            $copied->register(true);
            \KnowKit\Tests\Tooling\CandidateAutoload::register($root);
            $count = count(spl_autoload_functions());
            \KnowKit\Tests\Tooling\CandidateAutoload::register($root);
            $core = new ReflectionClass(\KnowKit\Domain\CandidateCanary::class);
            $answer = new ReflectionClass(\KnowKit\Answer\Domain\CandidateCanary::class);
            echo json_encode([
                $core->getConstant('SOURCE'), $answer->getConstant('SOURCE'),
                $core->getFileName() === $root . '/packages/core/src/Domain/CandidateCanary.php',
                $answer->getFileName() === $root . '/packages/answer/src/Domain/CandidateCanary.php',
                count(spl_autoload_functions()) === $count,
                class_exists('Illuminate\\Foundation\\Application', false),
            ], JSON_THROW_ON_ERROR);
            PHP);
        foreach (['core-first', 'optional-first'] as $order) {
            $result = $this->qa->process([PHP_BINARY, $this->temporary . '/check.php', $this->qa->root, $this->temporary, $order]);
            self::assertSame(0, $result['exit'], $result['stdout'] . $result['stderr']);
            self::assertSame(['candidate', 'candidate', true, true, true, false], json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR));
        }
    }

    public function testUnconnectedOptionalSourcesAreNeverRegisteredOrLoaded(): void
    {
        $this->put('packages/core/src/Domain/CandidateCanary.php', '<?php namespace KnowKit\\Domain; final class CandidateCanary {}');
        $this->put('packages/answer/src/Domain/UnconnectedCanary.php', '<?php throw new RuntimeException("unconnected optional source loaded");');
        $this->put('check.php', <<<'PHP'
            <?php
            require $argv[1] . '/vendor/autoload.php';
            require $argv[1] . '/tests/tooling/baseline/CandidateAutoload.php';
            $before = array_keys(\Composer\Autoload\ClassLoader::getRegisteredLoaders());
            \KnowKit\Tests\Tooling\CandidateAutoload::register($argv[2]);
            echo json_encode([
                class_exists('KnowKit\\Answer\\Domain\\UnconnectedCanary'),
                class_exists('KnowKit\\Domain\\CandidateCanary'),
                array_keys(\Composer\Autoload\ClassLoader::getRegisteredLoaders()) === $before,
                class_exists('Illuminate\\Foundation\\Application', false),
            ], JSON_THROW_ON_ERROR);
            PHP);
        $result = $this->qa->process([PHP_BINARY, $this->temporary . '/check.php', $this->qa->root, $this->temporary]);
        self::assertSame(0, $result['exit'], $result['stdout'] . $result['stderr']);
        self::assertSame([false, true, true, false], json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR));
    }
}
