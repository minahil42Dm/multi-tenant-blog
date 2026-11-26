<?php

namespace App\Http\Requests;

use App\Models\Tenant;
use App\Support\CentralAbility;
use App\Support\DomainName;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class DestroyTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(CentralAbility::MANAGE_TENANTS) === true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['confirm_domain' => DomainName::normalize($this->input('confirm_domain', ''))]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'confirm_domain' => [
                'required',
                'string',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($value !== self::confirmationValue($this->targetTenant())) {
                        $fail('Type the tenant\'s primary domain exactly to confirm deletion.');
                    }
                },
            ],
        ];
    }

    /**
     * A tenant left without domains by an earlier failure is confirmed with its ID instead.
     */
    public static function confirmationValue(Tenant $tenant): string
    {
        return $tenant->primaryDomainName() ?? (string) $tenant->getTenantKey();
    }

    public function targetTenant(): Tenant
    {
        return $this->route('tenant');
    }
}
