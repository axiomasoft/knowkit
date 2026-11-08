<?php

declare(strict_types=1);

use KnowKit\Tests\Bridge\UpstreamContract\CaseManifest;
use KnowKit\Tooling\Qa;

require dirname(__DIR__, 3) . '/packages/agentools-bridge/vendor/autoload.php';
require_once dirname(__DIR__, 3) . '/tools/qa.php';
require_once __DIR__ . '/CaseManifest.php';

$temporary = Qa::temporary();
try {
    $qa = new Qa(dirname(__DIR__, 3));
    $manifest = $qa->json('tests/fixtures/check-groups/bridge-upstream-contract.json');
    CaseManifest::assertDefinition($manifest);
    $qa->fixtures($manifest);
    $junit = $temporary . '/result.xml';
    $result = $qa->process(
        argv: ['packages/agentools-bridge/vendor/bin/phpunit', '--configuration', 'phpunit.xml',
            'tests/bridge/upstream-contract/SeamTest.php', '--log-junit', $junit],
        env: ['KNOWKIT_QA_COMPOSER_PROJECT' => 'packages/agentools-bridge'],
    );
    Qa::ensure($result['exit'] === 0, 'Contract tests failed: ' . $result['stdout'] . $result['stderr']);
    $cases = CaseManifest::assertExecuted(cases: Qa::junit($junit), manifest: $manifest);
    fwrite(STDOUT, json_encode(['fixture' => true, 'qualificationTier' => 'contract-scripted',
        'upstream_seam' => 'pending', 'count' => count($cases), 'cases' => $cases,
        'failures' => 0, 'skips' => 0], JSON_THROW_ON_ERROR) . PHP_EOL);
} catch (Throwable $failure) {
    fwrite(STDERR, $failure->getMessage() . PHP_EOL);
    exit(1);
} finally {
    Qa::remove($temporary);
}
