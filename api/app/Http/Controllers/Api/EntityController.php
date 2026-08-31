<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Entity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EntityController extends Controller
{
    /**
     * GET /api/entities
     * Return the authenticated user's entities with entries_count,
     * ordered by entries_count desc. All trigger_status values are included
     * so the dashboard can split them client-side.
     */
    public function index(Request $request): JsonResponse
    {
        $entities = $request->user()
            ->entities()
            ->withCount('entries')
            ->orderByDesc('entries_count')
            ->get();

        return response()->json($entities);
    }

    /**
     * GET /api/entities/{entity}
     * Returns entity detail: total entries_count, this-week count, confirmed strategies.
     */
    public function show(Request $request, Entity $entity): JsonResponse
    {
        if ($entity->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $startOfWeek = now()->startOfWeek(\Carbon\Carbon::MONDAY);

        $weekCount = $entity->entries()
            ->where('entries.created_at', '>=', $startOfWeek)
            ->count();

        $result = $entity->loadCount('entries')->toArray();
        $result['week_count'] = $weekCount;
        $result['strategies'] = $entity->strategies()
            ->where('status', 'confirmed')
            ->pluck('description');
        $result['recent_entries'] = $entity->entries()
            ->orderByDesc('entries.created_at')
            ->limit(5)
            ->get(['entries.id', 'entries.body', 'entries.created_at'])
            ->map(fn ($e) => [
                'id'         => $e->id,
                'body'       => $e->body,
                'created_at' => $e->created_at,
            ]);

        return response()->json($result);
    }

    /**
     * PATCH /api/entities/{entity}
     * Update trigger_status, valence, and/or category. If valence/category
     * is explicitly set (non-null), also clear its matching _suggested
     * field. category (2026-08-17) follows the exact same confirm-clears-
     * suggestion pattern as valence, just against Entity::CATEGORIES
     * instead — see that const's docblock for why it's a separate column.
     */
    public function update(Request $request, Entity $entity): JsonResponse
    {
        if ($entity->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $validated = $request->validate([
            'trigger_status' => ['nullable', 'in:unconfirmed,confirmed,dismissed'],
            'valence'        => ['nullable', 'in:positive,negative,neutral'],
            'category'       => ['nullable', 'in:' . implode(',', Entity::CATEGORIES)],
        ]);

        $updates = [];

        if (!is_null($validated['trigger_status'] ?? null)) {
            $updates['trigger_status'] = $validated['trigger_status'];
        }

        // Any explicit valence key in the request (even null = dismiss) clears the
        // suggestion so the user is never asked again after they've answered.
        if ($request->has('valence')) {
            $updates['valence']          = $validated['valence']; // null = no confirmed valence
            $updates['valence_suggested'] = null;
        }

        // Same pattern as valence above, independent column.
        if ($request->has('category')) {
            $updates['category']           = $validated['category']; // null = no confirmed category
            $updates['category_suggested'] = null;
        }

        if (!empty($updates)) {
            $entity->update($updates);
        }

        return response()->json($entity->fresh());
    }
}
