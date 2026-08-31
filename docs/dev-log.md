# me — Development Log

> Sprint format aligned with the 4-week roadmap in `build-roadmap.md`.
> Each day: what was built, key decisions made, and any blockers resolved.
> Use this as the primary source for your report's progress section.

---

## Sprint 1 — Foundation
**Dates:** 2026-08-04 → 2026-08-10
**Goal:** Scaffold, auth, full DB schema, daily journaling loop, mobile-ready UI.

---

### Day 1 — 2026-08-04

**Built:**
- Initialized two-repo structure: `api/` (Laravel 12) and `spa/` (Vue 3 + Vite + Router + Pinia)
- Configured Sanctum cookie-based SPA auth — CSRF cookie flow, `SANCTUM_STATEFUL_DOMAINS`, CORS with `supports_credentials: true`
- Created all 14 database migrations:
  - Core: `users` (with `role` enum client/therapist), `preferences`, `sessions`, `cache`, `jobs`
  - Journaling: `entries` (encrypted `body`, `in_vault` flag), `daily_intents` (`pick` enum + `analysis_requests` counter)
  - Analysis: `entry_analyses` (mood, sentiment, rumination signals, distress flag), `entry_entity` pivot
  - Entities: `entities` (name, type, trigger_status tri-state)
  - Social/safety: `coach_messages`, `safety_events`, `weekly_reflections`, `therapist_client`, `shares`
- Created all 10 Eloquent models with full relationships and casts:
  - `Entry` — encrypted `body` cast, `in_vault` boolean, relations to user, analysis, entities, coachMessage
  - `EntryAnalysis` — `raw_json` array cast, `distress_flag` boolean
  - `Entity` — `trigger_status` tri-state (unconfirmed / confirmed / dismissed)
  - `DailyIntent`, `User`, `Preference`, `CoachMessage`, `SafetyEvent`, `WeeklyReflection`, `Share`
- Built `AuthController` — register (with auto-created default Preference row), login, logout
- Built `Login.vue` and `Register.vue` — form validation, error surfacing, router redirect on success
- Built `Dashboard.vue` — complete UI shell:
  - 3-theme system (Dusk / Paper / Night) via CSS variables
  - Daily intent pill picker (engage / witness / light / rest)
  - Write textarea + "set down." confirmation
  - Weekly reflection sealed card (time-locked design)
  - Coach note with door choice (sit / leave)
  - Constellation SVG of named entity threads
- Configured `api.js` Axios instance — `withCredentials`, `withXSRFToken`, `baseURL` from env

**Key decisions:**
- SQLite for development (zero-config, fast iteration) — can migrate to Postgres before demo
- `QUEUE_CONNECTION=database` — jobs table already migrated, no Redis dependency during development
- `Entry.body` uses Laravel's `encrypted` cast — all raw queries on this column are permanently banned, encryption is invisible through Eloquent
- `trigger_status` is a tri-state enum, not boolean — `unconfirmed` vs `dismissed` must be distinguishable so the app doesn't re-surface things the user already rejected

**Status at end of day:** Auth working end-to-end. Dashboard renders with all theme variants. API and SPA can talk (CORS + Sanctum configured). No journaling routes yet.

---

### Day 2 — 2026-08-05

**Built:**
- `config/nvidia.php` — reads `NVIDIA_API_KEY` and `NVIDIA_MODEL` from env; endpoint hardcoded to `https://integrate.api.nvidia.com/v1/chat/completions`
- `NvidiaClient` service — thin Http facade wrapper; handles timeouts and non-200 responses by logging + returning `null`, never throws into the request cycle
- `EntryExtractionService` — takes an Entry, builds a fixed extraction prompt, calls the LLM, validates the response JSON strictly before touching the DB:
  - Expected shape: `{ mood, sentiment, people[], places[], topics[], focus_dominant }`
  - On bad JSON or missing keys: logs raw response and aborts — no partial writes
  - On success: `updateOrCreate` on `entry_analyses`, then `firstOrCreate` each entity scoped to `user_id + name + type`, then `syncWithoutDetaching` on the pivot
