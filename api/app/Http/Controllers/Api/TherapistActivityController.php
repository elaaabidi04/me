<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AssignedTask;
use App\Models\InsightSnapshot;
use App\Models\SessionBrief;
use App\Models\Share;
use App\Models\TherapistClient;
use App\Models\TherapySession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * 2026-08-19: "what changed since I last looked" feed for the therapist
 * dashboard. Merges four event types across all currently-linked clients
 * into one chronological list: tasks completed by a client, insights shared
 * by a client, session briefs shared by a client, and sessions the therapist
 * has logged. Scoped to the last DAYS_BACK days and capped at MAX_ITEMS.
 *
 * No Policy layer, role check inline, same as every other controller here.
 */
class TherapistActivityController extends Controller
{
    private const DAYS_BACK = 14;
    private const MAX_ITEMS = 30;

    /**
     * GET /api/therapist/activity
     * Therapist-only. Returns [{type, summary, client_id, client_name,
     * created_at}] newest-first. An empty array when no linked clients
     * or no recent activity -- never 404.
     */
    public function recent(Request $request): JsonResponse
    {
        $therapist = $request->user();
        if ($therapist->role !== 'therapist') {
            return response()->json(['message' => 'Only therapist accounts have an activity feed.'], 403);
        }

        $linkedClients = TherapistClient::where('therapist_id', $therapist->id)
            ->with('client:id,name')
            ->get()
            ->keyBy('client_id');

        if ($linkedClients->isEmpty()) {
            return response()->json([]);
        }

        $clientIds = $linkedClients->keys()->all();
        $since     = Carbon::now()->subDays(self::DAYS_BACK);

        $items = collect();

        // Tasks a client checked off
        AssignedTask::where('therapist_id', $therapist->id)
            ->whereIn('client_id', $clientIds)
            ->whereNotNull('completed_at')
            ->where('completed_at', '>=', $since)
            ->get()
            ->each(function (AssignedTask $t) use (&$items, $linkedClients) {
                $items->push([
                    'type'        => 'task_completed',
                    'summary'     => $t->title,
                    'client_id'   => $t->client_id,
                    'client_name' => $linkedClients[$t->client_id]?->client?->name ?? 'a client',
                    'created_at'  => $t->completed_at,
                ]);
            });

        // Insight panels a client shared (not revoked)
        Share::whereIn('user_id', $clientIds)
            ->where('shareable_type', InsightSnapshot::class)
            ->whereNull('revoked_at')
            ->where('granted_at', '>=', $since)
            ->with('shareable')
            ->get()
            ->each(function (Share $s) use (&$items, $linkedClients) {
                $items->push([
                    'type'        => 'insight_shared',
                    'summary'     => $s->shareable?->title,
                    'client_id'   => $s->user_id,
                    'client_name' => $linkedClients[$s->user_id]?->client?->name ?? 'a client',
                    'created_at'  => $s->granted_at,
                ]);
            });

        // Session briefs a client shared (not revoked)
        Share::whereIn('user_id', $clientIds)
            ->where('shareable_type', SessionBrief::class)
            ->whereNull('revoked_at')
            ->where('granted_at', '>=', $since)
            ->get()
            ->each(function (Share $s) use (&$items, $linkedClients) {
                $items->push([
                    'type'        => 'brief_shared',
                    'summary'     => null,
                    'client_id'   => $s->user_id,
                    'client_name' => $linkedClients[$s->user_id]?->client?->name ?? 'a client',
                    'created_at'  => $s->granted_at,
                ]);
            });

        // Sessions this therapist logged (attendance set recently)
        TherapySession::where('therapist_id', $therapist->id)
            ->whereIn('client_id', $clientIds)
            ->whereNotNull('attendance')
            ->where('updated_at', '>=', $since)
            ->get()
            ->each(function (TherapySession $s) use (&$items, $linkedClients) {
                $items->push([
                    'type'        => 'session_logged',
                    'summary'     => $s->attendance,
                    'client_id'   => $s->client_id,
                    'client_name' => $linkedClients[$s->client_id]?->client?->name ?? 'a client',
                    'created_at'  => $s->updated_at,
                ]);
            });

        $feed = $items
            ->sortByDesc(fn ($i) => $i['created_at'])
            ->take(self::MAX_ITEMS)
            ->values();

        return response()->json($feed);
    }
}
