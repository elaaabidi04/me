<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OnboardingController extends Controller
{
    /**
     * POST /api/onboarding
     * Update the user's preferences from the onboarding flow and mark it complete.
     * Accepts skip=true to skip all field updates and just mark complete.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'skip'        => ['sometimes', 'boolean'],
            'tone'        => ['sometimes', 'nullable', 'in:gentle,direct'],
            'nudge_level' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:3'],
            'language'    => ['sometimes', 'nullable', 'in:ar,darija,fr,en'],
            'product_mode'=> ['sometimes', 'nullable', 'in:solo,bridge'],
            'top_values'  => ['sometimes', 'nullable', 'array', 'max:3'],
            'top_values.*'=> ['string'],
        ]);

        $user = $request->user();
        $preferences = $user->preferences;

        // 2026-08-17: a therapist account has no preferences row (see
        // AuthController::register()) — onboarding is a journaling-only
        // concept. Guards against a null-object-call 500 rather than
        // assuming the frontend router will always keep a therapist away
        // from this endpoint.
        if (!$preferences) {
            return response()->json(['message' => 'Onboarding does not apply to this account.'], 422);
        }

        $updates = ['onboarding_completed' => true];

        if (!($validated['skip'] ?? false)) {
            foreach (['tone', 'nudge_level', 'language', 'product_mode', 'top_values'] as $field) {
                if (array_key_exists($field, $validated) && $validated[$field] !== null) {
                    $updates[$field] = $validated[$field];
                }
            }
        }

        $preferences->update($updates);

        return response()->json($preferences->fresh());
    }
}
