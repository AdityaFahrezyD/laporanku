<?php

namespace App\Services;

use App\Models\Income;
use Illuminate\Database\Eloquent\Collection;

class IncomeService extends TransactionService
{
    protected string $model = Income::class;

    protected array $relations = ['incomeWallet'];

    protected string $attachments = 'incomeAttachments';

    protected string $kind = 'income';

    public function getIncomes(): Collection
    {
        return $this->all();
    }

    public function getIncomeById(string $id): Income
    {
        return $this->find($id);
    }

    public function createIncome(array $data): Income
    {
        return $this->saveTransaction(null, $data);
    }

    public function updateIncome(string $id, array $data): Income
    {
        return $this->saveTransaction($id, $data);
    }

    public function deleteIncome(string $id): void
    {
        $this->remove($id);
    }
}
