<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Wallet;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

abstract class TransactionService
{
    protected string $model;

    protected array $relations;

    protected string $attachments;

    protected string $kind;

    protected function all(): Collection
    {
        return $this->model
            ::with($this->relations)
            ->orderByDesc("transaction_date")
            ->get();
    }

    protected function find(string $id): Model
    {
        return $this->model::with($this->relations)->findOrFail($id);
    }

    protected function saveTransaction(?string $id, array $data): Model
    {
        return DB::transaction(function () use ($id, $data) {
            $record =
                $id === null
                    ? new $this->model()
                    : $this->model::lockForUpdate()->findOrFail($id);
            $old = $record->exists
                ? $this->effects($record->getAttributes())
                : [];
            $merged = array_replace($record->getAttributes(), $data);
            if (
                $this->kind !== "transfer" &&
                ($merged["category_id"] ?? null) !== null
            ) {
                // Serialize category assignment with category type changes/deletion.
                $category = Category::lockForUpdate()->find(
                    $merged["category_id"]
                );
                if (!$category || $category->type !== $this->kind) {
                    throw ValidationException::withMessages([
                        "category_id" =>
                            "Kategori harus tersedia dan sesuai jenis transaksi.",
                    ]);
                }
            }
            $new = $this->effects($merged);
            $this->applyBalances($old, $new);

            if (array_key_exists("transaction_date", $data)) {
                $data["transaction_date"] = CarbonImmutable::createFromFormat(
                    "!d-m-Y H:i",
                    $data["transaction_date"],
                    config("app.timezone")
                );
            }
            if (array_key_exists("amount", $data)) {
                $data["amount"] = Money::decimal(Money::cents($data["amount"]));
            }
            $record->fill($data)->save();

            return $record->refresh()->load($this->relations);
        }, 3);
    }

    protected function remove(string $id): void
    {
        DB::transaction(function () use ($id) {
            $record = $this->model::lockForUpdate()->findOrFail($id);
            $this->applyBalances($this->effects($record->getAttributes()), []);
            foreach ($record->{$this->attachments}()->get() as $attachment) {
                app(AttachmentFiles::class)->removeAfterCommit($attachment);
            }
            $record->{$this->attachments}()->delete();
            $record->delete();
        }, 3);
    }

    private function effects(array $data): array
    {
        foreach (["wallet_id", "from_wallet_id", "to_wallet_id"] as $field) {
            if (isset($data[$field])) {
                $data[$field] = strtolower($data[$field]);
            }
        }
        $amount = Money::cents($data["amount"]);
        if ($this->kind === "transfer") {
            if ($data["from_wallet_id"] === $data["to_wallet_id"]) {
                throw ValidationException::withMessages([
                    "to_wallet_id" => "Wallet asal dan tujuan harus berbeda.",
                ]);
            }

            return [
                $data["from_wallet_id"] => -$amount,
                $data["to_wallet_id"] => $amount,
            ];
        }

        return [
            $data["wallet_id"] => $this->kind === "income" ? $amount : -$amount,
        ];
    }

    private function applyBalances(array $old, array $new): void
    {
        $ids = array_unique(array_merge(array_keys($old), array_keys($new)));
        sort($ids, SORT_STRING);
        $changes = [];
        // Lock individually in a deterministic order, including unchanged wallets.
        foreach ($ids as $id) {
            $wallet = Wallet::lockForUpdate()->findOrFail($id);
            if (!$wallet->is_active) {
                throw ValidationException::withMessages([
                    "wallet_id" =>
                        "Aktifkan wallet sebelum mengubah transaksi.",
                ]);
            }
            $balance =
                Money::cents($wallet->balance, "balance", true) -
                ($old[$id] ?? 0) +
                ($new[$id] ?? 0);
            if ($balance < 0 || $balance > Money::MAX) {
                throw ValidationException::withMessages([
                    "amount" =>
                        "Saldo akhir tidak mencukupi atau melebihi batas maksimum.",
                ]);
            }
            $changes[] = [$wallet, $balance];
        }
        foreach ($changes as [$wallet, $balance]) {
            $wallet->balance = Money::decimal($balance);
            $wallet->save();
        }
    }
}
