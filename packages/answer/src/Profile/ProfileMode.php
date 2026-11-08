<?php

declare(strict_types=1);

namespace KnowKit\Answer\Profile;

enum ProfileMode: string
{
    case Production = 'production';
    case Test = 'test';
}
