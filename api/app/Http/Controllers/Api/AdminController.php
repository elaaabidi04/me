<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AssignedTask;
use App\Models\CrisisMessage;
use App\Models\Entry;
use App\Models\SessionBrief;
use App\Models\TherapySession;
use App\Models\User;
use App\Support\DemoSandbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

/**
 * 2026-10-25: owner-only feed of what visitors typed into their demo
 * sandboxes (App\Support\DemoSandbox), labelled by sandbox so you can tell
 * visitors apart.
 *
 * Deliberately scoped to @sandbox.demo accounts -- never real users'
 * journals. "Written by a visitor" = created after that visitor's sandbox
 * was; every seeded row is backdated to before that moment, so the seeded
 * story never shows up here. Sandboxes are deleted after 24h, so this only
 * ever covers roughly the last day. Visitors are told on the demo login
 * screen that the owner can read it.
 */
class AdminController extends Controller
{
    private const LIMIT = 300;

    public function activity(): JsonResponse
    {
        $demoUsers = User::where('email', 'like', '%@'.DemoSandbox::DOMAIN)->get()->keyBy('id');

        if ($demoUsers->isEmpty()) {
            return response()->json(['sandboxes' => 0, 'items' => [], 'clicks' => self::clickStats()]);
        }

        $ids  = $demoUsers->keys();
        $name = fn ($id) => $demoUsers[$id]->name ?? 'unknown';
        $role = fn ($id) => $demoUsers[$id]->role ?? null;
        $box  = fn ($id) => 'visitor '.substr((string) DemoSandbox::tokenOf($demoUsers[$id]->email ?? null), 0, 6);
        // Each sandbox's accounts are created right before seeding, so
        // anything newer than its author's account was typed by the visitor.
        $byVisitor = fn ($id, $at) => isset($demoUsers[$id]) && $at && $at->gte($demoUsers[$id]->created_at) && $at->lte(now());
        $oldest = $demoUsers->min('created_at');
        $window = fn ($q, string $col = 'created_at') => $q->where($col, '>=', $oldest)->where($col, '<=', now());

        $items = new Collection();

        $window(Entry::query()->whereIn('user_id', $ids))
            ->with(['analysis:id,entry_id,mood,sentiment,distress_flag', 'coachMessage:id,entry_id,body,mode,reason'])
            ->latest()->limit(self::LIMIT)->get()
            ->filter(fn (Entry $e) => $byVisitor($e->user_id, $e->created_at))
            ->each(fn (Entry $e) => $items->push([
                'sandbox' => $box($e->user_id),
                // 2026-11-07: entries InjectionGuard blocked get their own type
                'type'   => str_starts_with((string) $e->coachMessage?->reason, 'prompt_injection_blocked') ? 'blocked attempt' : 'journal',
                'at'     => $e->created_at,
                'author' => $name($e->user_id),
                'role'   => $role($e->user_id),
                'body'   => $e->body,
                'meta'   => array_filter([
                    'mood'        => $e->analysis?->mood,
                    'flagged'     => $e->analysis?->distress_flag ? 'crisis flag' : null,
                    'coach_reply' => str_starts_with((string) $e->coachMessage?->reason, 'prompt_injection_blocked') ? null : $e->coachMessage?->body,
                    'rule'        => str_starts_with((string) $e->coachMessage?->reason, 'prompt_injection_blocked')
                        ? trim(substr((string) $e->coachMessage->reason, strlen('prompt_injection_blocked:'))) : null,
                ]),
            ]));

        $window(CrisisMessage::query()->whereIn('sender_id', $ids))
            ->latest()->limit(self::LIMIT)->get()
            ->filter(fn (CrisisMessage $m) => $byVisitor($m->sender_id, $m->created_at))
            ->each(fn (CrisisMessage $m) => $items->push([
                'sandbox' => $box($m->sender_id),
                'type'   => 'crisis chat',
                'at'     => $m->created_at,
                'author' => $name($m->sender_id),
                'role'   => $role($m->sender_id),
                'body'   => $m->body,
                'meta'   => [],
            ]));

        $window(AssignedTask::query()->whereIn('therapist_id', $ids))
            ->latest()->limit(self::LIMIT)->get()
            ->filter(fn (AssignedTask $t) => $byVisitor($t->therapist_id, $t->created_at))
            ->each(fn (AssignedTask $t) => $items->push([
                'sandbox' => $box($t->therapist_id),
                'type'   => 'task',
                'at'     => $t->created_at,
                'author' => $name($t->therapist_id),
                'role'   => 'therapist',
                'body'   => trim($t->title."\n".($t->instructions ?? '')),
                'meta'   => ['for' => $name($t->client_id)],
            ]));

        $window(SessionBrief::query()->whereIn('client_id', $ids))
            ->latest()->limit(self::LIMIT)->get()
            ->filter(fn (SessionBrief $b) => $byVisitor($b->client_id, $b->created_at))
            ->each(fn (SessionBrief $b) => $items->push([
                'sandbox' => $box($b->client_id),
                'type'   => 'session brief',
                'at'     => $b->created_at,
                'author' => $name($b->client_id),
                'role'   => 'client',
                'body'   => $b->content,
                'meta'   => [],
            ]));

        $window(TherapySession::query()->whereIn('therapist_id', $ids)->whereNotNull('outcome_note'), 'updated_at')
            ->latest('updated_at')->limit(self::LIMIT)->get()
            ->filter(fn (TherapySession $s) => $byVisitor($s->therapist_id, $s->updated_at))
            ->each(fn (TherapySession $s) => $items->push([
                'sandbox' => $box($s->therapist_id),
                'type'   => 'session note',
                'at'     => $s->updated_at,
                'author' => $name($s->therapist_id),
                'role'   => 'therapist',
                'body'   => $s->outcome_note,
                'meta'   => ['for' => $name($s->client_id)],
            ]));

        return response()->json([
            // ready-made copies nobody has opened yet don't count as visitors
            'sandboxes' => max(0, $demoUsers->filter(fn ($u) => str_starts_with($u->email, 'maya.'))->count() - \Illuminate\Support\Facades\DB::table('demo_pool')->count()),
            'clicks' => self::clickStats(),
            'items' => $items->sortByDesc('at')->take(self::LIMIT)->values(),
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
