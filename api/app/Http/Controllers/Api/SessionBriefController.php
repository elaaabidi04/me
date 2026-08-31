<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SessionBrief;
use App\Models\Share;
use App\Models\TherapistClient;
use App\Models\TherapySession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * 2026-08-19: Pre-session briefs — a client-authored "here's what I'd want
 * my therapist to know before we talk" note. Mirrors SharedInsightController
 * in shape (store/index/forClient/destroy), reusing the same polymorphic
 * Share table as InsightSnapshot. See docs/therapist-bridge.md §8 for the
 * Share table design and revoke-not-delete discipline.
 *
 * One deliberate difference from InsightSnapshot: the content here is pure
 * free text (not a frozen computed panel), and the brief can optionally be
 * anchored to a specific TherapySession — useful context for the therapist
 * ("this was what the client wanted to say before our Aug-22 session").
 *
 * No Policy layer, ownership checks inline, same as every other controller.
 */
class SessionBriefController extends Controller
{
    /**
     * POST /api/session-briefs { content, therapy_session_id? }
     * Client-only. Creates a SessionBrief and a Share row atomically —
     * writing and sharing are one step (same as InsightSnapshot). Requires
     * an active therapist link; sharing into a void nobody can see isn't
     * worth creating. therapy_session_id is optional; if omitted the brief
     * floats free, not attached to any particular session.
     */
    public function store(Request $request): JsonResponse
    {
        $client = $request->user();
        if ($client->role !== 'client') {
            return response()->json(['message' => 'Only client accounts can write session briefs.'], 403);
        }

        $link = TherapistClient::where('client_id', $client->id)->first();
        if (!$link) {
            return response()->json(['message' => 'Link a therapist first before writing a brief.'], 422);
        }

        $validated = $request->validate([
            'content'            => ['required', 'string', 'max:8000'],
            'therapy_session_id' => ['nullable', 'integer'],
        ]);

        // Ownership check on the session: only attach to a session that
        // actually belongs to this client (and is with their current therapist).
        if (!empty($validated['therapy_session_id'])) {
            $sessionOk = TherapySession::where('id', $validated['therapy_session_id'])
                ->where('client_id', $client->id)
                ->where('therapist_id', $link->therapist_id)
                ->exists();
            if (!$sessionOk) {
                return response()->json(['message' => 'That session does not belong to this link.'], 422);
            }
        }

        $brief = SessionBrief::create([
            'client_id'          => $client->id,
            'therapist_id'       => $link->therapist_id,
            'therapy_session_id' => $validated['therapy_session_id'] ?? null,
            'content'            => $validated['content'],
        ]);

        $share = Share::create([
            'user_id'        => $client->id,
            'shareable_type' => SessionBrief::class,
            'shareable_id'   => $brief->id,
            'granted_at'     => Carbon::now(),
        ]);

        return response()->json([
            'share_id' => $share->id,
            'brief'    => $this->formatBrief($share, $brief),
        ], 201);
    }

    /**
     * GET /api/session-briefs
     * Client-only. Their own brief history, newest first, including revoked
     * ones (shown greyed-out client-side). Also used by the client's form
     * to know which sessions already have an active brief attached.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user->role !== 'client') {
            return response()->json(['message' => 'Only client accounts have session briefs.'], 403);
        }

        $shares = Share::where('user_id', $user->id)
            ->where('shareable_type', SessionBrief::class)
            ->with(['shareable', 'shareable.session'])
            ->latest('granted_at')
            ->get()
            ->map(fn (Share $s) => $this->formatBrief($s, $s->shareable));

        return response()->json($shares->values());
    }

    /**
     * DELETE /api/session-briefs/{share}
     * Revoke — sets revoked_at, keeps the row so the client's history still
     * shows it happened. Same revoke-not-delete discipline as
     * SharedInsightController::destroy(). Must be the client who created it.
     */
    public function destroy(Request $request, Share $share): JsonResponse
    {
        $user = $request->user();
        if ($share->user_id !== $user->id) {
            return response()->json(['message' => 'Not your brief.'], 403);
        }
        if ($share->shareable_type !== SessionBrief::class) {
            return response()->json(['message' => 'Not a session brief.'], 422);
        }

        if (!$share->revoked_at) {
            $share->revoked_at = Carbon::now();
            $share->save();
        }

        return response()->json($share);
    }

    /**
     * GET /api/session-briefs/client/{clientId}
     * Therapist-only. Only currently-active (non-revoked) briefs for a
     * client they're linked to — same inline ownership check as
     * SharedInsightController::forClient(). A revoked brief is simply gone
     * from this view; the client's own history (index()) still shows it.
     */
    public function forClient(Request $request, int $clientId): JsonResponse
    {
        $therapist = $request->user();
        if ($therapist->role !== 'therapist') {
            return response()->json(['message' => 'Only therapist accounts can view a client\'s briefs.'], 403);
        }

        $linked = TherapistClient::where('therapist_id', $therapist->id)
            ->where('client_id', $clientId)
            ->exists();
        if (!$linked) {
            return response()->json(['message' => 'Not linked to this client.'], 403);
        }

        $shares = Share::where('user_id', $clientId)
            ->where('shareable_type', SessionBrief::class)
            ->whereNull('revoked_at')
            ->with(['shareable', 'shareable.session'])
            ->latest('granted_at')
            ->get()
            ->map(fn (Share $s) => $this->formatBrief($s, $s->shareable));

        return response()->json($shares->values());
    }

    private function formatBrief(Share $share, ?SessionBrief $brief): array
    {
        return [
            'share_id'           => $share->id,
            'granted_at'         => $share->granted_at,
            'revoked_at'         => $share->revoked_at,
            'content'            => $brief?->content,
            'therapy_session_id' => $brief?->therapy_session_id,
            'session_at'         => $brief?->session?->scheduled_at,
        ];
    }
}
