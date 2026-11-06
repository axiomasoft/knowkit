<?php
declare(strict_types=1);

namespace KnowKit\Registry;

use KnowKit\Domain\EffectiveProfile;

interface Factory
{
    public function create(EffectiveProfile $profile): object;
}
