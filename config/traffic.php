<?php

return [
    'login_per_minute' => (int) env('RATE_LIMIT_LOGIN', 5),
    'login_ip_per_minute' => (int) env('RATE_LIMIT_LOGIN_IP', 20),
    'reads_per_minute' => (int) env('RATE_LIMIT_READS', 120),
    'writes_per_minute' => (int) env('RATE_LIMIT_WRITES', 30),
    'transaction_cache_ttl' => (int) env('TRANSACTION_CACHE_TTL', 60),
];
