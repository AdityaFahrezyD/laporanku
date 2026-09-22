<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\Income;
use App\Models\Transfer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TransactionQuery
{
    private const MODELS = ['incomes' => Income::class, 'expenses' => Expense::class, 'transfers' => Transfer::class];

    private const PRIMARY_KEYS = ['incomes' => 'income_id', 'expenses' => 'expense_id', 'transfers' => 'transfer_id'];

    private const RELATIONS = ['incomes' => ['incomeWallet', 'category', 'attachments'], 'expenses' => ['expenseWallet', 'category', 'attachments'], 'transfers' => ['transferFrom', 'transferTo', 'attachments']];

    // Nama tabel/kolom hanya berasal dari daftar tetap, bukan input SQL pengguna.
    private function table(string $resource): string
    {
        abort_unless(isset(self::MODELS[$resource]), 404);

        return $resource;
    }

    private function decimal(mixed $value): string
    {
        // MySQL mengembalikan SUM(DECIMAL) sebagai string; SQLite tes bisa float.
        if (is_float($value)) {
            return number_format($value, 2, '.', '');
        }
        [$whole, $fraction] = array_pad(explode('.', (string) ($value ?? 0), 2), 2, '');

        return $whole.'.'.str_pad(substr($fraction, 0, 2), 2, '0');
    }

    private function records(string $resource, array $rows): Collection
    {
        // Model mempertahankan format nominal/tanggal dan relasi respons API.
        return self::MODELS[$resource]::hydrate($rows)->load(self::RELATIONS[$resource]);
    }

    public function page(string $resource, Request $request): array
    {
        $table = $this->table($resource);
        $primaryKey = self::PRIMARY_KEYS[$resource];
        $input = $request->validate([
            'paginated' => 'required|in:1',
            'start_date' => 'required_with:end_date|date_format:Y-m-d',
            'end_date' => 'required_with:start_date|date_format:Y-m-d|after_or_equal:start_date',
            'q' => 'nullable|string|max:200',
            'category_id' => $resource === 'transfers' ? 'prohibited' : 'nullable|uuid',
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:100',
        ]);
        $currentPage = (int) ($input['page'] ?? 1);
        $perPage = (int) ($input['per_page'] ?? 10);
        $conditions = [];
        $bindings = [];
        $joins = '';

        if (isset($input['category_id'])) {
            $categoryId = strtolower($input['category_id']);
            $category = DB::selectOne(<<<'SQL'
                SELECT category_id
                FROM categories
                WHERE category_id = ? AND type = ?
                LIMIT 1
                SQL, [$categoryId, rtrim($resource, 's')]);
            if (! $category) {
                throw ValidationException::withMessages([
                    'category_id' => 'Pilih kategori yang tersedia dan sesuai jenis transaksi.',
                ]);
            }
            // Klausa ini dipakai oleh query total dan query halaman sekaligus.
            $conditions[] = 't.category_id = ?';
            $bindings[] = $categoryId;
        }

        if (isset($input['start_date'])) {
            $start = CarbonImmutable::createFromFormat('!Y-m-d', $input['start_date'], 'Asia/Jakarta');
            $end = CarbonImmutable::createFromFormat('!Y-m-d', $input['end_date'], 'Asia/Jakarta')->addDay();
            $conditions[] = 't.transaction_date >= ? AND t.transaction_date < ?';
            $bindings[] = $start->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');
            $bindings[] = $end->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');
        }

        $search = $input['q'] ?? '';
        if ($search !== '') {
            // %, _, dan ! dicari sebagai teks literal, bukan wildcard pengguna.
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search)).'%';
            if ($resource === 'transfers') {
                $joins = <<<'SQL'
                    LEFT JOIN wallets AS source_wallet ON source_wallet.wallet_id = t.from_wallet_id
                    LEFT JOIN wallets AS target_wallet ON target_wallet.wallet_id = t.to_wallet_id
                    SQL;
                $conditions[] = <<<'SQL'
                    (
                        LOWER(t.description) LIKE ? ESCAPE '!'
                        OR CAST(t.amount AS CHAR) LIKE ? ESCAPE '!'
                        OR LOWER(source_wallet.name) LIKE ? ESCAPE '!'
                        OR LOWER(target_wallet.name) LIKE ? ESCAPE '!'
                    )
                    SQL;
            } else {
                $joins = <<<'SQL'
                    LEFT JOIN wallets AS wallet ON wallet.wallet_id = t.wallet_id
                    LEFT JOIN categories AS category ON category.category_id = t.category_id
                    SQL;
                $conditions[] = <<<'SQL'
                    (
                        LOWER(t.description) LIKE ? ESCAPE '!'
                        OR CAST(t.amount AS CHAR) LIKE ? ESCAPE '!'
                        OR LOWER(wallet.name) LIKE ? ESCAPE '!'
                        OR LOWER(category.name) LIKE ? ESCAPE '!'
                    )
                    SQL;
            }
            array_push($bindings, $pattern, $pattern, $pattern, $pattern);
        }
        $where = $conditions ? 'WHERE '.implode(' AND ', $conditions) : '';

        return DB::transaction(function () use ($table, $resource, $primaryKey, $joins, $where, $bindings, $currentPage, $perPage) {
            // Hitung seluruh hasil filter. Total tidak dibatasi LIMIT halaman.
            $aggregate = DB::selectOne(<<<SQL
                SELECT COUNT(*) AS total, COALESCE(SUM(t.amount), 0) AS total_amount,
                    COALESCE(SUM(t.admin_fee), 0) AS total_admin_fee
                FROM {$table} AS t
                {$joins}
                {$where}
                SQL, $bindings);
            $total = (int) $aggregate->total;
            $lastPage = max(1, (int) ceil($total / $perPage));
            $rows = [];

            if ($total > 0 && $currentPage <= $lastPage) {
                $offset = ($currentPage - 1) * $perPage;
                $rows = DB::select(<<<SQL
                    SELECT t.*
                    FROM {$table} AS t
                    {$joins}
                    {$where}
                    ORDER BY t.transaction_date DESC, t.{$primaryKey} ASC
                    LIMIT ? OFFSET ?
                    SQL, [...$bindings, $perPage, $offset]);
            }

            return ['data' => $this->records($resource, $rows)->all(), 'meta' => [
                'current_page' => $currentPage, 'last_page' => $lastPage,
                'per_page' => $perPage, 'total' => $total,
            ], 'summary' => [
                'total_amount' => $this->decimal($aggregate->total_amount),
                'total_admin_fee' => $this->decimal($aggregate->total_admin_fee),
            ]];
        });
    }

    public function summary(): array
    {
        return DB::transaction(function () {
            $balance = DB::selectOne(<<<'SQL'
                SELECT COALESCE(SUM(balance), 0) AS total_balance
                FROM wallets
                SQL);
            $totals = ['balance' => $this->decimal($balance->total_balance)];
            $counts = [];
            $latest = collect();

            foreach (array_keys(self::MODELS) as $resource) {
                $table = $this->table($resource);
                $primaryKey = self::PRIMARY_KEYS[$resource];
                $aggregate = DB::selectOne(<<<SQL
                    SELECT COUNT(*) AS total, COALESCE(SUM(amount), 0) AS total_amount
                    FROM {$table}
                    SQL);
                $totals[$resource] = $this->decimal($aggregate->total_amount);
                $counts[$resource] = (int) $aggregate->total;

                // Ambil paling banyak lima kandidat dari setiap jenis transaksi.
                $rows = DB::select(<<<SQL
                    SELECT *
                    FROM {$table}
                    ORDER BY transaction_date DESC, {$primaryKey} ASC
                    LIMIT 5
                    SQL);
                foreach ($this->records($resource, $rows) as $row) {
                    $latest->push([...$row->toArray(), 'id' => $row->getKey(), 'type' => rtrim($resource, 's')]);
                }
            }

            // Aggregate in SQL to retain DECIMAL precision, including totals above a single transaction's limit.
            $costs = DB::selectOne(<<<'SQL'
                SELECT COALESCE(SUM(admin_fees), 0) AS admin_fees,
                    COALESCE(SUM(expenses + admin_fees), 0) AS expenses
                FROM (
                    SELECT 0 AS expenses, COALESCE(SUM(admin_fee), 0) AS admin_fees FROM incomes
                    UNION ALL
                    SELECT COALESCE(SUM(amount), 0), COALESCE(SUM(admin_fee), 0) FROM expenses
                    UNION ALL
                    SELECT 0, COALESCE(SUM(admin_fee), 0) FROM transfers
                ) AS costs
                SQL);
            $totals['expenses'] = $this->decimal($costs->expenses);
            $totals['admin_fees'] = $this->decimal($costs->admin_fees);

            return ['totals' => $totals, 'counts' => $counts, 'latest' => $latest->sort(function ($a, $b) {
                return strcmp($b['transaction_date'], $a['transaction_date']) ?: strcmp($a['id'], $b['id']) ?: strcmp($a['type'], $b['type']);
            })->take(5)->values()->all()];
        });
    }
}
