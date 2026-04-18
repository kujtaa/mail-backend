<?php

$originsEnv = env('CORS_ORIGINS', '');
$origins = array_values(array_filter(array_map('trim', explode(',', $originsEnv))));
if (empty($origins)) {
    $origins = ['http://localhost:5173', 'http://localhost:3000'];
}

return [
    'paths' => ['*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => $origins,
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => true,
];
