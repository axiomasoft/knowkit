<?php

declare(strict_types=1);

namespace KnowKit\Tests\Worker\Ocr;

use KnowKit\Application\Access as A;
use KnowKit\Application\Validation\DefaultQualityPolicy;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\Jcs;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Tests\Security\Policy\Clock;
use KnowKit\Tests\Security\Policy\PolicyFixture;
use KnowKit\Tests\Worker\Protocol\Fixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/OcrFixture.php';
require_once Fixture::root() . '/tests/security/policy/bootstrap.php';

#[Group('worker:ocr')]
final class PolicyTest extends TestCase
{
    public function testExternalOcrBytesRequireCurrentAllowAndReadPermissionIsInsufficient(): void
    {
        $clock = new Clock();
        $owner = PolicyFixture::owner($clock);
        $context = A\AccessContext::fromHost(PolicyFixture::host());
        $checks = new A\PolicyChecks($owner, $owner, $clock);
        $checks->prepare($context, PolicyFixture::resources(), D\Operation::Disclose, $owner->binding);
        $bytes = file_get_contents(OcrFixture::corpus() . '/approved-eng.pdf');
        $request = $this->request($bytes);
        $observed = [];
        $provider = static function (array $payload) use (&$observed): string {
            $observed = $payload;

            return 'test-only-provider-spy';
        };
        foreach ([D\Decision::Deny, D\Decision::Unknown] as $decision) {
            $owner->egressDecision = $decision;
            try {
                $checks->transmit($context, $request, [$bytes], static fn (): JsonObject => $owner->binding, $provider);
                self::fail('Bytes escaped without an Allow');
            } catch (D\DomainFailure $failure) {
                self::assertSame($decision === D\Decision::Deny ? D\Outcome::Denied : D\Outcome::Unknown, $failure->outcome);
            }
            self::assertSame([], $observed);
        }
        $owner->egressDecision = D\Decision::Allow;
        $checks->transmit($context, $request, [$bytes], static fn (): JsonObject => $owner->binding, $provider);
        self::assertSame([$bytes], $observed);
    }

    private function request(string $bytes): D\EgressRequest
    {
        $items = [new JsonObject([
            'category' => 'ASSET',
            'contentDigest' => hash('sha256', $bytes),
            'sourceVersionRefs' => [PolicyFixture::source()->toWire()->with('sourceDigest', hash('sha256', $bytes))],
        ])];

        return new D\EgressRequest(new JsonObject([
            'operation' => 'reprocess',
            'destination' => new JsonObject([
                'providerId' => 'optional-ocr-test-spy',
                'accountId' => 'test',
                'region' => 'local',
                'modelId' => 'no-external-adapter-implemented',
            ]),
            'items' => $items,
            'manifestDigest' => hash('sha256', Jcs::canonicalize($items)),
        ]), Fixture::validator());
    }

    public function testPartialUnknownAndLowConfidenceRemainSubjectToQualityPolicy(): void
    {
        $policy = new DefaultQualityPolicy();
        foreach (['mixed', 'rotated', 'unreadable', 'wrong-language'] as $name) {
            $coverage = new D\Coverage(OcrFixture::document($name)->toWire()->get('coverage'));
            self::assertSame(D\Decision::Deny, $policy->evaluate($coverage, new D\CoveragePolicy())->decision);
            self::assertSame(D\Decision::Allow, $policy->evaluate($coverage, new D\CoveragePolicy(true))->decision);
            self::assertSame(D\Decision::Deny, $policy->evaluate($coverage, new D\CoveragePolicy(true, ['ocr']))->decision);
        }
        $wire = OcrFixture::document('approved-eng')->toWire()->get('coverage');
        self::assertSame(D\Decision::Allow, $policy->evaluate(new D\Coverage($wire), new D\CoveragePolicy())->decision);
        $unknown = $wire->with('state', 'unknown')->with('counts', $wire->get('counts')->without('expected'));
        self::assertSame(D\Decision::Unknown, $policy->evaluate(new D\Coverage($unknown), new D\CoveragePolicy())->decision);
        $low = $wire->with('diagnostics', [new JsonObject(['code' => 'ocr_low_confidence', 'severity' => 'warning'])]);
        self::assertSame(D\Decision::Deny, $policy->evaluate(new D\Coverage($low), new D\CoveragePolicy())->decision);
    }
}
