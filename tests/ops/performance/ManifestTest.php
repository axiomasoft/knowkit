<?php

declare(strict_types=1);

namespace KnowKit\Tests\Ops\Performance;

use KnowKit\Adapter\Lexical\SealedManifest;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\HashDomain;
use KnowKit\Domain\Contract\Jcs;
use KnowKit\Tests\Contract\Ports\PortFixture;
use KnowKit\Tests\Retrieval\Disclosure\Fixture as Disclosure;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/retrieval/disclosure/bootstrap.php';

#[Group('ops:performance')]
final class ManifestTest extends TestCase
{
    public function testValidationCacheRejectsChangedBytesOrDeclaredIdentityAndCannotPoisonValidEntry(): void
    {
        Disclosure::run(function (Disclosure $f): void {
            $manifest = $f->lexical->manifests[1];
            $digest = HashDomain::IndexManifest->digest($manifest->toWire());
            $cache = new SealedManifest();
            $wire = $cache->load(bytes: $manifest->canonical(), expectedDigest: $digest, validator: PortFixture::validator());
            foreach ([[$manifest->canonical(), str_repeat('f', 64)],
                [Jcs::canonicalize($wire->with('kbId', PortFixture::id(999))), $digest]] as [$bytes, $declared]) {
                try {
                    $cache->load(bytes: $bytes, expectedDigest: $declared, validator: PortFixture::validator());
                    self::fail('Changed immutable manifest identity accepted');
                } catch (D\DomainFailure $failure) {
                    self::assertSame(D\Outcome::Incompatible, $failure->outcome);
                    self::assertSame('manifest_mismatch', $failure->errorCode);
                }
            }
            self::assertSame($manifest->canonical(), Jcs::canonicalize($cache->load(
                bytes: $manifest->canonical(), expectedDigest: $digest, validator: PortFixture::validator(),
            )));
        });
    }

    public function testCachedManifestCannotAuthorizeDisclosureAfterCommittedSourceRevocation(): void
    {
        Disclosure::run(function (Disclosure $f): void {
            $selected = $f->select();
            $f->revoke();
            $outcome = $f->disclose($selected);
            self::assertSame(D\Outcome::Denied, $outcome->result->outcome);
            self::assertNull($outcome->result->data);
            self::assertSame(0, $f->lexical->catalog->count('evidence_usages'));
        });
    }
}
