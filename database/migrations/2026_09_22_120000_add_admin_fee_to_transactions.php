<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['incomes', 'expenses', 'transfers'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->decimal('admin_fee', 15, 2)->default(0);
            });
        }
    }

    public function down(): void
    {
        foreach (['incomes', 'expenses', 'transfers'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn('admin_fee');
            });
        }
    }
};
