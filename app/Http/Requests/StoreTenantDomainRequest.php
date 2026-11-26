<?php

namespace App\Http\Requests;

use App\Support\CentralAbility;
use App\Support\DomainName;
use Illuminate\Foundation\Http\FormRequest;

class StoreTenantDomainRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(CentralAbility::MANAGE_TENANTS) === true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('domain')) {
            $this->merge(['domain' => DomainName::normalize($this->input('domain'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'domain' => ['required', ...DomainName::rules()],
        ];
    }
}
