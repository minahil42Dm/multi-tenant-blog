<?php

namespace App\Http\Requests\Writer;

use App\Models\Contact;
use App\Support\TenantAbility;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return TenantAbility::allows($this->user('writer'), TenantAbility::MANAGE_CONTACTS);
    }

    protected function prepareForValidation(): void
    {
        $optional = ['email', 'phone', 'company', 'job_title', 'website', 'location'];
        $merged = [];

        foreach ($optional as $field) {
            if ($this->input($field) === '') {
                $merged[$field] = null;
            }
        }

        if ($merged !== []) {
            $this->merge($merged);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $contact = $this->route('contact');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'nullable',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique('contacts', 'email')->ignore($contact instanceof Contact ? $contact->getKey() : null),
            ],
            'phone' => ['nullable', 'string', 'max:50'],
            'company' => ['nullable', 'string', 'max:255'],
            'job_title' => ['nullable', 'string', 'max:255'],
            'website' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['integer', Rule::exists('tags', 'id')],
        ];
    }
}
