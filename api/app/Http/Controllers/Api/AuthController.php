<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            // 2026-08-17: role picked at signup instead of defaulting
            // everyone to 'client' and never offering a way to become a
            // 'therapist' — see docs/therapist-bridge.md. Optional + falls
            // back to the migration's own default so existing callers that
            // don't send it are unaffected.
            'role' => ['sometimes', 'in:client,therapist'],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => $validated['role'] ?? 'client',
        ]);

        // Preferences (tone, nudge level, product mode, theme) are all
        // journaling concerns — a therapist account never journals, so it
        // never gets a preferences row. OnboardingController::store() now
        // guards against this null case explicitly; the frontend router
        // also never routes a therapist to onboarding in the first place
        // (see router/index.js).
        if ($user->role === 'client') {
            $user->preferences()->create([
                'tone' => 'gentle',
                'nudge_level' => 2,
                'language' => 'en',
                'product_mode' => 'solo',
                'theme' => 'dusk',
            ]);
        }

        Auth::login($user);
        $request->session()->regenerate();

        return response()->json($user);
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        $request->session()->regenerate();

        return response()->json($request->user());
    }

    public function logout(Request $request)
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }
}
