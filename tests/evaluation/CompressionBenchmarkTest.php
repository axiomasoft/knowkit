<?php

declare(strict_types=1);

namespace KnowKit\Tests\Evaluation;

use KnowKit\Adapter\Pruning\DeterministicSentencePruner;
use KnowKit\Application\Artifacts\DeterministicOutlineBuilder;
use KnowKit\Bridge\Mcp\Client\McpToolCatalogLayer;
use KnowKit\Bridge\Mcp\Client\McpTransport;
use KnowKit\ContextCompilers\Codec\CanonicalJsonCodec;
use KnowKit\ContextCompilers\Codec\ToonCodec;
use KnowKit\ContextCompilers\Domain\CitationAliasMap;
use KnowKit\Domain\Chunk;
use KnowKit\Domain\ChunkId;
use KnowKit\Domain\ChunkRef;
use KnowKit\Domain\ChunkSetId;
use KnowKit\Domain\Contract\ContractValidator;
use KnowKit\Domain\Contract\HashDomain;
use KnowKit\Domain\Contract\Jcs;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Contract\SchemaSet;
use KnowKit\Domain\Contract\StrictJson;
use KnowKit\Domain\DocumentId;
use KnowKit\Domain\DocumentRevisionId;
use KnowKit\Domain\DocumentRevisionRef;
use KnowKit\Domain\EffectiveProfile;
use KnowKit\Domain\EvidenceSet;
use KnowKit\Domain\ExactSourceRef;
use KnowKit\Domain\Fingerprint;
use KnowKit\Domain\IrDigest;
use KnowKit\Domain\IrDocument;
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
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('quality:retrieval')]
final class CompressionBenchmarkTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $packages = [
            'KnowKit\\ContextCompilers\\' => 'context-compilers',
            'KnowKit\\Bridge\\Mcp\\' => 'mcp-bridge',
        ];
        $root = dirname(__DIR__, 2);
        spl_autoload_register(static function (string $class) use ($packages, $root): void {
            foreach ($packages as $prefix => $dir) {
                if (str_starts_with($class, $prefix)) {
                    $file = $root . '/packages/' . $dir . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                    if (is_file($file)) {
                        require_once $file;
                    }
                    return;
                }
            }
        });
    }

    public function testHashDomainsMatchNormativeJcsPrefixFormat(): void
    {
        $payload = ['b' => 2, 'a' => 'тест'];
        $canonical = Jcs::canonicalize($payload);
        self::assertSame('{"a":"тест","b":2}', $canonical);

        foreach (HashDomain::cases() as $domain) {
            $expected = hash('sha256', $domain->value . "\x00" . $canonical);
            self::assertSame($expected, $domain->digest($payload), $domain->name);
        }
    }

    public function testR0OutlineAchievesUnderTwoPercentBudget(): void
    {
        $h1 = 'Chapter 1. Classical Mechanics';
        $p1 = str_repeat('Momentum conservation in an isolated inertial system governs elastic and inelastic collisions. ', 300);
        $h2 = '1.1 Kinetic Energy';
        $p2 = str_repeat('Kinetic energy transforms into thermal energy during inelastic deformation of colliding bodies. ', 300);
        $totalSourceBytes = strlen($h1) + strlen($p1) + strlen($h2) + strlen($p2);

        $irWire = StrictJson::decode((string) json_encode([
            'schemaVersion' => 'ir-1.0',
            'tenantId' => '01JSC420000000000000000000',
            'sourceVersionId' => '01JSSV00000000000000000001',
            'parserSignature' => ['name' => 'md-parser', 'version' => '1.0.0', 'profileDigest' => str_repeat('c', 64)],
            'pages' => [],
            'segments' => [
                ['segmentId' => 'seg-1', 'text' => $h1 . "\n" . $p1, 'textDigest' => hash('sha256', $h1 . "\n" . $p1), 'status' => 'ok'],
                ['segmentId' => 'seg-2', 'text' => $h2 . "\n" . $p2, 'textDigest' => hash('sha256', $h2 . "\n" . $p2), 'status' => 'ok'],
            ],
            'blocks' => [
                ['blockId' => 'b-1', 'kind' => 'heading', 'parentId' => null, 'order' => 1, 'origin' => 'source', 'spans' => [['kind' => 'segment', 'segmentId' => 'seg-1', 'blockId' => 'b-1', 'byteStart' => 0, 'byteEnd' => strlen($h1)]], 'provenance' => ['kind' => 'verbatim', 'supportingRefs' => []]],
                ['blockId' => 'b-2', 'kind' => 'paragraph', 'parentId' => 'b-1', 'order' => 2, 'origin' => 'source', 'spans' => [['kind' => 'segment', 'segmentId' => 'seg-1', 'blockId' => 'b-2', 'byteStart' => strlen($h1) + 1, 'byteEnd' => strlen($h1 . "\n" . $p1)]], 'provenance' => ['kind' => 'verbatim', 'supportingRefs' => []]],
                ['blockId' => 'b-3', 'kind' => 'heading', 'parentId' => 'b-1', 'order' => 3, 'origin' => 'source', 'spans' => [['kind' => 'segment', 'segmentId' => 'seg-2', 'blockId' => 'b-3', 'byteStart' => 0, 'byteEnd' => strlen($h2)]], 'provenance' => ['kind' => 'verbatim', 'supportingRefs' => []]],
                ['blockId' => 'b-4', 'kind' => 'paragraph', 'parentId' => 'b-3', 'order' => 4, 'origin' => 'source', 'spans' => [['kind' => 'segment', 'segmentId' => 'seg-2', 'blockId' => 'b-4', 'byteStart' => strlen($h2) + 1, 'byteEnd' => strlen($h2 . "\n" . $p2)]], 'provenance' => ['kind' => 'verbatim', 'supportingRefs' => []]],
            ],
            'assets' => [],
            'coverage' => ['state' => 'complete', 'counts' => ['expected' => 2, 'processed' => 2, 'empty' => 0, 'failed' => 0, 'skipped' => 0], 'missingFeatures' => [], 'diagnostics' => []],
        ], JSON_THROW_ON_ERROR));

        $validator = $this->permissiveValidator();
        $document = new IrDocument($irWire, $validator);
        $revision = new DocumentRevisionRef(
            new ExactSourceRef(new TenantId('01JSC420000000000000000000'), new SourceId('01JSSR00000000000000000001'), new SourceVersionId('01JSSV00000000000000000001'), new SourceDigest(str_repeat('a', 64))),
            new DocumentRevisionId('01JSDV00000000000000000001'),
            new IrDigest(hash('sha256', $document->canonical())),
            new ParserSignature('md-parser', '1.0.0', new Fingerprint(str_repeat('c', 64))),
        );

        $outline = (new DeterministicOutlineBuilder())->build($document, $revision);
        $outlineBytes = strlen(Jcs::canonicalize($outline->toWire()));
        self::assertLessThanOrEqual((int) floor($totalSourceBytes * 0.02), $outlineBytes);
    }

    public function testPrunerToonAndMcpCatalogMeetCompressionKpis(): void
    {
        // 1) DeterministicSentencePruner >= 35% byte savings while keeping formula atom intact.
        $formula = '$$E = mc^2$$';
        $noisy = implode(' ', [
            'Momentum conservation governs isolated collisions ' . $formula . '.',
            'Historically Descartes discussed motion in 1644.',
            'Many textbooks include extra historical anecdotes about seventeenth century physics.',
            'Additional decorative commentary about laboratory notebooks and chalkboards.',
            'More unrelated prose about university lecture halls and archival manuscripts.',
        ]);
        $formulaPos = strpos($noisy, $formula);
        self::assertIsInt($formulaPos);

        $rev = new DocumentRevisionRef(
            new ExactSourceRef(new TenantId('01JSC420000000000000000000'), new SourceId('01JSSR00000000000000000001'), new SourceVersionId('01JSSV00000000000000000001'), new SourceDigest(str_repeat('a', 64))),
            new DocumentRevisionId('01JSDV00000000000000000001'),
            new IrDigest(str_repeat('b', 64)),
            new ParserSignature('md', '1.0.0', new Fingerprint(str_repeat('c', 64))),
        );
        $chunk = new Chunk(
            new ChunkRef($rev, new ChunkSetId('01JSCS00000000000000000001'), new ChunkId('01JSCK00000000000000000001'), new Sha256(hash('sha256', $noisy))),
            new DocumentId('01JSD000000000000000000001'),
            [new Span(TextLocator::segment(new LocalId('seg-1'), new LocalId('b-1'), 0, strlen($noisy)), new Sha256(hash('sha256', $noisy)))],
            $noisy,
        );
        $q = new Query('momentum conservation');
        $pruned = (new DeterministicSentencePruner())->prune(
            new QueryBinding($q, $q, 'rev1', new EffectiveProfile('test', '1.0', []), SelectionLimits::profile('compact')),
            [new PrunableUnit($chunk, [new AtomicByteRange($formulaPos, $formulaPos + strlen($formula), 'formula')])],
            new PruningPolicy(300, true, false, 16, 4096),
        );
        $keptBytes = 0;
        foreach ($pruned[0]->subspans as $sub) {
            $keptBytes += ($sub->endByte - $sub->startByte);
        }
        self::assertLessThanOrEqual((int) floor(strlen($noisy) * 0.65), $keptBytes, 'Expected >= 35% pruning savings');

        // 2) ToonCodec >= 30% savings vs CanonicalJsonCodec on 12 records.
        $rows = [];
        for ($i = 1; $i <= 12; $i++) {
            $rows[] = ['id' => "cit_$i", 'tier' => 'R1', 'tokens' => 40 + $i, 'summary' => "Derived proposition $i"];
        }
        $toon = (new ToonCodec())->encode($rows);
        $json = (new CanonicalJsonCodec())->encode($rows);
        self::assertLessThanOrEqual((int) floor(strlen($json) * 0.70), strlen($toon));

        // 3) McpToolCatalogLayer > 90% savings on 50-tool catalog.
        $toolsWire = [];
        $schemasByName = [];
        for ($i = 1; $i <= 50; $i++) {
            $name = sprintf('tool_%02d', $i);
            $props = [];
            for ($p = 1; $p <= 12; $p++) {
                $props['param_' . $p] = [
                    'type' => 'string',
                    'description' => str_repeat("Detailed JSON schema parameter $p specification ", 4),
                ];
            }
            $schema = [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => $props,
            ];
            $toolsWire[] = [
                'name' => $name,
                'summary' => sprintf('External catalog tool %d', $i),
                'schemaDigest' => hash('sha256', Jcs::canonicalize($schema)),
                'requiresApproval' => false,
                'ttlMs' => 300000,
                'inputSchema' => $schema,
            ];
            $schemasByName[$name] = $schema;
        }
        $fullBytes = strlen(Jcs::canonicalize($toolsWire));
        $transport = new class($toolsWire, $schemasByName) implements McpTransport {
            public function __construct(private array $tools, private array $schemas) {}
            public function call(string $serverId, string $method, array $params = []): array {
                if ($method === 'tools/index') {
                    return ['tools' => array_map(static fn (array $t): array => [
                        'name' => $t['name'],
                        'summary' => $t['summary'],
                        'schemaDigest' => $t['schemaDigest'],
                        'requiresApproval' => $t['requiresApproval'],
                        'ttlMs' => $t['ttlMs'],
                    ], $this->tools)];
                }
                if ($method === 'tools/describe') {
                    return ['inputSchema' => $this->schemas[$params['name']]];
                }
                return [];
            }
        };
        $layer = new McpToolCatalogLayer($transport);
        $layer->syncCatalog('srv-1');
        $compactIndex = $layer->r0Catalog();
        $hydrated = $layer->discoverRelevant('tool_01', 1);
        $compactBytes = strlen(json_encode($compactIndex, JSON_THROW_ON_ERROR)) + strlen(json_encode($hydrated, JSON_THROW_ON_ERROR));
        self::assertLessThan((int) floor($fullBytes * 0.10), $compactBytes, 'Expected > 90% MCP catalog savings');
    }

    private function permissiveValidator(): ContractValidator
    {
        $schemas = [];
        foreach (['urn:knowkit:schema:document-ir:1.0'] as $id) {
            $schemas[$id] = new JsonObject(['$schema' => 'https://json-schema.org/draft/2020-12/schema', '$id' => $id, 'type' => 'object']);
        }

        return new ContractValidator(new SchemaSet($schemas));
    }
}
