<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Strategy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StrategyController extends Controller
{
    /**
     * GET /api/strategies
     * Return the authenticated user's suggested and confirmed strategies,
     * with the related entity.
     */
    public function index(Request $request): JsonResponse
    {
        $strategies = $request->user()
            ->strategies()
            ->whereIn('status', ['suggested', 'confirmed'])
            ->with('entity')
            ->get();

        return response()->json($strategies);
    }

    /**
     * PATCH /api/strategies/{strategy}
     * Update the status of a strategy (confirmed or dismissed).
     */
    public function update(Request $request, Strategy $strategy): JsonResponse
    {
        if ($strategy->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $validated = $request->validate([
            'status' => ['required', 'in:confirmed,dismissed'],
        ]);

        $strategy->update($validated);

        return response()->json($strategy->fresh());
    }
}
