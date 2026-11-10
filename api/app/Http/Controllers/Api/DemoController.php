<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\DemoSandbox;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * One-click login into the public demo (2026-10-15). Since 2026-10-25 every
 * visitor's browser gets its OWN private sandbox (App\Support\DemoSandbox):
 *
 *   - first click: seed a fresh copy of the demo accounts, remember its
 *     token in an encrypted, httpOnly cookie, log in;
 *   - later clicks from the same browser: log back into that same copy;
 *   - a different browser/device never sees it.
 *
 * Sandboxes are deleted after config('demo.sandbox_hours'). 404s entirely
 * unless DEMO_MODE=true -- see config/demo.php.
 */
class DemoController extends Controller
{
    private const COOKIE = 'demo_sandbox';

    public function login(Request $request)
    {
        abort_unless(config('demo.enabled'), 404);

        $validated = $request->validate([
            'role' => ['required', 'in:client,therapist'],
        ]);
        $role = $validated['role'];

        $token = $request->cookie(self::COOKIE);
        $user  = DemoSandbox::isValidToken($token) ? DemoSandbox::find($token, $role) : null;
        $isNew = false;

        if (! $user) {
            // Cap fresh sandboxes per IP so nobody can fill the database.
            $key = 'demo-sandbox:'.$request->ip();
            if (RateLimiter::tooManyAttempts($key, config('demo.max_sandboxes_per_ip_per_day', 20))) {
                return response()->json([
                    'message' => 'Too many demo sessions from this network today — try again tomorrow.',
                ], 429);
            }
            RateLimiter::hit($key, 24 * 60 * 60);

            $token = Str::lower(Str::random(12));
            [$client, $therapist] = DemoSandbox::create($token);
            $user  = $role === 'therapist' ? $therapist : $client;
            $isNew = true;
        }

        Auth::login($user);
        $request->session()->regenerate();

        $response = response()->json($user->load('preferences'));

        // Only set on creation, so the cookie expires together with the sandbox.
        if ($isNew) {
            $response->withCookie(cookie(self::COOKIE, $token, (int) config('demo.sandbox_hours', 24) * 60));
        }

        return $response;
    }
}
