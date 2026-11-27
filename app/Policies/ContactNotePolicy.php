<?php

namespace App\Policies;

use App\Models\ContactNote;
use App\Models\Writer;
use App\Support\TenantAbility;

class ContactNotePolicy
{
    public function create(Writer $writer): bool
    {
        return TenantAbility::allows($writer, TenantAbility::MANAGE_CONTACTS);
    }

    public function update(Writer $writer, ContactNote $note): bool
    {
        return $this->owns($writer, $note);
    }

    public function delete(Writer $writer, ContactNote $note): bool
    {
        return $this->owns($writer, $note);
    }

    private function owns(Writer $writer, ContactNote $note): bool
    {
        if (! TenantAbility::allows($writer, TenantAbility::MANAGE_CONTACTS)) {
            return false;
        }

        return $writer->isTenantAdmin() || (int) $note->user_id === (int) $writer->getKey();
    }
}
