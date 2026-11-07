<?php

declare(strict_types=1);

use KnowKit\Adapter\Cli\Command;
use KnowKit\Adapter\Cli\Response;
use KnowKit\Domain\Result;

// Synthetic transport double, not an application policy or HTTP conformance claim.
return static function (Command $command): Response {
    echo 'SYNTHETIC_SECRET accidental host diagnostic';

    return Response::result(Result::empty());
};
