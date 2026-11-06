<?php
declare(strict_types=1);

namespace KnowKit\Port;

use KnowKit\Domain\{TokenizerCapabilities, TokenizerIdentity};

/** D5: exact UTF-8 count for the pinned identity; invalid UTF-8/identity rejects. No network or estimation. */
interface Tokenizer
{
    public function capabilities(): TokenizerCapabilities;
    public function count(string $utf8, TokenizerIdentity $identity): int;
}
