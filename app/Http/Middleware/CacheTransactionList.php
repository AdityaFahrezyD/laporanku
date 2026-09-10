<?php

namespace App\Http\Middleware;

use App\Support\TransactionCache;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class CacheTransactionList
{
    public function handle(Request $request, Closure $next): Response
    {
        $ttl = config('traffic.transaction_cache_ttl');
        if ($ttl <= 0 || ! $request->isMethod('GET')) {
            return $next($request);
        }

        $user = $request->user('sanctum');
        $scope = $user ? [$user->getAuthIdentifier(), $user->role] : ['guest'];
        $key = 'transaction-lists:'.TransactionCache::version().':'.hash('sha256', json_encode([
            $scope, $request->route()->getName(), $request->query(),
        ]));

        // Store JSON strings: no Eloquent objects need to be unserialized.
        $cached = Cache::get($key);
        if (is_string($cached)) {
            return response($cached, 200, ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store, private']);
        }

        $response = $next($request);
        if ($response->getStatusCode() === 200) {
            Cache::put($key, $response->getContent(), $ttl);
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }
}
