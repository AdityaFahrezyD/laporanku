<?php

namespace App\Http\Requests;

class UpdateTransferRequest extends TransactionRequest
{
    protected string $kind = 'transfer';
}
