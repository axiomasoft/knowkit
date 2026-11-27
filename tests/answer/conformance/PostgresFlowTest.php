<?php

declare(strict_types=1);

namespace KnowKit\Tests\Answer\Conformance;

use KnowKit\Answer\Application as App;
use KnowKit\Answer\Domain as A;
use KnowKit\Answer\Profile\EffectiveProfile;
use KnowKit\Answer\Profile\ProfileMode;
use KnowKit\Answer\Testing\ScriptedTransport;
use KnowKit\Domain\Contract\Jcs;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Tests\Answer\Contracts\Fixture;
use KnowKit\Tests\Answer\Grounding\GroundingFixture;
use KnowKit\Tests\Answer\Ledger\LedgerFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

#[Group('answer:conformance')]
final class PostgresFlowTest extends TestCase
{
    public function testPublicFlowPersistsExactEgressAndObservationThenReplaysWithoutGeneration(): void
    {
        LedgerFixture::run(function (LedgerFixture $base): void {
            $host = new GroundingFixture();
            $this->seedHost($base, $host);
            $sql = new ReplayFixture($base->blobs->catalog->db, $base->blobs->root, Fixture::scope());
            $sql->clock->fixed = $host->date();
            $host->binding = Fixture::binding(['transport' => ScriptedTransport::testDescriptor()]);
            $transport = new ScriptedTransport(new EffectiveProfile(ProfileMode::Test, ScriptedTransport::testDescriptor()), [
                new A\TransportResult(A\DispatchCertainty::Sent,
                    Jcs::canonicalize(new JsonObject(['text' => ReplayFixture::corpus()['answer'], 'citations' => ['#1']])),
                    true, new A\UsageObservation(123, 17, 'script:public-flow'), $host->binding->model, true),
            ]);
            $service = $this->service($host, $sql, $transport);
            $request = $host->request();
            $host->settlementFails = true;
            $first = $service->answer($request, $host->token());
            self::assertSame(A\OutcomeCode::Answered, $first->code);
            self::assertTrue($first->fixture);
            self::assertSame(1, $host->commits);
            $row = $sql->ledger->find($request->invocationRef());
            self::assertSame(A\InvocationState::Completed, $row->state);
            self::assertNotNull($row->modelCallIdentityDigest);
            self::assertSame(1, (int) $sql->db->pdo->query("SELECT count(*) FROM knowkit_answer_payloads WHERE kind='observation'")->fetchColumn());
            self::assertGreaterThanOrEqual(2, (int) $sql->db->pdo->query("SELECT count(*) FROM knowkit_answer_payloads WHERE kind='egress-check'")->fetchColumn());
            $host->settlementFails = false;
            $host->binding = Fixture::binding(['transport' => ScriptedTransport::testDescriptor(), 'model' => 'changed-tutor-model']);
            $second = $service->answer($request, $host->token());
            self::assertEquals($first->toWire(), $second->toWire());
            self::assertSame(1, $host->resolutions);
            self::assertSame(2, $host->commits);
            self::assertSame(1, $transport->sendCount());
            self::assertGreaterThan(0, $transport->outboundBytes());
            $reads = $sql->payloads->responseReads;
            $host->replayDenied = true;
            $denied = $service->answer($request, $host->token());
            self::assertSame(A\OutcomeCode::Denied, $denied->code);
            self::assertNull($denied->answeredContent());
            self::assertSame($reads, $sql->payloads->responseReads);
            self::assertEquals($row, $sql->ledger->find($request->invocationRef()));
            self::assertSame(1, $transport->sendCount());
        });
    }

    private function seedHost(LedgerFixture $base, GroundingFixture $host): void
    {
        $db = $base->blobs->catalog->db;
        $scope = Fixture::scope();
        $db->execute("INSERT INTO tenants(id,name,serving_state) VALUES (?,'synthetic schoolA','ready')", [$scope->tenantId->value]);
        $db->execute(<<<'SQL'
            INSERT INTO knowledge_bases(tenant_id,id,name,policy_ref,coverage_policy,default_profile,kb_policy_epoch)
            VALUES (?,?,'student_safe','fixture:policy','{}','postgres-fts-simple-v1',?)
            SQL, [$scope->tenantId->value, $scope->kbId->value, $scope->policyEpoch->value]);
        foreach ([ReplayFixture::id(2), $host->citation()->toWire()->get('sourceId')] as $source) {
            $db->execute("INSERT INTO sources(tenant_id,id,owner_ref,origin,lifecycle_state) VALUES (?,?,'synthetic:private','fixture','active')",
                [$scope->tenantId->value, $source]);
        }
    }

    private function service(GroundingFixture $host, ReplayFixture $sql, ScriptedTransport $transport): App\GroundedAnswerService
    {
        return new App\GroundedAnswerService(
            new App\InvocationAdmission($sql->ledger, $host),
            $sql->ledger,
            new App\InvocationReplay($sql->ledger, $sql->payloads, $host, $host->validator),
            new App\GroundingEvidenceReader($host, $host, $host, $host->validator, Fixture::profile()),
            new App\GroundingPromptComposer(),
            $host,
            new App\CurrentModelEgress($host, $host, $host->validator),
            $host,
            $transport,
            new App\AnswerOutcomeMapper(new App\CitationValidator($host->validator)),
            new App\AnswerSettlement($sql->payloads, $host, $host),
            $host,
            ProfileMode::Test,
        );
    }
}
