<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Wallet;
use App\Services\ExpenseService;
use App\Services\IncomeService;
use App\Services\TransferService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DemoDataSeeder extends Seeder
{
    // These reserved IDs identify the one-time demo dataset, even after names change.
    private const WALLET_IDS = [
        '01900000-0000-7000-8000-000000000001',
        '01900000-0000-7000-8000-000000000002',
        '01900000-0000-7000-8000-000000000003',
    ];

    public function run(): void
    {
        DB::transaction(function () {
            $count = Wallet::whereIn('wallet_id', self::WALLET_IDS)->count();
            if ($count === 3) {
                $this->command?->info('Data contoh sudah tersedia; transaksi dan saldo tidak diubah.');

                return;
            }
            if ($count > 0) {
                throw new RuntimeException('Sebagian UUID dompet contoh sudah digunakan. Periksa data sebelum seeding.');
            }

            $categories = [];
            foreach ([['Gaji', 'income'], ['Freelance', 'income'], ['Belanja', 'expense'], ['Makan & minum', 'expense'], ['Tagihan', 'expense']] as [$name, $type]) {
                $categories[$name] = Category::firstOrCreate(['name' => $name, 'type' => $type])->getKey();
            }

            foreach ([['Bank Utama', 'bank', '0.00'], ['Dompet Digital', 'ewallet', '0.00'], ['Uang Tunai', 'cash', '1000000.00']] as $index => [$name, $type, $balance]) {
                $wallet = new Wallet(['name' => $name, 'type' => $type, 'balance' => $balance, 'is_active' => true]);
                $wallet->wallet_id = self::WALLET_IDS[$index];
                $wallet->save();
            }
            [$bank, $digital, $cash] = self::WALLET_IDS;

            $income = app(IncomeService::class);
            $expense = app(ExpenseService::class);
            $transfer = app(TransferService::class);

            $income->createIncome(['wallet_id' => $bank, 'amount' => '8500000.00', 'category_id' => $categories['Gaji'], 'description' => 'Gaji September', 'transaction_date' => '01-09-2026 02:00']);
            $expense->createExpense(['wallet_id' => $bank, 'amount' => '475000.00', 'category_id' => $categories['Tagihan'], 'description' => 'Tagihan internet', 'transaction_date' => '08-09-2026 04:00']);
            $transfer->createTransfer(['from_wallet_id' => $bank, 'to_wallet_id' => $digital, 'amount' => '750000.00', 'description' => 'Isi saldo dompet digital', 'transaction_date' => '09-09-2026 01:15']);
            $expense->createExpense(['wallet_id' => $digital, 'amount' => '185000.00', 'category_id' => $categories['Makan & minum'], 'description' => 'Makan malam bersama', 'transaction_date' => '09-09-2026 12:00']);
            $income->createIncome(['wallet_id' => $bank, 'amount' => '1750000.00', 'category_id' => $categories['Freelance'], 'description' => 'Proyek desain website', 'transaction_date' => '10-09-2026 03:00']);
            $expense->createExpense(['wallet_id' => $cash, 'amount' => '325000.00', 'category_id' => $categories['Belanja'], 'description' => 'Belanja kebutuhan rumah', 'transaction_date' => '10-09-2026 05:30']);
        });
    }
}
