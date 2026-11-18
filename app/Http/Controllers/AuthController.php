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
        if (! $user || ! Hash::check($credentials['password'], $user->password_hash)) {
            return back()->withErrors(['email' => 'The email or password is incorrect.'])
                ->onlyInput('email');
        }
        if (app()->environment(['local', 'testing'])
            && $user->status === 'Pending'
            && $user->roles()->where('slug', 'super-admin')->exists()) {
            $user->forceFill([
                'status' => 'Active',
                'email_verified_at' => $user->email_verified_at ?? now(),
            ])->save();
        }
        if ($user->status !== 'Active') {
            return back()->withErrors(['email' => 'Your account is pending HR approval or is not active.'])
                ->onlyInput('email');
        }
        if ($user->email_verified_at === null) {
            return back()->withErrors(['email' => 'Your email address has not been verified.'])
                ->onlyInput('email');
        }

        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        $defaultRoute = $user->roles()->where('slug', 'applicant')->exists()
            ? route('applicant.portal')
            : ($user->hasPermission('employees.manage') || $user->roles()->where('slug', 'super-admin')->exists()
                ? route('admin.dashboard')
                : route('stay-tuned'));

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
