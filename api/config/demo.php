<?php

/*
|--------------------------------------------------------------------------
| Public demo mode (2026-10-15)
|--------------------------------------------------------------------------
| For the hosted portfolio showcase. With DEMO_MODE=true:
|   - POST /api/demo/login logs a visitor straight into their own seeded
|     sandbox account (no credentials are ever published),
|   - each visitor's browser gets its own private copy of the seeded demo
|     accounts (App\Support\DemoSandbox), deleted after 24h,
|   - demo users get a tighter hourly cap on routes that call the LLM,
|   - a few account-level actions (unlinking the therapist, regenerating
|     the invite code) are blocked so one visitor can't break the demo
|     for the next one,
|   - the crisis resource line never includes the developer's personal
|     number (see config/crisis.php).
|
| Everything here is inert when DEMO_MODE is false (local dev default).
*/

return [
    'enabled' => (bool) env('DEMO_MODE', false),

    // 2026-10-25: every visitor's browser gets its own private copy of the
    // demo accounts (App\Support\DemoSandbox), deleted this many hours after
    // it was created. `php artisan demo:prune` runs hourly.
    'sandbox_hours' => (int) env('DEMO_SANDBOX_HOURS', 24),

    // New sandboxes one IP may create per day (each is ~120 seeded rows).
    // Returning visitors reuse theirs and don't count.
    // 2026-11-08: raised from 20 -- a school / office Wi-Fi is one IP for everyone on it.
    'max_sandboxes_per_ip_per_day' => (int) env('DEMO_MAX_SANDBOXES_PER_IP', 150),

    // One-click demo logins per network per minute (a whole class at once).
    'logins_per_minute_per_network' => (int) env('DEMO_LOGINS_PER_MINUTE', 60),

    // The shared accounts used before per-visitor sandboxes; `demo:reset`
    // deletes them if they're still around.
    'legacy_emails' => [
        'demo@me.app',
        'therapist@example.com',
        'nora@example.com',
        'karim@example.com',
        'lina@example.com',
        'yassine@example.com',
    ],

    // Max LLM-backed requests (new entry, memory confirm, shared-insight
    // chat) per visitor IP per hour while logged in as a demo user.
    // 2026-11-08: counted per demo copy (each visitor), plus a much higher
    // per-network ceiling against someone farming the API key.
    'ai_requests_per_hour' => (int) env('DEMO_AI_PER_HOUR', 15),
    'ai_requests_per_hour_per_network' => (int) env('DEMO_AI_PER_HOUR_PER_NETWORK', 300),

    // 2026-11-10: journal entries (each one = an AI coach reply) a visitor
    // gets in their demo copy, for its whole lifetime. Crisis entries are
    // never blocked by this.
    'entries_per_visitor' => (int) env('DEMO_ENTRIES', 3),

    // The one account allowed into /admin (what visitors wrote in the demo
    // accounts). Create it with `php artisan admin:create`. Unset = no admin.
    'admin_email' => env('ADMIN_EMAIL'),

    // 2026-11-07: blocked prompt-injection attempts email ADMIN_EMAIL at
    // most once per account per this many minutes (App\Support\InjectionGuard).
    'injection_mail_cooldown_minutes' => (int) env('INJECTION_MAIL_COOLDOWN', 10),
];
