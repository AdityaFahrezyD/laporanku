<?php

namespace App\Services;

use App\Models\Expense;
use Illuminate\Database\Eloquent\Collection;

class ExpenseService extends TransactionService
{
    protected string $model = Expense::class;

    protected array $relations = ['expenseWallet', 'category'];

    protected string $attachments = 'expenseAttachments';

    protected string $kind = 'expense';

    public function getExpenses(): Collection
    {
        return $this->all();
    }

    public function getExpenseById(string $id): Expense
    {
        return $this->find($id);
    }

    public function createExpense(array $data): Expense
    {
        return $this->saveTransaction(null, $data);
    }

    public function updateExpense(string $id, array $data): Expense
    {
        return $this->saveTransaction($id, $data);
    }

    public function deleteExpense(string $id): void
    {
        $this->remove($id);
    }
}
