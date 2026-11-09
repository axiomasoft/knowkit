<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

echo json_encode(\KnowKit\Load\Summary::collect(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
