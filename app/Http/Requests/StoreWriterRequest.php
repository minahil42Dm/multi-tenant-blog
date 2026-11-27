<?php

namespace App\Http\Requests;

use App\Enums\TenantUserRole;
use App\Http\Controllers\Admin\WriterController;
use App\Models\Tenant;
use App\Models\Writer;
use App\Support\CentralAbility;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreWriterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(CentralAbility::MANAGE_TENANT_USERS) === true
            && $this->targetTenant()->isActive();
    }

    /**
     * @throws AuthorizationException
     */
    protected function failedAuthorization(): void
    {
        if ($this->user()?->can(CentralAbility::MANAGE_TENANT_USERS) === true) {
            throw new AuthorizationException(WriterController::SUSPENDED_TENANT_MESSAGE);
        }

        parent::failedAuthorization();
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
            'role' => ['required', Rule::enum(TenantUserRole::class)],
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
