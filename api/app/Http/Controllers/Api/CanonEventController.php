<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CanonEvent;
use App\Services\NvidiaClient;
use App\Support\ExtractionTaxonomy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Canon-event memory (2026-08-12, see docs/ai-behavior.md §11) — the
 * confirm/dismiss/forget surface for the suggestions EntryExtractionService
 * creates, plus the "what me remembers" list. Deliberately structured like
 * StrategyController: a suggestion is inert until a human acts on it, and
 * ownership is checked on every mutating request the same way.
 */
class CanonEventController extends Controller
{
    public function __construct(private NvidiaClient $client) {}

    /**
     * GET /api/canon-events[?status=confirmed|suggested]
     * Defaults to confirmed memories — this backs the main "what me
     * remembers" list. Memories.vue (2026-08-14) also calls this with
     * ?status=suggested to show the full pending inbox, not just the
     * single oldest item pending() surfaces on the dashboard — the
     * dashboard's one-at-a-time gentle note is the primary "should I
     * remember this?" prompt, but a suggestion the user didn't act on
     * immediately shouldn't only ever be reachable through that one
     * transient notification. dismissed/forgotten stay inert history with
     * no dedicated UI either way.
     */
    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status', 'confirmed');
        if (!in_array($status, ['confirmed', 'suggested'], true)) {
            $status = 'confirmed';
        }

        $query = $request->user()
            ->canonEvents()
            ->where('status', $status)
            ->with(['entity', 'sourceEntry:id,created_at']);

        $events = $status === 'confirmed'
            ? $query->orderByDesc('confirmed_at')->get()
            : $query->oldest()->get();

        return response()->json($events);
    }

    /**
     * GET /api/canon-events/pending
     * The oldest not-yet-reviewed suggestion, or null. Oldest-first (not
     * newest) so suggestions actually get worked through in order rather
     * than the newest one perpetually jumping the queue.
     */
    public function pending(Request $request): JsonResponse
    {
        $event = $request->user()
            ->canonEvents()
            ->where('status', 'suggested')
            ->with('entity')
            ->oldest()
            ->first();

        return response()->json($event);
    }

    /**
     * PATCH /api/canon-events/{canonEvent}
     * Accepts any of: status (confirmed|dismissed|forgotten), description,
     * category (one of ExtractionTaxonomy::MEMORY_CATEGORIES). 2026-08-14
     * added description/category so a memory can be corrected instead of
     * only confirmed/dismissed/forgotten wholesale — the "Edit" action in
     * Memories.vue. At least one field is required.
     *
     * Confirming — and, as of 2026-08-15, dismissing — are the two branches
     * with a side effect beyond the status change itself: both compute and
     * store the embedding right here, inline and synchronously (confirming/
     * dismissing are rare, user-initiated clicks, not a hot path, so one
     * embedding call in the request/response cycle is cheap and avoids
     * queue infrastructure for a low-frequency action). Editing the
     * description of an already-confirmed-and-embedded memory also
     * re-embeds — the old vector would otherwise silently describe text
     * that no longer matches what's stored, quietly degrading retrieval
     * without ever erroring. Editing a still-'suggested' row's description
     * does NOT embed — nothing to keep in sync yet.
     *
     * Dismissing gained an embedding for a reason unrelated to retrieval
     * (a dismissed row is never retrievable, see CanonMemoryService's
     * whereNotNull-on-'confirmed'-only query): it's what lets
     * EntryExtractionService::extract() semantically suppress a future
     * candidate that re-states a claim the person already declined, even
     * if it's reworded — see CanonMemoryService::findSimilar()'s docblock.
     * Without an embedding on the dismissed row, that check has nothing to
     * compare against and a decline would only ever protect against an
     * exact-text repeat, not a paraphrase.
     *
     * Best-effort throughout: if the embed call fails, the edit/confirm/
     * dismiss still saves — visibility (and, for dismiss, suppression)
     * never depends on the embedding call succeeding, same as before (see
     * CanonMemoryService, which treats a null embedding as "skip, not an
     * error"). No automatic retry if this fails — see
     * docs/dashboard-logic.md §7's limitations.
     */
    public function update(Request $request, CanonEvent $canonEvent): JsonResponse
    {
        if ($canonEvent->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $validated = $request->validate([
            'status'      => ['sometimes', 'required', 'in:confirmed,dismissed,forgotten'],
            'description' => ['sometimes', 'required', 'string', 'max:200'],
            'category'    => ['sometimes', 'required', 'in:'.implode(',', ExtractionTaxonomy::MEMORY_CATEGORIES)],
        ]);

        if (empty($validated)) {
            return response()->json(['message' => 'Nothing to update.'], 422);
        }

        $descriptionChanged = array_key_exists('description', $validated)
            && $validated['description'] !== $canonEvent->description;

        if (array_key_exists('status', $validated)) {
            $canonEvent->status = $validated['status'];
        }
        if (array_key_exists('description', $validated)) {
            $canonEvent->description = $validated['description'];
        }
        if (array_key_exists('category', $validated)) {
            $canonEvent->category = $validated['category'];
        }

        $justConfirmed    = ($validated['status'] ?? null) === 'confirmed';
        $justDismissed    = ($validated['status'] ?? null) === 'dismissed';
        $alreadyConfirmed = $canonEvent->status === 'confirmed';

        if ($justConfirmed) {
            $canonEvent->confirmed_at = now();
        }

        // Embed on confirm (existing, retrieval-facing) OR on dismiss (new
        // 2026-08-15, suppression-facing — see this method's docblock).
        // Re-embedding an edited already-confirmed description stays here
        // too, unchanged.
        if ($justConfirmed || $justDismissed || ($alreadyConfirmed && $descriptionChanged)) {
            $vector = $this->client->embed($canonEvent->description, 'passage');
            if ($vector !== null) {
                $canonEvent->embedding = $vector;
            }
        }

        $canonEvent->save();

        return response()->json($canonEvent->fresh());
    }
}
