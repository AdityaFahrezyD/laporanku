<?php

namespace App\Http\Requests;

class StoreIncomeRequest extends TransactionRequest
{
    protected string $kind = 'income';
}
