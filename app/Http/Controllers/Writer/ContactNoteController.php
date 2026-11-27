<?php

namespace App\Http\Controllers\Writer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Writer\ContactNoteRequest;
use App\Models\Contact;
use App\Models\ContactNote;
use App\Models\Writer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class ContactNoteController extends Controller
{
    public function store(ContactNoteRequest $request, Contact $contact): RedirectResponse
    {
        Gate::authorize('view', $contact);
        Gate::authorize('create', ContactNote::class);

        $note = new ContactNote($request->validated());
        $note->contact()->associate($contact);
        $note->author()->associate($this->actor());
        $note->save();

        return redirect()
            ->route('writer.contacts.show', $contact)
            ->with('success', 'Note added.');
    }

    public function update(ContactNoteRequest $request, Contact $contact, ContactNote $note): RedirectResponse
    {
        $this->belongsToContact($contact, $note);
        Gate::authorize('view', $contact);
        Gate::authorize('update', $note);

        $note->update($request->validated());

        return redirect()
            ->route('writer.contacts.show', $contact)
            ->with('success', 'Note updated.');
    }

    public function destroy(Contact $contact, ContactNote $note): RedirectResponse
    {
        $this->belongsToContact($contact, $note);
        Gate::authorize('view', $contact);
        Gate::authorize('delete', $note);

        $note->delete();

        return redirect()
            ->route('writer.contacts.show', $contact)
            ->with('success', 'Note deleted.');
    }

    private function belongsToContact(Contact $contact, ContactNote $note): void
    {
        abort_unless((int) $note->contact_id === (int) $contact->getKey(), 404);
    }

    private function actor(): Writer
    {
        $writer = auth('writer')->user();

        abort_unless($writer instanceof Writer, 403);

        return $writer;
    }
}
