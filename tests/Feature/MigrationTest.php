<?php

use App\Models\Income;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\Schema;

test('category migration rolls back and preserves legacy income when reapplied', function () {
    $wallet = Wallet::create(['name' => 'Legacy', 'type' => 'bank', 'balance' => '10', 'is_active' => true]);
    $income = Income::create(['wallet_id' => $wallet->getKey(), 'amount' => '10', 'transaction_date' => '2026-09-08 00:00:00']);
    $migration = require database_path('migrations/2026_09_08_000000_add_category_to_incomes_table.php');
    $migration->down();
    expect(Schema::hasColumn('incomes', 'category'))->toBeFalse();
    $migration->up();
    expect($income->fresh()->category)->toBeNull();
    expect($income->fresh()->amount)->toBe('10.00');
    $this->actingAs(User::factory()->create(['role' => 'admin']))
        ->patchJson('/api/incomes/'.$income->getKey(), ['description' => 'Legacy correction'])->assertOk();
});
