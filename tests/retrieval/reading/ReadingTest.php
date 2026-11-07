<?php

declare(strict_types=1);

namespace KnowKit\Tests\Retrieval\Reading;

use KnowKit\Domain as D;
use KnowKit\Domain\Access\PolicyContext;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Contract\LocatorConversion;
use KnowKit\Domain\Reading as R;
use KnowKit\Port\Tokenizer;
use KnowKit\Tests\Retrieval\Disclosure\Fixture as DisclosureFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

#[Group('retrieval:reading')]
final class ReadingTest extends TestCase
{
    public function testIssuedCitationIsPersistedAtomicallyAndExactSegmentStillResolves(): void
    {
        Fixture::run(function (Fixture $f): void {
            $record = $f->store->citation($f->scope(), $f->issuedCitation);
            self::assertEquals($f->upstream->ref(), $record->chunk);
            self::assertSame('segment', $record->citation->spans()[0]->locator->toWire()->get('kind'));
            $r = $f->request();
            $request = new R\ReadRequest($f->issuedCitation, $r->attemptId, $r->target, $r->limits, $r->maxBytes, $r->expiresAt);
            $result = $f->read($request);
            self::assertSame(D\Outcome::Success, $result->outcome, $result->errorCode ?? 'success');
            self::assertEquals($record->citation->contentDigest(), $result->data->body->digest);
            self::assertSame($record->citation->canonical(), $result->data->citation->canonical());
        });
    }

    public function testPageIndexZeroPhysicalOneAndPrintedLabelRemainSeparate(): void
    {
        Fixture::run(function (Fixture $f): void {
            $case = Fixture::corpus();
            $locator = LocatorConversion::pageIndexToLocator(new JsonObject([
                'pageIndex' => $case['pageIndex'], 'pageId' => 'p1', 'printedLabel' => $case['label'],
                'blockId' => 'b1', 'byteStart' => 0, 'byteEnd' => strlen($case['page']),
            ]));
            self::assertSame($case['physicalNo'], $locator->get('physicalNo'));
            $request = $f->request(R\ReadTarget::page(new D\LocalId('p1'), 1, 'xii'));
            $result = $f->read($request);
            self::assertSame(D\Outcome::Success, $result->outcome, $result->errorCode ?? 'success');
            self::assertSame($case['page'], $result->data->body->bytes());
            self::assertSame($case['expectedDigests']['page'], $result->data->body->digest->value);
            self::assertSame('xii', $result->data->body->metadata()->get('printedLabel'));
            self::assertSame(1, $result->data->body->metadata()->get('physicalNo'));
            $missing = $f->read($f->request(R\ReadTarget::page(new D\LocalId('p1'), 2)));
            $this->failure($missing, D\Outcome::Unknown, 'exact_target_missing');
            try {
                R\ReadTarget::page(physicalNo: 0);
                self::fail('Zero physical page accepted');
            } catch (D\DomainFailure $error) {
                self::assertSame('invalid_page_selector', $error->errorCode);
            }
        });
    }

    public function testAmbiguousLabelReturnsAlternativesAndVisualHashesStayDistinct(): void
    {
        Fixture::run(function (Fixture $f): void {
            $ambiguous = $f->read($f->request(R\ReadTarget::page(printedLabel: 'xii')));
            self::assertSame(D\Outcome::Success, $ambiguous->outcome, $ambiguous->errorCode ?? 'success');
            self::assertSame('', $ambiguous->data->body->bytes());
            self::assertSame('alternatives', $ambiguous->data->body->metadata()->get('resolution'));
            self::assertCount(2, $ambiguous->data->body->metadata()->get('alternatives'));
            $original = $f->read($f->request(R\ReadTarget::source()));
            $asset = $f->read($f->request(R\ReadTarget::asset(new D\LocalId('a1'))));
            $preview = $f->read($f->request(R\ReadTarget::region(new D\LocalId('p1'), new D\Bbox(0, 0, 500000, 500000))));
            foreach ([$original, $asset, $preview] as $result) {
                self::assertSame(D\Outcome::Success, $result->outcome, $result->errorCode ?? 'success');
                self::assertArrayNotHasKey('url', iterator_to_array($result->data->body->metadata()));
                self::assertSame($f->citation->canonical(), $result->data->citation->canonical());
            }
            self::assertSame(Fixture::corpus()['expectedDigests']['source'], $original->data->body->digest->value);
            self::assertSame(Fixture::corpus()['expectedDigests']['asset'], $asset->data->body->digest->value);
            self::assertSame(Fixture::corpus()['expectedDigests']['preview'], $preview->data->body->digest->value);
            self::assertNotEquals($asset->data->body->digest, $preview->data->body->digest);
            self::assertNotEquals($original->data->body->digest, $preview->data->body->digest);
            self::assertSame(500000, $preview->data->body->metadata()->get('transform')->get('sourceBbox')->get('x1'));
        });
    }

