<?php

declare(strict_types=1);

namespace KnowKit\Tests\Distill;

use KnowKit\Distill\Application\R0OutlineDistiller;
use KnowKit\Domain\Artifacts\NavigationTreeNode;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('distill:contracts')]
final class OutlineTitleTest extends TestCase
{
    public function testCyrillicTitleIsCutOnACharacterBoundary(): void
    {
        $title = 'A' . str_repeat('я', 200);
        $cut = R0OutlineDistiller::boundedTitle($title);

        self::assertLessThanOrEqual(NavigationTreeNode::MAX_TITLE_BYTES, strlen($cut));
        self::assertSame(1, preg_match('//u', $cut));
        self::assertNotSame('', $cut);
        self::assertSame('A' . str_repeat('я', 79), $cut);
    }
}
