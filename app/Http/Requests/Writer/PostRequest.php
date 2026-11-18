<?php

namespace App\Http\Requests\Writer;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('writer') !== null;
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('published_at') === '') {
            $this->merge(['published_at' => null]);
        }

        if (! $this->hasFile('cover_image')) {
            $this->merge(['cover_image' => null]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'status' => ['required', Rule::in(['draft', 'scheduled', 'published'])],
            'published_at' => [
                Rule::excludeIf(fn () => $this->input('status') === 'draft'),
                Rule::requiredIf(fn () => $this->input('status') !== 'draft'),
                'nullable',
                'date',
                Rule::when(
                    fn () => $this->input('status') === 'scheduled',
                    ['after:now'],
                ),
            ],
            'cover_image' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:2048'],
            'remove_cover_image' => ['sometimes', 'boolean'],
        ];
    }
}
