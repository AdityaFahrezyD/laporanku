<?php

use App\Models\Category;
use App\Models\Income;
use App\Models\User;
use App\Models\Wallet;
use App\Services\IncomeService;
use App\Support\Money;
use App\Support\TransactionCache;
use Illuminate\Support\Facades\DB;

function adminFeeWallet(string $balance = '100.00'): Wallet
{
    return Wallet::create(['name' => 'Bank', 'type' => 'bank', 'balance' => $balance, 'is_active' => true]);
}

function adminFeePayload(string $resource, Wallet $wallet, Wallet $to): array
{
    return [
        ...($resource === 'transfers' ? ['from_wallet_id' => $wallet->getKey(), 'to_wallet_id' => $to->getKey()] : ['wallet_id' => $wallet->getKey()]),
        'amount' => '20.25', 'admin_fee' => '2.50', 'transaction_date' => '22-09-2026 10:30',
    ];
}

beforeEach(function () {
    $this->actingAs(User::factory()->create(['role' => 'admin']));
});

test('admin fees round trip through CRUD and legacy updates retain the fee', function (string $resource) {
    $wallet = adminFeeWallet();
    $to = adminFeeWallet('0');
    $payload = adminFeePayload($resource, $wallet, $to);
    $key = rtrim($resource, 's').'_id';
    $id = $this->postJson('/api/'.$resource, $payload)->assertCreated()
        ->assertJsonPath('data.admin_fee', '2.50')->json('data.'.$key);
    $url = '/api/'.$resource.'/'.$id;
    expect($wallet->fresh()->balance)->toBe($resource === 'incomes' ? '117.75' : '77.25');
    expect($to->fresh()->balance)->toBe($resource === 'transfers' ? '20.25' : '0.00');
    $this->getJson($url)->assertOk()->assertJsonPath('data.admin_fee', '2.50');
    $this->getJson('/api/'.$resource)->assertOk()->assertJsonPath('data.0.admin_fee', '2.50');
    $this->getJson('/api/'.$resource.'?paginated=1')->assertOk()->assertJsonPath('data.0.admin_fee', '2.50');
    $this->getJson('/api/dashboard-summary')->assertOk()->assertJsonPath('data.latest.0.admin_fee', '2.50');

    $this->patchJson($url, ['admin_fee' => '1.25'])->assertOk()->assertJsonPath('data.admin_fee', '1.25');
    unset($payload['admin_fee']);
    $this->putJson($url, [...$payload, 'amount' => '30.10'])->assertOk()->assertJsonPath('data.admin_fee', '1.25');
    $this->patchJson($url, ['description' => 'Legacy client'])->assertOk()->assertJsonPath('data.admin_fee', '1.25');
    expect($wallet->fresh()->balance)->toBe($resource === 'incomes' ? '128.85' : '68.65');
    $this->patchJson($url, ['admin_fee' => 0])->assertOk()->assertJsonPath('data.admin_fee', '0.00');
    expect($wallet->fresh()->balance)->toBe($resource === 'incomes' ? '130.10' : '69.90');
    $this->patchJson($url, ['admin_fee' => '1.25'])->assertOk();
    $this->deleteJson($url)->assertOk();
    expect($wallet->fresh()->balance)->toBe('100.00');
    expect($to->fresh()->balance)->toBe('0.00');
    $this->postJson('/api/'.$resource, $payload)->assertCreated()->assertJsonPath('data.admin_fee', '0.00');
})->with(['incomes', 'expenses', 'transfers']);

test('moving a transaction reverses its original fee and charges the new wallet', function (string $resource) {
    $old = adminFeeWallet();
    $new = adminFeeWallet();
    $to = adminFeeWallet('0');
    $payload = adminFeePayload($resource, $old, $to);
    $id = $this->postJson('/api/'.$resource, $payload)->assertCreated()->json('data.'.rtrim($resource, 's').'_id');
    $url = '/api/'.$resource.'/'.$id;
    $this->patchJson($url, [($resource === 'transfers' ? 'from_wallet_id' : 'wallet_id') => $new->getKey()])->assertOk();
    expect($old->fresh()->balance)->toBe('100.00');
    expect($new->fresh()->balance)->toBe($resource === 'incomes' ? '117.75' : '77.25');
    if ($resource === 'transfers') {
        // The old source becomes the destination; overlapping wallets still use net deltas.
        $this->patchJson($url, ['to_wallet_id' => $old->getKey()])->assertOk();
        expect($old->fresh()->balance)->toBe('120.25');
        expect($to->fresh()->balance)->toBe('0.00');
    }
    $this->deleteJson($url)->assertOk();
    expect($old->fresh()->balance)->toBe('100.00');
    expect($new->fresh()->balance)->toBe('100.00');
    expect($to->fresh()->balance)->toBe('0.00');
})->with(['incomes', 'expenses', 'transfers']);

