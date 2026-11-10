<?php

namespace App\Support;

use App\Models\User;
use Database\Seeders\PublicDemoSeeder;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Collection;
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
}
