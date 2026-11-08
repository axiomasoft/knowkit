<?php

declare(strict_types=1);

namespace KnowKit\Bridge\AgenTools\Testing\ContractFixture;

enum BoundaryStage: string
{
    case Compilation = 'compilation';
    case Restore = 'restore';
    case BeforeModelIo = 'before-model-io';
    case AfterDurableIntent = 'after-durable-intent';
    case BufferedDelivery = 'buffered-delivery';
    case ActualDelivery = 'actual-delivery';
}
