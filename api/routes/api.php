<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AnalysisCorrectionController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CanonEventController;
use App\Http\Controllers\Api\CrisisThreadController;
use App\Http\Controllers\Api\DailyIntentController;
use App\Http\Controllers\Api\DailyReviewController;
use App\Http\Controllers\Api\DemoController;
use App\Http\Controllers\Api\EntryController;
use App\Http\Controllers\Api\EntityController;
use App\Http\Controllers\Api\MonthlyDashboardController;
use App\Http\Controllers\Api\OnboardingController;
use App\Http\Controllers\Api\PatternController;
use App\Http\Controllers\Api\SessionBriefController;
use App\Http\Controllers\Api\SharedInsightController;
use App\Http\Controllers\Api\StrategyController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\TherapistActivityController;
use App\Http\Controllers\Api\TherapistLinkController;
use App\Http\Controllers\Api\TherapySessionController;
use App\Http\Controllers\Api\WeeklyReflectionController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// --- Auth (Sanctum SPA / cookie-based session auth) -------------------
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1'); // 2026-10-25: also guards the admin password

// --- Public demo (2026-10-15) -- one-click login into the seeded demo
// accounts. 404s unless DEMO_MODE=true; see config/demo.php.
Route::post('/demo/login', [DemoController::class, 'login'])->middleware('throttle:demo-login');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);

    // --- Admin (2026-10-25) -- owner-only view of what visitors wrote in
    // the public demo accounts. See AdminController + config/demo.php.
    Route::get('/admin/activity', [AdminController::class, 'activity'])->middleware('admin');
    // 2026-11-10: the admin opens a demo copy with no entry / AI caps
    Route::post('/admin/demo-login', [DemoController::class, 'adminLogin'])->middleware('admin');
    Route::post('/admin/back', [DemoController::class, 'backToAdmin']);   // checks its own session flag
    Route::get('/user', function (Request $request) {
        $user = $request->user()->load('preferences');
        // 2026-11-10: how many demo entries this visitor has left
        if (! $user->is_demo) {
            return $user;
        }
        $unlimited = \App\Support\DemoSandbox::unlimited($request);

        return array_merge($user->toArray(), [
            'demo_tries_left' => $unlimited ? null : \App\Support\DemoSandbox::triesLeft($user),
            'demo_unlimited'  => $unlimited,   // 2026-11-10: the admin testing
        ]);
    });

    // --- Entries ------------------------------------------------------------
    // Rate-limited: nothing previously stopped rapid-fire entry creation,
    // which is both an API-cost abuse vector (each entry triggers two LLM
    // calls) and works against the app's own anti-over-analysis principle.
    // 20/minute is generous for a real journaling session, tight enough to
    // block scripted abuse.
    Route::get('/entries/dates', [EntryController::class, 'dates']); // before wildcard routes
    Route::get('/entries',       [EntryController::class, 'index']);
    Route::post('/entries',      [EntryController::class, 'store'])->middleware(['throttle:20,1', 'throttle:demo-ai']);
    Route::get('/entries/{entry}', [EntryController::class, 'show']); // after /entries/dates

    // --- Daily intent -----------------------------------------------------
    Route::get('/intent',  [DailyIntentController::class, 'show']);
    Route::post('/intent', [DailyIntentController::class, 'store']);

    // --- Phase 2: Entity valence -----------------------------------------
    Route::get('/entities',            [EntityController::class, 'index']);
    Route::get('/entities/{entity}',   [EntityController::class, 'show']);
    Route::patch('/entities/{entity}', [EntityController::class, 'update']);

    // --- Phase 3: Effective-strategies memory ----------------------------
    Route::get('/strategies',              [StrategyController::class, 'index']);
    Route::patch('/strategies/{strategy}', [StrategyController::class, 'update']);

    // --- Phase 4: Onboarding preferences ---------------------------------
    Route::post('/onboarding', [OnboardingController::class, 'store']);

    // --- Phase 5: Weekly reflection --------------------------------------
    Route::get('/reflections/live',                             [WeeklyReflectionController::class, 'live']);
    Route::get('/reflections/current',                          [WeeklyReflectionController::class, 'current']);
    Route::get('/reflections/emotion-detail/{emotion}',         [WeeklyReflectionController::class, 'emotionDetail']);

    // --- Monthly dashboard (2026-08-17) -----------------------------------
    // Live, ungated month-over-month trend view. /client/{clientId} listed
    // first (three-segment path before two-segment path, same two-segment-
    // vs-one-segment ordering discipline as shared-insights/tasks/sessions).
    Route::get('/monthly/trends/client/{clientId}', [MonthlyDashboardController::class, 'trendsForClient']);
    Route::get('/monthly/trends', [MonthlyDashboardController::class, 'trends']);

    // --- Phase 6: Analysis corrections -----------------------------------
    // pending-thinking-pattern must come before the generic POST so the
    // router doesn't try to match 'pending-thinking-pattern' as an {id}.
    Route::get('/analysis-corrections/pending-thinking-pattern', [AnalysisCorrectionController::class, 'pendingThinkingPattern']);
    Route::post('/analysis-corrections',                          [AnalysisCorrectionController::class, 'store']);

    // --- Phase 7: Pattern chains (all-time trigger -> emotion/thinking-
    // pattern/behavior co-occurrence, not windowed to the current week) ---
    Route::get('/patterns', [PatternController::class, 'index']);
    // Drill-through: the entries behind a trigger, optionally narrowed to
    // one specific chain link (?field is emotion|thinking_pattern|behavior).
    Route::get('/patterns/{trigger}', [PatternController::class, 'show']);
    Route::get('/patterns/{trigger}/{field}/{value}', [PatternController::class, 'show']);

    // --- Daily breakdown review (2026-08-18) -------------------------------
    // See docs/dashboard-logic.md and DailyReviewController's docblock.
    // 'pending' must come before the {date} wildcard so the router doesn't
    // try to parse 'pending' as a date — same ordering reasoning as
    // pending-thinking-pattern / canon-events/pending above.
    Route::get('/daily-review/pending',                     [DailyReviewController::class, 'pending']);
    Route::get('/daily-review/{date}',                      [DailyReviewController::class, 'show']);
    Route::post('/daily-review/{date}/save',                [DailyReviewController::class, 'save']);
    Route::post('/daily-review/{date}/mark-reviewed',        [DailyReviewController::class, 'markReviewed']);
    Route::get('/daily-review/{date}/{field}/{value}',       [DailyReviewController::class, 'drillThrough']);

    // --- Canon-event memory (2026-08-12) ----------------------------------
    // pending must come before the generic PATCH so the router doesn't try
    // to match 'pending' as a {canonEvent} id — same reasoning as
    // pending-thinking-pattern above.
    Route::get('/canon-events/pending',       [CanonEventController::class, 'pending']);
    Route::get('/canon-events',               [CanonEventController::class, 'index']);
    Route::patch('/canon-events/{canonEvent}', [CanonEventController::class, 'update'])->middleware('throttle:demo-ai'); // embeds via LLM on confirm

    // --- Therapist bridge: code-invite linking + crisis chat (2026-08-17) -
    // See docs/therapist-bridge.md. No wildcard-collision ordering concern
    // here (unlike pending/pending-thinking-pattern above) — grouped by
    // concern instead, linking routes then thread routes.
    Route::get('/therapist/invite-code',  [TherapistLinkController::class, 'inviteCode']);
    Route::post('/therapist/invite-code', [TherapistLinkController::class, 'regenerateInviteCode'])->middleware('demo.locked');
    // live-trends listed before the /link wildcard methods to avoid a
    // router trying to match 'live-trends' as a sub-resource of the base.
    Route::patch('/therapist/link/live-trends', [TherapistLinkController::class, 'toggleLiveTrends']);
    Route::get('/therapist/link',    [TherapistLinkController::class, 'show']);
    Route::post('/therapist/link',   [TherapistLinkController::class, 'link'])->middleware('demo.locked');
    Route::delete('/therapist/link', [TherapistLinkController::class, 'unlink'])->middleware('demo.locked');

    // 2026-08-19: dashboard "what changed" feed -- merges tasks/shares/
    // briefs/logged sessions across all currently-linked clients into one
    // chronological list. See TherapistActivityController's docblock.
    Route::get('/therapist/activity', [TherapistActivityController::class, 'recent']);

    // 2026-08-17 redesign: one persistent thread per link, not one per
    // crisis flag (user: "we only need one conversation just when the
    // crisis happen") -- singular /crisis-thread, flags surfaced inline.
    Route::get('/crisis-thread',                 [CrisisThreadController::class, 'show']);
    Route::post('/crisis-thread/messages',       [CrisisThreadController::class, 'storeMessage']);
    Route::patch('/crisis-thread/flags/{safetyEvent}', [CrisisThreadController::class, 'reviewFlag']);

    // --- Share to therapist (2026-08-18) -- see InsightSnapshot's
    // docblock and docs/therapist-bridge.md §1. /client/{clientId} is a
    // two-segment path so it never collides with /{share}'s single-
    // segment wildcard, but it's still listed first on principle (same
    // ordering discipline as daily-review's pending-before-wildcard).
    Route::post('/shared-insights/chat/{clientId}',   [SharedInsightController::class, 'chat'])->middleware('throttle:demo-ai');
    Route::get('/shared-insights/client/{clientId}', [SharedInsightController::class, 'forClient']);
    Route::get('/shared-insights',                   [SharedInsightController::class, 'index']);
    Route::post('/shared-insights',                  [SharedInsightController::class, 'store']);
    Route::delete('/shared-insights/{share}',         [SharedInsightController::class, 'destroy']);
    Route::patch('/shared-insights/{share}/note',     [SharedInsightController::class, 'updateTherapistNote']);

    // --- Therapist-assigned tasks (2026-08-18) -- see AssignedTask's
    // docblock and docs/therapist-bridge.md. /client/{clientId} listed
    // first for the same two-segment-vs-one-segment reason as shared-
    // insights above.
    Route::get('/tasks/client/{clientId}', [TaskController::class, 'forClient']);
    Route::get('/tasks',                   [TaskController::class, 'index']);
    Route::post('/tasks',                  [TaskController::class, 'store']);
    Route::patch('/tasks/{task}/complete', [TaskController::class, 'toggleComplete']);
    Route::delete('/tasks/{task}',         [TaskController::class, 'destroy']);

    // --- Scheduled sessions (2026-08-18) -- see TherapySession's
    // docblock and docs/therapist-bridge.md. /client/{clientId} and
    // /{session}/log listed first (two-segment paths before one-segment
    // wildcards, same ordering discipline as pending/client above).
    Route::get('/sessions/client/{clientId}',  [TherapySessionController::class, 'forClient']);
    Route::patch('/sessions/{session}/log',    [TherapySessionController::class, 'log']);
    Route::get('/sessions',                    [TherapySessionController::class, 'index']);
    Route::post('/sessions',                   [TherapySessionController::class, 'store']);
    Route::patch('/sessions/{session}',        [TherapySessionController::class, 'update']);
    Route::delete('/sessions/{session}',       [TherapySessionController::class, 'destroy']);

    // --- Pre-session briefs (2026-08-19) -- see SessionBrief's docblock
    // and docs/therapist-bridge.md. /client/{clientId} listed first,
    // same two-segment-vs-one-segment reasoning as above.
    Route::get('/session-briefs/client/{clientId}', [SessionBriefController::class, 'forClient']);
    Route::get('/session-briefs',                   [SessionBriefController::class, 'index']);
    Route::post('/session-briefs',                  [SessionBriefController::class, 'store']);
    Route::delete('/session-briefs/{share}',         [SessionBriefController::class, 'destroy']);
});
