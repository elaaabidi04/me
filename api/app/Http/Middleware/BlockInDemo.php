<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 2026-10-15: the public demo accounts are shared by every visitor, so
 * actions that would change the demo's *shape* for the next person
 * (unlinking the therapist, regenerating the invite code, linking to a
 * different therapist) are refused for them. Ordinary use -- writing
 * entries, reviewing flags, assigning tasks -- stays open and is wiped by
 * the nightly demo:reset anyway. No-op for real accounts.
 */
class BlockInDemo
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->is_demo) {
            return response()->json([
                'message' => "That action is turned off in the demo so it stays the same for the next visitor.",
            ], 403);
        }

        return $next($request);
    }
}