    public function testOldLocatorAfterCorrectionOrReOcrNeverUsesNewBytes(): void
    {
        Fixture::run(function (Fixture $f): void {
            $case = Fixture::corpus();
            $identity = $f->citation->canonical();
            $f->upstream->objects->bodies[$case['expectedDigests']['correctedPage']] = $case['correctedPage'];
            $first = $f->read($f->request(R\ReadTarget::page(new D\LocalId('p1'))));
            self::assertSame(D\Outcome::Success, $first->outcome, $first->errorCode ?? 'success');
            self::assertSame($case['page'], $first->data->body->bytes());
            self::assertSame($identity, $first->data->citation->canonical());
            $f->upstream->objects->bodies[$f->chunk->revisionRef->irDigest->value] = $case['correctedPage'];
            $this->failure($f->read(), D\Outcome::Incompatible, 'digest_mismatch');
            unset($f->upstream->objects->bodies[$f->chunk->revisionRef->irDigest->value]);
            $this->failure($f->read(), D\Outcome::Unavailable, 'canonical_object_missing');
            self::assertSame($identity, $f->store->citation($f->scope(), $f->citation->id())->citation->canonical());
            $f->guarded(function () use ($f): void {
                $f->upstream->db()->execute('UPDATE ' . $f->upstream->db()->table('source_version_state')
                    . " SET usability='corrected' WHERE tenant_id=? AND id=?",
                    [$f->scope()->tenantId->value, $f->chunk->revisionRef->sourceRef->sourceVersionId->value]);
            });
            $f->upstream->objects->bodies[$f->chunk->revisionRef->irDigest->value] = $f->document->canonical();
            $this->failure($f->read(), D\Outcome::Denied, 'ancestor_unusable');
        });
    }

    public function testAmbiguousRegionReturnsEveryExactAlternativeWithoutChoosingFirstAsset(): void
    {
        Fixture::run(function (Fixture $f): void {
            $result = $f->read($f->request(R\ReadTarget::region(new D\LocalId('p1'), new D\Bbox(0, 0, 500000, 500000))));
            self::assertSame(D\Outcome::Success, $result->outcome, $result->errorCode ?? 'success');
            self::assertSame('', $result->data->body->bytes());
            self::assertSame('alternatives', $result->data->body->metadata()->get('resolution'));
            $ids = array_map(static fn (JsonObject $item): string => $item->get('assetId'),
                $result->data->body->metadata()->get('alternatives'));
            self::assertSame(['a2', 'a3'], $ids);
        }, ambiguous: true);
    }

    public function testChangedUnderlyingSourceAndAssetDigestRefuseOldLocator(): void
    {
        Fixture::run(function (Fixture $f): void {
            $case = Fixture::corpus();
            foreach ([['source', R\ReadTarget::source()], ['asset', R\ReadTarget::asset(new D\LocalId('a1'))]] as [$key, $target]) {
                $f->upstream->objects->bodies[$case['expectedDigests'][$key]] = 'replacement newest bytes';
                $this->failure($f->read($f->request($target)), D\Outcome::Incompatible, 'digest_mismatch');
                $f->upstream->objects->bodies[$case['expectedDigests'][$key]] = $case[$key];
            }
        });
    }

