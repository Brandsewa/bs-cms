<?php

declare(strict_types=1);

return [
    'body_limit' => 2097152, // 2 MB
    'trusted_proxies' => [], // array of IP strings or CIDR blocks
    'rate_limit' => [
        'enabled' => true,
        'limit' => 60,
        'window' => 60, // seconds
    ],
];
