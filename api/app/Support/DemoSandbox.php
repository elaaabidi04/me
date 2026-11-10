<?php

namespace App\Support;

use App\Models\User;
use Database\Seeders\PublicDemoSeeder;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * 2026-10-25: one private copy of the demo per visitor's browser.
 *
 * Every account in a sandbox has an email like `maya.<token>@sandbox.demo`.
 * The browser keeps <token> in an encrypted, httpOnly cookie
 * (DemoController), so a visitor comes back to their own copy and nobody
 * ever sees another visitor's writing. Sandboxes are deleted
 * config('demo.sandbox_hours') after creation; everything else cascades off
 * users.id.
 *
 * (A MAC address can't be used for this -- browsers never expose it and it
 * doesn't cross routers; an IP is shared by everyone on the same Wi-Fi.)
 */
class DemoSandbox
{
    public const DOMAIN = 'sandbox.demo';

    public static function email(string $name, string $token): string
    {
        return "{$name}.{$token}@".self::DOMAIN;
    }

    public static function isValidToken(?string $token): bool
    {
        return is_string($token) && preg_match('/^[a-z0-9]{12}$/', $token) === 1;
    }

    public static function isDemoEmail(?string $email): bool
    {
        return is_string($email) && str_ends_with(strtolower($email), '@'.self::DOMAIN);
    }

    /** Token part of a sandbox email, or null. */
    public static function tokenOf(?string $email): ?string
    {
        if (! self::isDemoEmail($email)) {
            return null;
        }
        $local = strstr($email, '@', true);

        return substr($local, strrpos($local, '.') + 1) ?: null;
    }

    public static function users(string $token): Collection
    {
        return User::where('email', 'like', '%.'.$token.'@'.self::DOMAIN)->get();
    }

    /** The client or therapist account of an existing sandbox. */
    public static function find(string $token, string $role): ?User
    {
        $name = $role === 'therapist' ? 'amel' : 'maya';

        return User::where('email', self::email($name, $token))->first();
    }

    /** Seeds a brand-new sandbox (~1–2s). Returns [client, therapist]. */
    public static function create(string $token): array
    {
        $seeder = app(PublicDemoSeeder::class);
        $seeder->setContainer(app());
        $seeder->setCommand(self::silentCommand());
        $seeder->__invoke(['token' => $token]);

        return [self::find($token, 'client'), self::find($token, 'therapist')];
    }

    /** Deletes sandboxes older than $hours. Returns the number of accounts removed. */
    public static function prune(?int $hours = null): int
    {
        $hours ??= (int) config('demo.sandbox_hours', 24);

        $old = User::where('email', 'like', '%@'.self::DOMAIN)
            ->where('created_at', '<', now()->subHours($hours));
        self::forgetTries((clone $old)->pluck('id'));

        return $old->delete();
    }

    /** Deletes every sandbox, plus the old shared demo accounts from before 2026-10-25. */
    public static function wipeAll(): int
    {
        DB::table('demo_pool')->delete();
        self::forgetTries(User::where('email', 'like', '%@'.self::DOMAIN)->pluck('id'));

        return User::where('email', 'like', '%@'.self::DOMAIN)->delete()
            + User::whereIn('email', config('demo.legacy_emails', []))->delete();
    }

    /** The seeders print progress via $this->command; outside artisan, send it nowhere. */
    private static function silentCommand(): Command
    {
        $command = new Command();
        $command->setLaravel(app());
        $command->setOutput(new OutputStyle(new ArrayInput([]), new NullOutput()));

        return $command;
    }

    // ------------------------------------------------------------------
    // 2026-11-10: a demo copy gets config('demo.entries_per_visitor')
    // journal entries (AI tries) for its whole 24h life.

    /** 2026-11-10: the admin's own test sessions (POST /api/admin/demo-login) have no caps. */
    public static function unlimited(?\Illuminate\Http\Request $request = null): bool
    {
        $request ??= request();

        return $request->hasSession() && (bool) $request->session()->get('demo_unlimited');
    }

    public static function triesKey(User $user): string
    {
        return 'demo-entries:'.$user->id;
    }

    public static function triesLeft(User $user): int
    {
        $max = (int) config('demo.entries_per_visitor', 3);

        return max(0, $max - (int) \Illuminate\Support\Facades\RateLimiter::attempts(self::triesKey($user)));
    }

    public static function useTry(User $user): void
    {
        \Illuminate\Support\Facades\RateLimiter::hit(self::triesKey($user), max(1, (int) config('demo.sandbox_hours', 24)) * 3600);
    }

    /** Ids can be reused after a delete (SQLite) -- a new copy starts with all its tries. */
    private static function forgetTries(iterable $ids): void
    {
        foreach ($ids as $id) {
            \Illuminate\Support\Facades\RateLimiter::clear('demo-entries:'.$id);
        }
    }

    // ------------------------------------------------------------------
    // 2026-11-10: a small pool of copies built AHEAD of time. Seeding one
    // is ~700 queries -- seconds on a laptop, 20s+ on a small free host --
    // so the scheduler keeps config('demo.pool_size') ready and a click
    // just claims one.

    /** Takes a ready copy out of the pool; null when it's empty. */
    public static function claim(): ?string
    {
        for ($i = 0; $i < 5; $i++) {
            $row = DB::table('demo_pool')->orderBy('id')->first();
            if (! $row) {
                return null;
            }
            // the DELETE is the lock: if another visitor got there first it
            // removes 0 rows and we try the next one
            if (DB::table('demo_pool')->where('id', $row->id)->delete() !== 1) {
                continue;
            }
            if (! self::find($row->token, 'client')) {
                continue;   // pruned or wiped in the meantime
            }
            // its 24h (and the "what did the visitor write" cut-off on
            // /admin and in the coach's history) start now, not when built
            User::where('email', 'like', '%.'.$row->token.'@'.self::DOMAIN)
                ->update(['created_at' => now()]);

            return $row->token;
        }

        return null;
    }

    /** Tops the pool up to $size; copies older than pool_max_age_hours are replaced. Returns how many were built. */
    public static function fillPool(?int $size = null): int
    {
        $size ??= (int) config('demo.pool_size', 3);
        $maxAge = (int) config('demo.pool_max_age_hours', 4);

        // stale copies: their "today" has drifted -- throw them away
        $stale = DB::table('demo_pool')->where('created_at', '<', now()->subHours($maxAge))->get();
        foreach ($stale as $row) {
            if (DB::table('demo_pool')->where('id', $row->id)->delete() === 1) {
                self::forgetTries(self::users($row->token)->pluck('id'));
                User::where('email', 'like', '%.'.$row->token.'@'.self::DOMAIN)->delete();
            }
        }

        $built = 0;
        while (DB::table('demo_pool')->count() < $size && $built < $size) {
            $token = Str::lower(Str::random(12));
            self::create($token);
            DB::table('demo_pool')->insert(['token' => $token, 'created_at' => now()]);
            $built++;
        }

        return $built;
    }
}
