<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CrisisMessage;
use App\Models\SafetyEvent;
use App\Models\TherapistClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Crisis threads (2026-08-17, user: "create a chat between the client and
 * therapist in term of crisis", then same day: "we only need one
 * conversation just when the crisis happen"). ONE persistent conversation
 * per therapist_client link — not one per crisis flag. Every SafetyEvent
 * for that client still exists (CoachService::handleCrisis() writes one on
 * every crisis-keyword match, unchanged) and is surfaced as an inline
 * "flag" inside this single thread, timestamped alongside the messages,
 * rather than spawning a separate room. See docs/therapist-bridge.md.
 *
 * VISIBILITY IS AUTOMATIC, CONSENT IS NOT PER-ITEM: a therapist can see
 * and post in the conversation for any CURRENTLY linked client — no
 * separate per-flag opt-in. Linking via the invite code is the consent for
 * this specific channel (confirmed explicitly, not assumed — see
 * docs/therapist-bridge.md §4).
 *
 * PRIVACY BOUNDARY: this controller never loads or returns the triggering
 * Entry's body anywhere. A flag existing means a crisis flag fired on some
 * entry — it does NOT mean that entry's journal text is shared.
 *
 * WHAT THIS DOES NOT DO: no push/email/SMS, no websockets — see the class
 * docblock precedent in docs/therapist-bridge.md §4 for the full caveat.
 * The existing immediate crisis-resource response in
 * CoachService::handleCrisis() is unchanged and remains the only
 * guaranteed-immediate thing; this thread is a supplement, never a
 * replacement.
 */
class CrisisThreadController extends Controller
{
    /**
     * GET /api/crisis-thread[?client_id=]
     * Client: always their own thread (their current therapist link).
     * Therapist: must pass ?client_id= naming which of their linked
     * clients' conversation to open.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $link = $this->resolveLink($request, $user);

        if ($link === false) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
        if ($link === null) {
            return response()->json(['message' => 'No therapist linked yet.'], 404);
        }

        $flags = SafetyEvent::where('user_id', $link->client_id)
            ->orderBy('created_at')
            ->get(['id', 'entry_id', 'reviewed_at', 'created_at']);

        return response()->json([
            'therapist_client_id' => $link->id,
            'therapist'           => ['id' => $link->therapist_id, 'name' => $link->therapist->name],
            'client'              => ['id' => $link->client_id, 'name' => $link->client->name],
            'flags'               => $flags,
            'messages'            => $link->crisisMessages()->with('sender:id,name')->get(),
        ]);
    }

    /**
     * POST /api/crisis-thread/messages { body, client_id? (therapist only) }
     * Not tied to any specific flag — a message here is just part of the
     * ongoing conversation. safety_event_id stays null; nothing currently
     * needs to post a message scoped to one specific flag rather than the
     * thread as a whole.
     */
    public function storeMessage(Request $request): JsonResponse
    {
        $user = $request->user();
        $link = $this->resolveLink($request, $user);

        if ($link === false) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
        if ($link === null) {
            return response()->json(['message' => 'No therapist linked yet.'], 404);
        }

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:4000'],
        ]);

        $message = CrisisMessage::create([
            'therapist_client_id' => $link->id,
            'sender_id'           => $user->id,
            'body'                => $validated['body'],
        ]);

        // Replying is implicit acknowledgment — mark all unreviewed flags
        // for this client as seen so the therapist doesn't have to click
        // "mark seen" separately after already responding.
        if ($user->role === 'therapist') {
            SafetyEvent::where('user_id', $link->client_id)
                ->whereNull('reviewed_at')
                ->update(['reviewed_at' => now()]);
        }

        return response()->json($message->load('sender:id,name'), 201);
    }

    /**
     * PATCH /api/crisis-thread/flags/{safetyEvent} { reviewed: true }
     * Therapist-only. Marks one specific flag acknowledged — a plain "I've
     * seen this," not a resolution/closed state; the thread stays open
     * either way. First real consumer of reviewed_at, which existed on
     * safety_events since day one but was never set or read by anything.
     */
    public function reviewFlag(Request $request, SafetyEvent $safetyEvent): JsonResponse
    {
        $user = $request->user();
        if ($user->role !== 'therapist') {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $linked = TherapistClient::where('therapist_id', $user->id)
            ->where('client_id', $safetyEvent->user_id)
            ->exists();
        if (!$linked) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        if (!$safetyEvent->reviewed_at) {
            $safetyEvent->reviewed_at = now();
            $safetyEvent->save();
        }

        return response()->json($safetyEvent);
    }

    /**
     * Resolves which therapist_client row the request is about.
     *
     * @return TherapistClient|null|false  the link, null if the caller has
     *   no link yet (client) or named a client they aren't linked to
     *   (therapist), or false if the role itself is unrecognized/forbidden
     */
    private function resolveLink(Request $request, $user): TherapistClient|null|false
    {
        if ($user->role === 'client') {
            return $user->therapistLink()->first();
        }

        if ($user->role === 'therapist') {
            $clientId = $request->input('client_id');
            if (!$clientId) {
                return false;
            }
            return TherapistClient::where('therapist_id', $user->id)
                ->where('client_id', $clientId)
                ->first();
        }

        return false;
    }
}
