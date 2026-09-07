<?php

use App\Models\Expense;
use App\Models\Income;
use App\Models\Transfer;
use App\Models\User;
use App\Models\Wallet;
use App\Support\Money;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

function financeWallet(string $balance = '100.00', bool $active = true): Wallet
{
    return Wallet::create(['name' => 'Bank', 'type' => 'bank', 'balance' => $balance, 'is_active' => $active]);
}

function financePayload(string $kind, Wallet $wallet, ?Wallet $to = null): array
{
    return [
        ...($kind === 'transfers' ? ['from_wallet_id' => $wallet->getKey(), 'to_wallet_id' => $to->getKey()] : ['wallet_id' => $wallet->getKey()]),
        'amount' => '20.25', 'transaction_date' => '08-09-2026 10:30',
        ...($kind === 'incomes' ? ['category' => 'Gaji'] : []),
    ];
}

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => 'admin']);
});

test('all resource actions require an admin', function (string $resource) {
    $id = (string) Str::uuid();
    $actions = [['GET', "/api/$resource"], ['POST', "/api/$resource"], ['GET', "/api/$resource/$id"], ['PUT', "/api/$resource/$id"], ['PATCH', "/api/$resource/$id"], ['DELETE', "/api/$resource/$id"]];
    foreach ($actions as [$method, $url]) {
        $this->json($method, $url)->assertUnauthorized();
    }
    $this->actingAs(User::factory()->create());
    foreach ($actions as [$method, $url]) {
        $this->json($method, $url)->assertForbidden();
    }
    $this->actingAs($this->admin)->getJson("/api/$resource")->assertOk();
    $this->getJson("/api/$resource/$id")->assertNotFound();
})->with(['wallets', 'incomes', 'expenses', 'transfers']);

test('transaction CRUD keeps exact balances and date format', function (string $kind) {
    $this->actingAs($this->admin);
    $wallet = financeWallet();
    $to = financeWallet('0.00');
    $payload = financePayload($kind, $wallet, $to);
    $key = match ($kind) {
        'incomes' => 'income_id', 'expenses' => 'expense_id', 'transfers' => 'transfer_id'
    };
    $result = $this->postJson("/api/$kind", $payload)->assertCreated()->assertJsonPath('data.amount', '20.25')
        ->assertJsonPath('data.transaction_date', '2026-09-08T10:30:00.000000Z');
    $id = $result->json("data.$key");
    expect($wallet->fresh()->balance)->toBe($kind === 'incomes' ? '120.25' : '79.75');
    if ($kind === 'transfers') {
        expect($to->fresh()->balance)->toBe('20.25');
    }
    $this->getJson("/api/$kind/$id")->assertOk();
    $this->getJson("/api/$kind")->assertJsonCount(1, 'data');
    $this->putJson("/api/$kind/$id", ['amount' => '10'])->assertUnprocessable();
    $this->putJson("/api/$kind/$id", [...$payload, 'amount' => '10.10'])->assertOk();
    $this->patchJson("/api/$kind/$id", ['description' => 'Koreksi'])->assertOk()->assertJsonPath('data.amount', '10.10');
    expect($wallet->fresh()->balance)->toBe($kind === 'incomes' ? '110.10' : '89.90');
    $this->deleteJson("/api/$kind/$id")->assertOk();
    expect($wallet->fresh()->balance)->toBe('100.00');
    expect($to->fresh()->balance)->toBe('0.00');
    $this->deleteJson("/api/$kind/$id")->assertNotFound();
})->with(['incomes', 'expenses', 'transfers']);

test('moving income or expense reverses the old wallet', function (string $kind) {
    $this->actingAs($this->admin);
    $old = financeWallet();
    $new = financeWallet();
    $key = $kind === 'incomes' ? 'income_id' : 'expense_id';
    $id = $this->postJson("/api/$kind", financePayload($kind, $old))->assertCreated()->json("data.$key");
    $this->patchJson("/api/$kind/$id", ['wallet_id' => $new->getKey()])->assertOk();
    expect($old->fresh()->balance)->toBe('100.00');
    expect($new->fresh()->balance)->toBe($kind === 'incomes' ? '120.25' : '79.75');
})->with(['incomes', 'expenses']);

