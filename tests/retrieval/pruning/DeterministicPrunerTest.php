<?php

declare(strict_types=1);

namespace KnowKit\Tests\Retrieval\Pruning;

require_once dirname(__DIR__, 2) . '/storage/catalog/bootstrap.php';

use KnowKit\Adapter\Pruning\DeterministicSentencePruner;
use KnowKit\Domain\Chunk;
use KnowKit\Domain\ChunkId;
use KnowKit\Domain\ChunkRef;
use KnowKit\Domain\ChunkSetId;
use KnowKit\Domain\DocumentId;
use KnowKit\Domain\DocumentRevisionId;
use KnowKit\Domain\DocumentRevisionRef;
use KnowKit\Domain\EffectiveProfile;
use KnowKit\Domain\ExactSourceRef;
use KnowKit\Domain\Fingerprint;
use KnowKit\Domain\IrDigest;
use KnowKit\Domain\LocalId;
use KnowKit\Domain\ParserSignature;
use KnowKit\Domain\Pruning\AtomicByteRange;
use KnowKit\Domain\Pruning\PrunableUnit;
use KnowKit\Domain\Pruning\PruningPolicy;
use KnowKit\Domain\Query;
use KnowKit\Domain\QueryBinding;
use KnowKit\Domain\SelectionLimits;
use KnowKit\Domain\Sha256;
use KnowKit\Domain\SourceDigest;
use KnowKit\Domain\SourceId;
use KnowKit\Domain\SourceVersionId;
use KnowKit\Domain\Span;
use KnowKit\Domain\TenantId;
use KnowKit\Domain\TextLocator;
use KnowKit\Domain\Contract\JsonObject;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('retrieval:pruning')]
final class DeterministicPrunerTest extends TestCase
{
    private function makeChunk(string $text): Chunk
    {
        $revision = new DocumentRevisionRef(
            new ExactSourceRef(
                new TenantId('01JSC420000000000000000000'),
                new SourceId('01JSSR00000000000000000001'),
                new SourceVersionId('01JSSV00000000000000000001'),
                new SourceDigest(str_repeat('a', 64)),
            ),
            new DocumentRevisionId('01JSDV00000000000000000001'),
            new IrDigest(str_repeat('b', 64)),
            new ParserSignature('md-parser', '1.0.0', new Fingerprint(str_repeat('c', 64))),
        );
        $docId = new DocumentId('01JSD000000000000000000001');
        $span = new Span(
            TextLocator::segment(new LocalId('seg-1'), new LocalId('b-1'), 0, strlen($text)),
            new Sha256(hash('sha256', $text)),
        );
        $ref = new ChunkRef(
            $revision,
            new ChunkSetId('01JSCS00000000000000000001'),
            new ChunkId('01JSCK00000000000000000001'),
            new Sha256(hash('sha256', $text)),
        );

        return new Chunk($ref, $docId, [$span], $text);
    }

    private function makeQuery(string $text): QueryBinding
    {
        return new QueryBinding(
            new Query($text),
            new Query($text),
            'rev1',
            new EffectiveProfile('test', '1.0', []),
            SelectionLimits::profile('compact'),
        );
    }

    public function testPrunesIrrelevantSentencesWhileKeepingContextAndFormulaAtom(): void
    {
        $heading = '## § 4.2. Закон сохранения импульса';
        $s1 = 'В замкнутой системе тел векторная сумма импульсов остаётся постоянной, если внешние силы равны нулю.';
        $s2 = 'Исторически это положение восходит к работам Декарта и Ньютона XVII века, который сформулировал третий закон.';
        $s3 = 'На практике при решении задач важно сначала выбрать инерциальную систему отсчёта и спроецировать векторы на оси.';
        $formula = '$$ \sum_{i=1}^{n} m_i \vec{v}_i = \sum_{i=1}^{n} m_i \vec{v}\'_i $$';
        $s4 = 'При абсолютно неупругом столкновении импульс системы сохраняется строго.';

        $text = implode("\n", [$heading, $s1, $s2, $s3, $formula, $s4]);
        $formulaStart = strpos($text, $formula);
        self::assertIsInt($formulaStart);
        $atoms = [new AtomicByteRange($formulaStart, $formulaStart + strlen($formula), 'formula')];

        $chunk = $this->makeChunk($text);
        $unit = new PrunableUnit($chunk, $atoms, strlen($heading));
        $query = $this->makeQuery('Сохраняется ли импульс при неупругом столкновении?');
        $policy = new PruningPolicy(
            thresholdPermille: 300,
            alwaysKeepContext: true,
            keepAdjacentForCoreference: false,
            minKeptBytes: 64,
            maxKeptBytes: 1024,
        );

        $pruner = new DeterministicSentencePruner();
        $selections = $pruner->prune($query, [$unit], $policy);
        self::assertCount(1, $selections);

        $keptText = '';
        $keptBytes = 0;
        foreach ($selections[0]->subspans as $subspan) {
            $slice = substr($text, $subspan->startByte, $subspan->endByte - $subspan->startByte);
            $keptText .= $slice . "\n";
            $keptBytes += $subspan->byteLength();
        }

        self::assertStringContainsString($heading, $keptText);
        self::assertStringContainsString($s4, $keptText);
        self::assertStringNotContainsString('Декарта', $keptText);
        self::assertLessThan((int) (strlen($text) * 0.65), $keptBytes);
    }

    public function testKeepsPrecedingSentenceForAnaphoricCoreference(): void
    {
        $s0 = '## Введение';
        $s1 = 'Первый блок описывает протокол передачи данных.';
        $s2 = 'При этом неупругом столкновении импульс сохраняется строго.';
        $text = implode("\n", [$s0, $s1, $s2]);

        $chunk = $this->makeChunk($text);
        $unit = new PrunableUnit($chunk, [], strlen($s0));
        $query = $this->makeQuery('импульс столкновении');
        $policy = new PruningPolicy(
            thresholdPermille: 400,
            alwaysKeepContext: true,
            keepAdjacentForCoreference: true,
            minKeptBytes: 0,
            maxKeptBytes: 2048,
        );

        $pruner = new DeterministicSentencePruner();
        $selections = $pruner->prune($query, [$unit], $policy);
        $kept = array_map(
            static fn ($sub): string => substr($text, $sub->startByte, $sub->endByte - $sub->startByte),
            $selections[0]->subspans,
        );

        self::assertContains($s1, $kept);
        self::assertContains($s2, $kept);
    }
}
