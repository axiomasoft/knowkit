<?php

declare(strict_types=1);

namespace KnowKit\Tests\Worker\Acceptance;

use KnowKit\Application\Validation\ExtractionCacheEntry;
use KnowKit\Application\Validation\ValidationInput;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\ContractViolation;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Tests\Jobs\Leases\Fixture as Jobs;
use KnowKit\Tests\Worker\Protocol\Fixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

#[Group('worker:acceptance')]
final class ClientTest extends TestCase
{
    public function testValidResultIsIndependentlyImportedAndAcceptedWithCoverage(): void
    {
        Harness::run(function (Harness $h): void {
            $accepted = $h->execute(new FixtureRunner());
            self::assertSame('succeeded', $h->jobs->row()['state']);
            self::assertSame($accepted->output->result->resultDigest()->value, $h->jobs->row()['accepted_result_digest']);
            self::assertSame(D\Decision::Allow, $accepted->quality->decision);
            self::assertFalse($accepted->uncertainExternalEffect);
            self::assertCount(2, $h->refs);
            foreach ($h->refs as $ref) {
                self::assertSame(hash_file('sha256', $h->blobs->path($ref)), $ref->digest->value);
            }
            self::assertGreaterThanOrEqual(3, $h->authorizations);
            self::assertNotNull($h->jobs->row()['accepted_coverage_json']);
            self::assertSame(0, (int) $h->jobs->catalog->db->pdo->query('SELECT count(*) FROM publication_snapshots')->fetchColumn());
        });
    }

    public function testForeignSourceAndWrongSchemaAreRejectedBeforeImport(): void
    {
        foreach (['sourceId' => Jobs::id(999), 'tenantId' => Jobs::id(998), 'sourceVersionId' => Jobs::id(997),
            'fence' => '0', 'sourceEpoch' => '999', 'profileFingerprint' => str_repeat('0', 64),
            'schemaVersion' => 'worker-result-9.0'] as $field => $value) {
            Harness::run(function (Harness $h) use ($field, $value): void {
                $runner = new FixtureRunner(static fn (JsonObject $ir, JsonObject $result): array => [$ir, $result->with($field, $value)]);
                $failure = $this->rejects(fn () => $h->execute($runner));
                self::assertSame($field === 'schemaVersion' ? 'unsupported_schema_version' : 'worker_identity_mismatch', $failure->errorCode);
                self::assertSame('leased', $h->jobs->row()['state']);
                self::assertSame([], $h->refs);
            });
        }
    }

    public function testBadResultIrAndResourceDigestsAreRejected(): void
    {
        foreach (['resultDigest', 'irDigest', 'resource'] as $field) {
            Harness::run(function (Harness $h) use ($field): void {
                $runner = new FixtureRunner(afterWrite: static function (string $root) use ($field): void {
                    if ($field === 'resource') {
                        file_put_contents($root . '/ir.json', str_replace('source', 'forged', file_get_contents($root . '/ir.json')));
                        return;
                    }
                    $wire = \KnowKit\Domain\Contract\StrictJson::decode(file_get_contents($root . '/result.json'))
                        ->with($field, str_repeat('0', 64));
                    if ($field !== 'resultDigest') {
                        $wire = Fixture::sign($wire);
                    }
                    file_put_contents($root . '/result.json', \KnowKit\Domain\Contract\Jcs::canonicalize($wire));
                });
                $this->rejects(fn () => $h->execute($runner));
                self::assertNull($h->jobs->row()['checkpoint_digest']);
                self::assertSame([], $h->refs);
            });
        }
    }

    public function testStaleLeaseTokenAndEpochCannotAcceptAfterTakeover(): void
    {
        Harness::run(function (Harness $h): void {
            $h->jobs->expire($h->lease);
            $new = $h->jobs->lease();
            $old = new FixtureRunner();
            self::assertSame('stale_lease', $this->rejects(fn () => $h->execute($old))->errorCode);
            self::assertSame(0, $old->runs);
            $accepted = $h->execute(new FixtureRunner(), lease: $new);
            self::assertSame('succeeded', $h->jobs->row()['state']);
            self::assertFalse($h->queue->complete($h->lease, new D\JobResult(D\Outcome::Success, $accepted->output->result->resultDigest())));
        });
        foreach (['token', 'epoch'] as $mode) {
            Harness::run(function (Harness $h) use ($mode): void {
                $lease = $h->lease;
                if ($mode === 'token') {
                    $lease = new D\Lease($lease->scope, $lease->jobId, $lease->attemptId, $lease->sourceRef, null,
                        $lease->fence, str_repeat('forged-token', 4), $lease->leaseUntil, $lease->attemptDeadline, $lease->sourceEpoch);
                } else {
                    $h->jobs->catalog->db->execute('UPDATE sources SET lifecycle_epoch=lifecycle_epoch+1 WHERE tenant_id=? AND id=?',
                        [Jobs::id(1), Jobs::id(2)]);
                }
                self::assertSame('stale_lease', $this->rejects(fn () => $h->execute(new FixtureRunner(), lease: $lease))->errorCode);
                self::assertNull($h->jobs->row()['checkpoint_digest']);
            });
        }
    }