- `ExtractEntryJob` — implements `ShouldQueue`; re-checks `in_vault` at job-run time (user may have vaulted between creation and processing); 2 retries, 60s timeout
- `EntryController`:
  - `POST /api/entries` — validates `body` (required string), optional `daily_intent_id`, optional `in_vault`; hard rule: if `in_vault` is true, LLM call is skipped entirely and no job is dispatched
  - `GET /api/entries` — paginated (20/page), newest first, with `analysis` eager-loaded
- `DailyIntentController`:
  - `POST /api/intent` — validates `pick` is one of `engage|witness|light|rest`; `updateOrCreate` on `user_id + date` unique key
  - `GET /api/intent` — returns today's record or null
- Registered both controllers inside the `auth:sanctum` route group
- Added `NVIDIA_API_KEY` and `NVIDIA_MODEL` to `.env.example` with a comment linking to `build.nvidia.com`
- Wired `Dashboard.vue` to real API:
  - `onMounted` → `GET /api/intent` to restore today's pick on page load
  - `choosePick()` → `POST /api/intent` (optimistic UI, deselect is local-only)
  - `saveEntry()` → `POST /api/entries` with `body`, shows "set down." on success
- Fixed three mobile responsiveness issues:
  - Replaced Vite boilerplate `style.css` (had `#app { width: 1126px; text-align: center }` leaking globally) with a minimal 9-line reset
  - Auth cards: changed `width: 320px` (overflowed on 375px iPhone) to `width: 100%; max-width: 360px` with container padding
  - Auth inputs: added explicit `font-size: 16px` to prevent iOS Safari auto-zoom on focus
  - Dashboard: added `text-align: left` and switched `100vh` → `100svh` (fixes iOS Safari toolbar height bug)

