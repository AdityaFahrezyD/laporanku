<?php

namespace App\Http\Requests;

class UpdateIncomeRequest extends TransactionRequest
{
    protected string $kind = 'income';
}
