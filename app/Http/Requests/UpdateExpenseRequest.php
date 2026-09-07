<?php

namespace App\Http\Requests;

class UpdateExpenseRequest extends TransactionRequest
{
    protected string $kind = 'expense';
}
