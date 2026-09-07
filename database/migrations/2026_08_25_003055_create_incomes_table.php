<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('incomes', function (Blueprint $table) {
            $table->uuid('income_id')->primary();
            $table->uuid('wallet_id');
            $table->decimal('amount', 15, 2);
            $table->text('description')->nullable();
            $table->dateTime('transaction_date');
            $table->timestamps();
            $table->foreign('wallet_id')->references('wallet_id')->on('wallets')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('incomes');
    }
};
