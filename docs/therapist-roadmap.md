# Therapist bridge — what comes next

> Companion to `therapist-bridge.md` (the built feature). This is the not-yet-built list — sketched enough to pick up later, not designed in full. Ordered roughly by how self-contained/low-risk each item is, not by importance.
> **Last updated:** 2026-08-19.

## Live trend view (therapist side) — RESOLVED 2026-08-19

Built as `GET /api/monthly/trends/client/{clientId}` + `ClientTrends.vue`, gated by a client-only `live_trends_enabled` opt-in on the `therapist_client` pivot (not a `Share` row — this is continuous consent, a materially different shape than the one-off snapshot shares everything else in §8 uses). See `therapist-bridge.md` §14 for the full writeup.

## Pre-session briefs — RESOLVED 2026-08-19

Built as `SessionBrief` + `SessionBriefController` + `SessionBriefs.vue`, reusing the polymorphic `Share` gate `InsightSnapshot` uses. Client-authored free text, optionally anchored to their next upcoming session — the system doesn't draft or summarize anything, matching the "client stays the author" decision made when this was scoped. See `therapist-bridge.md` §13.

## Session log — RESOLVED 2026-08-19

`TherapySession` gained `attendance` (attended/no_show/cancelled) and `outcome_note`, set via `PATCH /api/sessions/{session}/log`. See `therapist-bridge.md` §12. Still not built, deliberately out of scope: no link from a session to the shared insights/tasks that were relevant to it at the time — the "worth a look before you meet" nudge still links to the CURRENT state of shared insights/tasks, not a frozen view of what existed when that particular session happened. Not revisited this round.

## Standardized assessments

Raised in the RAG discussion (2026-08-18): if severity/functioning measures are ever wanted, use real validated instruments with their actual scoring rules (PHQ-9, GAD-7, etc.), administered and scored properly — never an LLM inferring a severity score from journal prose. This is explicitly *not* the RAG case-formulation feature below; it's a separate, much more clinical feature (real instruments, real scoring, real psychometric stakes) that hasn't been scoped at all. Flagging it here mainly so it isn't accidentally conflated with the RAG assistant.

## RAG-assisted case formulation reference (therapist side)

From the 2026-08-18 chat discussion, responding to a detailed proposal (4 knowledge collections: case formulation, CBT, assessment, documentation practice, sourced from named textbooks by Eells/Beck/Kuyken/Persons). Where it landed:

- **Corpus can't be the actual textbooks.** Those are commercially copyrighted; ingesting their text into a retrievable store isn't something to build. The workable version is a small set of short, *original* reference notes (a few dozen, not hundreds of pages) written in this app's own words, capturing the frameworks those books teach — problem→factors→hypothesis→treatment for case formulation, situation→automatic-thought→emotion→behavior→consequence for CBT — cited by source without quoting it.
- **No new infrastructure needed.** `NvidiaClient::embed()` + `CanonMemoryService`'s cosine-similarity retrieval (built for canon-event memory, see `memory.md`) is the same mechanism this needs: embed the reference notes once, embed the query at retrieval time, rank by similarity, inject top matches into a prompt. No vector DB to add.
- **Retrieval should be therapist-initiated, not automatic.** A therapist asks a question, gets cited reference material back — not the system auto-generating a case formulation on page load. The first shape is the therapist pulling a tool; the second is the system doing clinical reasoning unprompted, which is a much bigger step past this app's existing "describe, don't diagnose" line (`therapist-bridge.md` §1, echoing the original spec).
- **DSM/ICD should not be the primary corpus.** Journal-extracted signal (self-critical cognition, avoidance, rumination, etc.) doesn't imply a diagnosis; that gap is clinical judgment, not something to shortcut with a diagnostic-manual RAG.
- **Not started.** No collections written, no ingestion pipeline, no UI. This section exists so the next round doesn't have to re-derive the copyright constraint or re-discover the existing embed/cosine code from scratch.

## Collaborative strategies

`Strategy` already exists as a client-only concept (self-authored coping strategies, surfaced in `WeeklyReflectionController`). A therapist-assigned or therapist-co-authored version is unbuilt — closest existing precedent is `AssignedTask` (`therapist-bridge.md` §9), which a "collaborative strategy" might extend (a task the client can annotate/reflect on, not just check off) rather than a wholly new mechanism.

## General messaging

Today the only channel between a linked pair is the crisis thread (`therapist-bridge.md` §4), deliberately anchored to crisis moments. A general, non-crisis message thread is a straightforward extension of the same `CrisisMessage`/`therapist_client_id` shape, but worth deciding on purpose: does crisis messaging staying visually/behaviorally distinct from routine chatter matter (so a real flag doesn't get lost in small talk), or does one merged thread outweigh that? Not decided, not built.

## Smaller follow-ups already noted as out-of-scope when their features shipped

- Tasks (§9 of `therapist-bridge.md`): no recurring tasks, no required written reflection to close one out, no therapist edit-in-place, no due-date reminders (no notification system exists anywhere in this app).
- Share-to-therapist (§8): no granular field-level redaction inside a panel, no re-share/edit of an existing snapshot, no live/continuous sharing (every share is a frozen moment).
- Dashboard follow-up round (§15): roster grid has no real pagination, just client-side search — would need revisiting for a large caseload. The recent-activity feed has no "seen/unseen" state, it's always just "the last 8 events," not a per-therapist read/unread inbox.
