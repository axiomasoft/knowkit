<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use KnowKitExample\Edufy\FixtureHost;
use KnowKitExample\Edufy\Sandbox;

$results = [];
foreach (['schoolA', 'schoolB', 'workplace'] as $profile) {
    $results[$profile] = Sandbox::run(static function (FixtureHost $host): \KnowKit\Domain\Contract\JsonObject {
        $request = $host->request();
        $outcome = $host->answer($request);
        $receipt = $host->replies->publish($request, $outcome);
        if ($outcome->answeredContent() === null || $receipt['status'] !== 'published' || $host->transport->sends !== 1) {
            throw new RuntimeException('Fixture walkthrough failed: ' . $outcome->code->value);
        }

        return new \KnowKit\Domain\Contract\JsonObject(['fixture' => true, 'outcome' => $outcome->code->value,
            'citations' => array_map(static fn ($citation) => $citation->toWire(), $outcome->answeredContent()->citations),
            'providerSendCount' => $host->transport->sends,
            'publicationReceipt' => new \KnowKit\Domain\Contract\JsonObject($receipt)]);
    }, $profile);
}

echo \KnowKit\Domain\Contract\Jcs::canonicalize(new \KnowKit\Domain\Contract\JsonObject([
    'fixture' => true, 'qualification' => 'HOST QUALIFICATION PENDING',
    'profiles' => new \KnowKit\Domain\Contract\JsonObject($results),
])) . PHP_EOL;
