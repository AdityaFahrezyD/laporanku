<?php

use App\Models\Income;
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
