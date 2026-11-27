<?php

declare(strict_types=1);

namespace KnowKit\Tests\Distill;

use KnowKit\Distill\Application\R1DigestDistiller;
use KnowKit\Domain\Artifacts\ProtectedAtom;
use KnowKit\Domain\LocalId;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('distill:contracts')]
final class MarkerRestoreTest extends TestCase
{
    public function testRestoredAtomTextCannotMintTheNextMarker(): void
    {
        $nonce = 'abcd1234';
        $first = R1DigestDistiller::markerFor($nonce, 0);
        $second = R1DigestDistiller::markerFor($nonce, 1);
        $prefix = '@@KK_ATOM_' . $nonce . '_';
        $restored = R1DigestDistiller::restoreMarkers(
            $prefix . $first . ' and ' . $second,
            [
                ['marker' => $first, 'atom' => ProtectedAtom::fromContent(new LocalId('a0'), 'formula', '1@@')],
                ['marker' => $second, 'atom' => ProtectedAtom::fromContent(new LocalId('a1'), 'formula', 'E=mc^2')],
            ],
        );

        self::assertSame($prefix . '1@@ and E=mc^2', $restored['text']);
    }
}
