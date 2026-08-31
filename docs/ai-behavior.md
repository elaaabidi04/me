# AI Behavior — Moved (2026-08-14)

This document was split into two files, along the seam between LLM prompt content and application logic:

- **`docs/ai-prompts.md`** — identity/framing, mode definitions (what each mode is allowed/forbidden to say), tone rules, security preamble and injection defenses, crisis prompt content, the extraction prompt schema, and the two-stage memory pipeline's prompt content (Stage 1 candidate extraction, Stage 2 evaluator).
- **`docs/dashboard-logic.md`** — mode-resolution control flow, extraction validation/persistence mechanics, weekly aggregation, pattern-chain computation and drill-through, the daily journal reset, the analysis-corrections system, the memory pipeline's scoring/confirmation/retrieval mechanics and UI, and the full known-limitations table.

Kept as a stub here (not deleted) because PHP and Vue code comments across both the `api` and `spa` repos still reference `docs/ai-behavior.md` by path and section number from before the split — those pointers are now approximate rather than exact (section numbers shifted), but this stub at least keeps the path itself from dead-ending. Follow up to either of the two files above for anything specific.
