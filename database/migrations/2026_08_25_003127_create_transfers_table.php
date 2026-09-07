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
        Schema::create('transfers', function (Blueprint $table) {
            $table->uuid('transfer_id')->primary();
            $table->uuid('from_wallet_id');
            $table->uuid('to_wallet_id');
            $table->decimal('amount', 15, 2);
            $table->text('description')->nullable();
            $table->dateTime('transaction_date');
            $table->timestamps();
            $table->foreign('from_wallet_id')->references('wallet_id')->on('wallets')->restrictOnDelete();
            $table->foreign('to_wallet_id')->references('wallet_id')->on('wallets')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transfers');
    }
};