    public function testPreviewRevocationDuringPreloadBlocksPageTableAssetAndSource(): void
    {
        foreach ([R\ReadTarget::page(new D\LocalId('p1')), R\ReadTarget::table(new D\LocalId('tb')),
            R\ReadTarget::asset(new D\LocalId('a1')), R\ReadTarget::source()] as $target) {
            Fixture::run(function (Fixture $f) use ($target): void {
                $request = $f->request($target);
                $before = $f->upstream->lexical->catalog->count('evidence_usages');
                $f->upstream->objects->beforeOpen = fn () => $f->upstream->revoke();
                $result = $f->read($request);
                self::assertSame(D\Outcome::Denied, $result->outcome, $result->errorCode ?? 'denied');
                self::assertNull($result->data);
                self::assertSame($before, $f->upstream->lexical->catalog->count('evidence_usages'));
            });
        }
    }

    public function testPolicyRevokeAfterBufferingAndBeforeServingIsFresh(): void
    {
        Fixture::run(function (Fixture $f): void {
            $request = $f->request(R\ReadTarget::asset(new D\LocalId('a1')));
            $result = $f->read($request);
            self::assertSame(D\Outcome::Success, $result->outcome, $result->errorCode ?? 'success');
            $f->upstream->host->decision = D\Decision::Deny;
            $this->failure($f->read($f->request(R\ReadTarget::asset(new D\LocalId('a1')))), D\Outcome::Denied, 'policy_denied');
            $replayed = $f->read($request);
            self::assertSame(D\Outcome::Success, $replayed->outcome);
            self::assertInstanceOf(D\DisclosureReplay::class, $replayed->data);
            self::assertEquals($result->data->usageRef, $replayed->data->usageRef);
            self::assertStringNotContainsString(Fixture::corpus()['asset'], serialize($replayed));
        });
    }

    public function testExpiredReadAndExpiryDuringPreloadIssueNoBytes(): void
    {
        Fixture::run(function (Fixture $f): void {
            $request = $f->request(expires: $f->upstream->host->now->plusSeconds(-1));
            $this->failure($f->read($request), D\Outcome::Denied, 'read_expired');
            $request = $f->request(expires: $f->upstream->host->now->plusSeconds(1));
            $f->upstream->objects->beforeOpen = function () use ($f): void {
                $f->upstream->db()->execute('SELECT pg_sleep(1.1)');
            };
            $this->failure($f->read($request), D\Outcome::Denied, 'read_expired');
            $now = DisclosureFixture::databaseNow($f->upstream->db());
            $long = $f->request(expires: $now->plusSeconds(301));
            $this->failure($f->read($long), D\Outcome::Incompatible, 'read_ttl_exceeded');
        });
    }

    public function testInvalidRegionCannotClampOrChooseNearestVisual(): void
    {
        foreach (Fixture::corpus()['invalidRegions'] as $coords) {
            try {
                new D\Bbox(...$coords);
                self::fail('Invalid region accepted');
            } catch (\InvalidArgumentException $error) {
                self::assertSame('Integer page millionths required', $error->getMessage());
            }
        }
        Fixture::run(function (Fixture $f): void {
            $this->failure($f->read($f->request(R\ReadTarget::region(new D\LocalId('p1'),
                new D\Bbox(0, 0, 500001, 500000)))), D\Outcome::Unknown, 'exact_target_missing');
        });
    }

    public function testPurgedResolverAndPayloadDuringPreloadRemainUnavailable(): void
    {
        Fixture::run(function (Fixture $f): void {
            $before = $f->citation->canonical();
            $f->guarded(function () use ($f): void {
                $f->upstream->db()->execute('UPDATE ' . $f->upstream->db()->table('citations')
                    . " SET payload_state='removed' WHERE tenant_id=? AND id=?", [$f->scope()->tenantId->value, $f->citation->id()->value]);
            });
            $this->failure($f->read(), D\Outcome::Unavailable, 'payload_purged');
            self::assertSame($before, $f->store->citation($f->scope(), $f->citation->id())->citation->canonical());
        });
        Fixture::run(function (Fixture $f): void {
            $f->upstream->objects->beforeOpen = function () use ($f): void {
                $f->guarded(function () use ($f): void {
                    $f->upstream->db()->execute('UPDATE ' . $f->upstream->db()->table('resource_ledger')
                        . " SET state='deleting' WHERE tenant_id=? AND id=?", [$f->scope()->tenantId->value, Fixture::id(21102)]);
                });
            };
            $this->failure($f->read($f->request(R\ReadTarget::page(new D\LocalId('p1')))), D\Outcome::Unavailable, 'payload_purged');
        });
    }