    public function testSourceRevokeAndCancellationInFlightRejectOutput(): void
    {
        foreach (['revoke', 'cancel', 'checkpoint-window', 'result-window'] as $mode) {
            Harness::run(function (Harness $h) use ($mode): void {
                $revoke = static function () use ($h, $mode): void {
                    if ($mode === 'cancel') {
                        $h->queue->cancel($h->lease->scope, $h->lease->jobId);
                        return;
                    }
                    $h->jobs->catalog->db->execute("UPDATE sources SET lifecycle_state='revoked', lifecycle_epoch=lifecycle_epoch+1 WHERE tenant_id=? AND id=?",
                        [Jobs::id(1), Jobs::id(2)]);
                };
                if ($mode === 'checkpoint-window') {
                    $h->onSave = $revoke;
                }
                if ($mode === 'result-window') {
                    $h->onAuthorize = static function () use ($h, $revoke): void {
                        if ($h->jobs->row()['checkpoint_digest'] !== null) {
                            $revoke();
                        }
                    };
                }
                $runner = new FixtureRunner(after: in_array($mode, ['revoke', 'cancel'], true) ? $revoke : null);
                self::assertSame(D\Outcome::Denied, $this->rejects(fn () => $h->execute($runner))->outcome);
                self::assertNull($h->jobs->row()['accepted_result_digest']);
            });
        }
    }

    public function testRevokedProfileAndCapabilityAfterRegistrationDenyOutput(): void
    {
        foreach (['profile', 'capability', 'after-import'] as $mode) {
            Harness::run(function (Harness $h) use ($mode): void {
                $revoke = static function () use ($h, $mode): void {
                    if ($mode === 'capability') {
                        $h->profiles->revokeCapability($h->profile->id, D\Capability::ParserText);
                        return;
                    }
                    $h->profiles->revoke($h->profile->id);
                };
                if ($mode === 'after-import') {
                    $h->onSave = $revoke;
                }
                $runner = new FixtureRunner(after: $mode === 'after-import' ? null : $revoke);
                self::assertSame(D\Outcome::Denied, $this->rejects(fn () => $h->execute($runner))->outcome);
                self::assertNull($h->jobs->row()['checkpoint_digest']);
            });
        }
    }

    public function testUnsupportedExplicitAndNetworkAndUnqualifiedProfilesDoNotStartWorker(): void
    {
        foreach (['unknown', 'network', 'unqualified', 'mismatch'] as $mode) {
            Harness::run(function (Harness $h) use ($mode): void {
                $profile = new D\ParserProfile($mode, 'synthetic', '1.0.0', new JsonObject(['mode' => $mode]));
                if (in_array($mode, ['network', 'unqualified'], true)) {
                    $h->profiles->register($profile, FixtureRunner::capabilities(), $mode !== 'unqualified', $mode === 'network');
                }
                if ($mode === 'mismatch') {
                    $profile = new D\ParserProfile($h->profile->id, 'synthetic', '2.0.0', $h->profile->options->toWire());
                }
                $runner = new FixtureRunner();
                $failure = $this->rejects(fn () => $h->execute($runner, $profile));
                self::assertSame(match ($mode) {
                    'network' => D\Outcome::Denied,
                    'unqualified' => D\Outcome::Unavailable,
                    default => D\Outcome::Incompatible,
                }, $failure->outcome);
                self::assertSame(0, $runner->runs);
                self::assertSame([], $h->refs);
            });
        }
    }

