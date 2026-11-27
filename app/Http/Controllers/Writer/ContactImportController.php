<?php

namespace App\Http\Controllers\Writer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Writer\ImportContactsRequest;
use App\Jobs\ImportContacts;
use App\Models\Contact;
use App\Models\ContactImport;
use App\Models\Writer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ContactImportController extends Controller
{
    public function create(): Response
    {
        Gate::authorize('create', Contact::class);

        return Inertia::render('Writer/Contacts/Import');
    }

    public function store(ImportContactsRequest $request): RedirectResponse
    {
        $file = $request->file('file');
        $path = $file->store('contact-imports');

        $import = new ContactImport([
            'status' => 'pending',
            'original_filename' => $file->getClientOriginalName(),
            'path' => $path,
        ]);
        $import->author()->associate($this->actor());
        $import->save();

        ImportContacts::dispatch($import->id, (string) tenant()->getTenantKey());

        return redirect()
            ->route('writer.contacts.imports.show', $import)
            ->with('success', 'Import submitted.');
    }

    public function show(ContactImport $import): Response
    {
        Gate::authorize('viewAny', Contact::class);

        $actor = $this->actor();

        abort_unless(
            $actor->isTenantAdmin() || (int) $import->user_id === (int) $actor->getKey(),
            403,
        );

        return Inertia::render('Writer/Contacts/ImportResult', [
            'import' => [
                'id' => $import->id,
                'status' => $import->status,
                'filename' => $import->original_filename,
                'summary' => $import->summary ?? [
                    'created' => 0,
                    'skipped' => 0,
                    'failed' => 0,
                    'errors' => [],
                ],
            ],
        ]);
    }

    private function actor(): Writer
    {
        $writer = auth('writer')->user();

        abort_unless($writer instanceof Writer, 403);

        return $writer;
    }
}
