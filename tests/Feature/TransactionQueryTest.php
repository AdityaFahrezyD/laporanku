<?php

use App\Models\Expense;
use App\Models\Income;
use App\Models\Transfer;
use App\Models\Wallet;
use App\Support\TransactionCache;
use Carbon\CarbonImmutable;

function queryFixture(string $resource, string $date = '2026-09-14 00:00:00', string $description = 'Gaji', string $amount = '10.01')
{
    $wallet = Wallet::first() ?? Wallet::create(['name' => 'Bank Utama', 'type' => 'bank', 'balance' => '100.00', 'is_active' => true]);
    $model = ['incomes' => Income::class, 'expenses' => Expense::class, 'transfers' => Transfer::class][$resource];

    return $model::create([
        ...($resource === 'transfers' ? ['from_wallet_id' => $wallet->getKey(), 'to_wallet_id' => $wallet->getKey()] : ['wallet_id' => $wallet->getKey()]),
        'transaction_date' => $date, 'description' => $description, 'amount' => $amount,
    ]);
}

test('transaction pages preserve legacy lists and total across pages', function ($resource) {
    for ($i = 0; $i < 12; $i++) {
        queryFixture($resource);
    }
    $this->getJson('/api/'.$resource)->assertOk()->assertJsonCount(12, 'data');
    $this->getJson('/api/'.$resource.'?paginated=1')->assertOk()->assertJsonCount(10, 'data')->assertJsonPath('meta.total', 12)->assertJsonPath('summary.total_amount', '120.12');
    $this->getJson('/api/'.$resource.'?paginated=1&page=2')->assertJsonCount(2, 'data')->assertJsonPath('summary.total_amount', '120.12');
    $this->getJson('/api/'.$resource.'?paginated=1&page=99')->assertJsonCount(0, 'data')->assertJsonPath('meta.last_page', 2);
})->with(['incomes', 'expenses', 'transfers']);

test('date boundaries use WIB in either configured storage timezone', function ($timezone) {
    config(['app.timezone' => $timezone]);
    date_default_timezone_set($timezone);
    try {
        $local = fn ($date) => CarbonImmutable::parse($date, 'Asia/Jakarta')->setTimezone($timezone)->format('Y-m-d H:i:s');
        queryFixture('incomes', $local('2026-09-13 23:59:59'));
        queryFixture('incomes', $local('2026-09-14 00:00:00'));
        queryFixture('incomes', $local('2026-09-14 23:59:59'));
        queryFixture('incomes', $local('2026-09-15 00:00:00'));
        $this->getJson('/api/incomes?paginated=1&start_date=2026-09-14&end_date=2026-09-14')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('summary.total_amount', '20.02');
    } finally {
        date_default_timezone_set('UTC');
    }
})->with(['UTC', 'Asia/Jakarta']);

test('search combines dates and literal substring matching across relations', function ($resource) {
    queryFixture($resource, description: 'Bonus 100%_!');
    queryFixture($resource, description: 'Bonus 100xx');
    $prefix = '/api/'.$resource.'?paginated=1';
    $this->getJson($prefix.'&q='.urlencode('%_!'))->assertJsonCount(1, 'data');
    $this->getJson($prefix.'&q=BANK')->assertJsonCount(2, 'data');
    $this->getJson($prefix.'&q=10.01')->assertJsonCount(2, 'data');
    $this->getJson($prefix.'&q=bonus&start_date=2026-10-01&end_date=2026-10-02')->assertJsonCount(0, 'data')->assertJsonPath('summary.total_amount', '0.00');
})->with(['incomes', 'expenses', 'transfers']);

test('invalid query parameters return 422', function ($query) {
    $this->getJson('/api/incomes?paginated=1&'.$query)->assertUnprocessable();
})->with(['page=0', 'per_page=101', 'start_date=2026-09-01', 'end_date=2026-09-01', 'start_date=2026-02-30&end_date=2026-03-01', 'start_date=2026-09-02&end_date=2026-09-01', 'q[]='.'x', 'q='.str_repeat('x', 201)]);

test('summary selects five globally and stable id order', function () {
    foreach (['incomes', 'expenses', 'transfers'] as $resource) {
        for ($i = 0; $i < 6; $i++) {
            queryFixture($resource);
        }
    }
    $this->getJson('/api/dashboard-summary')->assertOk()->assertJsonCount(5, 'data.latest')->assertJsonPath('data.totals.incomes', '60.06')->assertJsonPath('data.counts.expenses', 6)->assertJsonPath('data.totals.balance', '100.00');
    $expected = Income::orderBy('income_id')->pluck('income_id')->all();
    $response = $this->getJson('/api/incomes?paginated=1')->assertOk();
    expect(array_column($response->json('data'), 'income_id'))->toBe($expected);
});

test('cache distinguishes queries and refreshes summary after invalidation', function () {
    config(['traffic.transaction_cache_ttl' => 60]);
    queryFixture('incomes', description: 'First');
    $this->getJson('/api/incomes?paginated=1&q=First')->assertJsonCount(1, 'data');
    $this->getJson('/api/incomes?paginated=1&q=Second')->assertJsonCount(0, 'data');
    $this->getJson('/api/dashboard-summary')->assertJsonPath('data.counts.incomes', 1);
    queryFixture('incomes', description: 'Second');
    // RefreshDatabase holds an outer transaction; explicitly simulate its commit notification.
    TransactionCache::invalidate();
    $this->getJson('/api/dashboard-summary')->assertJsonPath('data.counts.incomes', 2);
    $this->getJson('/api/incomes?paginated=1&q=Second')->assertJsonCount(1, 'data');
});

test('category and destination wallet names are searchable', function () {
    $category = \App\Models\Category::create(['name' => 'Salary Special', 'type' => 'income']);
    $income = queryFixture('incomes');
    $income->update(['category_id' => $category->getKey()]);
    $destination = Wallet::create(['name' => 'Destination Unique', 'type' => 'cash', 'balance' => '0.00', 'is_active' => true]);
    $transfer = queryFixture('transfers');
    $transfer->update(['to_wallet_id' => $destination->getKey()]);
    $this->getJson('/api/incomes?paginated=1&q=SALARY')->assertJsonCount(1, 'data');
    $this->getJson('/api/transfers?paginated=1&q=destination')->assertJsonCount(1, 'data');
});

test('empty summary has decimal zero totals and no recent records', function () {
    $this->getJson('/api/dashboard-summary')->assertOk()->assertJsonPath('data.totals.balance', '0.00')->assertJsonPath('data.totals.incomes', '0.00')->assertJsonCount(0, 'data.latest');
});

test('date index migration can roll back and preserves an existing covering index', function () {
    $migration = require database_path('migrations/2026_09_14_120000_index_transaction_dates.php');
    $migration->down();
    foreach (['incomes', 'expenses', 'transfers'] as $table) {
        expect(\Illuminate\Support\Facades\Schema::hasIndex($table, $table.'_transaction_date_query_idx'))->toBeFalse();
    }
    \Illuminate\Support\Facades\Schema::table('incomes', fn ($table) => $table->index(['transaction_date', 'income_id'], 'existing_date_index'));
    $migration->up();
    expect(\Illuminate\Support\Facades\Schema::hasIndex('incomes', 'incomes_transaction_date_query_idx'))->toBeFalse();
    expect(\Illuminate\Support\Facades\Schema::hasIndex('expenses', 'expenses_transaction_date_query_idx'))->toBeTrue();
    $migration->down();
    expect(\Illuminate\Support\Facades\Schema::hasIndex('incomes', 'existing_date_index'))->toBeTrue();
});
