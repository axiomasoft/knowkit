<?php

declare(strict_types=1);

namespace KnowKit\Tests\Adoption\Edufy;

use KnowKit\Domain as D;
use KnowKitExample\Edufy\Audience;
use KnowKitExample\Edufy\BindingMode;
use KnowKitExample\Edufy\FixtureHost;
use KnowKitExample\Edufy\FixtureResolver;
use KnowKitExample\Edufy\HostScope;
use KnowKitExample\Edufy\KbContext;
use KnowKitExample\Edufy\KbEntry;
use KnowKitExample\Edufy\KbResolution;
use KnowKitExample\Edufy\KbRule;
use KnowKitExample\Edufy\PolicyRevisions;
use KnowKitExample\Edufy\Sandbox;
use KnowKitExample\Host;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/examples/edufy-first-adopter/bootstrap.php';

#[Group('adoption:edufy')]
final class EligibilityTest extends TestCase
{
    private function entry(string $kb): KbEntry
    {
        return new KbEntry($kb, 'entry:1', new D\SourceVersionId(Host::id('version:' . $kb)));
    }

    public function testOverrideRemovesDefaultBeforeTopKAndBroadReadCannotReintroduceIt(): void
    {
        $default = $this->entry('default');
        $override = $this->entry('override');
        $resolver = new FixtureResolver([
            new KbRule('topic:1', BindingMode::Additive, [$default]),
            new KbRule('topic:1', BindingMode::Override, [$override], ['class:1']),
        ]);
        self::assertSame([$override], $resolver->resolve('topic:1', new KbContext(['class:1'], 14))->entries);
        Sandbox::run(function (FixtureHost $f): void {
            $scope = $f->authority->current()->scope;
            self::assertNotEmpty($f->authority->authorizeQuery($scope)->toWire()->get('allowedSourceVersionIds'));
            $f->authority->resolver = new FixtureResolver([
                new KbRule('topic:1', BindingMode::Additive, [new KbEntry('default', 'entry:1', new D\SourceVersionId(Host::id('demo-version')))]),
                new KbRule('topic:1', BindingMode::Override, [$this->entry('override')]),
            ]);
            $outcome = $f->core->retrieve($scope, new D\Query('support'), new D\Limit(1));
            self::assertSame(D\Outcome::Empty, $outcome->result->outcome);
            self::assertNull($outcome->result->data);
            self::assertSame(0, $f->transport->sends);
        });
    }

    public function testAdditiveKeepsOrderButDenySurvivesLaterAdditionAndOverride(): void
    {
        $a = $this->entry('a');
        $b = $this->entry('b');
        $c = $this->entry('c');
        $resolver = new FixtureResolver([
            new KbRule('topic:1', BindingMode::Additive, [$a, $b], exclude: ['b']),
            new KbRule('topic:1', BindingMode::Additive, [$b, $c]),
        ]);
        self::assertSame([$a, $c], $resolver->resolve('topic:1', new KbContext([], 14))->entries);
        $override = new FixtureResolver([
            new KbRule('topic:1', BindingMode::Additive, [$a], exclude: ['b']),
            new KbRule('topic:1', BindingMode::Override, [$b, $c]),
        ]);
        self::assertSame([$c], $override->resolve('topic:1', new KbContext([], 14))->entries);
        $this->expectException(\InvalidArgumentException::class);
        new KbResolution([$b], ['b']);
    }

    public function testAgeTopicAndScopeAreEligibilityNotRankingHints(): void
    {
        $entry = $this->entry('age');
        $resolver = new FixtureResolver([new KbRule('topic:1', BindingMode::Additive, [$entry], ['class:1'], 12, 16)]);
        self::assertSame([$entry], $resolver->resolve('topic:1', new KbContext(['class:1'], 14))->entries);
        foreach ([new KbContext(['class:1'], 11), new KbContext(['class:1'], 17),
            new KbContext(['class:1'], null), new KbContext(['class:2'], 14)] as $context) {
            self::assertSame([], $resolver->resolve('topic:1', $context)->entries);
        }
        self::assertSame([], $resolver->resolve('topic:2', new KbContext(['class:1'], 14))->entries);
    }

    public function testFullHostHashBindsEveryInputAndRevisionAndPreservesNativeHash(): void
    {
        $scope = Sandbox::inputs('schoolA');
        $fields = get_object_vars($scope);
        foreach ($fields as $key => $value) {
            if ($key === 'revisions') {
                continue;
            }
            $changed = $fields;
            $changed[$key] = match (true) {
                $value instanceof Audience => Audience::TeacherOnly,
                is_array($value) => [...$value, 'other'],
                default => 'other',
            };
            self::assertNotEquals($scope->hash(), (new HostScope(...$changed))->hash(), $key);
        }
        $revisions = get_object_vars($scope->revisions);
        foreach ($revisions as $key => $value) {
            $changed = $revisions;
            $changed[$key] = '2';
            self::assertNotEquals($scope->hash(), (new HostScope(...array_replace($fields,
                ['revisions' => new PolicyRevisions(...$changed)])))->hash(), $key);
        }
        $native = new D\Sha256(str_repeat('a', 64));
        self::assertSame($native, HostScope::agentoolsHash($native));
    }
}
