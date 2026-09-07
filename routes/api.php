<?php

use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\IncomeController;
use App\Http\Controllers\TransferController;
use App\Http\Controllers\WalletController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->get('/user', fn (Request $request) => $request->user());

Route::apiResource('wallets', WalletController::class)->only(['index', 'show']);
Route::apiResource('categories', CategoryController::class)->only(['index', 'show']);
Route::apiResource('incomes', IncomeController::class)->only(['index', 'show']);
Route::apiResource('expenses', ExpenseController::class)->only(['index', 'show']);
Route::apiResource('transfers', TransferController::class)->only(['index', 'show']);

Route::middleware(['auth:sanctum', 'role:admin'])->group(function () {
    Route::apiResource('categories', CategoryController::class)->except(['index', 'show']);
    Route::apiResource('wallets', WalletController::class)->except(['index', 'show']);
    Route::apiResource('incomes', IncomeController::class)->except(['index', 'show']);
    Route::apiResource('expenses', ExpenseController::class)->except(['index', 'show']);
    Route::apiResource('transfers', TransferController::class)->except(['index', 'show']);
});
