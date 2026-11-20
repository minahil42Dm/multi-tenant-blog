<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreWriterRequest;
use App\Models\Tenant;
use App\Models\Writer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class WriterController extends Controller
{
    public function create(string $centralDomain, Tenant $tenant): Response
    {
        return Inertia::render('Admin/Tenants/Writers/Create', [
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'domain' => $tenant->domains()->value('domain'),
            ],
        ]);
    }

    /**
     * @throws ValidationException
     */
    public function store(StoreWriterRequest $request, string $centralDomain, Tenant $tenant): RedirectResponse
    {
        $attributes = $request->safe()->only(['name', 'email', 'password']);

        $created = $tenant->run(function () use ($attributes): bool {
            try {
                Writer::create($attributes);
            } catch (UniqueConstraintViolationException) {
                return false;
            }

            return true;
        });

        if (! $created) {
            throw ValidationException::withMessages([
                'email' => trans('validation.unique', ['attribute' => 'email']),
            ]);
        }

        return redirect()
            ->route('admin.tenants.index')
            ->with('success', 'Writer created for '.$tenant->name.'.');
    }
}
