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

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
        ];
    }
}
