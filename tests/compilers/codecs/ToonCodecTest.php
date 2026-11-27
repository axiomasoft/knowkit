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
        $skill = [[
            'id' => 'support.refund',
            'title' => 'Refund a ticket',
            'steps' => ['verify identity', 'issue refund'],
        ]];
        self::assertSame(
            "- Refund a ticket\n  - **steps**:\n    - verify identity\n    - issue refund",
            $md->encode($skill),
        );
        self::assertSame(['Chapter 1', '  - Momentum'], $md->decode("- Chapter 1\n-   - Momentum"));

        $this->expectException(ContractViolation::class);
        $json->encode(['ratio' => 0.25]);
    }

    public function testToonPreservesCrlfInsideQuotedCells(): void
    {
        $codec = new ToonCodec();
        $rows = [
            ['id' => 1, 'text' => "line1\r\nline2"],
            ['id' => 2, 'text' => "plain\r\nend"],
        ];

        $encoded = $codec->encode($rows);
        self::assertSame($rows, $codec->decode($encoded));

        $crlfTable = str_replace("\n  ", "\r\n  ", $encoded);
        self::assertSame($rows, $codec->decode($crlfTable));
    }

    public function testToonRejectsUnterminatedQuotedCell(): void
    {
        $codec = new ToonCodec();
        $this->expectException(\InvalidArgumentException::class);
        $codec->decode("items[1]{a}:\n  \"unterminated");
    }

    public function testToonRejectsIntegersOutsideTheJsonSafeRange(): void
    {
        $codec = new ToonCodec();
        foreach ([9007199254740991, -9007199254740991, 0, 17, -3, '9007199254740993'] as $cell) {
            $rows = [['n' => $cell]];
            self::assertSame($rows, $codec->decode($codec->encode($rows)));
        }
        foreach ([9007199254740992, -9007199254740992, PHP_INT_MAX, PHP_INT_MIN] as $unsafe) {
            try {
                $codec->encode([['n' => $unsafe]]);
                self::fail('Expected unsafe integer rejection');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('safe range', $error->getMessage());
            }
        }
        foreach (['9007199254740992', '-9007199254740992', str_repeat('9', 40)] as $raw) {
            try {
                $codec->decode("items[1]{n}:\n  {$raw}");
                self::fail('Expected unsafe integer rejection');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('safe range', $error->getMessage());
            }
        }
    }
}
