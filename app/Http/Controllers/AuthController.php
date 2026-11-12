<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function showLogin(): View
    {
        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', strtolower(trim($credentials['email'])))->first();
        if (! $user || ! Hash::check($credentials['password'], $user->password_hash)
            || $user->status !== 'Active' || $user->email_verified_at === null) {
            return back()->withErrors(['email' => 'The provided credentials are invalid, unverified, or the account is not active.'])
                ->onlyInput('email');
        }

        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        $defaultRoute = collect([
            'users.manage',
            'roles.manage',
            'departments.manage',
            'employees.manage',
            'onboarding.review',
            'audit.view',
        ])->contains(fn (string $permission) => $user->hasPermission($permission))
            ? route('admin.dashboard')
            : route('home');

        return redirect()->intended($defaultRoute);
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
