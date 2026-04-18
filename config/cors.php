<?php

return [
    'paths' => ['*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => array_values(array_filter(
        array_map('trim', explode(',', env('CORS_ORIGINS', 'http://localhost:5173,http://localhost:3000')))
    )),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => true,
];
