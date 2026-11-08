<?php

declare(strict_types=1);

return [
    // Canonical host-profile:1 JSON; credentials are references, never literal secrets.
    'profile' => null,
    'credentials' => [],
    'http' => ['enabled' => false, 'registrar' => null],
    'migrations' => ['publish' => false],
    'commands' => ['enabled' => false],
    'jobs' => ['enabled' => false],
];
