<?php

declare(strict_types=1);

namespace KnowKit\Answer;

use KnowKit\Answer\Domain\AnswerOutcome;
use KnowKit\Answer\Domain\AnswerRequest;
use KnowKit\Answer\Domain\CancellationToken;

/** One bounded invocation. Implementations own no transcript store or agent loop. */
interface GroundedAnswering
{
    public function answer(AnswerRequest $request, CancellationToken $cancellation): AnswerOutcome;
}
