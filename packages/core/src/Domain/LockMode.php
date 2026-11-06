<?php
declare(strict_types=1);

namespace KnowKit\Domain;

enum LockMode: string
{
    case Share = 'share';
    case Update = 'update';
}
