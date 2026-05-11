<?php

namespace App\Modules\Tenancy\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'contact_email' => ['required', 'email', 'unique:users,email'],
            'contact_phone' => ['nullable', 'string', 'max:20'],
            'industry' => ['nullable', 'string', 'max:100'],
        ];
    }
}
