<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\OnboardingRequest;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class RegistrationController extends Controller
{
    public function showRegistrationForm(): View
    {
        return view('auth.register');
    }

    public function register(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:150', 'unique:users,email'],
            'contact_number' => ['nullable', 'string', 'max:20'],
            'password' => ['required', 'confirmed', 'string', 'min:12', 'max:128'],
        ]);

        $email = strtolower(trim($validated['email']));
        if (User::where('email', $email)->exists()) {
            return back()->withErrors(['email' => 'An account with this email already exists.'])
                ->withInput($request->only('name', 'email', 'contact_number'));
        }

        $role = Role::where('slug', 'employee')->firstOrFail();

        DB::transaction(function () use ($validated, $email, $role): void {
            $user = User::create([
                'name' => trim($validated['name']),
                'email' => $email,
                'password_hash' => Hash::make($validated['password']),
                'status' => 'Pending',
            ]);
            $user->roles()->sync([$role->id]);
            OnboardingRequest::create([
                'user_id' => $user->id,
                'contact_number' => $validated['contact_number'] ?? null,
                'status' => 'Pending',
            ]);
            AuditLog::create([
                'event_key' => 'registration.submitted',
                'entity_type' => 'user',
                'entity_id' => (string) $user->id,
            ]);
        });

        return redirect()->route('login')->with(
            'status',
            'Registration submitted. HR must review and approve your account before you can sign in.',
        );
    }
}
