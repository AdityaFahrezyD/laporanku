<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateIncomeRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->isMethod('PUT') && ! $this->exists('category_id')) {
            $this->merge(['category_id' => null]);
        }
        foreach (['wallet_id', 'category_id'] as $field) {
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

        return [
            'wallet_id' => [...$required, 'uuid', 'exists:wallets,wallet_id'],
            'amount' => [...$required, 'numeric', 'gt:0', 'regex:/^\\d{1,13}(?:\\.\\d{1,2})?$/D'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'transaction_date' => [...$required, 'date_format:d-m-Y H:i'],
            'category' => ['missing'],
            'category_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('categories', 'category_id')->where('type', 'income')],
        ];
    }
}