    public function testTableRetainsCompleteRowsHeadersAndUnitsOrRequiresExplicitRead(): void
    {
        Fixture::run(function (Fixture $f): void {
            $target = R\ReadTarget::table(new D\LocalId('tb'));
            $small = D\SelectionLimits::profile('table', maxBytes: 10, maxBytesPerFragment: 10);
            $this->failure($f->read($f->request($target, $small)), D\Outcome::Incompatible, 'table_read_required');
            $result = $f->read($f->request($target, D\SelectionLimits::profile('table')));
            self::assertSame(D\Outcome::Success, $result->outcome, $result->errorCode ?? 'success');
            $table = json_decode($result->data->body->bytes(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(2, $table['rows']);
            self::assertCount(2, $table['cells']);
            self::assertSame(['h'], $table['cells'][1]['headerRefs']);
            self::assertSame('кг', $table['cells'][1]['unit']);
            self::assertSame('12', $table['cells'][1]['text']);
            self::assertSame(0, $result->data->body->metadata()->get('omittedRows'));
            self::assertTrue($result->data->body->metadata()->get('completeRows'));
            self::assertSame('table', $result->data->profile);
        });
    }

    public function testMultibyteTokenByteFragmentAndAssetLimitsRemainIndependent(): void
    {
        Fixture::run(function (Fixture $f): void {
            $target = R\ReadTarget::quote();
            $case = Fixture::corpus();
            $bytes = strlen($case['page']);
            $tokens = mb_strlen($case['page'], 'UTF-8');
            self::assertGreaterThan($tokens, $bytes);
            $identity = new D\TokenizerIdentity(new D\AdapterId('test-codepoint'), '1', new D\Sha256(str_repeat('f', 64)));
            $tokenizer = new class ($identity) implements Tokenizer {
                public function __construct(private D\TokenizerIdentity $identity) {}
                public function capabilities(): D\TokenizerCapabilities
                {
                    return new D\TokenizerCapabilities($this->identity, true);
                }
                public function count(string $utf8, D\TokenizerIdentity $identity): int
                {
                    $this->identity->assertCompatible($identity);

                    return mb_strlen($utf8, 'UTF-8');
                }
            };
            $f->service = $f->service($tokenizer);
            $limits = D\SelectionLimits::profile('compact', maxFragments: 1, maxBytes: $bytes, maxBytesPerFragment: $bytes,
                maxTokens: $tokens, tokenizer: $identity);
            $result = $f->read($f->request($target, $limits));
            self::assertSame(D\Outcome::Success, $result->outcome, $result->errorCode ?? 'success');
            self::assertSame($case['page'], $result->data->body->bytes());
            self::assertSame($bytes, $result->data->citation->spans()[0]->locator->toWire()->get('byteEnd'));
            self::assertSame('compact', $result->data->profile);
            $fewTokens = D\SelectionLimits::profile('compact', maxTokens: $tokens - 1, tokenizer: $identity);
            $this->failure($f->read($f->request($target, $fewTokens)), D\Outcome::Incompatible, 'limit_exceeded');
            $fewBytes = D\SelectionLimits::profile('compact', maxBytes: $bytes - 1, maxBytesPerFragment: $bytes - 1);
            $this->failure($f->read($f->request($target, $fewBytes)), D\Outcome::Incompatible, 'limit_exceeded');
            $fewFragmentBytes = D\SelectionLimits::profile('compact', maxBytesPerFragment: $bytes - 1);
            $this->failure($f->read($f->request($target, $fewFragmentBytes)), D\Outcome::Incompatible, 'limit_exceeded');
            $this->failure($f->read($f->request($target, maxBytes: $bytes - 1)), D\Outcome::Incompatible, 'limit_exceeded');
            $this->failure($f->read($f->request(R\ReadTarget::asset(new D\LocalId('a1')), D\SelectionLimits::profile('visual', maxAssets: 0))),
                D\Outcome::Incompatible, 'limit_exceeded');
            $f->service = $f->service();
            $this->failure($f->read($f->request($target, $limits)), D\Outcome::Incompatible, 'tokenizer_required');
            self::assertSame(1, $limits->maxFragments);
            try {
                D\SelectionLimits::profile('compact', maxFragments: 0);
                self::fail('Zero fragment limit accepted');
            } catch (D\DomainFailure $error) {
                self::assertSame('limit_exceeded', $error->errorCode);
            }
        });
    }

    public function testUnknownDeniedUnavailableAndIncompatibleStayDistinctAndNeverLeakBody(): void
    {
        Fixture::run(function (Fixture $f): void {
            $host = $f->upstream->host;
            $host->decision = D\Decision::Unknown;
            $unknown = $f->read();
            self::assertSame(D\Outcome::Unknown, $unknown->outcome);
            self::assertNull($unknown->data);
            $host->decision = D\Decision::Allow;
            $request = $f->request(etag: new D\Sha256(str_repeat('0', 64)));
            $this->failure($f->read($request), D\Outcome::Incompatible, 'etag_mismatch');
            $identity = $host->identity;
            $s = $f->scope();
            $foreign = new D\Scope($s->tenantId, $s->kbId, $s->actorId, $s->purpose, $s->policyEpoch, new D\Sha256(str_repeat('b', 64)));
            $host->identity = new PolicyContext($foreign, $identity->audience, $identity->grants);
            $this->failure($f->read(), D\Outcome::Denied, 'scope_mismatch');
            $host->identity = $identity;
            $otherTenant = new D\Scope(new D\TenantId(Fixture::id(2)), $s->kbId, $s->actorId, $s->purpose, $s->policyEpoch, $s->hostScopeHash);
            $host->identity = new PolicyContext($otherTenant, $identity->audience, $identity->grants);
            $this->failure($f->read(), D\Outcome::Unknown, 'citation_unknown');
            $host->identity = $identity;
            unset($f->upstream->objects->bodies[$f->chunk->revisionRef->irDigest->value]);
            $this->failure($f->read(), D\Outcome::Unavailable, 'canonical_object_missing');
        });
    }

    public function testUsageAuditContainsExactRefsAndOutputDigestWithoutPlaintext(): void
    {
        Fixture::run(function (Fixture $f): void {
            $request = $f->request(R\ReadTarget::asset(new D\LocalId('a1')));
            $result = $f->read($request);
            self::assertSame(D\Outcome::Success, $result->outcome, $result->errorCode ?? 'success');
            $db = $f->upstream->db();
            $row = $db->row('SELECT exact_version_ref FROM ' . $db->table('usage_refs') . ' WHERE tenant_id=? AND usage_id=?',
                [$f->scope()->tenantId->value, $result->data->usageRef->value]);
            $ref = json_decode($row['exact_version_ref'], true, 512, JSON_THROW_ON_ERROR)['fragmentRefs'][0];
            self::assertSame($result->data->body->digest->value, $ref['fragmentDigest']);
            self::assertSame($f->chunk->chunkId->value, $ref['chunkRef']['chunkId']);
            $audit = $db->row('SELECT response_blob FROM ' . $db->table('idempotency_receipts') . ' WHERE tenant_id=? AND key=?',
                [$f->scope()->tenantId->value, 'reading:' . $request->attemptId->value]);
            self::assertStringNotContainsString(Fixture::corpus()['asset'], $audit['response_blob']);
            self::assertSame($request->digest()->value, json_decode($audit['response_blob'], true)['requestDigest']);
            $event = $db->row('SELECT event_json FROM ' . $db->table('outbox') . ' WHERE tenant_id=? AND id=?',
                [$f->scope()->tenantId->value, $result->data->usageRef->value]);
            self::assertStringNotContainsString(Fixture::corpus()['page'], $event['event_json']);
            $altered = new R\ReadRequest($request->citationId, $request->attemptId, R\ReadTarget::source(), $request->limits,
                $request->maxBytes, $request->expiresAt, runRef: $request->runRef);
            $this->failure($f->read($altered), D\Outcome::Denied, 'attempt_conflict');
        });
    }

    private function failure(D\Result $result, D\Outcome $outcome, string $code): void
    {
        self::assertSame($outcome, $result->outcome, $result->errorCode ?? 'unexpected success');
        self::assertSame($code, $result->errorCode);
        self::assertNull($result->data);
    }
}
