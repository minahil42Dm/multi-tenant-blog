<?php

namespace App\Http\Requests;

use App\Support\CentralAbility;
use App\Support\DomainName;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class StoreTenantRequest extends FormRequest
{
    public const STEPS = [1, 2, 3, 4];

    public const MAX_ADDITIONAL_DOMAINS = 5;

    public function authorize(): bool
    {
        return $this->user()?->can(CentralAbility::MANAGE_TENANTS) === true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('domain')) {
            $this->merge(['domain' => DomainName::normalize($this->input('domain'))]);
        }

        if (is_array($this->input('additional_domains'))) {
            $this->merge([
                'additional_domains' => collect($this->input('additional_domains'))
                    ->map(fn (mixed $domain) => DomainName::normalize($domain))
                    ->filter(fn (mixed $domain) => $domain !== '' && $domain !== null)
                    ->values()
                    ->all(),
            ]);
        }

        if ($this->has('brand_color')) {
            $this->merge(['brand_color' => strtolower(trim($this->string('brand_color')->toString()))]);
        }

        if ($this->has('admin_email')) {
            $this->merge(['admin_email' => strtolower(trim($this->string('admin_email')->toString()))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge(...array_map(fn (int $step) => self::stepRules($step), self::STEPS));
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'additional_domains.*' => 'additional domain',
            'admin_name' => 'admin name',
            'admin_email' => 'admin email',
            'admin_password' => 'admin password',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function stepRules(int $step): array
    {
        return match ($step) {
            1 => [
                'name' => ['required', 'string', 'max:255'],
            ],
            2 => [
                'domain' => ['required', ...DomainName::rules()],
                'additional_domains' => ['nullable', 'array', 'max:'.self::MAX_ADDITIONAL_DOMAINS],
                'additional_domains.*' => ['distinct', 'different:domain', ...DomainName::rules()],
            ],
            3 => [
                'brand_color' => ['required', 'regex:/^#[0-9a-f]{6}$/'],
                'logo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            ],
            4 => [
                'admin_name' => ['required', 'string', 'max:255'],
                'admin_email' => ['required', 'string', 'lowercase', 'email', 'max:255'],
                'admin_password' => ['required', 'confirmed', Password::defaults()],
            ],
        };
    }

    /**
     * @return array<int, string>
     */
    public function domains(): array
    {
        return [
            $this->validated('domain'),
            ...($this->validated('additional_domains') ?? []),
        ];
    }
}
