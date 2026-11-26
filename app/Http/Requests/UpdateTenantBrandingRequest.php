<?php

namespace App\Http\Requests;

use App\Support\CentralAbility;
use App\Support\TenantBranding;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTenantBrandingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(CentralAbility::MANAGE_TENANTS) === true;
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (['brand_color', 'accent_color'] as $color) {
            if ($this->has($color)) {
                $normalized[$color] = strtolower(trim($this->string($color)->toString()));
            }
        }

        foreach (['support_email', 'sidebar_tagline'] as $text) {
            if ($this->has($text)) {
                $value = trim($this->string($text)->toString());
                $normalized[$text] = $value === '' ? null : $value;
            }
        }

        if ($this->has('support_email') && $normalized['support_email'] !== null) {
            $normalized['support_email'] = strtolower($normalized['support_email']);
        }

        $links = $this->input('footer_links');

        if (is_array($links)) {
            $normalized['footer_links'] = collect($links)
                ->map(fn (mixed $link) => is_array($link) ? [
                    'label' => trim((string) ($link['label'] ?? '')),
                    'url' => trim((string) ($link['url'] ?? '')),
                ] : $link)
                ->reject(fn (mixed $link) => is_array($link) && $link['label'] === '' && $link['url'] === '')
                ->values()
                ->all();
        }

        $this->merge($normalized);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'brand_color' => ['required', 'regex:/^#[0-9a-f]{6}$/'],
            'accent_color' => ['required', 'regex:/^#[0-9a-f]{6}$/'],
            'logo' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:max_width=4096,max_height=4096'],
            'favicon' => ['nullable', 'file', 'mimes:png,ico', 'max:512'],
            'remove_favicon' => ['sometimes', 'boolean'],
            'support_email' => ['nullable', 'string', 'email:rfc', 'max:254'],
            'sidebar_style' => ['required', Rule::in(TenantBranding::SIDEBAR_STYLES)],
            'sidebar_tagline' => ['nullable', 'string', 'max:80'],
            'footer_links' => ['nullable', 'array', 'max:'.TenantBranding::MAX_FOOTER_LINKS],
            'footer_links.*' => ['array:label,url'],
            'footer_links.*.label' => ['required', 'string', 'max:40'],
            'footer_links.*.url' => ['required', 'string', 'max:2048', 'url:http,https'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'brand_color' => 'primary color',
            'accent_color' => 'accent color',
            'support_email' => 'support email',
            'sidebar_style' => 'sidebar style',
            'sidebar_tagline' => 'sidebar tagline',
            'footer_links.*.label' => 'link label',
            'footer_links.*.url' => 'link URL',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'favicon.mimes' => 'The favicon must be a PNG or ICO file.',
            'logo.uploaded' => 'The logo failed to upload. Please try again.',
            'favicon.uploaded' => 'The favicon failed to upload. Please try again.',
        ];
    }
}
