<?php

use App\Models\Category;
use App\Models\Income;
use App\Models\User;
use App\Models\Wallet;
use App\Services\ExpenseService;
use App\Services\IncomeService;
use App\Support\TransactionCache;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    Cache::flush();
});

test('read quota includes cache hits and HEAD and resets after a minute', function () {
    config(['traffic.reads_per_minute' => 2]);
    $this->getJson('/api/incomes')->assertOk();
    $this->json('HEAD', '/api/incomes')->assertOk();
    $this->getJson('/api/expenses')->assertStatus(429)->assertHeader('Retry-After');
    $this->travel(61)->seconds();
    $this->getJson('/api/incomes')->assertOk();
});

test('guest IP and authenticated user quotas are independent', function () {
    config(['traffic.reads_per_minute' => 1]);
    $this->getJson('/api/incomes')->assertOk();
    $this->getJson('/api/incomes')->assertStatus(429);
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])->getJson('/api/incomes')->assertOk();
    $this->actingAs(User::factory()->create(), 'sanctum')->getJson('/api/incomes')->assertOk();
    $this->getJson('/api/incomes')->assertStatus(429);
    $this->actingAs(User::factory()->create(), 'sanctum')->getJson('/api/incomes')->assertOk();
});

test('write quota is separate from reads and blocks mutations', function () {
    config(['traffic.writes_per_minute' => 1]);
    $this->actingAs(User::factory()->create(['role' => 'admin']));
    $this->postJson('/api/incomes', [])->assertUnprocessable();
    $this->postJson('/api/expenses', [])->assertStatus(429)->assertHeader('Retry-After');
    $this->getJson('/api/incomes')->assertOk();
    expect(Income::count())->toBe(0);
});

test('login limits account and IP even when email changes', function () {
    config(['traffic.login_per_minute' => 1, 'traffic.login_ip_per_minute' => 2]);
    $this->postJson('/login', ['email' => 'one@example.com', 'password' => 'wrong'])->assertUnprocessable();
    $this->postJson('/login', ['email' => 'ONE@example.com', 'password' => 'wrong'])->assertStatus(429);
    $this->postJson('/login', ['email' => 'two@example.com', 'password' => 'wrong'])->assertUnprocessable();
    $this->postJson('/login', ['email' => 'three@example.com', 'password' => 'wrong'])->assertStatus(429)->assertHeader('Retry-After');
});

test('transaction lists cache JSON and expire on the database cache store', function (string $resource) {
    config(['cache.default' => 'database', 'traffic.transaction_cache_ttl' => 2]);
    $this->getJson("/api/$resource")->assertOk()->assertJsonCount(0, 'data');
    DB::enableQueryLog();
    $this->getJson("/api/$resource")->assertOk()->assertJsonCount(0, 'data');
    expect(collect(DB::getQueryLog())->contains(fn ($query) => str_contains($query['query'], 'from "'.$resource.'"')))->toBeFalse();
    DB::flushQueryLog();
    $this->travel(3)->seconds();
    $this->getJson("/api/$resource")->assertOk();
    expect(collect(DB::getQueryLog())->contains(fn ($query) => str_contains($query['query'], 'from "'.$resource.'"')))->toBeTrue();
    DB::disableQueryLog();
})->with(['incomes', 'expenses', 'transfers']);

test('cache is partitioned by user and role', function () {
    $this->getJson('/api/incomes')->assertOk();
    $user = User::factory()->create();
    $this->actingAs($user, 'sanctum');
    DB::enableQueryLog();
    $this->getJson('/api/incomes')->assertOk();
    expect(collect(DB::getQueryLog())->contains(fn ($q) => str_contains($q['query'], 'from "incomes"')))->toBeTrue();
    DB::flushQueryLog();
    $this->getJson('/api/incomes')->assertOk();
    expect(collect(DB::getQueryLog())->contains(fn ($q) => str_contains($q['query'], 'from "incomes"')))->toBeFalse();
    $user->role = 'admin';
    DB::flushQueryLog();
    $this->getJson('/api/incomes')->assertOk();
    expect(collect(DB::getQueryLog())->contains(fn ($q) => str_contains($q['query'], 'from "incomes"')))->toBeTrue();
    DB::disableQueryLog();
});

