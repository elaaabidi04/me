<?php

use App\Models\User;
use App\Support\DemoSandbox;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// 2026-10-25: public demo = one private sandbox per visitor's browser
// (App\Support\DemoSandbox), created on their first click and deleted after
// config('demo.sandbox_hours'). Only ever touches @sandbox.demo accounts
// (plus the old shared demo accounts on reset) -- everything else cascades
// off users.id, so real accounts are never affected.
Artisan::command('demo:prune', function () {
    $n = DemoSandbox::prune();
    $this->info("Removed {$n} expired sandbox account(s).");
})->purpose('Delete demo sandboxes older than DEMO_SANDBOX_HOURS');

Artisan::command('demo:reset', function () {
    $n = DemoSandbox::wipeAll();
    $this->info("Removed {$n} demo account(s). Visitors get a fresh sandbox on their next click.");
})->purpose('Delete every demo sandbox right now');

// 2026-10-25: create (or reset the password of) the owner account behind
// /admin. Uses ADMIN_EMAIL from .env unless an email is passed.
Artisan::command('admin:create {email?}', function (?string $email = null) {
    $email = $email ?: config('demo.admin_email');
    if (! $email) {
        $this->error('Set ADMIN_EMAIL in .env first (or pass an email).');
        return 1;
    }
    if (DemoSandbox::isDemoEmail($email) || in_array($email, config('demo.legacy_emails', []), true)) {
        $this->error('That is one of the public demo accounts — pick your own email.');
        return 1;
    }

    $password = $this->secret('Password for '.$email.' (min 12 characters)');
    if (! $password || strlen($password) < 12) {
        $this->error('Password must be at least 12 characters.');
        return 1;
    }

    $user = User::firstOrNew(['email' => $email]);
    $user->name ??= 'Admin';
    $user->role ??= 'client';
    $user->password = $password; // hashed by the model cast
    $user->save();

    if (strcasecmp((string) config('demo.admin_email'), $email) !== 0) {
        $this->warn("Saved — now set ADMIN_EMAIL={$email} in .env so this account gets /admin.");
    } else {
        $this->info("Admin account ready: {$email}. Log in via the \"admin\" link on /login.");
    }
})->purpose('Create the owner account for the /admin page');

// 2026-11-07: tone check for the journaling coach without writing entries.
//   php artisan coach:try "i have an exam tomorrow"
//   php artisan coach:try "had an amazing day" --mode=witness
//   php artisan coach:try "hey" --model=nvidia/nemotron-3.5-lightning-30b-a3b --times=3
// Uses the real coach prompt + sanitizer (see CoachService::tryReply).
//   php artisan coach:try "it was so nice" --before="i had a nice day=>glad it was a good one ❤️ anything stand out?"
//     (--before can be repeated: earlier entries today, each as "entry=>coach reply")
Artisan::command('coach:try {text} {--mode=engage : engage (dig in) or witness (just listen)} {--model= : defaults to NVIDIA_MODEL_COACH} {--times=1} {--before=* : earlier "entry=>coach reply" pairs from today}', function (string $text) {
    $mode  = in_array($this->option('mode'), ['engage', 'witness'], true) ? $this->option('mode') : 'engage';
    $model = $this->option('model') ?: config('nvidia.model_coach');
    $this->line("<comment>{$model}</comment> · mode <comment>{$mode}</comment>");

    $coach = app(\App\Services\CoachService::class);
    for ($i = 1, $n = max(1, (int) $this->option('times')); $i <= $n; $i++) {
        $earlier = array_values(array_filter(array_map(
            fn ($pair) => str_contains($pair, '=>') ? array_map('trim', explode('=>', $pair, 2)) : null,
            (array) $this->option('before')
        )));
        $r = $coach->tryReply($text, $mode, $model, $earlier);
        if ($r['reply'] === null) {
            $this->error("  #{$i} no usable reply ({$r['seconds']}s)".($r['raw'] ? ' — filtered: '.mb_substr($r['raw'], 0, 160) : ' — see storage/logs/laravel.log'));
            continue;
        }
        $this->line("  #{$i} ({$r['seconds']}s)".($r['flagged'] ? ' <comment>[injection detector: flagged]</comment>' : '')." {$r['reply']}");
    }
})->purpose('Try the journaling coach on a piece of text (no entry is created)');

// Needs the host to run `php artisan schedule:run` every minute (cron) or
// `php artisan schedule:work` as a worker process. No-op unless DEMO_MODE=true.
Schedule::command('demo:prune')
    ->hourly()
    ->when(fn () => config('demo.enabled'))
    ->withoutOverlapping();
