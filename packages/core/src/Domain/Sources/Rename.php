<?php
declare(strict_types=1);

namespace KnowKit\Domain\Sources;

use KnowKit\Domain\ValueGuard;

final readonly class Rename
{
    public function __construct(
        public string $oldOriginKey,
        public string $newOriginKey,
        public string $oldPath,
        public string $newPath,
    ) {
        foreach ([$oldOriginKey, $newOriginKey, $oldPath, $newPath] as $value) {
            ValueGuard::text($value, 1024);
        }
        if ($oldOriginKey === $newOriginKey && $oldPath === $newPath) {
            throw new \InvalidArgumentException('A rename must change identity or path');
        }
    }
}
