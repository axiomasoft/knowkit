<?php
declare(strict_types=1);

namespace KnowKit\Tests\Conformance\AdapterSdk;

use KnowKit\Conformance\CorpusContract;
use KnowKit\Examples\CustomAdapter\FakeHost;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support.php';

#[Group('extension:conformance-sdk')]
final class CorpusTest extends TestCase
{
    private function execute(string $id): void
    {
        $harness = Support::harness(); $host = new FakeHost($harness->fixture);
        (new CorpusContract())->execute($id,$host,Support::profile($host),$harness->fixture);
        $this->addToAssertionCount(1);
    }
    public function testR36A01(): void { $this->execute('R36-A01'); }
    public function testR36A02(): void { $this->execute('R36-A02'); }
    public function testDTR02(): void { $this->execute('DT-R02'); }
    public function testDTR03(): void { $this->execute('DT-R03'); }
    public function testDTR05(): void { $this->execute('DT-R05'); }
    public function testDTR06(): void { $this->execute('DT-R06'); }
    public function testSDKPOLICY(): void { $this->execute('SDK-POLICY'); }
    public function testSDKBLOB(): void { $this->execute('SDK-BLOB'); }
    public function testSDKPARSER(): void { $this->execute('SDK-PARSER'); }
    public function testSDKSEARCH(): void { $this->execute('SDK-SEARCH'); }
    public function testSDKSPACE(): void { $this->execute('SDK-SPACE'); }
    public function testSDKTOKENIZER(): void { $this->execute('SDK-TOKENIZER'); }
    public function testSDKBUDGET(): void { $this->execute('SDK-BUDGET'); }
    public function testSDKJOBS(): void { $this->execute('SDK-JOBS'); }
    public function testSDKOUTBOX(): void { $this->execute('SDK-OUTBOX'); }
    public function testSDKDELETION(): void { $this->execute('SDK-DELETION'); }
    public function testSDKEGRESS(): void { $this->execute('SDK-EGRESS'); }
    public function testSDKISOLATION(): void { $this->execute('SDK-ISOLATION'); }
    public function testR41A01(): void { $this->execute('R41-A01'); }
    public function testR41A02(): void { $this->execute('R41-A02'); }
}
