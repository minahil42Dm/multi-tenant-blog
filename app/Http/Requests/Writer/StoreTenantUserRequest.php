<?php

namespace App\Http\Requests\Writer;

use Illuminate\Validation\Rules\Password;

class StoreTenantUserRequest extends TenantUserRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => $this->emailRules(),
            'role' => $this->roleRules(),
            'password' => ['required', 'confirmed', Password::defaults()],
        ];
    }
}
