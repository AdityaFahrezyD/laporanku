<?php

use App\Models\Expense;
use App\Models\Income;
use App\Models\Transfer;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\Schema;

test('initial income migration includes nullable category after rollback and reapply', function () {
    expect(Schema::hasColumn('incomes', 'category_id'))->toBeTrue();
    $expenseMigration = require database_path('migrations/2026_08_25_003113_create_expenses_table.php');
    $expenseMigration->down();
    $migration = require database_path('migrations/2026_08_25_003055_create_incomes_table.php');
    $migration->down();
    expect(Schema::hasTable('incomes'))->toBeFalse();
    expect(Schema::hasTable('categories'))->toBeTrue();
    $categoryMigration = require database_path('migrations/2026_08_25_003045_create_categories_table.php');
    $categoryMigration->down();
    expect(Schema::hasTable('categories'))->toBeFalse();
    $categoryMigration->up();
    $migration->up();
    $expenseMigration->up();
    // Rebuilt initial tables also need the later admin-fee migration.
    Schema::table('transfers', fn ($table) => $table->dropColumn('admin_fee'));
    (require database_path('migrations/2026_09_22_120000_add_admin_fee_to_transactions.php'))->up();
    expect(Schema::hasColumn('incomes', 'category_id'))->toBeTrue();
    expect(Schema::hasColumn('expenses', 'category_id'))->toBeTrue();
    expect(Schema::hasTable('categories'))->toBeTrue();
    $wallet = Wallet::create(['name' => 'Legacy', 'type' => 'bank', 'balance' => '10', 'is_active' => true]);
    $income = Income::create(['wallet_id' => $wallet->getKey(), 'amount' => '10', 'transaction_date' => '2026-09-08 00:00:00']);
    expect($income->fresh()->category)->toBeNull();
    expect($income->fresh()->amount)->toBe('10.00');
    $this->actingAs(User::factory()->create(['role' => 'admin']))
        ->patchJson('/api/incomes/'.$income->getKey(), ['description' => 'Legacy correction'])->assertOk();
});

test('admin fee migration preserves legacy records and wallet balances on reapply', function () {
    $migration = require database_path('migrations/2026_09_22_120000_add_admin_fee_to_transactions.php');
    $migration->down();
    $wallet = Wallet::create(['name' => 'Legacy', 'type' => 'bank', 'balance' => '123.45', 'is_active' => true]);
    $to = Wallet::create(['name' => 'Destination', 'type' => 'bank', 'balance' => '67.89', 'is_active' => true]);
    $records = [];
    foreach (['incomes' => Income::class, 'expenses' => Expense::class, 'transfers' => Transfer::class] as $table => $model) {
        expect(Schema::hasColumn($table, 'admin_fee'))->toBeFalse();
        $records[] = $model::create([
            ...($table === 'transfers' ? ['from_wallet_id' => $wallet->getKey(), 'to_wallet_id' => $to->getKey()] : ['wallet_id' => $wallet->getKey()]),
            'amount' => '10.25', 'description' => 'Legacy record', 'transaction_date' => '2026-09-08 10:30:00',
        ]);
    }
    foreach ([1, 2] as $attempt) {
        $migration->up();
        foreach ($records as $record) {
            expect($record->fresh()->getAttributes())->toMatchArray($record->getAttributes());
            expect($record->fresh()->admin_fee)->toBe('0.00');
        }
        expect($wallet->fresh()->balance)->toBe('123.45');
        expect($to->fresh()->balance)->toBe('67.89');
        if ($attempt === 1) {
            $migration->down();
        }
    }
});
