<?php

use App\Models\Category;
use App\Models\Income;
use App\Models\Wallet;
use App\Services\CategoryService;
use App\Services\ExpenseService;
use App\Services\IncomeService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// Standalone integration checks. Only the randomly named database is modified.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

set_exception_handler(function (Throwable $error): never {
    fwrite(STDERR, get_class($error).': '.$error->getMessage().PHP_EOL);
    exit(1);
});

function verify(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

$connection = config('database.default');
if (! in_array(config("database.connections.$connection.driver"), ['mysql', 'mariadb'], true)) {
    fwrite(STDERR, "Requires a MySQL/MariaDB connection with CREATE DATABASE permission.\n");
    exit(1);
}

$worker = ($argv[1] ?? '') === 'worker';
$database = $worker ? getenv('LAPORANKU_CONCURRENCY_DATABASE') : 'laporanku_test_'.bin2hex(random_bytes(8));
verify((bool) preg_match('/^laporanku_test_[a-f0-9]{16}$/D', $database), 'Invalid isolated database name.');
$config = config("database.connections.$connection");
// Resolve the configured connection before replacing the database (including DB_URL).
$config = DB::connection()->getConfig();
$config['url'] = null;
$config['name'] = 'finance_test';
$config['database'] = $database;
config(['database.connections.finance_test' => $config]);

if ($worker) {
    config(['database.default' => 'finance_test']);
    verify(DB::connection()->getName() === 'finance_test' && DB::connection()->getDatabaseName() === $database, 'Worker connection is not isolated.');
    $start = (float) $argv[4];
    while (microtime(true) < $start) {
        usleep(1000);
    }
    try {
        match ($argv[2]) {
            'expense' => app(ExpenseService::class)->createExpense(['wallet_id' => $argv[3], 'amount' => '80.00', 'transaction_date' => '08-09-2026 10:30']),
            'update' => app(IncomeService::class)->updateIncome($argv[3], ['amount' => '200.00']),
            'delete' => app(IncomeService::class)->deleteIncome($argv[3]),
        };
        echo '200';
    } catch (ValidationException) {
        echo '422';
    } catch (ModelNotFoundException) {
        echo '404';
    }
    exit(0);
}

function race(string $database, array $operations): array
{
    $workers = [];
    $start = (string) (microtime(true) + 2);
    foreach ($operations as [$operation, $id]) {
        $process = proc_open([PHP_BINARY, __FILE__, 'worker', $operation, $id, $start],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(),
            [...getenv(), 'LAPORANKU_CONCURRENCY_DATABASE' => $database]);
        verify(is_resource($process), 'Could not start worker.');
        fclose($pipes[0]);
        $workers[] = [$process, $pipes];
    }
    $results = [];
    foreach ($workers as [$process, $pipes]) {
        $results[] = trim(stream_get_contents($pipes[1]));
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        verify(proc_close($process) === 0, 'Worker failed: '.$error);
    }

    return $results;
}

$created = false;
$originalTables = DB::connection($connection)->select('SHOW TABLES');
try {
    DB::connection($connection)->statement("CREATE DATABASE `$database`");
    $created = true;
    config(['database.default' => 'finance_test']);
    verify(DB::connection()->getName() === 'finance_test' && DB::connection()->getDatabaseName() === $database, 'Migration connection is not isolated.');
    verify(Artisan::call('migrate', ['--database' => 'finance_test', '--force' => true]) === 0, 'Migration failed.');

    // The initial migrations must include the master and nullable category foreign keys.
    verify(DB::connection('finance_test')->getSchemaBuilder()->hasTable('categories'), 'Category master is missing.');
    verify(DB::connection('finance_test')->getSchemaBuilder()->hasColumn('expenses', 'category_id'), 'Expense category is missing.');
    verify(DB::connection('finance_test')->getSchemaBuilder()->hasColumn('incomes', 'category_id'), 'Initial migration is missing category.');
    $wallet = Wallet::create(['name' => 'Integration', 'type' => 'bank', 'balance' => '100.00', 'is_active' => true]);
    $legacy = Income::create(['wallet_id' => $wallet->getKey(), 'amount' => '1.00', 'transaction_date' => '2026-09-08 10:30:00']);
    verify($legacy->fresh()->category === null, 'Category must allow null.');
    $legacy->delete();

    $category = app(CategoryService::class)->createCategory(['name' => 'Gaji', 'type' => 'income']);
    $categorized = app(IncomeService::class)->createIncome(['wallet_id' => $wallet->getKey(), 'amount' => '1.00', 'category_id' => $category->getKey(), 'transaction_date' => '08-09-2026 10:30']);
    verify($categorized->category->getKey() === $category->getKey(), 'Category relation mismatch.');
    foreach (['delete', 'change_type'] as $operation) {
        try {
            if ($operation === 'delete') {
                app(CategoryService::class)->deleteCategory($category->getKey());
            } else {
                app(CategoryService::class)->updateCategory($category->getKey(), ['type' => 'expense']);
            }
            throw new RuntimeException('Used category was modified.');
        } catch (ValidationException) {
            verify(Category::find($category->getKey())->type === 'income', 'Used category changed.');
        }
    }
    app(IncomeService::class)->deleteIncome($categorized->getKey());
    app(CategoryService::class)->deleteCategory($category->getKey());

    for ($iteration = 0; $iteration < 5; $iteration++) {
        $wallet->update(['balance' => '100.00']);
        $results = race($database, [['expense', $wallet->getKey()], ['expense', $wallet->getKey()]]);
        sort($results);
        verify($results === ['200', '422'], 'Concurrent expenses did not reject overspending.');
        verify($wallet->fresh()->balance === '20.00', 'Concurrent expense balance mismatch.');

        $wallet->update(['balance' => '1000.00']);
        $income = app(IncomeService::class)->createIncome(['wallet_id' => $wallet->getKey(), 'amount' => '100.00', 'transaction_date' => '08-09-2026 10:30']);
        $results = race($database, [['update', $income->getKey()], ['delete', $income->getKey()]]);
        verify($results[1] === '200' && in_array($results[0], ['200', '404'], true), 'Concurrent edit/delete failed.');
        verify($wallet->fresh()->balance === '1000.00' && Income::find($income->getKey()) === null, 'Income reversed more than once.');
    }

    $wallet->update(['balance' => '0.00']);
    $income = app(IncomeService::class)->createIncome(['wallet_id' => $wallet->getKey(), 'amount' => '9999999999999.99', 'transaction_date' => '08-09-2026 10:30']);
    verify($wallet->fresh()->balance === '9999999999999.99', 'Maximum DECIMAL precision lost.');
    try {
        app(IncomeService::class)->createIncome(['wallet_id' => $wallet->getKey(), 'amount' => '0.01', 'transaction_date' => '08-09-2026 10:30']);
        throw new RuntimeException('Overflow was allowed.');
    } catch (ValidationException) {
        verify($wallet->fresh()->balance === '9999999999999.99', 'Overflow changed balance.');
    }
    app(IncomeService::class)->deleteIncome($income->getKey());
    verify($wallet->fresh()->balance === '0.00', 'Maximum reversal mismatch.');
    verify(Artisan::call('migrate:reset', ['--database' => 'finance_test', '--force' => true]) === 0, 'Migration rollback failed.');
    verify(Artisan::call('migrate', ['--database' => 'finance_test', '--force' => true]) === 0, 'Migration reapply failed.');
    verify(DB::connection('finance_test')->getSchemaBuilder()->hasColumn('incomes', 'category_id'), 'Reapplied migration is missing category.');
    verify(DB::connection('finance_test')->getSchemaBuilder()->hasColumn('expenses', 'category_id'), 'Reapplied expense category is missing.');
    echo "PASS: 5 overspending races, 5 edit/delete races, DECIMAL boundary, category master, full rollback/reapply.\n";
} finally {
    DB::disconnect('finance_test');
    if ($created) {
        DB::connection($connection)->statement("DROP DATABASE `$database`");
    }
    verify(DB::connection($connection)->select('SHOW TABLES') == $originalTables, 'Working database schema changed.');
}
