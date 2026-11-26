<?php

declare(strict_types=1);

namespace KnowKit\Tests\Chatom\Qualification;

use KnowKit\Chatom\KnowKitDurableAgentHandler;
use KnowKit\Tests\Chatom\Contracts\Fixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

require_once __DIR__ . '/bootstrap.php';

#[Group('chatom:qualification')]
final class PinsTest extends TestCase
{
    public function testExactPinsMatchInstalledCandidateAndRejectDriftBeforeBoot(): void
    {
        RuntimePins::requireInstalledProfile();
        $root = dirname(__DIR__, 3);
        $candidate = (new ReflectionClass(KnowKitDurableAgentHandler::class))->getFileName();
        self::assertSame(realpath($root . '/packages/chatom/src/KnowKitDurableAgentHandler.php'), $candidate);
        foreach (['php', 'lock', 'chatom', 'laravel', 'native-tree'] as $mutation) {
            $matrix = NativeHost::matrix();
            match ($mutation) {
                'php' => $matrix['phpVersion'] = '0.0.0',
                'lock' => $matrix['composerLock']['sha256'] = str_repeat('0', 64),
                'chatom' => $matrix['chatom']['reference'] = str_repeat('0', 40),
                'laravel' => $matrix['laravel']['reference'] = str_repeat('0', 40),
                'native-tree' => $matrix['chatom']['installedTreeSha256'] = str_repeat('0', 64),
            };
            try {
                RuntimePins::requireInstalledProfile($matrix);
                self::fail('Changed runtime pin accepted: ' . $mutation);
            } catch (RuntimeException $error) {
                self::assertStringContainsString('no substitution', $error->getMessage());
            }
        }
    }

    public function testReuseReceiptBindsEveryInspectedDonorAndNativeTree(): void
    {
        $receipt = json_decode(file_get_contents(__DIR__ . '/evidence/P7.6-reuse.json'), true, 64, JSON_THROW_ON_ERROR);
        self::assertSame('P7.6', $receipt['item']);
        self::assertCount(3, $receipt['sources']);
        foreach ($receipt['sources'] as $source) {
            self::assertSame(
                $source['sha256'],
                Fixture::inspectedSha256(donor: $source['donor'], relative: $source['path'], sourceUrl: $source['sourceUrl']),
            );
            self::assertSame($source['sha256'], $source['observedSha256']);
            self::assertContains($source['decision'], ['adapt', 'reject']);
            self::assertNotEmpty($source['reason']);
            self::assertNotEmpty($source['license']);
        }
        self::assertSame(RuntimePins::nativeTreeDigest(), $receipt['nativeContracts']['installedTreeSha256']);
    }
}
