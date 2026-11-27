<?php

namespace App\Http\Requests\Writer;

use App\Models\Contact;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ShareContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        $contact = $this->route('contact');
        $writer = $this->user('writer');

        return $contact instanceof Contact && $writer !== null && $writer->can('share', $contact);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'users' => ['present', 'array', 'max:100'],
            'users.*' => ['integer', 'distinct', Rule::exists('users', 'id')],
        ];
    }
}