test('invalid admin fees never write transaction or wallet changes', function (string $resource, mixed $fee) {
    $wallet = adminFeeWallet();
    $to = adminFeeWallet('0');
    $payload = adminFeePayload($resource, $wallet, $to);
    $this->postJson('/api/'.$resource, [...$payload, 'admin_fee' => $fee])
        ->assertUnprocessable()->assertJsonValidationErrors('admin_fee');
    $this->assertDatabaseCount($resource, 0);
    expect($wallet->fresh()->balance)->toBe('100.00');
    $id = $this->postJson('/api/'.$resource, $payload)->assertCreated()->json('data.'.rtrim($resource, 's').'_id');
    $balance = $wallet->fresh()->balance;
    $this->patchJson('/api/'.$resource.'/'.$id, ['admin_fee' => $fee])
        ->assertUnprocessable()->assertJsonValidationErrors('admin_fee');
    expect($wallet->fresh()->balance)->toBe($balance);
    $this->getJson('/api/'.$resource.'/'.$id)->assertJsonPath('data.admin_fee', '2.50');
})->with(['incomes', 'expenses', 'transfers'])
    ->with(['negative' => '-1', 'precision' => '1.001', 'overflow' => '10000000000000', 'scientific' => '1e2', 'thousands' => '1,000.00', 'Indonesian' => '1.000,00', 'null' => null, 'empty' => '', 'array' => [[]]]);

test('income fee limit uses merged values and permits a zero net income', function () {
    $wallet = adminFeeWallet();
    $payload = adminFeePayload('incomes', $wallet, $wallet);
    $this->postJson('/api/incomes', [...$payload, 'admin_fee' => '20.26'])->assertUnprocessable()->assertJsonValidationErrors('admin_fee');
    $id = $this->postJson('/api/incomes', [...$payload, 'admin_fee' => '20.25'])->assertCreated()->json('data.income_id');
    expect($wallet->fresh()->balance)->toBe('100.00');
    $this->patchJson('/api/incomes/'.$id, ['amount' => '20.24'])->assertUnprocessable()->assertJsonValidationErrors('admin_fee');
    $this->patchJson('/api/incomes/'.$id, ['admin_fee' => '20.26'])->assertUnprocessable()->assertJsonValidationErrors('admin_fee');
    $this->patchJson('/api/incomes/'.$id, ['amount' => '10.10', 'admin_fee' => '0.01'])->assertOk();
    expect($wallet->fresh()->balance)->toBe('110.09');
    $this->deleteJson('/api/incomes/'.$id)->assertOk();
    expect($wallet->fresh()->balance)->toBe('100.00');
});

test('fees cannot overdraw the source and failed edits leave all balances intact', function (string $resource) {
    $wallet = adminFeeWallet('20.25');
    $to = adminFeeWallet('0');
    $payload = adminFeePayload($resource, $wallet, $to);
    $version = TransactionCache::version();
    $this->postJson('/api/'.$resource, $payload)->assertUnprocessable();
    expect(TransactionCache::version())->toBe($version);
    expect($wallet->fresh()->balance)->toBe('20.25');
    expect($to->fresh()->balance)->toBe('0.00');
    $this->assertDatabaseCount($resource, 0);
    $id = $this->postJson('/api/'.$resource, [...$payload, 'amount' => '10'])->assertCreated()->json('data.'.rtrim($resource, 's').'_id');
    $version = TransactionCache::version();
    $this->patchJson('/api/'.$resource.'/'.$id, ['admin_fee' => '10.26'])->assertUnprocessable();
    expect(TransactionCache::version())->toBe($version);
    expect($wallet->fresh()->balance)->toBe('7.75');
    expect($to->fresh()->balance)->toBe($resource === 'transfers' ? '10.00' : '0.00');
    $this->getJson('/api/'.$resource.'/'.$id)->assertJsonPath('data.admin_fee', '2.50');
})->with(['expenses', 'transfers']);

test('spent income blocks fee increases and deletion without partial writes', function () {
    $wallet = adminFeeWallet('0');
    $payload = adminFeePayload('incomes', $wallet, $wallet);
    $id = $this->postJson('/api/incomes', $payload)->assertCreated()->json('data.income_id');
    $this->postJson('/api/expenses', [...$payload, 'amount' => '17.75', 'admin_fee' => 0])->assertCreated();
    $this->patchJson('/api/incomes/'.$id, ['admin_fee' => '2.51'])->assertUnprocessable();
    $this->deleteJson('/api/incomes/'.$id)->assertUnprocessable();
    expect($wallet->fresh()->balance)->toBe('0.00');
    expect(Income::findOrFail($id)->admin_fee)->toBe('2.50');
});

test('maximum fee stays exact and fee removal cannot overflow a wallet', function () {
    expect(Money::decimal(Money::cents('9999999999999.99', 'admin_fee', true)))->toBe('9999999999999.99');
    // Whole values at this magnitude avoid SQLite floating point rounding.
    $wallet = adminFeeWallet('9999999999999.00');
    $payload = adminFeePayload('incomes', $wallet, $wallet);
    $id = $this->postJson('/api/incomes', [...$payload, 'amount' => '9999999999999.00', 'admin_fee' => '9999999999999.00'])
        ->assertCreated()->assertJsonPath('data.admin_fee', '9999999999999.00')->json('data.income_id');
    $this->patchJson('/api/incomes/'.$id, ['admin_fee' => '9999999999998.00'])->assertUnprocessable();
    expect($wallet->fresh()->balance)->toBe('9999999999999.00');
});

