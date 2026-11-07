<?php

declare(strict_types=1);

namespace KnowKitExample;

use KnowKit\Adapter\Postgres\Navigation\PdoNavigationStore;
use KnowKit\Adapter\Postgres\Usages\PdoCanonicalDocuments;
use KnowKit\Adapter\Postgres\Usages\PdoUsages;
use KnowKit\Application\Access\PolicyChecks;
use KnowKit\Application\SourceNavigation;
use KnowKit\Domain as D;
use KnowKit\Domain\Contract\Jcs;
use KnowKit\Domain\Contract\JsonObject;
use KnowKit\Domain\Navigation\MatchMode;
use KnowKit\Domain\Navigation\UnitRange;

require_once __DIR__ . '/bootstrap.php';

/** Executable local host example: a new invocation at every delivery boundary rechecks rights. */
function runNavigationExample(Runtime $runtime): JsonObject
{
    $walkthrough = new Walkthrough($runtime);
    $walkthrough->prepare();
    $host = $runtime->host;
    $set = array_values($runtime->text->sets)[0]->chunkSet;
    $revision = $set->revisionRef;
    $usages = new PdoUsages($host->db, $runtime->tx, $runtime->dependencies);
    $store = new PdoNavigationStore($host->db, $runtime->tx, $usages, $host->validator, $host);
    $navigation = new SourceNavigation($host, $host, new PolicyChecks($host, $host, $host), $store,
        new PdoCanonicalDocuments($host->db, $runtime->storage->blobs, $host->validator), $host, $host);
    $scope = $host->scope();
    // The demo owner issues each resource receipt against the current exact resource plan.
    $boundary = static function (callable $read) use ($host, $store, $revision): mixed {
        $host->bindingOverride = $store->plan($host->scope(), $revision)->guards->binding();
        try {
            return $read();
        } finally {
            $host->bindingOverride = null;
        }
    };
    $outline = $boundary(fn () => $navigation->listStructure($scope, $revision, 2, 24));
    $located = $boundary(fn () => $navigation->locateQuote($scope, $revision, $set->chunks[0]->text(), MatchMode::Exact));
    $first = $boundary(fn () => $navigation->readUnits($scope, $revision, UnitRange::segments(1), 8192))->result;
    if ($outline->status !== D\Outcome::Success || $located->status !== 'unique_exact' || $first->outcome !== D\Outcome::Success) {
        throw new \RuntimeException('Navigation example failed: ' . ($outline->errorCode ?? $outline->status->value)
            . '/' . ($located->errorCode ?? $located->status) . '/' . ($first->errorCode ?? $first->outcome->value));
    }
    $walkthrough->require($walkthrough->call('lifecycle revoke', ['sourceId' => $revision->sourceRef->sourceId->value,
        'reason' => 'source_revoked'], 'navigation-revoke', '0'));
    // A previous buffer or location is not permission for a later send. Request a fresh disclosure.
    $second = $navigation->readUnits($host->scope(), $revision, UnitRange::segments(1), 8192)->result;
    $metadata = $navigation->listStructure($host->scope(), $revision, 2, 24);
    if ($second->outcome !== D\Outcome::Denied || $second->data !== null || $metadata->status !== D\Outcome::Denied) {
        throw new \RuntimeException('Fresh authorization assertion failed');
    }

    return new JsonObject(['structure' => $outline->status->value, 'located' => $located->status,
        'firstRead' => $first->outcome->value, 'afterRevoke' => $second->outcome->value,
        'metadataAfterRevoke' => $metadata->status->value, 'usageId' => $first->data->usageRef->value,
        'revisionId' => $revision->documentRevisionId->value]);
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        $result = Sandbox::run(runNavigationExample(...));
        fwrite(STDOUT, Jcs::canonicalize($result) . "\n");
    } catch (\Throwable) {
        fwrite(STDERR, "Navigation example failed; verify the disposable PostgreSQL environment.\n");
        exit(1);
    }
}
