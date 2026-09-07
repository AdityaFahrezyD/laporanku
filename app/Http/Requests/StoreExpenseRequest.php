<?php

namespace App\Http\Requests;

class StoreExpenseRequest extends TransactionRequest
{
    protected string $kind = 'expense';
}
