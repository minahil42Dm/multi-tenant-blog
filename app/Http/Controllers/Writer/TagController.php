<?php

namespace App\Http\Controllers\Writer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Writer\TagRequest;
use App\Models\Contact;
use App\Models\Tag;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class TagController extends Controller
{
    public function store(TagRequest $request): RedirectResponse
    {
        Gate::authorize('create', Contact::class);

        Tag::query()->create($request->validated());

        return redirect()
            ->route('writer.contacts.index')
            ->with('success', 'Tag created.');
    }

    public function update(TagRequest $request, Tag $tag): RedirectResponse
    {
        Gate::authorize('viewAny', Contact::class);

        $tag->update($request->validated());

        return redirect()
            ->route('writer.contacts.index')
            ->with('success', 'Tag updated.');
    }

    public function destroy(Tag $tag): RedirectResponse
    {
        Gate::authorize('viewAny', Contact::class);

        $tag->delete();

        return redirect()
            ->route('writer.contacts.index')
            ->with('success', 'Tag deleted.');
    }
}
