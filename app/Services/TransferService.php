<?php

namespace App\Services;

use App\Models\Transfer;
use Illuminate\Database\Eloquent\Collection;

class TransferService extends TransactionService
{
    protected string $model = Transfer::class;

    protected array $relations = ['transferFrom', 'transferTo'];

    protected string $attachments = 'transferAttachments';

    protected string $kind = 'transfer';

    public function getTransfers(): Collection
    {
        return $this->all();
    }

    public function getTransferById(string $id): Transfer
    {
        return $this->find($id);
    }

    public function createTransfer(array $data): Transfer
    {
        return $this->saveTransaction(null, $data);
    }

    public function updateTransfer(string $id, array $data): Transfer
    {
        return $this->saveTransaction($id, $data);
    }

    public function deleteTransfer(string $id): void
    {
        $this->remove($id);
    }
}
