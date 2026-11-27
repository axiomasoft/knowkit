<?php

declare(strict_types=1);

return [
    'default' => 'default',
    // Each instance: name => ['profile' => ?string, 'preset' => string, 'credentials' => array<string, string>, 'http' => ['enabled' => bool, 'registrar' => ?class-string], 'jobs' => ['enabled' => bool]]
    'instances' => [],
    'migrations' => ['publish' => false],
    'commands' => ['enabled' => false],
];
