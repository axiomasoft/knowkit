<?php
declare(strict_types=1);

namespace KnowKit\Tests\Compilers\Codecs;

use KnowKit\ContextCompilers\Codec\CanonicalJsonCodec;
use KnowKit\ContextCompilers\Codec\CompactMarkdownCodec;
use KnowKit\ContextCompilers\Codec\ToonCodec;
use KnowKit\Domain\Contract\ContractViolation;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('compilers:codecs')]
final class ToonCodecTest extends TestCase
{
    public function testToonAchievesAtLeastThirtyPercentSavingsAndRoundTripsTenPlusRecords(): void
    {
        $codec = new ToonCodec();
        $jsonCodec = new CanonicalJsonCodec();

        $records = [];
        for ($i = 1; $i <= 12; $i++) {
            $records[] = [
                'id' => sprintf('doc_%02d', $i),
                'tier' => $i % 3 === 0 ? 'R0' : 'R1',
                'tokens' => 40 + ($i * 15),
                'pinned' => $i <= 3,
                'title' => sprintf('Section %d: Conservation, "momentum" & energy', $i),
            ];
        }

        $toon = $codec->encode($records);
        $json = $jsonCodec->encode($records);

        self::assertLessThanOrEqual((int) floor(strlen($json) * 0.70), strlen($toon), sprintf(
            'Expected >= 30%% byte savings: TOON=%d bytes vs JSON=%d bytes',
            strlen($toon),
            strlen($json),
        ));

        $decoded = $codec->decode($toon);
        $expected = array_map(static function (array $row): array {
            ksort($row, SORT_STRING);
            return $row;
        }, $records);

        self::assertSame($expected, $decoded);
    }

    public function testToonRoundTripsEscapingTypesAndEmptyTable(): void
    {
        $codec = new ToonCodec();

        self::assertSame([], $codec->decode($codec->encode([])));

        $tricky = [
            [
                'id' => 'row_1',
                'num_str' => '180',
                'bool_str' => 'true',
                'empty' => '',
                'nullable' => null,
                'flag' => false,
                'count' => 0,
                'notes' => "Line 1, \"quoted\"\nLine 2",
            ],
            [
                'id' => 'row_2',
                'num_str' => '-42',
                'bool_str' => 'null',
                'empty' => 'plain',
                'nullable' => 'present',
                'flag' => true,
                'count' => -7,
                'notes' => 'Закон сохранения импульса',
            ],
        ];

        $encoded = $codec->encode($tricky);
        $decoded = $codec->decode($encoded);

        $expected = array_map(static function (array $row): array {
            ksort($row, SORT_STRING);
            return $row;
        }, $tricky);

        self::assertSame($expected, $decoded);
    }

    public function testCanonicalJsonAndCompactMarkdownCodecs(): void
    {
        $json = new CanonicalJsonCodec();
        $md = new CompactMarkdownCodec();

        self::assertSame('{"a":1,"b":"x"}', $json->encode(['b' => 'x', 'a' => 1]));

        $tree = [
            ['id' => 'n1', 'title' => 'Chapter 1', 'children' => [
                ['id' => 'n1.1', 'title' => 'Momentum'],
            ]],
        ];
        $rendered = $md->encode($tree);
        self::assertSame("- Chapter 1\n  - Momentum", $rendered);
        self::assertSame(['Chapter 1', '  - Momentum'], $md->decode("- Chapter 1\n-   - Momentum"));

        $this->expectException(ContractViolation::class);
        $json->encode(['ratio' => 0.25]);
    }
}
