<?php

declare(strict_types=1);

namespace KnowKit\Tests\Worker\Protocol;

use KnowKit\Adapter\ProcessWorker\WorkerFiles;
use KnowKit\Adapter\ProcessWorker\WorkerIngressBundleValidator;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Fingerprint;
use KnowKit\Domain\ParserProfile;
use KnowKit\Domain\ParserSignature;
use KnowKit\Domain\ProcessingIdentity;
use KnowKit\Domain\SourceDigest;
use KnowKit\Domain\SourceVersionId;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

#[Group('worker:protocol')]
final class IdentityTest extends TestCase
{
    public function testReuseRequiresFullSemanticIdentityAndValidatedOutput(): void
    {
        $base = $this->processing('1.0.0', 'en');
        self::assertSame($base->digest()->value, $this->processing('1.0.0', 'en')->digest()->value);
        self::assertSame([], $base->changedAxes($this->processing('1.0.0', 'en')));
        foreach ([$this->processing('1.0.1', 'en'), $this->processing('1.0.0', 'ru')] as $changed) {
            self::assertNotSame($base->digest()->value, $changed->digest()->value);
            self::assertSame(['documentRevisionId', 'chunkSetId', 'generationId'], $base->changedAxes($changed));
        }
        $directory = Fixture::temporary();
        Fixture::output($directory . '/output');
        try {
            $validator = new WorkerIngressBundleValidator(Fixture::validator());
            $first = $validator->verify(Fixture::job(), $directory . '/output');
            $second = $validator->verify(Fixture::job(), $directory . '/output');
            self::assertSame($first->result->irDigest(), $second->result->irDigest());
        } finally {
            WorkerFiles::remove($directory);
        }
    }

    public function testTemporaryStagingAndHandlesCannotBecomeSemanticIdentity(): void
    {
        $first = Fixture::job();
        $second = Fixture::job(changes: ['inputPath' => '/input/transient-123.bin']);
        self::assertSame($first->toWire()->get('profileFingerprint'), $second->toWire()->get('profileFingerprint'));
        foreach (['credentialHandle', 'temporaryPath', 'apiKey'] as $key) {
            try {
                new ParserProfile('fixture-v1', 'synthetic', '1.0.0', new JsonObject([$key => 'SECRET_CANARY']));
                self::fail('transient setting fingerprinted');
            } catch (\InvalidArgumentException $failure) {
                self::assertStringNotContainsString('SECRET_CANARY', $failure->getMessage());
            }
        }
        $wire = Fixture::wire('result');
        self::assertStringNotContainsString('/input', json_encode(iterator_to_array($wire)));
        self::assertFalse($wire->has('credentials'));
        self::assertFalse($wire->has('leaseToken'));
    }

    private function processing(string $parserRevision, string $language): ProcessingIdentity
    {
        $parser = new ParserProfile('fixture-v1', 'synthetic', $parserRevision,
            new JsonObject(['revision' => $parserRevision, 'fixture' => true]));
        $ocr = new ParserProfile('ocr-fixture-v1', 'synthetic-ocr', '1.0.0',
            new JsonObject(['language' => $language, 'modelHash' => str_repeat('a', 64)]));
        $chunker = new ParserSignature('fixture-chunker', '1.0.0', new Fingerprint(str_repeat('b', 64)));
        return new ProcessingIdentity(new SourceVersionId(Fixture::wire('envelope')->get('sourceVersionId')),
            new SourceDigest(Fixture::wire('envelope')->get('inputDigest')), $parser->signature,
            $ocr->signature, $chunker, 'ir-1.0', new Fingerprint(str_repeat('c', 64)), null);
    }
}
