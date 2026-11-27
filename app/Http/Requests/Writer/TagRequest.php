<?php

namespace App\Http\Requests\Writer;

use App\Models\Tag;
use App\Support\TenantAbility;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TagRequest extends FormRequest
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
        $tag = $this->route('tag');

        return [
            'name' => [
                'required',
                'string',
                'max:50',
                Rule::unique('tags', 'name')->ignore($tag instanceof Tag ? $tag->getKey() : null),
            ],
        ];
    }
}
