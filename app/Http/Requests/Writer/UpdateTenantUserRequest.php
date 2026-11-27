<?php

namespace App\Http\Requests\Writer;

use App\Models\Writer;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateTenantUserRequest extends TenantUserRequest
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
            'password' => ['nullable', 'confirmed', Password::defaults()],
            'current_password' => [
                Rule::requiredIf(fn (): bool => $this->changingOwnPassword()),
                Rule::excludeIf(fn (): bool => ! $this->changingOwnPassword()),
                'current_password:writer',
            ],
        ];
    }

    /**
     * Another user's password is set by the admin, so only a self-service
     * password change has to prove knowledge of the current password.
     */
    private function changingOwnPassword(): bool
    {
        if (! filled($this->input('password'))) {
            return false;
        }

        $target = $this->route('writer');
        $actor = $this->user('writer');

        return $target instanceof Writer
            && $actor instanceof Writer
            && (int) $target->getKey() === (int) $actor->getKey();
    }
}
