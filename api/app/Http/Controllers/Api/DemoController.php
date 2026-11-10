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

            // 2026-11-10: take a copy built ahead of time (instant); build
            // one on the spot only if the pool is empty
            $token = DemoSandbox::claim();
            if (! $token) {
                $token = Str::lower(Str::random(12));
                DemoSandbox::create($token);
            }
            $user  = DemoSandbox::find($token, $role);
            $isNew = true;
        }

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->forget('demo_unlimited');   // only the admin route sets it

        // 2026-11-10: count the click for /admin (button + time only). Never
        // allowed to break a login.
        try {
            \Illuminate\Support\Facades\DB::table('demo_logins')->insert(['role' => $role, 'is_new' => $isNew, 'created_at' => now()]);
        } catch (\Throwable $e) {
            report($e);
        }

        $response = response()->json($user->load('preferences'));

        // Only set on creation, so the cookie expires together with the sandbox.
        if ($isNew) {
            $response->withCookie(cookie(self::COOKIE, $token, (int) config('demo.sandbox_hours', 24) * 60));
        }

        return $response;
    }

    /**
     * 2026-11-10: POST /api/admin/demo-login {role} (admin only). Same as a
     * visitor's click, but this session gets no caps: unlimited journal
     * entries and AI requests (DemoSandbox::unlimited). Logging out ends it.
     */
    public function adminLogin(Request $request)
    {
        abort_unless(config('demo.enabled'), 404);
        $role = $request->validate(['role' => ['required', 'in:client,therapist']])['role'];

        $token = $request->cookie(self::COOKIE);
        $user  = DemoSandbox::isValidToken($token) ? DemoSandbox::find($token, $role) : null;
        $isNew = false;
        if (! $user) {
            $token = DemoSandbox::claim() ?? Str::lower(Str::random(12));
            if (! DemoSandbox::find($token, 'client')) {
                DemoSandbox::create($token);
            }
            $user  = DemoSandbox::find($token, $role);
            $isNew = true;
        }

        // inside the auth:sanctum group the default guard is Sanctum's
        // request guard, which can't switch users -- use the session guard
        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put('demo_unlimited', true);

        $response = response()->json(array_merge($user->load('preferences')->toArray(), [
            'demo_tries_left' => null,
            'demo_unlimited'  => true,
        ]));
        if ($isNew) {
            $response->withCookie(cookie(self::COOKIE, $token, (int) config('demo.sandbox_hours', 24) * 60));
        }

        return $response;
    }
}
