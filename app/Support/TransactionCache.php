<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class TransactionCache
{
    private const VERSION_KEY = 'transaction-lists:version';

    public static function version(): string
    {
        Cache::add(self::VERSION_KEY, (string) Str::uuid(), now()->addDays(7));

        return (string) Cache::get(self::VERSION_KEY);
    }

    public static function invalidate(): void
    {
        // Rotating the namespace also prevents an in-flight old read from
        // repopulating the current cache after a committed write.
        Cache::put(self::VERSION_KEY, (string) Str::uuid(), now()->addDays(7));
    }
}
