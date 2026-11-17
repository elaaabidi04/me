<?php

namespace App\Support;

use App\Models\AssignedTask;
use App\Models\CrisisMessage;
use App\Models\Entry;
use App\Models\SessionBrief;
use App\Models\TherapySession;
use Illuminate\Support\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 2026-11-15: what demo visitors typed, for /admin -- and kept after their
 * copy is deleted.
 *
 * collect() reads it from live sandbox accounts (moved here from
 * AdminController, unchanged). archive() copies it into `demo_archive` right
 * before DemoSandbox::prune()/wipeAll() delete the accounts, so the admin
 * feed keeps everything instead of only the last 24h. Visitors are told on
 * screen that what they write is kept and visible to the developer.
 *
 * Only ever touches @sandbox.demo accounts -- never real users' journals.
 */
class DemoActivity
{
    /**
     * Items written by visitors (not the seeded story) in these demo accounts.
     *
     * @param  Collection<int, \App\Models\User>  $demoUsers  keyed by id
     */
    public static function collect(Collection $demoUsers, int $limit = 300): Collection
    {
        $items = new Collection();
        if ($demoUsers->isEmpty()) {
            return $items;
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

        $window(Entry::query()->whereIn('user_id', $ids))
            ->with(['analysis:id,entry_id,mood,sentiment,distress_flag', 'coachMessage:id,entry_id,body,mode,reason'])
            ->latest()->limit($limit)->get()
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
            ->latest()->limit($limit)->get()
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
            ->latest()->limit($limit)->get()
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
            ->latest()->limit($limit)->get()
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
            ->latest('updated_at')->limit($limit)->get()
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

        return $items->sortByDesc('at')->take($limit)->values();
    }

    /**
     * Copies what visitors wrote in these accounts into demo_archive.
     * Safe to call twice (fingerprints are unique). Returns rows added.
     */
    public static function archive(Collection $demoUsers): int
    {
        $added = 0;
        foreach (self::collect($demoUsers->keyBy('id'), 100000) as $it) {
            $at = Carbon::parse($it['at']);
            $fp = hash('sha256', $it['sandbox'].'|'.$it['type'].'|'.$at->toIso8601String().'|'.$it['body']);
            $added += DB::table('demo_archive')->insertOrIgnore([
                'fingerprint' => $fp,
                'sandbox'     => $it['sandbox'],
                'type'        => $it['type'],
                'written_at'  => $at,
                'author'      => $it['author'],
                'role'        => $it['role'],
                'body'        => (string) $it['body'],
                'meta'        => json_encode($it['meta'] ?: (object) []),
                'created_at'  => now(),
            ]);
        }

        // optional retention: DEMO_ARCHIVE_DAYS=0 (default) keeps everything
        $days = (int) config('demo.archive_days', 0);
        if ($days > 0) {
            DB::table('demo_archive')->where('written_at', '<', now()->subDays($days))->delete();
        }

        return $added;
    }

    /** Archived items, newest first, in the same shape as collect(). */
    public static function archived(int $limit = 300): Collection
    {
        return DB::table('demo_archive')->orderByDesc('written_at')->limit($limit)->get()
            ->map(fn ($r) => [
                'sandbox'  => $r->sandbox,
                'type'     => $r->type,
                'at'       => Carbon::parse($r->written_at),
                'author'   => $r->author,
                'role'     => $r->role,
                'body'     => $r->body,
                'meta'     => (array) json_decode($r->meta ?? '{}', true),
                'archived' => true,
            ]);
    }
}
