<?php

namespace App\Http\Requests;

class StoreTransferRequest extends TransactionRequest
{
    protected string $kind = 'transfer';
}
