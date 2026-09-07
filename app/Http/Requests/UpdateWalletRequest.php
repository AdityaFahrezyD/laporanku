<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateWalletRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    public function rules(): array
    {
        $required = $this->isMethod('PATCH') ? ['sometimes', 'required'] : ['required'];

        return [
            'name' => [...$required, 'string', 'max:50'],
            'type' => [...$required, 'in:cash,bank,ewallet'],
            'balance' => ['missing'],
            'is_active' => [...$required, 'boolean'],
        ];
    }
}
