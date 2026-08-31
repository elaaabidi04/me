<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TherapistClient;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Client <-> therapist linking via a code the therapist shows their client
 * (2026-08-17, user: "code invite might be easier" — chosen over an email
 * invite, since it puts the client in control of who they link to rather
 * than requiring a therapist to already have the client's email on file).
 * See docs/therapist-bridge.md for the full design, including why a client
 * can only ever have ONE active therapist link at a time (this is what
 * makes "who gets the automatic crisis alert," in CrisisThreadController,
 * unambiguous).
 *
 * No Policy layer here, matching every other controller in this app —
 * ownership/role checks are inline, same style as CanonEventController's
 * user_id check.
 */
class TherapistLinkController extends Controller
{
    // Avoids visually ambiguous characters (0/O, 1/I/L) since this code is
    // meant to be read off one person's screen and typed into another's.
    private const CODE_CHARS = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    private const CODE_LENGTH = 8;

    /**
     * GET /api/therapist/invite-code
     * Therapist-only. Returns the current code, generating one on first
     * call rather than requiring a separate "create" step.
     */
    public function inviteCode(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user->role !== 'therapist') {
            return response()->json(['message' => 'Only therapist accounts have an invite code.'], 403);
        }

        if (!$user->invite_code) {
            $user->invite_code = $this->generateUniqueCode();
            $user->save();
        }

        return response()->json(['invite_code' => $user->invite_code]);
    }

    /**
     * POST /api/therapist/invite-code
     * Therapist-only. Regenerates the code, invalidating the old one —
     * there's no history/revocation list, the old code just stops matching
     * anything the moment this overwrites it.
     */
    public function regenerateInviteCode(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user->role !== 'therapist') {
            return response()->json(['message' => 'Only therapist accounts have an invite code.'], 403);
        }

        $user->invite_code = $this->generateUniqueCode();
        $user->save();

        return response()->json(['invite_code' => $user->invite_code]);
    }

    /**
     * POST /api/therapist/link { code }
     * Client-only. Replaces any existing link rather than erroring on a
     * second link attempt — simpler than making the client explicitly
     * unlink first, and matches the one-active-therapist-at-a-time rule.
     * Past crisis threads with a PREVIOUS therapist stay in that
     * therapist's history (CrisisMessage rows aren't touched here) but stop
     * being reachable by them going forward, since CrisisThreadController
     * always resolves against the CURRENT link.
     */
    public function link(Request $request): JsonResponse
    {
        $client = $request->user();
        if ($client->role !== 'client') {
            return response()->json(['message' => 'Only client accounts can link to a therapist.'], 403);
        }

        $validated = $request->validate([
            'code' => ['required', 'string'],
        ]);

        $therapist = User::where('invite_code', strtoupper(trim($validated['code'])))
            ->where('role', 'therapist')
            ->first();

        if (!$therapist) {
            return response()->json(['message' => 'That code doesn\'t match a therapist account.'], 422);
        }

        TherapistClient::where('client_id', $client->id)->delete();
        $link = TherapistClient::create([
            'therapist_id' => $therapist->id,
            'client_id'    => $client->id,
        ]);

        return response()->json($link->load('therapist:id,name'));
    }

    /**
     * DELETE /api/therapist/link[?client_id=]
     * Either role can sever the link. A client always unlinks their own
     * (single) link. A therapist must name which client via ?client_id= —
     * there's no ambiguity to resolve on the client side, but a therapist
     * can have many clients, so "unlink" alone doesn't say which one.
     */
    public function unlink(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->role === 'client') {
            TherapistClient::where('client_id', $user->id)->delete();
            return response()->json(['message' => 'Unlinked.']);
        }

        if ($user->role === 'therapist') {
            $validated = $request->validate(['client_id' => ['required', 'integer']]);
            TherapistClient::where('therapist_id', $user->id)
                ->where('client_id', $validated['client_id'])
                ->delete();
            return response()->json(['message' => 'Unlinked.']);
        }

        return response()->json(['message' => 'Unrecognized role.'], 403);
    }

    /**
     * GET /api/therapist/link
     * Role-aware: a client gets their current therapist (or null); a
     * therapist gets their full client list. Deliberately minimal fields
     * (id, name only) rather than the full User row on either side — a
     * link doesn't need to expose email/invite_code to the other party.
     *
     * 2026-08-17: each client row also carries unreviewed_flags_count (see
     * TherapistClient::unreviewedFlags()) so the client list itself surfaces
     * who needs attention, now that flags live inside one ongoing thread per
     * client rather than as separate rooms a therapist would otherwise have
     * to open one-by-one to notice.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->role === 'client') {
            $link = $user->therapistLink()->with('therapist:id,name')->first();
            return response()->json($link);
        }

        if ($user->role === 'therapist') {
            $links = $user->linkedClients()
                ->with('client:id,name')
                ->withCount('unreviewedFlags')
                ->latest()
                ->get();
            return response()->json($links);
        }

        return response()->json(['message' => 'Unrecognized role.'], 403);
    }

    /**
     * PATCH /api/therapist/link/live-trends
     * Client-only. Toggles live_trends_enabled on their current therapist
     * link — the CLIENT opts in (or out), never the therapist. Off by
     * default; turning it off cuts therapist access to
     * GET /api/monthly/trends/client/{clientId} immediately, because that
     * endpoint checks the live column on every request (same "always live,
     * check when you check" pattern as everything else in this app).
     */
    public function toggleLiveTrends(Request $request): JsonResponse
    {
        $client = $request->user();
        if ($client->role !== 'client') {
            return response()->json(['message' => 'Only client accounts control live trend sharing.'], 403);
        }

        $link = $client->therapistLink()->first();
        if (!$link) {
            return response()->json(['message' => 'No active therapist link.'], 422);
        }

        $link->live_trends_enabled = !$link->live_trends_enabled;
        $link->save();

        return response()->json(['live_trends_enabled' => $link->live_trends_enabled]);
    }

    private function generateUniqueCode(): string
    {
        do {
            $code = '';
            for ($i = 0; $i < self::CODE_LENGTH; $i++) {
                $code .= self::CODE_CHARS[random_int(0, strlen(self::CODE_CHARS) - 1)];
            }
        } while (User::where('invite_code', $code)->exists());

        return $code;
    }
}
