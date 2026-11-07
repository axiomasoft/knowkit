<?php
declare(strict_types=1);
namespace KnowKit\Tests\Publication\Activate;

use KnowKit\Application\Access\{AccessContext, PolicyChecks};
use KnowKit\Application\Catalog\SourceCommands;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\{HashDomain, Jcs, JsonObject};
use KnowKit\Tests\Contract\Ports\PortFixture;
use KnowKit\Tests\Security\Policy\{Clock, PolicyFixture};
use KnowKit\Tooling\Qa;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
require_once __DIR__.'/Fixture.php';
require_once dirname(__DIR__,2).'/security/policy/bootstrap.php';

#[Group('publication:activate')]
final class FixtureTest extends TestCase
{
    public function testVersionedFixturesAndIndependentDigests(): void
    {
        $root = dirname(__DIR__,3); $manifest = json_decode(file_get_contents($root.'/tests/fixtures/check-groups/publication-activate.json'),true,512,JSON_THROW_ON_ERROR);
        self::assertSame('check-group:1',$manifest['schemaVersion']); self::assertNotEmpty($manifest['rights']['license']);
        self::assertNotEmpty($manifest['versionRationale']);
        foreach ($manifest['fixtures'] as $fixture) { self::assertSame($fixture['sha256'],hash_file('sha256',$root.'/'.$fixture['path'])); }
        $data = Fixture::data(); self::assertSame('publication-activate-fixtures:1',$data->get('schemaVersion'));
        $second = $data->get('second');
        self::assertSame($second->get('chunkSetManifest')->get('revisionRef')->get('irDigest'),hash('sha256',Jcs::canonicalize($second->get('ir'))));
        self::assertSame($data->get('secondChunkSetDigest'),HashDomain::ChunkSetManifest->digest($second->get('chunkSetManifest')));
        self::assertSame($manifest['requiredCaseIds'],$data->get('outcomes')->keys());
    }

    public function testRequiredCaseRunnerRejectsMissingSkippedAndEmptyCases(): void
    {
        $manifest = json_decode(file_get_contents(dirname(__DIR__,3).'/tests/fixtures/check-groups/publication-activate.json'),true,512,JSON_THROW_ON_ERROR);
        $ids = array_unique(array_merge(...array_values($manifest['testCaseMap'])));
        $cases = array_map(static fn($id) => ['id'=>$id,'status'=>'passed'],array_values($ids));
        self::assertCount(count($manifest['requiredCaseIds']),Qa::validateCases($cases,$manifest));
        $skipped = $cases; $skipped[0]['status'] = 'skipped';
        $failed = $cases; $failed[0]['status'] = 'failed';
        foreach ([[],array_slice($cases,1),$skipped,$failed] as $invalid) {
            try { Qa::validateCases($invalid,$manifest); self::fail('Broken required-case inventory accepted'); }
            catch (\RuntimeException $error) { self::assertNotEmpty($error->getMessage()); }
        }
    }

    public function testReadPermissionCannotAuthorizeProviderEgress(): void
    {
        $clock = new Clock(); $owner = PolicyFixture::owner($clock); $context = AccessContext::fromHost(PolicyFixture::host());
        $checks = new PolicyChecks($owner,$owner,$clock); $observed = [];
        $checks->prepare($context,PolicyFixture::resources(),D\Operation::Disclose,$owner->binding);
        $owner->egressDecision = D\Decision::Deny;
        Fixture::failure(fn() => $checks->transmit($context,PolicyFixture::request(),['provider input'],static fn() => $owner->binding,
            static function(array $bytes) use (&$observed): void { $observed = $bytes; }),'policy_denied',D\Outcome::Denied);
        self::assertSame([],$observed); self::assertSame(1,$owner->egressCalls);
        Fixture::outcome('R12-A01',['read'=>'allowed','egress'=>'denied','providerObservedBytes'=>0]);
    }

    public function testUnseenTombstoneRejectsDelayedRegistrationAndStalePublication(): void
    {
        Fixture::run(function(Fixture $f): void {
            $r = $f->request(); $f->seal($r); $service = $f->services($r); $scope = $r->lease->scope;
            $source = new D\SourceId(Fixture::id(77));
            $service->lifecycle($scope)->revoke($scope,new D\Revocation($source,new D\Decimal('0')),new D\IdempotencyKey('unseen'));
            $catalog = $f->manifest->jobs->catalog;
            $commands = new SourceCommands($catalog->repo->registerSourceMetadata(...),$catalog->repo->acceptSourceVersion(...),$catalog->tx,$catalog->authority);
            Fixture::failure(fn() => $commands->register($scope,new D\RegisterSource($source,'owner:test','host:upload'),$catalog->context('late-register','0','0')),'storage_conflict');
            self::assertNull($catalog->repo->getSource($scope->tenantId,$source));
            self::assertSame('future_versions',$f->db()->execute('SELECT applies_to FROM tombstones WHERE tenant_id=? AND source_id=?',[$scope->tenantId->value,$source->value])->fetchColumn());
            $service->lifecycle($scope)->revoke($scope,new D\Revocation($r->lease->sourceRef->sourceId,new D\Decimal('0')),new D\IdempotencyKey('revoke-existing'));
            Fixture::failure(fn() => $f->services($r)->publisher->publish($r),'source_changed',D\Outcome::Denied);
            self::assertNull($f->manifest->pointer());
            Fixture::outcome('R30-A01',['lateRegistration'=>'rejected','futureVersions'=>'blocked','stalePublication'=>'rejected']);
        });
    }
}
