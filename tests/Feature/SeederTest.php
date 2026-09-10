<?php

use App\Models\Category;
use App\Models\Expense;
use App\Models\Income;
use App\Models\Transfer;
use App\Models\User;
use App\Models\Wallet;
use App\Services\IncomeService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Hash;

test('seed creates an admin and consistent public dashboard data', function () {
    $this->seed(DatabaseSeeder::class);

    expect(User::count())->toBe(1)
        ->and(User::first()->role)->toBe('admin')
        ->and(Hash::check('AdminLaporanKu123!', User::first()->password))->toBeTrue()
        ->and(Wallet::count())->toBe(3)
        ->and(Category::count())->toBe(5)
        ->and(Income::count())->toBe(2)
        ->and(Expense::count())->toBe(3)
        ->and(Transfer::count())->toBe(1)
        ->and(Wallet::where('type', 'bank')->first()->balance)->toBe('9025000.00')
        ->and(Wallet::where('type', 'ewallet')->first()->balance)->toBe('565000.00')
        ->and(Wallet::where('type', 'cash')->first()->balance)->toBe('675000.00');

    $this->getJson('/api/incomes')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.category.name', 'Freelance');
    $this->getJson('/api/transfers')->assertOk()->assertJsonPath('data.0.transfer_from.name', 'Bank Utama');
    $this->post('/login', ['email' => 'admin@laporanku.test', 'password' => 'AdminLaporanKu123!'])->assertNoContent();
    $this->getJson('/api/user')->assertOk()->assertJsonPath('role', 'admin');
});

test('rerunning seed restores admin credentials and preserves finance data', function () {
    $other = Wallet::create(['name' => 'Dompet pribadi', 'type' => 'cash', 'balance' => '100.00', 'is_active' => true]);
    $this->seed(DatabaseSeeder::class);
    User::first()->update(['password' => 'ChangedPassword123!']);
    $bank = Wallet::where('type', 'bank')->first();
    $bank->update(['name' => 'Nama baru']);
    app(IncomeService::class)->createIncome(['wallet_id' => $bank->getKey(), 'amount' => '10.25', 'transaction_date' => '11-09-2026 00:00']);

    $this->seed(DatabaseSeeder::class);

    expect(User::count())->toBe(1)
        ->and(Hash::check('AdminLaporanKu123!', User::first()->password))->toBeTrue()
        ->and(Wallet::count())->toBe(4)
        ->and(Income::count())->toBe(3)
        ->and(Expense::count())->toBe(3)
        ->and(Transfer::count())->toBe(1)
        ->and($bank->fresh()->balance)->toBe('9025010.25')
        ->and($bank->fresh()->name)->toBe('Nama baru')
        ->and($other->fresh()->balance)->toBe('100.00');
});

test('partial reserved wallets roll back new admin and leave existing data intact', function () {
    $wallet = new Wallet(['name' => 'Existing', 'type' => 'cash', 'balance' => '5.00', 'is_active' => true]);
    $wallet->wallet_id = '01900000-0000-7000-8000-000000000001';
    $wallet->save();
    expect(fn () => $this->seed(DatabaseSeeder::class))->toThrow(RuntimeException::class);
    expect(User::count())->toBe(0)->and(Wallet::count())->toBe(1)->and($wallet->fresh()->balance)->toBe('5.00');
});