test('transfer edits handle overlapping and swapped wallets using net deltas', function () {
    $this->actingAs($this->admin);
    $a = financeWallet('100');
    $b = financeWallet('0');
    $c = financeWallet('0');
    $payload = [...financePayload('transfers', $a, $b), 'amount' => '50'];
    $id = $this->postJson('/api/transfers', $payload)->assertCreated()->json('data.transfer_id');
    $this->patchJson("/api/transfers/$id", ['to_wallet_id' => $a->getKey()])->assertUnprocessable();
    $this->patchJson("/api/transfers/$id", ['to_wallet_id' => $c->getKey()])->assertOk();
    expect($b->fresh()->balance)->toBe('0.00');
    $this->patchJson("/api/transfers/$id", ['from_wallet_id' => $c->getKey(), 'to_wallet_id' => $a->getKey()])->assertUnprocessable();
    expect($a->fresh()->balance)->toBe('50.00');
    expect($c->fresh()->balance)->toBe('50.00');
    $this->deleteJson("/api/transfers/$id")->assertOk();
    expect($a->fresh()->balance)->toBe('100.00');
});

test('insufficient funds and spent income reversal roll back completely', function () {
    $this->actingAs($this->admin);
    $wallet = financeWallet('0');
    $income = $this->postJson('/api/incomes', [...financePayload('incomes', $wallet), 'amount' => '10'])->assertCreated()->json('data.income_id');
    $expense = $this->postJson('/api/expenses', [...financePayload('expenses', $wallet), 'amount' => '10'])->assertCreated()->json('data.expense_id');
    expect($wallet->fresh()->balance)->toBe('0.00');
    $this->postJson('/api/expenses', financePayload('expenses', $wallet))->assertUnprocessable();
    $this->patchJson("/api/incomes/$income", ['amount' => '9.99'])->assertUnprocessable();
    $this->deleteJson("/api/incomes/$income")->assertUnprocessable();
    expect(Income::find($income)->amount)->toBe('10.00');
    expect(Expense::count())->toBe(1);
    $this->deleteJson("/api/expenses/$expense")->assertOk();
    $this->deleteJson("/api/incomes/$income")->assertOk();
    expect($wallet->fresh()->balance)->toBe('0.00');
});

test('spent transfer destination prevents delete and edit without partial writes', function () {
    $this->actingAs($this->admin);
    $a = financeWallet();
    $b = financeWallet('0');
    $id = $this->postJson('/api/transfers', financePayload('transfers', $a, $b))->assertCreated()->json('data.transfer_id');
    $this->postJson('/api/expenses', financePayload('expenses', $b))->assertCreated();
    $this->deleteJson("/api/transfers/$id")->assertUnprocessable();
    $this->patchJson("/api/transfers/$id", ['amount' => '1'])->assertUnprocessable();
    expect($a->fresh()->balance)->toBe('79.75');
    expect($b->fresh()->balance)->toBe('0.00');
    expect(Transfer::find($id)->amount)->toBe('20.25');
});

test('inactive wallets block all transaction mutations but allow history', function (string $kind) {
    $this->actingAs($this->admin);
    $wallet = financeWallet();
    $to = financeWallet();
    $key = match ($kind) {
        'incomes' => 'income_id', 'expenses' => 'expense_id', 'transfers' => 'transfer_id'
    };
    $payload = financePayload($kind, $wallet, $to);
    $id = $this->postJson("/api/$kind", $payload)->assertCreated()->json("data.$key");
    $this->patchJson('/api/wallets/'.$wallet->getKey(), ['is_active' => false])->assertOk();
    $balance = $wallet->fresh()->balance;
    $this->getJson("/api/$kind/$id")->assertOk();
    $this->postJson("/api/$kind", $payload)->assertUnprocessable();
    $this->patchJson("/api/$kind/$id", ['description' => 'edit'])->assertUnprocessable();
    $this->deleteJson("/api/$kind/$id")->assertUnprocessable();
    expect($wallet->fresh()->balance)->toBe($balance);
    $this->patchJson('/api/wallets/'.$wallet->getKey(), ['is_active' => true])->assertOk();
    $this->deleteJson("/api/$kind/$id")->assertOk();
})->with(['incomes', 'expenses', 'transfers']);

