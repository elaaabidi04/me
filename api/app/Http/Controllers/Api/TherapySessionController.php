<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TherapistClient;
use App\Models\TherapySession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 2026-08-18, user: "should we add calendar for it to check when is the
 * session and before a day a notification to check the in between
 * progress." A therapist schedules a session with a currently-linked
 * client; either side can see when the next one is. See
 * TherapySession's docblock for is_soon (the honest, in-app version of
 * "a day-before notification" -- this app has no email/push/SMS
 * delivery anywhere, see docs/therapist-bridge.md §4's list) and
 * docs/therapist-bridge.md §10 for the full writeup.
 *
 * Scoping mirrors TaskController exactly -- same shape of problem
 * (something crossing the therapist<->client boundary), same answer.
 * No Policy layer, ownership checks inline, same as every other
 * controller in this app.
 */
class TherapySessionController extends Controller
{
    /**
     * POST /api/sessions { client_id, scheduled_at, notes? }
     * Therapist-only. Requires an active link to that client.
     * scheduled_at is a full datetime ('Y-m-d H:i' from a <input
     * type="datetime-local">, Laravel's date_format validator accepts
     * it) -- a session has a time, not just a day, unlike AssignedTask's
     * due_date.
     */
    public function store(Request $request): JsonResponse
    {
        $therapist = $request->user();
        if ($therapist->role !== 'therapist') {
            return response()->json(['message' => 'Only therapist accounts can schedule sessions.'], 403);
        }

        $validated = $request->validate([
            'client_id'    => ['required', 'integer'],
            'scheduled_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'notes'        => ['nullable', 'string', 'max:2000'],
        ]);

        $linked = TherapistClient::where('therapist_id', $therapist->id)
            ->where('client_id', $validated['client_id'])
            ->exists();
        if (!$linked) {
            return response()->json(['message' => 'Not linked to this client.'], 403);
        }

        $session = TherapySession::create([
            'therapist_id' => $therapist->id,
            'client_id'    => $validated['client_id'],
            'scheduled_at' => $validated['scheduled_at'],
            'notes'        => $validated['notes'] ?? null,
        ]);

        return response()->json($session, 201);
    }

    /**
     * PATCH /api/sessions/{session} { scheduled_at?, notes? }
     * Therapist-only, must be the one who scheduled it. Reschedule is a
     * real, expected action for a calendar (unlike AssignedTask, which
     * deliberately has no edit endpoint) -- losing an already-written
     * note to a delete+recreate would be a real annoyance here.
     */
    public function update(Request $request, TherapySession $session): JsonResponse
    {
        $therapist = $request->user();
        if ($session->therapist_id !== $therapist->id) {
            return response()->json(['message' => 'Not your session.'], 403);
        }

        $validated = $request->validate([
            'scheduled_at' => ['sometimes', 'date_format:Y-m-d\TH:i'],
            'notes'        => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $session->fill($validated);
        $session->save();

        return response()->json($session);
    }

    /**
     * PATCH /api/sessions/{session}/log { attendance, outcome_note? }
     * Therapist-only. Records what actually happened once a scheduled
     * session is in the past. attendance must be one of attended /
     * no_show / cancelled. outcome_note is separate from `notes` (the
     * pre-session scheduling context) -- see the migration's docblock.
     * No guard on "is it past?" here: a therapist may log a just-ended
     * session before the server clock has fully ticked past it; that edge
     * belongs to the UI (which only offers the affordance for past rows).
     */
    public function log(Request $request, TherapySession $session): JsonResponse
    {
        $therapist = $request->user();
        if ($session->therapist_id !== $therapist->id) {
            return response()->json(['message' => 'Not your session.'], 403);
        }

        $validated = $request->validate([
            'attendance'   => ['required', 'string', 'in:attended,no_show,cancelled'],
            'outcome_note' => ['nullable', 'string', 'max:4000'],
        ]);

        $session->fill($validated);
        $session->save();

        return response()->json($session);
    }

    /**
     * DELETE /api/sessions/{session}
     * Therapist-only, must be the one who scheduled it. "Cancel" --
     * removed outright rather than soft-cancelled, since attendance/
     * outcome are now captured via the log endpoint above, not by
     * keeping a deleted row around.
     */
    public function destroy(Request $request, TherapySession $session): JsonResponse
    {
        $therapist = $request->user();
        if ($session->therapist_id !== $therapist->id) {
            return response()->json(['message' => 'Not your session.'], 403);
        }

        $session->delete();

        return response()->json(['message' => 'Cancelled.']);
    }

    /**
     * GET /api/sessions
     * Role-aware, same shape as TherapistLinkController::show(). Client:
     * every session ever scheduled with them, regardless of current link
     * status -- same "history stays visible" asymmetry TaskController::
     * index() and CrisisThreadController use for old data. Therapist:
     * every session they scheduled, across every client they're
     * CURRENTLY linked to -- one call instead of Therapist.vue firing
     * forClient() per row to build the "next session" summary the
     * landing page needed once it stopped being just a client list.
     * Soonest-first chronologically either way.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->role === 'client') {
            $sessions = TherapySession::where('client_id', $user->id)
                ->with('therapist:id,name')
                ->orderBy('scheduled_at')
                ->get();
            return response()->json($sessions);
        }

        if ($user->role === 'therapist') {
            $linkedClientIds = TherapistClient::where('therapist_id', $user->id)->pluck('client_id');
            $sessions = TherapySession::where('therapist_id', $user->id)
                ->whereIn('client_id', $linkedClientIds)
                ->with('client:id,name')
                ->orderBy('scheduled_at')
                ->get();
            return response()->json($sessions);
        }

        return response()->json(['message' => 'Unrecognized role.'], 403);
    }

    /**
     * GET /api/sessions/client/{clientId}
     * Therapist-only. Only sessions THEY scheduled with a client they're
     * currently linked to -- same scoping as TaskController::forClient().
     */
    public function forClient(Request $request, int $clientId): JsonResponse
    {
        $therapist = $request->user();
        if ($therapist->role !== 'therapist') {
            return response()->json(['message' => 'Only therapist accounts can view sessions.'], 403);
        }

        $linked = TherapistClient::where('therapist_id', $therapist->id)
            ->where('client_id', $clientId)
            ->exists();
        if (!$linked) {
            return response()->json(['message' => 'Not linked to this client.'], 403);
        }

        $sessions = TherapySession::where('therapist_id', $therapist->id)
            ->where('client_id', $clientId)
            ->orderBy('scheduled_at')
            ->get();

        return response()->json($sessions);
    }
}
