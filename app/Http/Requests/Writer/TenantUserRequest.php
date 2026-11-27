<?php

namespace App\Http\Requests\Writer;

use App\Enums\TenantUserRole;
use App\Models\Writer;
use App\Support\TenantAbility;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

abstract class TenantUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return TenantAbility::allows($this->user('writer'), TenantAbility::MANAGE_USERS);
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('password') === '') {
            $this->merge(['password' => null]);
        }
    }

    /**
     * @return array<int, mixed>
     */
    protected function emailRules(): array
    {
        return [
            'bail',
            'required',
            'string',
            'lowercase',
            'email',
            'max:255',
            function (string $attribute, mixed $value, Closure $fail): void {
                $query = Writer::query()->where('email', $value);
                $current = $this->route('writer');

                if ($current instanceof Writer) {
                    $query->whereKeyNot($current->getKey());
                }

                if ($query->exists()) {
                    $fail(trans('validation.unique', ['attribute' => $attribute]));
                }
            },
        ];
    }

    /**
     * @return array<int, mixed>
     */
    protected function roleRules(): array
    {
        return ['required', Rule::enum(TenantUserRole::class)];
    }
}