**Blockers resolved:**
- `User::first()` returned null in tinker — no users in DB yet; fix: create test user via `User::create()`
- Login returning 500 — root cause: `Invoke-RestMethod` (PowerShell's curl alias) doesn't send an `Origin` header. Sanctum's `EnsureFrontendRequestsAreStateful` middleware only initializes the session for requests from configured stateful domains. Without `Origin: http://localhost:5173`, the session store is never set and `$request->session()->regenerate()` throws. Fix: add `Origin` header to all test requests
- `Route [login] not defined` 500 on protected routes — root cause: unauthenticated requests without `Accept: application/json` cause the auth middleware to try redirecting to a named `login` route that doesn't exist in an API-only project. Fix: add `Accept: application/json` to all requests

**Test results (manual, via PowerShell `Invoke-RestMethod`):**
- ✅ `POST /api/login` — returns user object
- ✅ `POST /api/intent` — creates `daily_intent` row with correct date + pick
- ✅ `POST /api/entries` — creates entry, encrypts body at rest, dispatches extraction job
- ✅ `GET /api/entries` — returns paginated list with `analysis` eager-loaded
- ✅ Vault guard — entry with `in_vault: true` has `analysis: null` (no LLM call dispatched)

**Status at end of day:** Full daily journaling loop working. Entry creation, intent picking, and extraction pipeline complete. SPA wired to real API. Mobile-responsive on all screen sizes. Ready for Phase 2 (coach classifier + response generator).

---

### Day 3 — 2026-08-06

**Built:**
- **Extraction schema v2** — `EntryExtractionService` rewritten from an 8-key mood/sentiment/entity schema into a richer psychology-oriented one: `emotions[]` (closed 12-value vocab, with intensity), `stressors[]` (hierarchical: 6 categories, each with its own subcategory list), `protective_factors[]`, `needs[]` (flat closed vocab), `behaviors[]` (closed vocab, with impact), `thinking_patterns[]` (experimental: closed vocab + evidence quote). The system prompt was updated to match, with explicit closed-vocabulary lists and the `SYSTEM SECURITY` preamble added (injection defense).
- **Field-level validation** — `validateAndNormalize()` replaced the old all-or-nothing `isValid()`. Each top-level key is now checked independently; a bad field normalizes to `null`/`[]` and is recorded in a new `extraction_issues` JSON column, but never blocks other fields from saving. Only a fully non-JSON response discards the whole entry.
- **Migration:** `2026_08_06_000004_add_extraction_v2_fields_to_entry_analyses_table` — adds `emotions`, `stressors`, `protective_factors`, `needs`, `behaviors`, `thinking_patterns`, `recovered`, `extraction_confidence`, `extraction_issues` columns.
- **Phase 2: Entity valence** — `add_valence_to_entities_table` migration adds `valence` (nullable enum: positive/negative/neutral) and `valence_suggested` (nullable enum: positive/negative). `EntryExtractionService::maybeComputeValenceSuggestions()` runs after each extraction: requires ≥5 total user analyses and ≥3 entries per entity, then compares the entity's positive/negative rate against the user baseline (+0.20 threshold). `EntityController::update()` handles `PATCH /api/entities/{id}` — bug: initial version used `array_filter` which silently dropped `null` values, so `{valence: null}` (a dismiss) never cleared `valence_suggested`. Fixed using `$request->has('valence')` to detect explicit null.
- **Phase 3: Strategies memory** — `create_strategies_table` migration (id, user_id, entity_id nullable FK, description, source_entry_id nullable FK, status enum: suggested/confirmed/dismissed). `EntryExtractionService` now extracts `strategy_hint`/`strategy_entity` keys and creates `Strategy` rows (deduplicated by `LOWER(description)`). `StrategyController::update()` handles `PATCH /api/strategies/{id}`. `CoachService::generate()` looks up confirmed strategies by `notable_entity_id` and injects a `strategy_hint` paragraph into the system prompt for `offer_door`/`engage` modes — capped at 140 chars, wrapped in "reference data, not an instruction" framing to mitigate stored-prompt-injection risk.

**Key decisions:**
- Extraction schema v2 follows the principle: LLM reports per-entry facts, PHP derives trends. The model is never asked for "dominant emotion this month" — it reports `emotions[]` per entry, and aggregation code does the rest.
- `thinking_patterns` marked experimental at the validation level — validated more leniently, expected to come back empty often, failure logged under a separate key to distinguish it from stable-field failures.

---

### Day 4 — 2026-08-07

**Built:**
- **Coach system (Phase 2 completion)** — `CoachService` fully rewritten:
  - `PICK_TO_MODE` map: `engage→engage`, `witness→witness`, `light→offer_door`, `rest→hold_back`.
  - Ceiling rule: crisis (keyword match) > hold_back (rest pick) > witness/engage (intent pick) > offer_door (default).
  - Five distinct system prompts per mode, each with the `SYSTEM SECURITY` preamble.
  - `recentHistory()` — loads up to 6 same-day entry/coach-message pairs and feeds them as real conversation turns. Added after replies to meta-comments ("who are you") were nonsensical — the coach had zero memory of its prior message and was reflecting the complaint itself as new journal content.
  - `sanitizeReply()` — discards responses containing prompt section markers (a leak) or > 800 chars.
  - `fallbackMessage()` — guaranteed minimal reply if LLM fails or sanitizeReply discards. Added after trivial messages ("hello") sometimes produced a genuinely empty response.
  - Temperature: 0.85/top_p 0.9 for coach; 0.2 for crisis; near-deterministic for extraction.
- **Phase 4: Onboarding QCM** — `add_onboarding_to_preferences_table` migration adds `top_values` (JSON nullable) and `onboarding_completed` (boolean, default false). `OnboardingController::store()` handles `POST /api/onboarding`. `Onboarding.vue` — 5-question flow: tone (gentle/direct), nudge_level (1–3), language (ar/darija/fr/en), product_mode (solo/bridge), top_values (multi-select, max 3). Progress dots, skip-all button. On completion: POST /api/onboarding, fetchUser(), push('/').
- **Router guard** — `beforeEach` in `router/index.js`: if `auth.user?.preferences?.onboarding_completed === false`, redirect to `/onboarding` before any authenticated page. Added `/onboarding` and `/calendar` routes.
- **Phase 5: Weekly reflection** — `WeeklyReflectionController::current()` with find-or-create, lock enforcement (`sealed_until` 423 response until Sunday EOD), `REFLECTION_DEV_BYPASS` env flag for dev. `generateContent()` computes: `mood_distribution`, `dominant_mood`, `trend` (week vs. prior week positive rate, ±0.10 threshold), `top_positive_trigger`, `top_negative_trigger`, `insights` (entity-mood correlation sentences, up to 2), and all six v2 breakdowns via `tagBreakdown()`.

**Blockers resolved:**
- Weekly reflection unique constraint crash: `whereDate()` vs exact string comparison — `firstOrCreate` compared the date as a bare string (`'2026-08-07'`) against a DB value stored as `'2026-08-07 00:00:00'` (the model's `date` cast). Fix: use `whereDate()` which calls `DATE()` in SQL.
- `GET /api/user` not loading preferences on auth check: fixed by adding `->load('preferences')` to the user route.

---

### Day 5 — 2026-08-08

**Built:**
- **Calendar view** — `Calendar.vue`: split layout (300px calendar panel + day thread panel), Monday-first 7×6 grid, month navigation, `datesWithEntries` from `GET /api/entries/dates?month=YYYY-MM` (dots on days that have entries). Day selection ignores future dates. Day thread: read-only for past, compose bar only for today (same polling logic as Journal). `EntryController::dates()` added — `GROUP BY DATE(created_at)` returning `{"YYYY-MM-DD": count}` map. Route registered before `GET /api/entries` to avoid wildcard collision.
- **Journal.vue polling fix** — replaced `POLL_DELAYS = [3000,3000,4000,5000]` (15s max) with time-based polling: 3s interval, 90s deadline matching `GenerateCoachMessageJob::$timeout`. Applied identically to Calendar.vue.
- **Journal.vue merge-in-place** — `refreshEntries()` replaced full array replacement with find-by-ID update-or-append, so in-flight optimistic bubbles don't flash/disappear during a background refresh.
- **DemoDataSeeder** — 4 entities (morning walk/positive, the deadline/negative, Sarah/unconfirmed, the office/confirmed), 13 backdated entries across 3 weeks (trend: UP), 2 confirmed strategies, realistic coach messages. Idempotency guard checks for `CoachMessage` with `reason='demo_seeder'`. Uses `updateOrCreate` (not `firstOrCreate`) for entities that might already exist from real extraction, to force valence even on pre-existing rows.
- **Dashboard.vue v2 charts** — full visual showcase: mood ring (SVG donut from `mood_distribution`), pictorial fraction chart (10-dot isotype per emotion), circular gauges (one per protective factor), pie chart (behaviors, colored by self-supportive vs. self-undermining), concrete triggers bar chart (from entities/strategies, not abstract stressor categories), radar chart (needs, fixed 8-axis order). `warm()` / `frequencyWord()` humanization layer — no raw enum values or percentages shown to the user.

**Key decisions:**
- Calendar is read-only for past days client-side only. No server-side date constraint on entry creation — the user can only reach the compose bar for today, enforced by `v-if="isToday"`.
- DemoDataSeeder clears and re-generates the current week's `WeeklyReflection` so the seeded data is visible immediately without waiting for natural accumulation.

---

### Day 6 — 2026-08-09

**Built:**
- **Journal.vue daily reset** — `setInterval` (30s tick) + post-send trigger: if the current time is 23:30–00:15 and the compose box is empty and no send is in flight, clears `entries.value` and sets `dailyResetDone`. Fires once per page session. Old entries remain in the DB; the calendar is the archive.
- Bug fixes from testing:
  - `DemoDataSeeder` idempotency guard was checking for entity name+valence, which failed if the entity already existed from real extraction without valence. Changed to check for `CoachMessage.reason = 'demo_seeder'`.
  - Stale weekly reflection after seeder: added `WeeklyReflection::where(...)->delete()` in seeder to force regeneration.
  - `$entry->timestamps = false` + manual `created_at`/`updated_at` required for backdate to persist correctly through Eloquent.

---

### Day 7 — 2026-08-10

**Built:**
- **Phase 6: ExtractionTaxonomy** — `app/Support/ExtractionTaxonomy.php` extracts all six closed vocabularies from `EntryExtractionService` as public consts, plus two static helpers: `enumForField(string $field): array` (flat allowed values per field) and `labelKeyForField(string $field): ?string` (the JSON item key that carries the label, null for flat-string fields like `needs`). `EntryExtractionService` now references these via private const aliases — behavior identical, no drift possible between the extraction validator and the correction validator.
- **Phase 6: Analysis corrections** — `analysis_corrections` table (entry_analysis_id FK, field, original_value, action enum, corrected_value nullable; unique on the triple). `AnalysisCorrection` model. `AnalysisCorrectionController`:
  - `POST /api/analysis-corrections` — validates ownership (analysis → entry → user_id), verifies `original_value` actually appears in the extracted JSON array for that field (using `ExtractionTaxonomy::labelKeyForField()` to navigate item shapes), validates `corrected_value` against `ExtractionTaxonomy::enumForField()` when action is `corrected`, then `updateOrCreate` keyed on the unique triple.
  - `GET /api/analysis-corrections/pending-thinking-pattern` — batch-loads corrections in one query, then iterates analyses in PHP to return the first unreviewed `thinking_patterns` item from the last 30 days.
- **Weekly aggregation corrections** — `WeeklyReflectionController::tagBreakdown()` now batch-loads corrections per column in one query before the main loop. Inside the loop: `removed` → skip, `corrected` → swap label, `confirmed` → unchanged. Corrections immediately change what the weekly breakdown charts show.
- **Dashboard pattern_correction note** — `pendingPattern` ref loaded in parallel on mount. Fourth priority tier in `activeNote` computed (lowest, after strategy suggestions). Shows the evidence quote with the warm phrase from `THINKING_PATTERN_WORDS`, asks "does that feel right?". Confirm → `action: confirmed`, dismiss → `action: removed`, then reloads the pending check.

**Verified:**
- `ExtractionTaxonomy::enumForField('thinking_patterns')` returns the expected 8-value list; `labelKeyForField('needs')` returns null (flat-string field).
- Inserted a `removed` correction for `catastrophizing` on a real analysis row via tinker; re-ran `generateContent()` — `catastrophizing` absent from `thinking_pattern_breakdown`, remaining patterns (`rumination`, `self_criticism`) still present. Correction removed after test.

---

## Sprint 2 — Core Intelligence
**Dates:** 2026-08-11 → 2026-08-17
**Goal:** Coach classifier (LLM call #1), response generator (LLM call #2), ceiling rule, theming wired.

| # | Task | Status |
|---|------|--------|
| 1 | Coach classifier prompt + schema validation | — |
| 2 | Coach response generator (one prompt per mode) | — |
| 3 | Ceiling rule: `crisis > hold_back > pick-driven > offer_door` | — |
| 4 | `CoachController` + route | — |
| 5 | SPA: display coach note from real API | — |
| 6 | Prompt tuning against worked examples in `coach-decision-spec.md` | — |

> *Daily log to be added here during the sprint.*

---

## Sprint 3 — Longitudinal Layer + Safety
**Dates:** 2026-08-18 → 2026-08-24
**Goal:** Weekly analysis scheduler, longitudinal aggregation, entity graph, anti-overuse limits, consent controls.

| # | Task | Status |
|---|------|--------|
| 1 | Crisis handling end-to-end (detection → resource surfacing) | — |
| 2 | Weekly analysis scheduler job (sealed HTTP 423 until Sunday) | — |
| 3 | Longitudinal aggregation: `theme_streak`, `mood_on_theme`, `self_ref_density`, `analysis_requests` | — |
| 4 | Entity frequency + mood correlation | — |
| 5 | Anti-over-analysis limits: daily request cap, compulsive-recheck nudge | — |
| 6 | Consent controls: vault toggle, per-item share flag, delete | — |
| 7 | Entity graph or ranked-list fallback on dashboard | — |

> *Daily log to be added here during the sprint.*

---

## Sprint 4 — Validation + Therapist Bridge + Polish
**Dates:** 2026-08-25 → 2026-08-31
**Goal:** Validation note, guardrail tests, therapist dashboard, demo polish.

| # | Task | Status |
|---|------|--------|
| 1 | Validation note: label ~30 entries, run classifier, report agreement | — |
| 2 | Guardrail regression tests: prefs/pick cannot override crisis or hold_back | — |
| 3 | Therapist dashboard: brief generator + entity graph + clinical-worded view | — |
| 4 | Demo polish: full user journey end-to-end on staging | — |
| 5 | Architecture / consent-privacy write-up | — |

> *Daily log to be added here during the sprint.*

---

## Running decisions log

| Date | Decision | Why |
|------|----------|-----|
| 2026-08-04 | SQLite for dev | Zero-config, no Postgres to spin up; migrate before demo |
| 2026-08-04 | Queue driver: `database` | Jobs table already migrated; no Redis needed in dev |
| 2026-08-04 | `Entry.body` encrypted cast | Journal text is sensitive; encryption is invisible through Eloquent |
| 2026-08-04 | `trigger_status` tri-state, not boolean | Need to distinguish "not asked" from "user said no" to avoid re-surfacing dismissed items |
| 2026-08-05 | LLM provider: NVIDIA NIM (OpenAI-compatible) | `meta/llama-3.1-70b-instruct` via `integrate.api.nvidia.com`; swappable via env var |
| 2026-08-05 | Extraction prompt: strict JSON, no markdown fences | Prevents parsing failures; model is instructed to return raw JSON only |
| 2026-08-05 | Vault rule: hard skip in controller, re-checked in job | Belt-and-suspenders: even if a bug dispatches a job for a vaulted entry, the job handler re-validates and aborts |
| 2026-08-05 | `QUEUE_CONNECTION=sync` for local dev testing | Extraction runs inline; switch back to `database` + run `queue:work` for production-like behaviour |
| 2026-08-06 | Extraction validation is field-level, not all-or-nothing | A single malformed `thinking_patterns` response previously discarded all of mood/sentiment/emotions/stressors too; field-level isolation is worth the added complexity |
| 2026-08-06 | `thinking_patterns` validated leniently, logged under a separate issues key | Newest, least battle-tested taxonomy — its failure rate shouldn't pollute monitoring signals for the stable fields |
| 2026-08-07 | Coach history: same-day only, up to 6 prior pairs | Cross-day history risks anchoring bias (the coach "remembering" a bad day from three weeks ago); same-day context is enough to fix the "who are you" failure mode |
| 2026-08-07 | Onboarding redirect is a router guard, not enforced server-side | The guard is sufficient for a personal-use app; a multi-user product would need a server-side gate on every authenticated route |
| 2026-08-08 | Calendar read-only enforcement is client-side only | No server-side date constraint needed — the compose bar's `v-if="isToday"` is the only path to POST /api/entries from the calendar |
| 2026-08-08 | DemoDataSeeder uses `updateOrCreate` not `firstOrCreate` for entities with valence | `firstOrCreate` returned pre-existing rows without setting valence; `updateOrCreate` forces the correct value even on rows that already exist |
| 2026-08-09 | Daily journal reset is client-side, once per page session | A server-side last-cleared-at timestamp would survive tab switches but adds complexity for a cosmetic feature; client-side `dailyResetDone` flag is sufficient |
| 2026-08-10 | Taxonomy in `ExtractionTaxonomy.php`; frontend dictionaries stay in `Dashboard.vue` | The backend needed a single shared source to stop drift between extraction and correction validation. The frontend humanization copy is a different concern (design, not data) and doesn't benefit from being in PHP |
| 2026-08-10 | Corrections applied in `tagBreakdown()`, not stored as a separate corrected-analysis snapshot | Computing corrections at read time (weekly aggregation) rather than mutating the stored analysis row preserves the original extraction data for future review and avoids reprocessing |

---

## Cut list (from roadmap — track if anything slips here)

| Feature | Priority | Status |
|---------|----------|--------|
| Multi-language (Arabic / Darija / French) | Stretch | Not started |
| Therapist dashboard (full) | Secondary | Not started |
| Entity graph (Cytoscape.js) | Optional — ranked list acceptable | Not started |
| Additional theming beyond Dusk/Paper/Night | Optional | Done in Sprint 1 |
| Architecture write-up | Week 4 | Not started |
