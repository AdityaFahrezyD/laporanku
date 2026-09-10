<?php

namespace App\Services;

use App\Models\Wallet;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WalletService
{
    public function getWallets(): Collection
    {
        return Wallet::orderByRaw(
            "CASE type WHEN 'bank' THEN 1 WHEN 'ewallet' THEN 2 ELSE 3 END"
        )
            ->orderBy("name")
            ->get();
    }

    public function getWalletById(string $id): Wallet
    {
        return Wallet::findOrFail($id);
    }

    public function createWallet(array $data): Wallet
    {
        $data["balance"] = Money::decimal(
            Money::cents($data["balance"], "balance", true)
        );

        return Wallet::create($data);
    }

    public function updateWallet(string $id, array $data): Wallet
    {
        if (array_key_exists("balance", $data)) {
            throw ValidationException::withMessages([
                "balance" => "Saldo hanya dapat diubah melalui transaksi.",
            ]);
        }

        return DB::transaction(function () use ($id, $data) {
            $wallet = Wallet::lockForUpdate()->findOrFail($id);
            $wallet->fill($data)->save();

            return $wallet->refresh();
        }, 3);
    }

    public function deleteWallet(string $id): void
    {
        $this->getWalletById($id);
        abort(403, "Wallet ini bersifat permanen dan tidak dapat dihapus");
    }
}
