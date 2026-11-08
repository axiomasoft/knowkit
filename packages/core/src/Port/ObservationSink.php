<?php

declare(strict_types=1);

namespace KnowKit\Port;

use KnowKit\Domain\Observability\Observation;

interface ObservationSink
{
    public function emit(Observation $observation): void;
}
