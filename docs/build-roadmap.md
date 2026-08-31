# me — 4-Week Build Roadmap

> Ordered by urgency within each week: MUST items block everything after them; OPTIONAL items can slip to a later week or be cut without breaking the demo. If time runs short, drop OPTIONAL items first, in the order listed — never reorder the MUST chain, it's a dependency chain, not a wishlist.

**Minimum shippable path** (MUST only, all OPTIONAL cut): scaffold → auth → journaling → extraction → coach (5 modes + ceiling rule) → weekly analysis → crisis handling → consent/delete → validation note. This alone satisfies the sujet's "partial success" bar (§13).

---

## Week 1 — Foundation

1. **[MUST] Verify crisis resources now, not later.** — ❌ **BLOCKING — still unresolved.** `config/crisis.php` contains a placeholder text. Do not show real users this app until a verified crisis line is configured.
2. **[MUST] Laravel + Vue scaffold** — ✅ Done (Day 1).
3. **[MUST] Auth** — ✅ Done (Day 1). Sanctum cookie-based SPA auth.
4. **[MUST] DB schema & migrations** — ✅ Done (Day 1 + ongoing additions through Phase 6).
5. **[MUST] Daily journaling loop** — ✅ Done (Day 2). Entry creation, intent pick, extraction queue.
6. **[MUST] Consent skeleton** — ⚠️ Partial. `product_mode` field exists on `preferences` (set during onboarding). No per-item share flag or delete UI.
7. **[OPTIONAL] Onboarding preferences Q&A UI** — ✅ Done (Day 4). 5-question flow with router guard.
8. **[OPTIONAL] Seed script for synthetic entries** — ✅ Done. `DemoDataSeeder` with 13 backdated entries, 4 entities, 2 strategies, realistic coach messages.

---

## Week 2 — Core Intelligence (the differentiator)

1. **[MUST] Per-entry LLM extraction** — ✅ Done (schema v2: emotions, stressors, protective_factors, needs, behaviors, thinking_patterns; field-level validation; `extraction_issues` logging).
2. **[MUST] Coach classifier (LLM call #1)** — ⚠️ Partially done. Mode resolution exists (pick + keyword ceiling rule), but there is no separate LLM classifier call — mode comes from the user's daily intent pick mapped through `PICK_TO_MODE`, not from a classifier. See `ai-behavior.md §3`.
3. **[MUST] Coach response generator (LLM call #2)** — ✅ Done. `CoachService` with five distinct prompts, `SYSTEM SECURITY` preamble, `sanitizeReply()`, `fallbackMessage()`, strategy-hint injection, same-day conversation history.
4. **[MUST] Ceiling rule logic** — ✅ Done. `crisis > hold_back > pick-driven > offer_door`, enforced in `CoachService::resolveMode()`.
5. **[MUST] Daily intent pick** — ✅ Done. `DailyIntentController`, pill picker UI on both Dashboard and Journal.
6. **[OPTIONAL] Theming system** — ✅ Done (Dusk / Paper / Night, CSS variables, cycle button).
7. **[OPTIONAL] Prompt tuning pass** — ✅ Done. Clinical framing removed; phrasing bans list added; tone rules rewritten after real-usage testing.

---

## Week 3 — Longitudinal Layer + Safety

1. **[MUST] Crisis handling end-to-end** — ✅ Done. Keyword detection (English/French/Arabic-Darija), `SafetyEvent` trail, LLM acknowledgment at T=0.2, `config/crisis.php` resource text appended. **BLOCKING: resource text is still a placeholder — not safe to show real users.**
2. **[MUST] Weekly analysis scheduler job** — ✅ Done. `WeeklyReflectionController`, HTTP 423 lock until Sunday EOD, `REFLECTION_DEV_BYPASS` env flag.
3. **[MUST] Longitudinal aggregation** — ⚠️ Partial. Weekly v2 breakdowns (emotions, stressors, protective_factors, needs, behaviors, thinking_patterns), entity-mood correlation, mood trend vs. prior week — all done. Named spec fields (`theme_streak`, `mood_on_theme`, `self_ref_density`, `analysis_requests`) not built.
4. **[MUST] Anti-over-analysis limits** — ❌ Not built. `analysis_requests` column exists; never incremented or checked.
5. **[MUST] Consent controls** — ⚠️ Partial. Vault flag enforced at entry creation and job run time. No UI toggle; no share/delete UI.
6. **[OPTIONAL] Entity graph visualization** — ✅ Done (constellation SVG fallback, colored by valence, sized by frequency, with "tends to help / tends to weigh" tag groups).
7. **[OPTIONAL] Additional theme/UI polish.** — ✅ Done.

> **Items completed ahead of this sprint (pulled forward):**
> - Phase 2: Entity valence — suggestion computation, confirm/dismiss flow, dashboard surface — ✅ Done
> - Phase 3: Strategies memory — extraction, confirm/dismiss, coach injection — ✅ Done
> - Phase 4: Onboarding QCM — 5-question flow, preferences, router guard — ✅ Done
> - Phase 6: Analysis corrections — `AnalysisCorrection` model/controller/routes, weekly aggregation integration, dashboard pattern-correction note — ✅ Done
> - Calendar view (read-only past, compose today) — ✅ Done
> - DemoDataSeeder — ✅ Done

---

## Week 4 — Validation + Therapist Bridge + Polish

1. **[MUST] Validation note** — hand-label ~30 seeded entries with expected mode, run the classifier, report agreement. Priority order: zero missed crises, then rare wrong hold_backs — these are the two asymmetric failures the sujet calls out (§10 of the decision spec). — **Not started**
2. **[MUST] Guardrail regression tests** — confirm prefs/pick can never override crisis or hold_back (the ceiling rule under adversarial input). — **Not started**
3. **[MUST] Demo polish + bug fixes** — deploy to staging, walk the full user journey once end-to-end. — **Not started**
4. **[OPTIONAL] Therapist dashboard** — consent-gated brief generator + clinical-worded view + graph reuse. Explicitly a secondary objective (§10). — **Not started**
5. **[OPTIONAL] Architecture / consent-privacy write-up** — formalizes what's already implemented; write it last since it should describe reality, not intentions. — `ai-behavior.md` and `dev-log.md` serve this role; formal write-up not yet drafted.
6. **[OPTIONAL] Multi-language beyond default** — Arabic/Darija/French prompt handling. Real differentiator but highest implementation risk. — **Not started** (Darija crisis keywords added to §5, but full multi-language prompt handling is not built)

---

## Cut list if behind schedule

In order of least costly to cut: multi-language support → therapist dashboard → entity graph (keep ranked-list fallback) → theming beyond one default palette → architecture write-up (can be a short retrospective instead of upfront doc). Never cut: crisis handling, the ceiling rule, consent/delete, or the validation note — these are what make the demo defensible as "designed, not improvised."
