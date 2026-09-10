<?php

namespace App\Observers;

use App\Support\TransactionCache;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class TransactionCacheObserver implements ShouldHandleEventsAfterCommit
{
    public function saved(object $model): void
    {
        TransactionCache::invalidate();
    }

    public function deleted(object $model): void
    {
        TransactionCache::invalidate();
    }
}
