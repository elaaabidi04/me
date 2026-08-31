<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AssignedTask;
use App\Models\TherapistClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * 2026-08-18, user: "i want to add tasks if he wants to give them to his
 * patient and the patient cross them." A therapist assigns a small task
 * to a currently-linked client; the client checks it off. See
 * AssignedTask's docblock and docs/therapist-bridge.md for the full
 * design.
 *
 * No Policy layer, matching every other controller in this app —
 * ownership/role checks are inline (same style as TherapistLinkController
 * and SharedInsightController).
 */
class TaskController extends Controller
{
    /**
     * POST /api/tasks { client_id, title, instructions?, due_date? }
     * Therapist-only. Requires an active link to that client — same
     * "consent/link gates every boundary" rule as everything else that
     * crosses the therapist<->client line (SharedInsightController::store()
     * requires the mirror image of this on the client's side).
     */
    public function store(Request $request): JsonResponse
    {
        $therapist = $request->user();
        if ($therapist->role !== 'therapist') {
            return response()->json(['message' => 'Only therapist accounts can assign tasks.'], 403);
        }

        $validated = $request->validate([
            'client_id'    => ['required', 'integer'],
            'title'        => ['required', 'string', 'max:255'],
            'instructions' => ['nullable', 'string', 'max:2000'],
            'due_date'     => ['nullable', 'date_format:Y-m-d'],
        ]);

        $linked = TherapistClient::where('therapist_id', $therapist->id)
            ->where('client_id', $validated['client_id'])
            ->exists();
        if (!$linked) {
            return response()->json(['message' => 'Not linked to this client.'], 403);
        }

        $task = AssignedTask::create([
            'therapist_id' => $therapist->id,
            'client_id'    => $validated['client_id'],
            'title'        => $validated['title'],
            'instructions' => $validated['instructions'] ?? null,
            'due_date'     => $validated['due_date'] ?? null,
        ]);

        return response()->json($task, 201);
    }

    /**
     * GET /api/tasks
     * Client-only. Every task ever assigned to them, regardless of
     * whether the assigning therapist is still linked — same "history
     * stays visible, only new access is gated by the current link" shape
     * CrisisThreadController uses for old messages. Incomplete first
     * (oldest due date first within that group), so the list reads as an
     * actual to-do list rather than a activity feed.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user->role !== 'client') {
            return response()->json(['message' => 'Only client accounts have tasks.'], 403);
        }

        $tasks = AssignedTask::where('client_id', $user->id)
            ->with('therapist:id,name')
            ->get()
            ->sort(function (AssignedTask $a, AssignedTask $b) {
                $aDone = $a->completed_at !== null;
                $bDone = $b->completed_at !== null;
                if ($aDone !== $bDone) {
                    return $aDone <=> $bDone; // incomplete (false) first
                }
                $aDue = $a->due_date ?? '9999-99-99';
                $bDue = $b->due_date ?? '9999-99-99';
                if ($aDue !== $bDue) {
                    return $aDue <=> $bDue;
                }
                return $a->created_at <=> $b->created_at;
            })
            ->values();

        return response()->json($tasks);
    }

    /**
     * GET /api/tasks/client/{clientId}
     * Therapist-only. Only tasks THEY assigned to a client they're
     * currently linked to — a therapist never sees another therapist's
     * tasks for a shared-in-the-past client, and loses visibility into
     * their own old tasks once unlinked (matches SharedInsightController::
     * forClient()'s scoping).
     */
    public function forClient(Request $request, int $clientId): JsonResponse
    {
        $therapist = $request->user();
        if ($therapist->role !== 'therapist') {
            return response()->json(['message' => 'Only therapist accounts can view assigned tasks.'], 403);
        }

        $linked = TherapistClient::where('therapist_id', $therapist->id)
            ->where('client_id', $clientId)
            ->exists();
        if (!$linked) {
            return response()->json(['message' => 'Not linked to this client.'], 403);
        }

        $tasks = AssignedTask::where('therapist_id', $therapist->id)
            ->where('client_id', $clientId)
            ->latest()
            ->get();

        return response()->json($tasks);
    }

    /**
     * PATCH /api/tasks/{task}/complete
     * Client-only, toggles rather than one-way sets — "cross it" should
     * be undoable if clicked by mistake, same spirit as every other
     * confirm-style action in this app never being a one-shot trap.
     */
    public function toggleComplete(Request $request, AssignedTask $task): JsonResponse
    {
        $user = $request->user();
        if ($task->client_id !== $user->id) {
            return response()->json(['message' => 'Not your task.'], 403);
        }

        $task->completed_at = $task->completed_at ? null : Carbon::now();
        $task->save();

        return response()->json($task);
    }

    /**
     * DELETE /api/tasks/{task}
     * Therapist-only, must be the one who created it. No edit endpoint on
     * purpose (v1 scope) -- delete and re-assign covers "I got it wrong"
     * without adding a second way to change the same fields store() sets.
     */
    public function destroy(Request $request, AssignedTask $task): JsonResponse
    {
        $therapist = $request->user();
        if ($task->therapist_id !== $therapist->id) {
            return response()->json(['message' => 'Not your task.'], 403);
        }

        $task->delete();

        return response()->json(['message' => 'Deleted.']);
    }
}
