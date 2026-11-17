<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\DemoActivity;
use App\Support\DemoSandbox;
use Illuminate\Http\JsonResponse;

/**
 * 2026-10-25: owner-only feed of what visitors typed into their demo
 * sandboxes (App\Support\DemoSandbox), labelled by sandbox so you can tell
 * visitors apart.
 *
 * Deliberately scoped to @sandbox.demo accounts -- never real users'
 * journals. "Written by a visitor" = created after that visitor's sandbox
 * was; every seeded row is backdated to before that moment, so the seeded
 * story never shows up here. 2026-11-15: sandboxes are still deleted after
 * 24h, but what visitors wrote is archived first (App\Support\DemoActivity),
 * so the feed keeps it. Visitors are told on screen that it's kept and
 * visible to the owner.
 */
class AdminController extends Controller
{
    private const LIMIT = 300;

    public function activity(): JsonResponse
    {
        $demoUsers = User::where('email', 'like', '%@'.DemoSandbox::DOMAIN)->get()->keyBy('id');

        // 2026-11-15: live copies + everything archived from deleted ones
        $items = DemoActivity::collect($demoUsers, self::LIMIT)
            ->concat(DemoActivity::archived(self::LIMIT))
            ->sortByDesc(fn ($it) => $it['at'])
            ->take(self::LIMIT)
            ->values();

        return response()->json([
            // ready-made copies nobody has opened yet don't count as visitors
            'sandboxes' => max(0, $demoUsers->filter(fn ($u) => str_starts_with($u->email, 'maya.'))->count() - \Illuminate\Support\Facades\DB::table('demo_pool')->count()),
            'archived'  => \Illuminate\Support\Facades\DB::table('demo_archive')->count(),
            'clicks'    => self::clickStats(),
            'items'     => $items,
        ]);
    }

    /** 2026-11-10: clicks on "test user" / "test therapist" (demo_logins). */
    private static function clickStats(): array
    {
        $q = fn () => \Illuminate\Support\Facades\DB::table('demo_logins');
        $out = [];
        foreach (['client', 'therapist'] as $role) {
            $out[$role] = [
                'today' => $q()->where('role', $role)->where('created_at', '>=', now()->startOfDay())->count(),
                'week'  => $q()->where('role', $role)->where('created_at', '>=', now()->subDays(7))->count(),
                'total' => $q()->where('role', $role)->count(),
            ];
        }
        $out['new_visitors'] = [
            'today' => $q()->where('is_new', true)->where('created_at', '>=', now()->startOfDay())->count(),
            'week'  => $q()->where('is_new', true)->where('created_at', '>=', now()->subDays(7))->count(),
            'total' => $q()->where('is_new', true)->count(),
        ];

        return $out;
    }
}
