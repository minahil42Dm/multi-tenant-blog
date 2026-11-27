<?php

namespace App\Http\Requests\Writer;

use App\Models\ContactNote;
use App\Support\TenantAbility;
use Illuminate\Foundation\Http\FormRequest;

class ContactNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        $note = $this->route('note');

        if ($note instanceof ContactNote) {
            return $this->user('writer')?->can('update', $note) ?? false;
        }

        return TenantAbility::allows($this->user('writer'), TenantAbility::MANAGE_CONTACTS);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:5000'],
        ];
    }
}
