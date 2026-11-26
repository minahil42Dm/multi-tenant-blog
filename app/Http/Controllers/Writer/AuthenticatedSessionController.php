<?php

namespace App\Http\Controllers\Writer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Writer\LoginRequest;
use App\Support\Impersonation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class AuthenticatedSessionController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Writer/Auth/Login', [
            'status' => session('status'),
        ]);
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        return redirect()->intended(route('writer.dashboard'));
    }

    public function destroy(Request $request): SymfonyResponse
    {
        $impersonationReturnUrl = Impersonation::stop($request);

        Auth::guard('writer')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return $impersonationReturnUrl
            ? Inertia::location($impersonationReturnUrl)
            : redirect()->route('writer.login');
    }
}
