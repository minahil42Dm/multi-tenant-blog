<?php

namespace App\Http\Controllers\Writer;

use App\Enums\TenantUserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Writer\StoreTenantUserRequest;
use App\Http\Requests\Writer\UpdateTenantUserRequest;
use App\Models\Writer;
use App\Support\TenantAbility;
use App\Support\TenantUserGuard;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(): Response
    {
        $this->authorizeUsers();

        $actor = $this->actor();
        $adminCount = TenantUserGuard::adminCount();

        $users = Writer::query()
            ->withCount('posts')
            ->orderBy('name')
            ->get()
            ->map(fn (Writer $writer) => $this->present($writer, $actor, $adminCount))
            ->all();

        return Inertia::render('Writer/Users/Index', [
            'users' => $users,
        ]);
    }

    public function create(): Response
    {
        $this->authorizeUsers();

        return Inertia::render('Writer/Users/Create', [
            'roles' => $this->roles(),
        ]);
    }

    /**
     * @throws ValidationException
     */
    public function store(StoreTenantUserRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $writer = new Writer;

        $writer->forceFill([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'role' => TenantUserRole::from($validated['role']),
        ]);

        $this->save($writer);

        return redirect()
            ->route('writer.users.index')
            ->with('success', 'User created.');
    }

    public function edit(Writer $writer): Response
    {
        $this->authorizeUsers();

        return Inertia::render('Writer/Users/Edit', [
            'user' => $this->present($writer, $this->actor(), TenantUserGuard::adminCount()),
            'roles' => $this->roles(),
        ]);
    }

    /**
     * @throws ValidationException
     */
    public function update(UpdateTenantUserRequest $request, Writer $writer): RedirectResponse
    {
        $validated = $request->validated();
        $role = TenantUserRole::from($validated['role']);

        DB::transaction(function () use ($writer, $validated, $role): void {
            $locked = Writer::query()->whereKey($writer->getKey())->lockForUpdate()->firstOrFail();
            $blocked = TenantUserGuard::demotionBlocked($this->actor(), $locked, $role);

            if ($blocked !== null) {
                abort(403, $blocked);
            }

            $attributes = [
                'name' => $validated['name'],
                'email' => $validated['email'],
                'role' => $role,
            ];

            if (filled($validated['password'] ?? null)) {
                $attributes['password'] = $validated['password'];
            }

            $locked->forceFill($attributes);
            $this->save($locked);
        });

        return redirect()
            ->route('writer.users.index')
            ->with('success', 'User updated.');
    }

    /**
     * @throws ValidationException
     */
    public function destroy(Writer $writer): RedirectResponse
    {
        $this->authorizeUsers();

        DB::transaction(function () use ($writer): void {
            $locked = Writer::query()->whereKey($writer->getKey())->lockForUpdate()->firstOrFail();
            $blocked = TenantUserGuard::deletionBlocked($this->actor(), $locked);

            if ($blocked !== null) {
                abort(403, $blocked);
            }

            if ($locked->posts()->exists()) {
                throw ValidationException::withMessages([
                    'user' => 'This user still has posts. Delete those posts before deleting the user.',
                ]);
            }

            if ($locked->contacts()->exists()) {
                throw ValidationException::withMessages([
                    'user' => 'This user still owns contacts. Delete or reassign those contacts before deleting the user.',
                ]);
            }

            $locked->delete();
        });

        return redirect()
            ->route('writer.users.index')
            ->with('success', 'User deleted.');
    }

    private function authorizeUsers(): void
    {
        abort_unless(TenantAbility::allows($this->actor(), TenantAbility::MANAGE_USERS), 403);
    }

    private function actor(): Writer
    {
        $writer = auth('writer')->user();

        abort_unless($writer instanceof Writer, 403);

        return $writer;
    }

    /**
     * @throws ValidationException
     */
    private function save(Writer $writer): void
    {
        try {
            $writer->save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'email' => trans('validation.unique', ['attribute' => 'email']),
            ]);
        } catch (AuthorizationException $exception) {
            $fields = array_values(array_filter([
                $writer->isDirty('email') ? 'email' : null,
                $writer->isDirty('password') ? 'password' : null,
            ]));

            throw ValidationException::withMessages(array_fill_keys(
                $fields === [] ? ['email'] : $fields,
                $exception->getMessage(),
            ));
        }
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function roles(): array
    {
        return array_map(fn (TenantUserRole $role) => [
            'value' => $role->value,
            'label' => $role->label(),
        ], TenantUserRole::cases());
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Writer $writer, Writer $actor, int $adminCount): array
    {
        $isSelf = (int) $writer->getKey() === (int) $actor->getKey();
        $isLastAdmin = $writer->isTenantAdmin() && $adminCount <= 1;
        $postCount = (int) ($writer->posts_count ?? $writer->posts()->count());

        return [
            'id' => $writer->id,
            'name' => $writer->name,
            'email' => $writer->email,
            'role' => ($writer->role ?? TenantUserRole::Writer)->value,
            'role_label' => ($writer->role ?? TenantUserRole::Writer)->label(),
            'created_at' => $writer->created_at?->toDateString(),
            'is_self' => $isSelf,
            'post_count' => $postCount,
            'can_delete' => ! $isSelf && ! $isLastAdmin && $postCount === 0,
            'can_change_role' => ! $writer->isTenantAdmin() || (! $isSelf && ! $isLastAdmin),
        ];
    }
}
