<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['incomes', 'expenses', 'transfers'] as $table) {
            $covered = collect(Schema::getIndexes($table))->contains(fn ($index) => ($index['columns'][0] ?? null) === 'transaction_date');
            if (! $covered) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->index('transaction_date', $table.'_transaction_date_query_idx'));
            }
        }
    }

    public function down(): void
    {
        foreach (['incomes', 'expenses', 'transfers'] as $table) {
            $name = $table.'_transaction_date_query_idx';
            if (Schema::hasIndex($table, $name)) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropIndex($name));
            }
        }
    }
};
