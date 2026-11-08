<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/tests/evaluation/bootstrap.php';

use KnowKit\Tests\Evaluation\Corpus;
use KnowKit\Tests\Evaluation\Evaluator;

try {
    $options = getopt('', ['corpus:', 'profile:', 'output:']);
    if (count($options) !== 3 || !isset($options['corpus'], $options['profile'], $options['output'])
        || !is_string($options['corpus']) || !is_string($options['profile']) || !is_string($options['output'])) {
        throw new InvalidArgumentException('Usage: php tools/evaluate.php --corpus <manifest> --profile <id> --output <local report>');
    }
    $report = (new Evaluator(new Corpus($options['corpus'])))->run($options['profile']);
    $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    if (file_put_contents($options['output'], $json, LOCK_EX) !== strlen($json)) {
        throw new RuntimeException('Cannot write evaluation report');
    }
    fwrite(STDOUT, json_encode(['qualified' => $report['qualified'], 'datasetSize' => $report['datasetSize']], JSON_THROW_ON_ERROR) . "\n");
    exit($report['qualified'] ? 0 : 1);
} catch (Throwable) {
    // Never echo provider, database credentials, query text or raw exception inputs.
    fwrite(STDERR, "Evaluation failed: invalid corpus/options, unqualified profile, unavailable backend or output failure.\n");
    exit(1);
}
