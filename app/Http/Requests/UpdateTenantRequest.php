<?php

namespace App\Http\Requests;

use App\Support\CentralAbility;
use Illuminate\Foundation\Http\FormRequest;

class UpdateTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(CentralAbility::MANAGE_TENANTS) === true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('brand_color')) {
            $this->merge(['brand_color' => strtolower(trim($this->string('brand_color')->toString()))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'brand_color' => ['required', 'regex:/^#[0-9a-f]{6}$/'],
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ];
    }
}