test('dashboard includes all fees once and committed mutations refresh cached responses', function () {
    config(['traffic.transaction_cache_ttl' => 60]);
    $wallet = adminFeeWallet();
    $to = adminFeeWallet('0');
    $this->getJson('/api/dashboard-summary')->assertJsonPath('data.totals.admin_fees', '0.00');
    $ids = [];
    foreach (['incomes', 'expenses', 'transfers'] as $resource) {
        $ids[$resource] = $this->postJson('/api/'.$resource, adminFeePayload($resource, $wallet, $to))
            ->assertCreated()->json('data.'.rtrim($resource, 's').'_id');
    }
    $this->getJson('/api/dashboard-summary')->assertOk()
        ->assertJsonPath('data.totals.incomes', '20.25')->assertJsonPath('data.totals.expenses', '27.75')
        ->assertJsonPath('data.totals.transfers', '20.25')->assertJsonPath('data.totals.admin_fees', '7.50')
        ->assertJsonPath('data.totals.balance', '92.50')->assertJsonPath('data.counts.expenses', 1)->assertJsonCount(3, 'data.latest');
    $this->getJson('/api/transfers?paginated=1')->assertJsonPath('summary.total_admin_fee', '2.50');
    $this->getJson('/api/transfers')->assertJsonPath('data.0.admin_fee', '2.50');
    $this->patchJson('/api/transfers/'.$ids['transfers'], ['admin_fee' => '1.25'])->assertOk();
    $this->getJson('/api/transfers?paginated=1')->assertJsonPath('summary.total_admin_fee', '1.25');
    $this->getJson('/api/transfers')->assertJsonPath('data.0.admin_fee', '1.25');
    $this->getJson('/api/dashboard-summary')->assertJsonPath('data.totals.admin_fees', '6.25')->assertJsonPath('data.totals.expenses', '26.50');
    $this->deleteJson('/api/incomes/'.$ids['incomes'])->assertOk();
    $this->getJson('/api/dashboard-summary')->assertJsonPath('data.totals.admin_fees', '3.75')->assertJsonPath('data.totals.expenses', '24.00');
});

test('filtered fee totals cover all matching pages for every resource', function (string $resource) {
    $wallet = adminFeeWallet('1000');
    $to = adminFeeWallet('0');
    $payload = [...adminFeePayload($resource, $wallet, $to), 'description' => 'Match'];
    $category = $resource !== 'transfers' ? Category::create(['name' => 'Selected', 'type' => rtrim($resource, 's')]) : null;
    if ($category) {
        $payload['category_id'] = $category->getKey();
    }
    for ($i = 0; $i < 12; $i++) {
        $this->postJson('/api/'.$resource, $payload)->assertCreated();
    }
    $this->postJson('/api/'.$resource, [...$payload, 'description' => 'Excluded'])->assertCreated();
    $this->postJson('/api/'.$resource, [...$payload, 'transaction_date' => '01-08-2026 10:30'])->assertCreated();
    if ($category) {
        $this->postJson('/api/'.$resource, [...$payload, 'category_id' => null])->assertCreated();
    }
    $url = '/api/'.$resource.'?paginated=1&q=Match&start_date=2026-09-01&end_date=2026-09-30'.($category ? '&category_id='.$category->getKey() : '');
    $this->getJson($url.'&page=2')->assertOk()->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.total', 12)->assertJsonPath('summary.total_amount', '243.00')->assertJsonPath('summary.total_admin_fee', '30.00');
    $this->getJson($url.'&page=99')->assertJsonCount(0, 'data')->assertJsonPath('summary.total_admin_fee', '30.00');
    $this->getJson('/api/'.$resource.'?paginated=1&q=Missing')->assertJsonPath('summary.total_admin_fee', '0.00');
})->with(['incomes', 'expenses', 'transfers']);

test('rolled back fee edits retain cached totals and stored balances', function () {
    $wallet = adminFeeWallet();
    $record = app(IncomeService::class)->createIncome(adminFeePayload('incomes', $wallet, $wallet));
    $this->getJson('/api/dashboard-summary')->assertJsonPath('data.totals.admin_fees', '2.50');
    $version = TransactionCache::version();
    DB::beginTransaction();
    app(IncomeService::class)->updateIncome($record->getKey(), ['admin_fee' => '1.00']);
    expect(TransactionCache::version())->toBe($version);
    DB::rollBack();
    expect(TransactionCache::version())->toBe($version);
    expect($record->fresh()->admin_fee)->toBe('2.50');
    expect($wallet->fresh()->balance)->toBe('117.75');
    $this->getJson('/api/dashboard-summary')->assertJsonPath('data.totals.admin_fees', '2.50');
});
