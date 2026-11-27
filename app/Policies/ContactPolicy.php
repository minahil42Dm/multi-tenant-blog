<?php

namespace App\Policies;

use App\Models\Contact;
use App\Models\Writer;
use App\Support\TenantAbility;

/**
 * A contact is private to its owner. Tenant admins see every contact in the
 * tenant. A tenant user also sees contacts shared with them, and may add notes
 * there. Editing the record, deleting it, and changing the share list stay
 * with the owner and tenant admins.
 */
class ContactPolicy
{
    public function viewAny(Writer $writer): bool
    {
        return $this->usesContacts($writer);
    }

    public function view(Writer $writer, Contact $contact): bool
    {
        return $this->usesContacts($writer) && $this->visible($writer, $contact);
    }

    public function create(Writer $writer): bool
    {
        return $this->usesContacts($writer);
    }

    public function update(Writer $writer, Contact $contact): bool
    {
        return $this->usesContacts($writer) && $this->manages($writer, $contact);
    }

    public function delete(Writer $writer, Contact $contact): bool
    {
        return $this->update($writer, $contact);
    }

    public function share(Writer $writer, Contact $contact): bool
    {
        return $this->update($writer, $contact);
    }

    private function usesContacts(Writer $writer): bool
    {
        return TenantAbility::allows($writer, TenantAbility::MANAGE_CONTACTS);
    }

    private function visible(Writer $writer, Contact $contact): bool
    {
        if ($writer->isTenantAdmin() || (int) $contact->owner_id === (int) $writer->getKey()) {
            return true;
        }

        return $contact->sharedWith()->whereKey($writer->getKey())->exists();
    }

    private function manages(Writer $writer, Contact $contact): bool
    {
        return $writer->isTenantAdmin() || (int) $contact->owner_id === (int) $writer->getKey();
    }
}
