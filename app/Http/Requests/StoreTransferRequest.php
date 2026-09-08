<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTransferRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        foreach (['from_wallet_id', 'to_wallet_id'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => strtolower($this->input($field))]);
            }
        }
    }

    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    public function rules(): array
    {
        return [
            'from_wallet_id' => ['required', 'uuid', 'exists:wallets,wallet_id'],
            'to_wallet_id' => ['required', 'uuid', 'exists:wallets,wallet_id'],
            'amount' => ['required', 'numeric', 'gt:0', 'regex:/^\\d{1,13}(?:\\.\\d{1,2})?$/D'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'transaction_date' => ['required', 'date_format:d-m-Y H:i'],
            'category' => ['missing'],
        ];
    }
}
