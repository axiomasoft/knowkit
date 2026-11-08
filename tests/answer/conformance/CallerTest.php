<?php

declare(strict_types=1);

namespace KnowKit\Tests\Answer\Conformance;

use KnowKit\Answer\Domain as A;
use KnowKit\Tests\Answer\Grounding\GroundingFixture;
use KnowKitExample\GroundedAnswerCaller;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__, 3) . '/examples/plain-php/grounded-answer.php';

#[Group('answer:conformance')]
final class CallerTest extends TestCase
{
    public function testExampleCallerSuppliesProtectedTurnsWithCurrentAttestationWithoutPersistingChat(): void
    {
        $f = new GroundingFixture();
        $request = $f->request();
        $caller = new GroundedAnswerCaller($f->service());
        $turns = $request->priorTurns->turns;
        $invoke = static fn (?A\HistoryReadAttestation $attestation, string $key): A\AnswerOutcome => $caller->answer(
            $request->scope, $request->question, $turns, $attestation, $request->modelBindingRef,
            $request->retrievalProfileRef, new A\InvocationKey($key), $f->token());
        $outcome = $invoke($request->priorTurns->attestation, 'caller:one');
        self::assertSame(A\OutcomeCode::Answered, $outcome->code);
        self::assertTrue($outcome->fixture);
        self::assertSame($turns, $request->priorTurns->turns);
        self::assertStringContainsString($turns[0]->text, $f->lastRequest->bytes);
        self::assertEquals($outcome->toWire(), $invoke($request->priorTurns->attestation, 'caller:one')->toWire());
        self::assertSame(1, $f->sends);
        // A separate host admission cannot silently use protected history without an attestation.
        $fresh = new GroundingFixture();
        $freshCaller = new GroundedAnswerCaller($fresh->service());
        $denied = $freshCaller->answer($request->scope, $request->question, $turns, null,
            $request->modelBindingRef, $request->retrievalProfileRef, new A\InvocationKey('caller:unattested'), $fresh->token());
        self::assertSame(A\OutcomeCode::Denied, $denied->code);
        self::assertSame(0, $fresh->sends);
        self::assertNull($denied->answeredContent());
    }
}
