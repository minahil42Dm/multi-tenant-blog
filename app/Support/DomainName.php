<?php

namespace App\Support;

use Closure;
use Illuminate\Validation\Rule;

/**
 * Normalization and validation rules shared by every form that assigns a tenant domain.
 */
final class DomainName
{
    private const HOSTNAME_PATTERN = '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/';

    public static function normalize(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        return rtrim(strtolower(trim($value)), '.');
    }

    /**
     * @return array<int, mixed>
     */
    public static function rules(): array
    {
        return [
            'string',
            'max:253',
            'regex:'.self::HOSTNAME_PATTERN,
            function (string $attribute, mixed $value, Closure $fail): void {
                if (filter_var($value, FILTER_VALIDATE_IP) !== false) {
                    $fail('The :attribute must be a domain name, not an IP address.');
                }
            },
            Rule::notIn(config('tenancy.central_domains', [])),
            Rule::unique('domains', 'domain'),
        ];
    }
}
