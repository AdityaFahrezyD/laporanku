<?php

use App\Models\Category;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;

dataset('plain text inputs', [
    'script tag' => '<script>alert(1)</script>',
    'image event handler' => '<img src=x onerror=alert(1)>',
    'quotes and Indonesian text' => 'Iuran "warga" & \'kas\' < > café',
    'literal entities' => 'Kas &amp; &lt;b&gt; warga',
]);

// JSON preserves text; XSS protection belongs to the HTML rendering context.
test('names and descriptions round trip as plain text through API and cache', function (string $resource, string $text) {
    config(['traffic.transaction_cache_ttl' => 0, 'traffic.reads_per_minute' => 1000]);
    $this->actingAs(User::factory()->create(['role' => 'admin']), 'sanctum');
    $transaction = in_array($resource, ['incomes', 'expenses', 'transfers'], true);
    $key = $resource === 'categories' ? 'category_id' : rtrim($resource, 's').'_id';
    $field = $transaction ? 'description' : 'name';
    $relations = [];

    if ($transaction) {
        $wallet = Wallet::create(['name' => $text, 'type' => 'bank', 'balance' => '100.00', 'is_active' => true]);
        if ($resource === 'transfers') {
            $destination = Wallet::create(['name' => $text, 'type' => 'cash', 'balance' => '0.00', 'is_active' => true]);
            $payload = ['from_wallet_id' => $wallet->getKey(), 'to_wallet_id' => $destination->getKey()];
            $relations = ['transfer_from.name', 'transfer_to.name'];
        } else {
            $category = Category::create(['name' => $text, 'type' => rtrim($resource, 's')]);
            $payload = ['wallet_id' => $wallet->getKey(), 'category_id' => $category->getKey()];
            $relations = [$resource === 'incomes' ? 'income_wallet.name' : 'expense_wallet.name', 'category.name'];
        }
        $payload += ['amount' => '10.00', 'transaction_date' => '15-09-2026 10:30'];
    } else {
        $payload = $resource === 'wallets'
            ? ['type' => 'bank', 'balance' => '100.00', 'is_active' => true]
            : ['type' => 'income'];
    }

    $created = $this->postJson('/api/'.$resource, [...$payload, $field => $text])
        ->assertCreated()->assertHeader('Content-Type', 'application/json')
        ->assertJsonPath('data.'.$field, $text);
    $id = $created->json('data.'.$key);
    $this->assertDatabaseHas($resource, [$key => $id, $field => $text]);

    // Resave the value returned by the API twice to detect repeated encoding.
    $value = $text.' revisi';
    for ($i = 0; $i < 2; $i++) {
        $updated = $this->patchJson("/api/$resource/$id", [$field => $value])
            ->assertOk()->assertJsonPath('data.'.$field, $text.' revisi');
        $value = $updated->json('data.'.$field);
    }
    $this->assertDatabaseHas($resource, [$key => $id, $field => $value]);
    $detail = $this->getJson("/api/$resource/$id")->assertOk()
        ->assertHeader('Content-Type', 'application/json')->assertJsonPath('data.'.$field, $value);
    foreach ($relations as $relation) {
        $detail->assertJsonPath('data.'.$relation, $text);
    }

    $paths = ["/api/$resource" => 'data.0'];
    if ($transaction) {
        $paths["/api/$resource?paginated=1"] = 'data.0';
        $paths['/api/dashboard-summary'] = 'data.latest.0';
    }

    foreach ($paths as $url => $prefix) {
        config(['traffic.transaction_cache_ttl' => 0]);
        $uncached = $this->getJson($url)->assertOk()->assertHeader('Content-Type', 'application/json')
            ->assertJsonPath($prefix.'.'.$field, $value);
        foreach ($relations as $relation) {
            $uncached->assertJsonPath($prefix.'.'.$relation, $text);
        }
        if (! $transaction) {
            continue;
        }

        config(['traffic.transaction_cache_ttl' => 60]);
        $warm = $this->getJson($url)->assertOk();
        expect($warm->getContent())->toBe($uncached->getContent());

        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $cached = $this->getJson($url)->assertOk()->assertHeader('Content-Type', 'application/json')
                ->assertHeader('Cache-Control', 'no-store, private');
            expect($cached->getContent())->toBe($uncached->getContent());
            // Prove this was a cache hit rather than another database list read.
            expect(collect(DB::getQueryLog())->contains(
                fn ($query) => str_contains($query['query'], 'from "'.$resource.'"')
            ))->toBeFalse();
        } finally {
            DB::disableQueryLog();
        }
    }
})->with(['wallets', 'categories', 'incomes', 'expenses', 'transfers'])->with('plain text inputs');
