<?php

namespace App\Http\Requests\Writer;

use App\Support\TenantAbility;
use Illuminate\Foundation\Http\FormRequest;

class ImportContactsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return TenantAbility::allows($this->user('writer'), TenantAbility::MANAGE_CONTACTS);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:1024'],
        ];
    }
}