    public function testMissingUnitCannotClaimCompleteAndPartialQualityRemainsVisible(): void
    {
        Harness::run(function (Harness $h): void {
            $runner = new FixtureRunner(static function (JsonObject $ir, JsonObject $result): array {
                $coverage = $ir->get('coverage')->with('counts', $ir->get('coverage')->get('counts')->with('expected', 2));
                $page = new JsonObject([
                    'pageId' => 'p1', 'physicalNo' => 1, 'printedLabel' => null,
                    'text' => 'source', 'textDigest' => hash('sha256', 'source'), 'status' => 'ok',
                ]);
                return [$ir->with('pages', [$page])->with('segments', [])->with('coverage', $coverage), $result->with('coverage', $coverage)];
            });
            $this->rejects(fn () => $h->execute($runner));
            self::assertSame([], $h->refs);
        });
        Harness::run(function (Harness $h): void {
            $runner = new FixtureRunner(static function (JsonObject $ir, JsonObject $result): array {
                $coverage = $ir->get('coverage')->with('state', 'partial')->with('counts',
                    $ir->get('coverage')->get('counts')->with('expected', 2)->with('failed', 1));
                $pages = [new JsonObject([
                    'pageId' => 'p1', 'physicalNo' => 1, 'printedLabel' => null,
                    'text' => 'source', 'textDigest' => hash('sha256', 'source'), 'status' => 'ok',
                ]), new JsonObject([
                    'pageId' => 'p2', 'physicalNo' => 2, 'printedLabel' => null,
                    'text' => '', 'textDigest' => hash('sha256', ''), 'status' => 'failed',
                ])];
                return [$ir->with('pages', $pages)->with('segments', [])->with('coverage', $coverage),
                    $result->with('status', 'partial')->with('coverage', $coverage)];
            });
            $accepted = $h->execute($runner);
            self::assertSame(D\Decision::Deny, $accepted->quality->decision);
            self::assertSame('partial', $accepted->quality->coverage->toWire()->get('state'));
            self::assertStringContainsString('partial', $h->jobs->row()['accepted_coverage_json']);
        });
    }

    public function testExternalUploadUncertaintyIsDurableAndRetryDoesNotClaimExactlyOnce(): void
    {
        Harness::run(function (Harness $h): void {
            $h->onAccepted = static function (): void {
                throw new D\DomainFailure(D\Outcome::Unknown, 'upload_commit_unknown');
            };
            self::assertSame(D\Outcome::Unknown, $this->rejects(fn () => $h->execute(new FixtureRunner()))->outcome);
            self::assertTrue($h->jobs->row()['uncertain_external_effect']);
            self::assertSame('pending', $h->jobs->row()['state']);
            self::assertNull($h->jobs->row()['checkpoint_digest']);
            $h->jobs->catalog->db->execute('UPDATE jobs SET available_at=clock_timestamp() WHERE tenant_id=? AND id=?',
                [Jobs::id(1), $h->lease->jobId->value]);
            $next = $h->jobs->lease();
            $h->onAccepted = null;
            $accepted = $h->execute(new FixtureRunner(), lease: $next);
            self::assertTrue($accepted->uncertainExternalEffect);
            self::assertSame('succeeded', $h->jobs->row()['state']);
            self::assertTrue($h->jobs->row()['uncertain_external_effect']);
            self::assertTrue($h->queue->complete($next, new D\JobResult(D\Outcome::Success,
                $accepted->output->result->resultDigest(), $accepted->output->result->coverage())));
        });
    }

    public function testReuseBindsFullIdentityAndExcludesTransientSecrets(): void
    {
        Harness::run(function (Harness $h): void {
            $accepted = $h->execute(new FixtureRunner());
            $input = new ValidationInput($h->source, $accepted->revision, $h->processing, []);
            $cache = ExtractionCacheEntry::accept($input, $accepted->output->ir);
            self::assertSame('valid', $cache->reuse($input, $accepted->output->ir)->status);
            foreach (['ocr', 'parser'] as $axis) {
                $changed = new D\ParserSignature('changed', '2.0.0', new D\Fingerprint(str_repeat('a', 64)));
                $next = new D\ProcessingIdentity($h->processing->sourceVersionId, $h->processing->sourceDigest,
                    $axis === 'parser' ? $changed : $h->processing->parserSignature,
                    $axis === 'ocr' ? $changed : $h->processing->ocrProfileSignature,
                    $h->processing->chunkerSignature, 'ir-1.0', $h->processing->indexProfileDigest, null);
                self::assertContains('documentRevisionId', $h->processing->changedAxes($next));
                self::assertSame('invalid', $cache->reuse(new ValidationInput($h->source, $accepted->revision, $next, []), $accepted->output->ir)->status);
            }
            foreach (['credentialHandle', 'signedUrl'] as $key) {
                try {
                    new D\ParserProfile('forbidden', 'synthetic', '1', new JsonObject([$key => 'CREDENTIAL_CANARY']));
                    self::fail('Transient credential fingerprinted');
                } catch (\InvalidArgumentException $failure) {
                    self::assertStringNotContainsString('CREDENTIAL_CANARY', $failure->getMessage());
                }
            }
            self::assertStringNotContainsString('source', print_r($accepted->output, true));
            self::assertStringNotContainsString('leaseToken', $accepted->output->result->canonical());
            self::assertGreaterThanOrEqual(3, $h->authorizations);
        });
    }

