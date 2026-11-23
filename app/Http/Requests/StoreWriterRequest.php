<?php

namespace App\Http\Requests;

use App\Models\Tenant;
use App\Models\Writer;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class StoreWriterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_admin === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'bail',
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($this->emailExistsInTenant((string) $value)) {
                        $fail(trans('validation.unique', ['attribute' => $attribute]));
                    }
                },
            ],
            'password' => ['required', 'confirmed', Password::defaults()],
        ];
    }

    public function targetTenant(): Tenant
    {
        return $this->route('tenant');
    }

    private function emailExistsInTenant(string $email): bool
    {
        return (bool) $this->targetTenant()->run(
            fn () => Writer::query()->where('email', $email)->exists()
        );
    }
}