test('CRUD and related changes invalidate cached lists across users', function () {
    $wallet = Wallet::create(['name' => 'Bank', 'type' => 'bank', 'balance' => '100.00', 'is_active' => true]);
    $admin = User::factory()->create(['role' => 'admin']);
    $reader = User::factory()->create();
    $this->actingAs($reader, 'sanctum')->getJson('/api/incomes')->assertJsonCount(0, 'data');
    $this->actingAs($admin, 'sanctum');
    $id = $this->postJson('/api/incomes', ['wallet_id' => $wallet->getKey(), 'amount' => '10.00', 'transaction_date' => '08-09-2026 10:30'])
        ->assertCreated()->json('data.income_id');
    $this->actingAs($reader, 'sanctum')->getJson('/api/incomes')->assertJsonCount(1, 'data');
    $this->actingAs($admin, 'sanctum')->patchJson('/api/incomes/'.$id, ['description' => 'Updated'])->assertOk();
    $this->actingAs($reader, 'sanctum')->getJson('/api/incomes')->assertJsonPath('data.0.description', 'Updated');
    $wallet->update(['name' => 'Renamed']);
    $this->getJson('/api/incomes')->assertJsonPath('data.0.income_wallet.name', 'Renamed');
    $this->actingAs($admin, 'sanctum')->deleteJson('/api/incomes/'.$id)->assertOk();
    $this->actingAs($reader, 'sanctum')->getJson('/api/incomes')->assertJsonCount(0, 'data');
});

test('rolled back changes do not invalidate cache', function () {
    $version = TransactionCache::version();
    DB::beginTransaction();
    Wallet::create(['name' => 'Temporary', 'type' => 'bank', 'balance' => '0.00', 'is_active' => true]);
    expect(TransactionCache::version())->toBe($version);
    DB::rollBack();
    expect(TransactionCache::version())->toBe($version);
});

test('related category attachment and expense changes refresh income cache', function () {
    $wallet = Wallet::create(['name' => 'Bank', 'type' => 'bank', 'balance' => '100.00', 'is_active' => true]);
    $category = Category::create(['name' => 'Salary', 'type' => 'income']);
    $income = app(IncomeService::class)->createIncome([
        'wallet_id' => $wallet->getKey(), 'category_id' => $category->getKey(),
        'amount' => '10.00', 'transaction_date' => '08-09-2026 10:30',
    ]);
    $this->getJson('/api/incomes')->assertJsonPath('data.0.category.name', 'Salary');
    $category->update(['name' => 'Bonus']);
    $this->getJson('/api/incomes')->assertJsonPath('data.0.category.name', 'Bonus');
    $version = TransactionCache::version();
    $attachment = $income->incomeAttachments()->create(['file_path' => 'receipt.pdf']);
    expect(TransactionCache::version())->not->toBe($version);
    $version = TransactionCache::version();
    $attachment->delete();
    expect(TransactionCache::version())->not->toBe($version);
    app(ExpenseService::class)->createExpense([
        'wallet_id' => $wallet->getKey(), 'amount' => '5.00', 'transaction_date' => '08-09-2026 10:30',
    ]);
    $this->getJson('/api/incomes')->assertJsonPath('data.0.income_wallet.balance', '105.00');
});

test('zero TTL bypasses a previously cached list', function () {
    $this->getJson('/api/incomes')->assertOk();
    config(['traffic.transaction_cache_ttl' => 0]);
    DB::enableQueryLog();
    $this->getJson('/api/incomes')->assertOk();
    expect(collect(DB::getQueryLog())->contains(fn ($q) => str_contains($q['query'], 'from "incomes"')))->toBeTrue();
    DB::disableQueryLog();
});
