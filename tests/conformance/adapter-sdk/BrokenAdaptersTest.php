<?php
declare(strict_types=1);

namespace KnowKit\Tests\Conformance\AdapterSdk;

use KnowKit\Examples\CustomAdapter\FakeHost;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support.php';

#[Group('extension:conformance-sdk')]
final class BrokenAdaptersTest extends TestCase
{
    private function rejects(string $fault, string $contract, string $caseId): void
    {
        $harness = Support::harness(); $host = new FakeHost($harness->fixture,$fault);
        $report = $harness->run($host,Support::profile($host),$contract);
        self::assertFalse($report['ok']);
        $failed = array_column(array_filter($report['cases'],static fn(array $c): bool => $c['status'] === 'failed'),'id');
        self::assertContains($caseId,$failed);
        self::assertNotContains('skipped',array_column($report['cases'],'status'));
    }
    public function testRejectsUnknownAllow(): void { $this->rejects('unknown-allow','policy','SDK-POLICY'); }
    public function testRejectsPostfilter(): void { $this->rejects('postfilter','search','SDK-SEARCH'); }
    public function testRejectsMutable(): void { $this->rejects('mutable','blob','SDK-BLOB'); }
    public function testRejectsStaleFence(): void { $this->rejects('stale-fence','jobs','SDK-JOBS'); }
    public function testRejectsMisleadingDelete(): void { $this->rejects('misleading-delete','deletion','SDK-DELETION'); }
    public function testRejectsLeakyArtifacts(): void { $this->rejects('leaky-artifacts','search','SDK-ISOLATION'); }
    public function testRejectsSpaceMismatch(): void { $this->rejects('space-mismatch','search','SDK-SPACE'); }
    public function testRejectsUnguardedEgress(): void { $this->rejects('unguarded-egress','egress','SDK-EGRESS'); }
    public function testRejectsDuplicateOutbox(): void { $this->rejects('duplicate-outbox','outbox','SDK-OUTBOX'); }
    public function testRejectsInexactTokenizer(): void { $this->rejects('inexact-tokenizer','tokenizer','SDK-TOKENIZER'); }
    public function testRejectsRetargetParser(): void { $this->rejects('retarget-parser','parser','SDK-PARSER'); }
    public function testRejectsRevokedParent(): void { $this->rejects('revoked-parent','dependencies','R41-A01'); }
}
