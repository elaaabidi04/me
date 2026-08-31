<?php

namespace App\Services;

use App\Models\CanonEvent;

/**
 * Retrieval half of canon-event memory (2026-08-12) — see
 * docs/ai-behavior.md §11. Extraction + confirmation (EntryExtractionService,
 * CanonEventController) decide what's worth remembering; this decides which
 * of those confirmed memories are relevant to the entry the coach is about
 * to reply to, via cosine similarity over embeddings.
 *
 * Deliberately brute-force, not a vector database. Consistent with the
 * design note already in docs/ai-behavior.md's (pre-existing) "Embeddings —
 * scoped, not built" section: SQLite has no native vector type, and at this
 * app's per-user data volume (a personal journal, not a corpus), looping
 * over every confirmed canon event and computing cosine similarity in PHP
 * is genuinely fine — a sqlite-vec/ANN index would be solving a scaling
 * problem this app doesn't have yet.
 */
class CanonMemoryService
{
    // How many memories to surface per reply. Kept small on purpose — this
    // is meant to read as "the coach happens to remember one relevant
    // thing," not a dossier. A long reference list would also crowd out
    // the strategy-hint reference block in the same prompt.
    private const DEFAULT_LIMIT = 3;

    // Below this, a "closest match" is more coincidental than genuinely
    // relevant — cosine similarity always returns *a* ranking even when
    // nothing in the set is actually related, so a floor is needed or the
    // coach would reference something tenuously-connected just because it
    // scored highest among bad options.
    private const MIN_SIMILARITY = 0.35;

    // Deliberately much stricter than MIN_SIMILARITY above (2026-08-15,
    // see findSimilar()) — that threshold answers "is this worth mentioning
    // in a reply," a fairly loose bar. This one answers "is this actually
    // the same underlying claim as an existing memory, just reworded,"
    // which needs real confidence before suppressing or blocking a new
    // suggestion on the strength of it. A hand-picked constant, not tuned
    // against any real distribution of same-claim vs. different-claim
    // similarity scores — same caveat as MIN_SIMILARITY and
    // MIN_MEMORY_SCORE (EntryExtractionService) already carry.
    private const DEDUP_SIMILARITY = 0.85;

    /**
     * @param  float[]  $entryEmbedding  the current entry's embedding,
     *   already computed by the caller with input_type 'query' — this
     *   service only does the comparison, not the embedding call itself.
     * @param  bool     $all  when true, bypasses the MIN_SIMILARITY floor
     *   and returns ALL confirmed memories sorted by similarity, up to a
     *   higher limit. Used when the entry is a direct memory query ("what
     *   do you know about me?") — a generic meta-question has low cosine
     *   similarity with specific factual memories by construction, so the
     *   floor would filter everything out even though the coach should be
     *   enumerating its full knowledge in that case. See
     *   CoachService::looksLikeMemoryQuery().
     * @return CanonEvent[] sorted most-to-least relevant
     */
    public function retrieveRelevant(int $userId, array $entryEmbedding, int $limit = self::DEFAULT_LIMIT, bool $all = false): array
    {
        if (empty($entryEmbedding)) {
            return [];
        }

        // Confirmed-and-embedded only: 'suggested'/'dismissed' were never
        // agreed to by the user, and 'forgotten' was explicitly withdrawn —
        // neither should ever be retrievable, regardless of similarity
        // score. A confirmed event with no embedding yet (a failed or not-
        // yet-run embed call at confirm time) is simply not comparable, not
        // an error — it just sits out of retrieval until it has one.
        $candidates = CanonEvent::where('user_id', $userId)
            ->where('status', 'confirmed')
            ->whereNotNull('embedding')
            ->get();

        if ($candidates->isEmpty()) {
            return [];
        }

        $scored = $candidates->map(fn (CanonEvent $event) => [
            'event'      => $event,
            'similarity' => $this->cosineSimilarity($entryEmbedding, $event->embedding ?? []),
        ]);

        if (!$all) {
            $scored = $scored->filter(fn (array $s) => $s['similarity'] >= self::MIN_SIMILARITY);
        }

        return $scored
            ->sortByDesc('similarity')
            ->take($all ? 20 : $limit)
            ->pluck('event')
            ->all();
    }

    /**
     * Semantic dedup lookup (2026-08-15) — the other half of what this
     * class does, alongside retrieveRelevant() above. Given a not-yet-
     * persisted candidate's own embedding, finds the most similar existing
     * memory among the given statuses, if any clears DEDUP_SIMILARITY.
     *
     * Deliberately generic over $statuses rather than hardcoding one status
     * list, because the two callers in EntryExtractionService::extract()
     * need genuinely different behavior depending on WHICH status a match
     * is found against, and must not be collapsed into one branch:
     *   - a match among ['dismissed'] means the person already declined
     *     this exact claim (maybe worded differently) — the correct
     *     response is silent suppression, full stop. It must never feed
     *     into a confidence boost or any other "corroborating evidence"
     *     path — that would be the worst-case inversion of what dismissing
     *     something is supposed to do (a decline making the same claim
     *     resurface MORE confidently). This is why the caller checks
     *     ['dismissed'] first and short-circuits before ever looking at
     *     ['confirmed'].
     *   - a match among ['confirmed'] means it's a genuine duplicate of an
     *     already-active memory — also suppressed, but for the ordinary
     *     "don't say the same thing twice" reason, not a protective one.
     *
     * Only 'confirmed' and 'dismissed' rows are ever comparable here —
     * 'suggested' rows are still deliberately inert (no embedding computed
     * until a human actually confirms or dismisses them, see
     * CanonEventController::update()), so two still-pending suggestions
     * that happen to paraphrase the same claim won't be caught by this
     * check until one of them is resolved. The cheap exact-text guard in
     * EntryExtractionService (checked first, before this ever runs) still
     * catches literal duplicates among pending suggestions in the
     * meantime — a known, accepted gap for near-duplicate phrasing only,
     * not a silent hole for exact repeats.
     *
     * @param  string[]  $statuses
     * @return array{event: CanonEvent, similarity: float}|null
     */
    public function findSimilar(int $userId, array $candidateEmbedding, array $statuses): ?array
    {
        if (empty($candidateEmbedding) || empty($statuses)) {
            return null;
        }

        $rows = CanonEvent::where('user_id', $userId)
            ->whereIn('status', $statuses)
            ->whereNotNull('embedding')
            ->get();

        $best = null;

        foreach ($rows as $row) {
            $similarity = $this->cosineSimilarity($candidateEmbedding, $row->embedding ?? []);
            if ($similarity < self::DEDUP_SIMILARITY) {
                continue;
            }
            if ($best === null || $similarity > $best['similarity']) {
                $best = ['event' => $row, 'similarity' => $similarity];
            }
        }

        return $best;
    }

    /**
     * Standard cosine similarity, guarded against the shapes that would
     * otherwise throw (mismatched dimensions, an all-zero vector) rather
     * than assuming every stored embedding is well-formed — a partially
     * failed embed() call in the past shouldn't be able to crash retrieval
     * for every other memory today.
     */
    private function cosineSimilarity(array $a, array $b): float
    {
        if (empty($a) || empty($b) || count($a) !== count($b)) {
            return 0.0;
        }

        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        foreach ($a as $i => $value) {
            $bValue = $b[$i] ?? 0.0;
            $dot   += $value * $bValue;
            $normA += $value * $value;
            $normB += $bValue * $bValue;
        }

        if ($normA <= 0.0 || $normB <= 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($normA) * sqrt($normB));
    }
}
