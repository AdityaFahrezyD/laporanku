<?php

use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\IncomeController;
use App\Http\Controllers\TransferController;
use App\Http\Controllers\WalletController;
use App\Http\Middleware\CacheTransactionList;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get("/{resource}/{id}/attachments/{attachmentId}", [
    AttachmentController::class,
    "show",
])
    ->whereIn("resource", ["incomes", "expenses", "transfers"])
    ->middleware("throttle:api-reads");
Route::middleware(["auth:sanctum", "role:admin", "throttle:api-writes"])->group(
    function () {
        Route::post("/{resource}/{id}/attachments", [
            AttachmentController::class,
            "store",
        ])->whereIn("resource", ["incomes", "expenses", "transfers"]);
        Route::delete("/{resource}/{id}/attachments/{attachmentId}", [
            AttachmentController::class,
            "destroy",
        ])->whereIn("resource", ["incomes", "expenses", "transfers"]);
    }
);

Route::middleware(["auth:sanctum", "throttle:api-reads"])->get(
    "/user",
    fn(Request $request) => $request->user()
);

Route::middleware("throttle:api-reads")->group(function () {
    Route::apiResource("wallets", WalletController::class)->only([
        "index",
        "show",
    ]);
    Route::apiResource("categories", CategoryController::class)->only([
        "index",
        "show",
    ]);
    Route::apiResource("incomes", IncomeController::class)
        ->only(["index", "show"])
        ->middlewareFor("index", CacheTransactionList::class);
    Route::apiResource("expenses", ExpenseController::class)
        ->only(["index", "show"])
        ->middlewareFor("index", CacheTransactionList::class);
    Route::apiResource("transfers", TransferController::class)
        ->only(["index", "show"])
        ->middlewareFor("index", CacheTransactionList::class);
});

Route::middleware(["auth:sanctum", "role:admin", "throttle:api-writes"])->group(
    function () {
        Route::apiResource("categories", CategoryController::class)->except([
            "index",
            "show",
        ]);
        Route::apiResource("wallets", WalletController::class)->except([
            "index",
            "show",
        ]);
        Route::apiResource("incomes", IncomeController::class)->except([
            "index",
            "show",
        ]);
        Route::apiResource("expenses", ExpenseController::class)->except([
            "index",
            "show",
        ]);
        Route::apiResource("transfers", TransferController::class)->except([
            "index",
            "show",
        ]);
    }
);
