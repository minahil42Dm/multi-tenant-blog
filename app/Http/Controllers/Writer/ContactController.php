<?php

namespace App\Http\Controllers\Writer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Writer\ContactRequest;
use App\Http\Requests\Writer\ShareContactRequest;
use App\Models\Contact;
use App\Models\Tag;
use App\Models\Writer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ContactController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Contact::class);

        $search = trim((string) $request->query('search', ''));
        $tag = $request->filled('tag') ? (int) $request->query('tag') : null;
        $sort = in_array($request->query('sort'), ['name', 'email', 'company', 'created_at'], true)
            ? (string) $request->query('sort')
            : 'name';
        $direction = $request->query('direction') === 'desc' ? 'desc' : 'asc';

        $actor = $this->actor();

        $paginator = Contact::query()
            ->visibleTo($actor)
            ->with(['tags:id,name', 'owner:id,name'])
            ->withCount('sharedWith')
            ->search($search)
            ->when($tag, fn ($query) => $query->whereHas('tags', fn ($query) => $query->whereKey($tag)))
            ->orderBy($sort, $direction)
            ->orderBy('id')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Writer/Contacts/Index', [
            'contacts' => $paginator->getCollection()->map(fn (Contact $contact) => $this->present($contact, $actor))->values(),
            'tags' => $this->tags(),
            'filters' => [
                'search' => $search,
                'tag' => $tag ? (string) $tag : '',
                'sort' => $sort,
                'direction' => $direction,
            ],
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
                'total' => $paginator->total(),
                'links' => $paginator->linkCollection()->all(),
            ],
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Contact::class);

        return Inertia::render('Writer/Contacts/Create', [
            'tags' => $this->tags(),
        ]);
    }

    public function store(ContactRequest $request): RedirectResponse
    {
        $contact = new Contact($this->attributes($request));
        $contact->owner()->associate($this->actor());

        try {
            $contact->save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'email' => trans('validation.unique', ['attribute' => 'email']),
            ]);
        }

        $contact->tags()->sync($request->validated('tags') ?? []);

        return redirect()
            ->route('writer.contacts.show', $contact)
            ->with('success', 'Contact created.');
    }

    public function show(Contact $contact): Response
    {
        Gate::authorize('view', $contact);

        $contact->load(['tags:id,name', 'notes.author:id,name']);
        $actor = $this->actor();

        return Inertia::render('Writer/Contacts/Show', [
            'contact' => $this->present($contact, $actor),
            'shareUsers' => $actor->can('share', $contact) ? $this->shareUsers($contact) : [],
            'notes' => $contact->notes->map(fn ($note) => [
                'id' => $note->id,
                'body' => $note->body,
                'author' => $note->author?->name ?? 'Former user',
                'created_at' => $note->created_at?->toDayDateTimeString(),
                'can_edit' => $actor->can('update', $note),
            ])->values(),
            'tags' => $this->tags(),
        ]);
    }

    public function edit(Contact $contact): Response
    {
        Gate::authorize('update', $contact);

        $contact->load('tags:id,name');

        return Inertia::render('Writer/Contacts/Edit', [
            'contact' => $this->present($contact, $this->actor()),
            'tags' => $this->tags(),
        ]);
    }

    public function updateShares(ShareContactRequest $request, Contact $contact): RedirectResponse
    {
        $ownerId = (int) $contact->owner_id;
        $ids = collect($request->validated('users'))
            ->map(fn (mixed $id) => (int) $id)
            ->reject(fn (int $id) => $id === $ownerId)
            ->unique()
            ->values()
            ->all();

        $contact->sharedWith()->sync($ids);

        return redirect()
            ->route('writer.contacts.show', $contact)
            ->with('success', 'Sharing updated.');
    }

    public function update(ContactRequest $request, Contact $contact): RedirectResponse
    {
        Gate::authorize('update', $contact);

        try {
            $contact->update($this->attributes($request));
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'email' => trans('validation.unique', ['attribute' => 'email']),
            ]);
        }

        $contact->tags()->sync($request->validated('tags') ?? []);

        return redirect()
            ->route('writer.contacts.show', $contact)
            ->with('success', 'Contact updated.');
    }

    public function destroy(Contact $contact): RedirectResponse
    {
        Gate::authorize('delete', $contact);

        $contact->delete();

        return redirect()
            ->route('writer.contacts.index')
            ->with('success', 'Contact deleted.');
    }

    /**
     * @return array<string, ?string>
     */
    private function attributes(ContactRequest $request): array
    {
        $validated = $request->validated();

        return [
            'name' => $validated['name'],
            'email' => $validated['email'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'company' => $validated['company'] ?? null,
            'job_title' => $validated['job_title'] ?? null,
            'website' => $validated['website'] ?? null,
            'location' => $validated['location'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Contact $contact, Writer $actor): array
    {
        $contact->loadMissing('owner:id,name');

        return [
            'id' => $contact->id,
            'name' => $contact->name,
            'email' => $contact->email,
            'phone' => $contact->phone,
            'company' => $contact->company,
            'job_title' => $contact->job_title,
            'website' => $contact->website,
            'location' => $contact->location,
            'created_at' => $contact->created_at?->toFormattedDateString(),
            'owner' => $contact->owner?->name ?? 'Former user',
            'shared' => ($contact->shared_with_count ?? $contact->sharedWith()->count()) > 0,
            'can_update' => $actor->can('update', $contact),
            'can_delete' => $actor->can('delete', $contact),
            'can_share' => $actor->can('share', $contact),
            'tags' => $contact->relationLoaded('tags')
                ? $contact->tags->map(fn (Tag $tag) => ['id' => $tag->id, 'name' => $tag->name])->values()
                : [],
        ];
    }

    /**
     * @return array<int, array{id: int, name: string, email: string, shared: bool}>
     */
    private function shareUsers(Contact $contact): array
    {
        $shared = $contact->sharedWith()->pluck('users.id')->map(fn (mixed $id) => (int) $id)->all();

        return Writer::query()
            ->whereKeyNot($contact->owner_id)
            ->orderBy('name')
            ->get(['id', 'name', 'email'])
            ->map(fn (Writer $writer) => [
                'id' => $writer->id,
                'name' => $writer->name,
                'email' => $writer->email,
                'shared' => in_array((int) $writer->id, $shared, true),
            ])
            ->all();
    }

    /**
     * @return array<int, array{id: int, name: string}>
     */
    private function tags(): array
    {
        return Tag::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Tag $tag) => ['id' => $tag->id, 'name' => $tag->name])
            ->all();
    }

    private function actor(): Writer
    {
        $writer = auth('writer')->user();

        abort_unless($writer instanceof Writer, 403);

        return $writer;
    }
}
