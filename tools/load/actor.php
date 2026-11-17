<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/Dataset.php';

use KnowKit\Load\Actor;
use KnowKit\Load\Graph;
use KnowKit\Load\Reindex;
use KnowKit\Load\Search;

try {
    $config = unserialize(\KnowKit\Tooling\LocalIO::decode(trim(\KnowKit\Tooling\LocalIO::line(STDIN))), ['allowed_classes' => true]);
    $db = Actor::connect($config);
    Actor::emit(['event' => 'ready', 'backend' => (int) $db->execute('SELECT pg_backend_pid()')->fetchColumn()]);
    if (trim(\KnowKit\Tooling\LocalIO::line(STDIN)) !== 'GO') {
        throw new RuntimeException('Invalid load barrier');
    }
    $data = match ($config['operation']) {
        'search' => Search::client(db: $db, config: $config),
        'graph' => Graph::client(db: $db, config: $config),
        'reindex' => Reindex::client(db: $db, config: $config),
        default => throw new RuntimeException('Unknown load operation'),
    };
    Actor::emit(['event' => 'result', 'data' => $data]);
} catch (Throwable $failure) {
    Actor::emit(['failure' => get_class($failure) . ':' . ($failure instanceof \KnowKit\Domain\DomainFailure
        ? $failure->errorCode : $failure->getCode())]);
    exit(1);
}