test('wallet balances cannot be overwritten and sorting is portable', function () {
    $this->actingAs($this->admin);
    foreach (['cash', 'ewallet', 'bank'] as $type) {
        $this->postJson('/api/wallets', ['name' => $type, 'type' => $type, 'balance' => '0.01', 'is_active' => true])->assertCreated()->assertJsonPath('data.balance', '0.01')->assertJsonPath('data.is_active', true);
    }
    $this->getJson('/api/wallets')->assertJsonPath('data.0.type', 'bank')->assertJsonPath('data.1.type', 'ewallet')->assertJsonPath('data.2.type', 'cash');
    $wallet = Wallet::first();
    foreach (['0', null, ''] as $balance) {
        $this->patchJson('/api/wallets/'.$wallet->getKey(), ['balance' => $balance])->assertUnprocessable();
    }
    $this->putJson('/api/wallets/'.$wallet->getKey(), ['name' => 'New', 'type' => 'bank', 'is_active' => false])->assertOk();
    $this->deleteJson('/api/wallets/'.$wallet->getKey())->assertForbidden();
    expect($wallet->fresh()->balance)->toBe('0.01');
});

test('invalid amounts are rejected without writes', function (mixed $amount) {
    $this->actingAs($this->admin);
    $wallet = financeWallet();
    $this->postJson('/api/incomes', [...financePayload('incomes', $wallet), 'amount' => $amount])->assertUnprocessable();
    expect(Income::count())->toBe(0);
    expect($wallet->fresh()->balance)->toBe('100.00');
})->with(['0', '-1', '1.001', '10000000000000', '1e2', 'NaN', null]);

test('money maximum is exact and balance overflow rolls back', function () {
    expect(Money::decimal(Money::cents('9999999999999.99')))->toBe('9999999999999.99');
    $this->actingAs($this->admin);
    $wallet = financeWallet('0');
    // SQLite NUMERIC uses floating point; full DECIMAL precision is tested on MySQL.
    $this->postJson('/api/incomes', [...financePayload('incomes', $wallet), 'amount' => '9999999999999.00'])->assertCreated();
    expect($wallet->fresh()->balance)->toBe('9999999999999.00');
    $this->postJson('/api/incomes', [...financePayload('incomes', $wallet), 'amount' => '1.00'])->assertUnprocessable();
    expect(Income::count())->toBe(1);
});

test('category uuid and dates are validated', function () {
    $this->actingAs($this->admin);
    $wallet = financeWallet();
    $payload = financePayload('incomes', $wallet);
    foreach ([['category' => null], ['category' => str_repeat('a', 101)], ['wallet_id' => 'bad'], ['wallet_id' => (string) Str::uuid()], ['transaction_date' => '31-02-2026 10:30']] as $invalid) {
        $this->postJson('/api/incomes', [...$payload, ...$invalid])->assertUnprocessable();
    }
});

test('deleting transactions cleans attachment records', function (string $kind) {
    $this->actingAs($this->admin);
    $wallet = financeWallet();
    $to = financeWallet();
    $this->postJson("/api/$kind", financePayload($kind, $wallet, $to))->assertCreated();
    [$model, $relation] = match ($kind) {
        'incomes' => [Income::first(), 'incomeAttachments'],
        'expenses' => [Expense::first(), 'expenseAttachments'],
        'transfers' => [Transfer::first(), 'transferAttachments'],
    };
    $model->{$relation}()->create(['file_path' => 'private/example.pdf']);
    $this->deleteJson("/api/$kind/".$model->getKey())->assertOk();
    $this->assertDatabaseCount('attachments', 0);
})->with(['incomes', 'expenses', 'transfers']);

test('interactive command creates admin without default credentials', function () {
    $this->artisan('app:create-admin')->expectsQuestion('Nama', 'Owner')->expectsQuestion('Username', 'owner')
        ->expectsQuestion('Email', 'owner@example.com')->expectsQuestion('Password', 'a-long-secret-password')
        ->expectsQuestion('Konfirmasi password', 'a-long-secret-password')->expectsOutput('Admin berhasil dibuat.')->assertSuccessful();
    $user = User::where('username', 'owner')->firstOrFail();
    expect($user->role)->toBe('admin');
    expect(Hash::check('a-long-secret-password', $user->password))->toBeTrue();
});
