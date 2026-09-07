<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

abstract class TransactionRequest extends FormRequest
{
    protected string $kind;

    protected function prepareForValidation(): void
    {
        if ($this->kind !== 'transfer' && $this->isMethod('PUT') && ! $this->exists('category_id')) {
            $this->merge(['category_id' => null]);
        }
        foreach (['wallet_id', 'from_wallet_id', 'to_wallet_id', 'category_id'] as $field) {
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
        $required = $this->isMethod('PATCH') ? ['sometimes', 'required'] : ['required'];
        $rules = [
            'amount' => [...$required, 'numeric', 'gt:0', 'regex:/^\\d{1,13}(?:\\.\\d{1,2})?$/D'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'transaction_date' => [...$required, 'date_format:d-m-Y H:i'],
        ];
        foreach ($this->kind === 'transfer' ? ['from_wallet_id', 'to_wallet_id'] : ['wallet_id'] as $field) {
            $rules[$field] = [...$required, 'uuid', 'exists:wallets,wallet_id'];
        }
        $rules['category'] = ['missing'];
        if ($this->kind !== 'transfer') {
            $rules['category_id'] = ['sometimes', 'nullable', 'uuid', Rule::exists('categories', 'category_id')->where('type', $this->kind)];
        }

        return $rules;
    }
}
