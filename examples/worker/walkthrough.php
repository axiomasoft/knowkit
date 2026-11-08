#!/usr/bin/env php
<?php

declare(strict_types=1);

use KnowKit\Domain as D;
use KnowKit\Domain\Contract\Jcs;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Reading\ReadTarget;
use KnowKit\Tests\Worker\E2e\Harness;
use KnowKit\Tests\Worker\E2e\MeasuredRunner;
use KnowKit\Tests\Worker\E2e\Reader;

require_once dirname(__DIR__, 2) . '/tests/worker/e2e/bootstrap.php';

// Executable qualification example: disposable *_test PostgreSQL, qualified local runtime.
// It reuses the E2E composition; it is not a host delivery API or deployment command.
Harness::run(function (Harness $h): void {
    $attempt = $h->attempt();
    $metrics = new MeasuredRunner($h->root . '/stages');
    $accepted = $h->extract($attempt, $metrics);
    $set = $h->pipeline->persist($attempt->lease, $accepted->revision, $accepted->output);
    $request = $h->pipeline->request($attempt->lease, $set);
    $h->pipeline->seal($request);
    $receipt = $h->pipeline->publish($request);
    $reader = new Reader($h->pipeline, $set, $accepted->output->ir, $request, $receipt);
    $selected = $reader->select();
    if ($selected->result->outcome !== D\Outcome::Success) {
        throw new RuntimeException('Walkthrough search did not succeed');
    }
    $disclosed = $reader->disclose($selected);
    if ($disclosed->result->outcome !== D\Outcome::Success) {
        throw new RuntimeException('Walkthrough disclosure did not succeed');
    }
    $wire = $disclosed->result->data->toWire()->get('fragments')[0]->get('citation');
    $citation = new D\Ulid($wire->get('citationId'));
    $asset = $accepted->output->ir->assets()[0];
    $preview = $reader->read($citation, ReadTarget::asset(new D\LocalId($asset->get('assetId'))));
    if ($preview->outcome !== D\Outcome::Success) {
        throw new RuntimeException('Walkthrough preview did not succeed');
    }
    print Jcs::canonicalize(new JsonObject([
        'schemaVersion' => 'worker-walkthrough-1.0',
        'profile' => $attempt->profile->id,
        'sourceDigest' => $attempt->source->ref->sourceDigest->value,
        'irDigest' => $accepted->revision->irDigest->value,
        'coverage' => $accepted->output->result->coverage()->toWire(),
        'chunks' => count($set->chunkSet->chunks),
        'snapshotId' => $receipt->toWire()->get('snapshotId'),
        'citationId' => $citation->value,
        'previewDigest' => $preview->data->body->digest->value,
        'previewBytes' => strlen($preview->data->body->bytes()),
        'sampledMemoryPeakBytes' => $metrics->sampledMemoryPeakBytes,
        'sampledCpuUsec' => $metrics->sampledCpuUsec,
    ])) . "\n";
});
