<?php

namespace App\Http\Requests;

/**
 * Validates one step of the guided tenant creation flow without creating anything.
 */
class ValidateTenantStepRequest extends StoreTenantRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return self::stepRules($this->step());
    }

    public function step(): int
    {
        return (int) $this->route('step');
    }
}