    public function testCredentialRotationKeepsFingerprintAndRechecksCurrentAccess(): void
    {
        Harness::run(function (Harness $h): void {
            $credential = 'CREDENTIAL_BEFORE';
            $url = 'https://synthetic.invalid/input?temporary=BEFORE';
            $seen = [];
            $fingerprint = $h->processing->digest();
            $h->onAuthorize = static function () use (&$credential, &$seen): void {
                $seen[] = $credential;
            };
            $runner = new FixtureRunner(after: static function () use (&$credential, &$url): void {
                $credential = 'CREDENTIAL_AFTER';
                $url = 'https://synthetic.invalid/input?temporary=AFTER';
            });
            $accepted = $h->execute($runner);
            self::assertContains('CREDENTIAL_BEFORE', $seen);
            self::assertContains('CREDENTIAL_AFTER', $seen);
            self::assertEquals($fingerprint, $h->processing->digest());
            self::assertStringNotContainsString($credential, $accepted->output->result->canonical());
            self::assertStringNotContainsString($url, $accepted->output->result->canonical());
        });
        Harness::run(function (Harness $h): void {
            $h->onAuthorize = static function () use ($h): void {
                if (!$h->accessAllowed) {
                    throw new D\DomainFailure(D\Outcome::Denied, 'owner_revoked');
                }
            };
            $runner = new FixtureRunner(after: static function () use ($h): void {
                $h->accessAllowed = false;
            });
            self::assertSame('owner_revoked', $this->rejects(fn () => $h->execute($runner))->errorCode);
            self::assertSame([], $h->refs);
            self::assertNull($h->jobs->row()['accepted_result_digest']);
        });
    }

    public function testCheckpointResourceBoundAndCurrentUncertaintyRemainExplicit(): void
    {
        Harness::run(function (Harness $h): void {
            $h->onSave = static function () use ($h): void {
                $h->jobs->catalog->db->execute('UPDATE jobs SET uncertain_external_effect=true WHERE tenant_id=? AND id=?',
                    [Jobs::id(1), $h->lease->jobId->value]);
            };
            $runner = new FixtureRunner();
            $accepted = $h->execute($runner, limits: new D\WorkerLimits(3, 3, 134217728, 8388608, 8, 8388608, 2000));
            self::assertSame(126, $runner->lastJob->limits()->maxAssets);
            self::assertTrue($accepted->uncertainExternalEffect);
            self::assertTrue($h->jobs->row()['uncertain_external_effect']);
        });
        Harness::run(function (Harness $h): void {
            $ledger = $h->blobs->ledger;
            $importer = new \KnowKit\Application\Ingest\Worker\WorkerArtifactImporter($h->blobs->store,
                new \KnowKit\Tests\Storage\Catalog\TestIds(950000), $ledger->reserveWriter(...),
                static function (D\BlobRef $ref) use ($ledger): void {
                    $ledger->requireAccepted($ref);
                }, $ledger->markUncertain(...), static function (): void {});
            $reserved = $importer->reserve($h->lease);
            $output = (new FixtureRunner())->run(Fixture::job(), "KnowKit protocol fixture.\n");
            $paths = array_map(static fn (int $id): string => "asset-{$id}", range(0, 126));
            $oversized = new D\WorkerOutput($output->result, $output->ir, array_fill_keys($paths, 'bytes'));
            self::assertSame('output_limit', $this->rejects(fn () => $importer->import($h->lease, $reserved,
                $oversized, $h->queue->workerContext($h->lease)))->errorCode);
            self::assertSame([], $h->refs);
            $importer->abandon($reserved);
        });
    }

    public function testExtractionCheckpointLeavesFollowingIngestStagesPending(): void
    {
        Harness::run(function (Harness $h): void {
            $accepted = $h->execute(new FixtureRunner());
            self::assertFalse($accepted->jobCompleted);
            self::assertSame('leased', $h->jobs->row()['state']);
            self::assertSame('chunk', $h->jobs->row()['stage']);
            self::assertSame(1, (int) $h->jobs->row()['completed_stage_count']);
            self::assertNull($h->jobs->row()['accepted_result_digest']);
            self::assertNotNull($h->jobs->row()['checkpoint_digest']);
        }, jobType: 'ingest');
    }

    private function rejects(\Closure $call): D\DomainFailure|ContractViolation
    {
        try {
            $call();
            self::fail('Untrusted worker accepted');
        } catch (D\DomainFailure|ContractViolation $failure) {
            return $failure;
        }
    }
}
