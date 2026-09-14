<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\Income;
use App\Models\Transfer;
use App\Models\Wallet;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TransactionQuery
{
    private const MODELS = ['incomes' => Income::class, 'expenses' => Expense::class, 'transfers' => Transfer::class];

    private const RELATIONS = ['incomes' => ['incomeWallet', 'category', 'attachments'], 'expenses' => ['expenseWallet', 'category', 'attachments'], 'transfers' => ['transferFrom', 'transferTo', 'attachments']];

    private function base(string $resource)
    {
        return self::MODELS[$resource]::query();
    }

    // MySQL DECIMAL sums stay decimal strings; SQLite test sums may be numeric.
    private function decimal(mixed $value): string
    {
        if (is_float($value)) {
            return number_format($value, 2, '.', '');
        }
        [$whole, $fraction] = array_pad(explode('.', (string) ($value ?? 0), 2), 2, '');

        return $whole.'.'.str_pad(substr($fraction, 0, 2), 2, '0');
    }

    public function page(string $resource, Request $request): array
    {
        $input = $request->validate([
            'paginated' => 'required|in:1',
            'start_date' => 'required_with:end_date|date_format:Y-m-d',
            'end_date' => 'required_with:start_date|date_format:Y-m-d|after_or_equal:start_date',
            'q' => 'nullable|string|max:200',
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:100',
        ]);
        $query = $this->base($resource);
        if (isset($input['start_date'])) {
            $start = CarbonImmutable::createFromFormat('!Y-m-d', $input['start_date'], 'Asia/Jakarta');
            $end = CarbonImmutable::createFromFormat('!Y-m-d', $input['end_date'], 'Asia/Jakarta')->addDay();
            $query->where('transaction_date', '>=', $start->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s'))
                ->where('transaction_date', '<', $end->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s'));
        }
        $search = $input['q'] ?? '';
        if ($search !== '') {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search)).'%';
            $query->where(function ($q) use ($pattern, $resource) {
                $q->whereRaw("LOWER(description) LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("CAST(amount AS CHAR) LIKE ? ESCAPE '!'", [$pattern]);
                foreach (array_diff(self::RELATIONS[$resource], ['attachments']) as $relation) {
                    $q->orWhereHas($relation, fn ($related) => $related->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$pattern]));
                }
            });
        }

        return DB::transaction(function () use ($query, $resource, $input) {
            $total = $this->decimal((clone $query)->sum('amount'));
            $page = $query->with(self::RELATIONS[$resource])->orderByDesc('transaction_date')
                ->orderBy($query->getModel()->getKeyName())
                ->paginate($input['per_page'] ?? 10, ['*'], 'page', $input['page'] ?? 1);

            return ['data' => $page->items(), 'meta' => [
                'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(), 'total' => $page->total(),
            ], 'summary' => ['total_amount' => $total]];
        });
    }

    public function summary(): array
    {
        return DB::transaction(function () {
            $latest = collect();
            $totals = ['balance' => $this->decimal(Wallet::sum('balance'))];
            $counts = [];
            foreach (self::MODELS as $resource => $model) {
                $totals[$resource] = $this->decimal($model::sum('amount'));
                $counts[$resource] = $model::count();
                $query = $this->base($resource);
                foreach ($query->with(self::RELATIONS[$resource])->orderByDesc('transaction_date')->orderBy($query->getModel()->getKeyName())->limit(5)->get() as $row) {
                    $latest->push([...$row->toArray(), 'id' => $row->getKey(), 'type' => rtrim($resource, 's')]);
                }
            }

            return ['totals' => $totals, 'counts' => $counts, 'latest' => $latest->sort(function ($a, $b) {
                return strcmp($b['transaction_date'], $a['transaction_date']) ?: strcmp($a['id'], $b['id']) ?: strcmp($a['type'], $b['type']);
            })->take(5)->values()->all()];
        });
    }
}
