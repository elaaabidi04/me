# Internship Project — *Sujet*

## **me** — a consent-driven journaling companion focused on how an AI responds, not how much it analyses

> Product name: **me** (lowercase — the journal is you; it is your space). This *sujet* covers the **solo self-analysis product** (the default) and an **optional, designed therapist bridge**. Where the therapist side is design/mockup rather than shipped code, this is stated explicitly.

---

## 1. Context and motivation

People who journal for their wellbeing face two problems. First, most AI journaling apps position the AI *as* a therapist — ethically fraught, clinically weak, and it closes the loop between a person and a machine. Second, everyone builds for **maximum engagement** — more prompts, more analysis, more "insight" — when the honest evidence is that *over*-analysing yourself is harmful.

**me** takes the opposite stance on both. The AI is not a therapist; it is a companion that helps you notice your own patterns and knows when to tell you to stop. Its entire design question is not *"how much can we analyse?"* but *"how should the AI respond?"* — including when the right response is to say nothing and let you rest.

Optionally — and only with explicit, per-item consent — **me** can hand a curated summary to a *real human clinician* so the therapy hour starts further ahead. But that is a door the user opens, not the default state. Most users will use **me** purely to understand themselves.

---

## 2. Problem statement

Build a journaling companion where (a) the user writes freely every day, (b) interpretation is delivered on a **weekly** cadence to prevent compulsive self-monitoring, (c) the AI's *responses* are the core deliverable — calm, correctable, non-diagnostic, and willing to hold back, (d) recurring entities (people, places, topics, focus) surface as observations the *user* labels, not verdicts the app assigns, and (e) an optional consent-gated summary can reach a therapist. All of it explicitly non-diagnostic, with a crisis-aware safety layer.

---

## 3. Core principles (read this first)

An LLM cannot diagnose, and free text is noisy evidence for something as private as mood. Five commitments follow, and every later decision flows from them:

1. **Reflections, not verdicts.** Every pattern is *offered* ("this seems to come up a lot — does that ring true?"); the user confirms, edits, or dismisses. The user is the authority on their own experience.
2. **Depth by invitation, never interrogation.** Follow-ups are doors the user can decline at no cost. "There's something here about your manager — want to sit with that, or leave it?"
3. **Guard against over-analysis.** *Self-reflection* (adaptive, insight-seeking) and *rumination/brooding* (circular, a known risk factor for depression and anxiety) look similar and feel similar. A tool that invites daily "what does this mean about me" can quietly push people from the first into the second. **me** therefore designs *against* its own overuse (see §4).
4. **The response is the product.** The project's centre of gravity is *how the AI responds* — tone, when to engage vs. hold back, reflect-don't-prescribe, never pathologise. Not the volume of features.
5. **Consent gates every boundary.** Nothing crosses from user to therapist — and no named third party's data is shared — without an explicit, per-item consent action.

---

## 4. Designing against over-analysis (the differentiator)

Every competitor maximises engagement. **me**'s distinctive stance is that it deliberately *moderates* it — which is both the ethical core and the strongest novelty claim, since "an AI that knows when to tell you to stop" is uncommon.

- **Write daily, analyse weekly.** Expressive writing every day is beneficial; the *interpretation* is gated to a weekly delivery. The delay is protective — no obsessive refreshing of "what's wrong with me." Fits Laravel's scheduler naturally (see §9).
- **Reminders and limits.** A cap on repeat deep-analysis requests; a gentle nudge on compulsive re-checking ("you've turned this over a lot today — maybe let it rest"); a visible way to pause analysis entirely.
- **The AI sometimes closes the door.** The coach that usually *offers* depth will, when it senses looping or distress-without-benefit, decline to dig: "You don't need to solve this right now." This is the direct extension of principle 2 — the app declining to open a door, for the user's sake.

---

## 5. Recurring entities & focus model

The "trigger" feature is modelled as **recurring themes & focus patterns**, with the word *trigger* reserved for what the **user** marks as one. Four entity types are extracted per entry:

| Type | Examples |
|------|----------|
| **People** | a manager, a friend, a family member |
| **Places** | the office, home, a specific city |
| **Topics / activities** | a deadline, an exam, a habit, an object |
| **Focus intensity** | how much one entity dominates recent entries |

**How it stays safe, not invasive:**
- **The user labels it, not the app.** **me** never says "David is a trigger for you." It says: *"'David' has come up in 6 entries this month, and your mood tends to be lower on those days — does that mean anything to you?"* Only the person knows if a recurrence is good stress, bad stress, or nothing.
- **Focus intensity is an observation about the journal, not the person.** When one entity dominates, **me** notes *"Sarah has been in most of what you've written this week"* — never a label like "fixated" or "obsessive." No clinical labels the app assigns on its own.
- **Third-party privacy.** A recurring named person is a real individual who never consented. Their attributions stay user-labelled and private by default, and are **never** placed in a therapist brief unless the user explicitly shares that specific item.

**Fits the LLM-only stack cleanly** — the LLM extracts entities per entry as JSON; *your code* aggregates frequency and mood-correlation across weeks:

```json
{
  "people": ["David"],
  "places": ["the office"],
  "topics": ["deadline", "presentation"],
  "focus_dominant": null,
  "mood": "anxious"
}
```

---

## 6. Two dashboards, same data, opposite presentation

Both dashboards render the **same** underlying analysis, but tuned in opposite directions. The governing principle: **directness is safe in proportion to the reader's training.**

| Dashboard | Who | Design philosophy |
|-----------|-----|-------------------|
| **User dashboard** (built) | Everyone | Gentle, anti-addiction, user-friendly. Soft language, protective pacing, weekly cadence, hold-back nudges. A blunt "your mood drops on days you mention X" would feed the over-analysis §4 guards against — so the user side softens and offers. |
| **Therapist dashboard** (designed / partial) | Optional, consented | Detailed, direct, clinically-worded. Frequencies, correlations, mood variance, entity graph, descriptive clinical language — the density a trained professional can hold and contextualise, which would harm the person it is about. |

**Graph-based analysis.** Recurring entities render as a graph: nodes (people, places, topics) sized by frequency and coloured by mood valence, edges showing co-occurrence. When the user **confirms** a trigger, that node takes the trigger styling — the graph never asserts a trigger the user did not confirm. (Vue graph library e.g. Cytoscape.js / vis-network; Laravel builds the node/edge data from confirmed entities + mood correlation.)

**The line the therapist dashboard must not cross — describe, don't diagnose.** "Negative affect strongly correlated with entity X; high self-referential density this week" is rich description and fine. "Patient presents with [disorder]" is not — the app never assigns disorders. The app describes richly; the therapist diagnoses.

**Consent + third-party privacy carry over unchanged.** The therapist graph shows *only* consent-shared items, and a named person appearing on it is sensitive third-party data that surfaces only if the user explicitly shared that specific node.

**Solo mode is the default.** At onboarding: *"I just want to understand myself"* (solo — therapist dashboard never appears, nothing is shareable) or *"I'm working with a therapist"* (bridge).

**Customisable theming.** The user dashboard's CSS is user-customisable (palettes, fonts, a calm minimal default) — not cosmetic filler: **me** should feel like *your* space, and ownership reinforces safety, which makes people write more openly.

---

## 7. The coach: response modes, and how the user sets them

The coach's first job on any entry is not to reply but to pick a **mode**. That choice is the project's core, and it runs *classify-then-respond*: the LLM first classifies the situation (given the entry + your aggregated context), then generates the reply in that mode.

**Response modes:**
- **Engage / go deeper** — a live thread; the person is open and exploring; reflection adds something.
- **Offer a door** — worth noticing, but no invitation yet: *"there's something here about X — want to sit with that, or leave it?"* The default when unsure.
- **Just witness** — venting that wants to be heard, not analysed; acknowledgement, no analysis, no door.
- **Hold back / soothe** — the person is *looping*; the coach declines to dig (*"you've turned this over a lot — maybe let it rest"*). The anti-over-analysis mode.
- **Route to crisis** — acute distress; the coach stops coaching and surfaces human help. Over-triggers on purpose.

**The signal hold-back depends on — *movement*.** Productive reflection and rumination look identical in a single entry (both revisit a theme); they differ *across* entries. Reflection returns with a **new angle** (curiosity, forward motion); rumination returns **circular** — repetitive phrasing, rising self-criticism ("why do I always…"), absolutist words (always/never), mood trending down on each revisit. The coach can only see this because the Laravel aggregation feeds it cross-entry context a single entry can't show — which is exactly where "not a wrapper" becomes concrete.

**How the mode is set — the user leads, safety overrides downward.** Two intake mechanisms:

1. **Onboarding preferences (once).** A short, skippable Q&A — itself following the offer-don't-interrogate rule, or it would contradict the product — that sets *durable* tone and depth: how direct vs. gentle, how much to nudge vs. listen, language (Arabic / Darija / French / English), solo vs. therapist mode. These shape every coach prompt.
2. **Daily intent pick (one tap, optional).** Each day: *"what do you want from me today?"* — one pick that pre-sets the coach's posture:
   - "Help me dig into something" → *engage*
   - "I just need to vent / be heard" → *witness*
   - "Keep it light, just company" → *offer a door*
   - "I need to offload and rest" → *hold back*
   - (no pick → *offer a door*, the gentle middle)

**The governing rule: the daily pick sets the engagement *ceiling*; the safety layer can only lower it, never raise it.** If someone picks "dig deep" but is clearly spiralling, the coach still holds back or routes to crisis. And no onboarding preference can switch off hold-back or crisis routing — preferences tune tone and depth, never the safety floor.

**Why this matters.** Inferring engage-vs-hold-back from text alone is the coach's hardest call; letting the user *declare* intent turns it into a lighter safety check. But the pick sets *posture*, not permission to over-use — picking "dig deep" daily is itself over-analysis, so the weekly cadence and hold-back limits still sit on top of it.

---

## 8. Competitive landscape (stated honestly)

This is **not a novel category.** The journaling → AI-summary → therapist-brief idea is already shipping (Grow Therapy's "Between-Session Reflections", Aug 2025; Theryo; Empath; Rosebud; Quenza; Upheal). The *sujet* does not claim to invent it. The defensible contributions are:

1. **The anti-over-analysis stance** (§4) — moderating engagement instead of maximising it. This is the sharpest differentiator; no listed competitor does it.
2. **Locale / language** — none serve Tunisian users or Arabic / Darija journaling. A real gap aligned with the author's background.
3. **The response-quality thesis** — treating *how the AI responds* as the whole project rather than a feature.

---

## 9. Technology stack

| Layer | Technology |
|-------|-----------|
| Backend | **Laravel** (PHP) — auth, storage, consent logic, LLM orchestration |
| Scheduling / async | **Laravel scheduler + queues** — daily journaling lands immediately; the weekly analysis job runs on schedule; LLM calls run async on the queue |
| Frontend | **Vue.js** — both dashboards, the entity graph (Cytoscape.js / vis-network), the theming system |
| Storage | MySQL / PostgreSQL |
| Per-entry extraction | **LLM API** (no custom model) — mood, sentiment, entities as strict JSON via fixed prompt + schema, validated in code |
| Longitudinal analysis | **Your own code** in Laravel — aggregation, trend math, entity frequency + mood correlation across entries |
| Reflective coach | Same **LLM API**, separate prompt, tuned for tone + when-to-hold-back |

> **Why Laravel fits:** the "write daily, analyse weekly, call the LLM async" cadence maps directly onto Laravel's task scheduler and queue system — a real architectural reason, not just a preference.

> **Note on the LLM-only choice:** no custom model is trained. What makes **me** *not* a thin wrapper is the software around the API: (1) strict structured extraction with schema validation, (2) the longitudinal aggregation layer — the LLM sees one entry; the pattern-across-weeks intelligence is your code, (3) the response-design + safety orchestration (tone, hold-back, crisis routing), which is the project's stated focus. Judge it by those three.

---

## 10. Objectives

**Primary (must ship):**
1. Daily journaling loop with storage.
2. **Weekly** analysis delivery (not daily) — the anti-over-analysis cadence, with reminders and a repeat-analysis limit.
3. Per-entry LLM extraction (mood, sentiment, four entity types) as schema-validated JSON; longitudinal aggregation in your own code.
4. The reflective coach as the centrepiece: five response modes (engage / offer-a-door / witness / hold-back / crisis) chosen classify-then-respond; correctable offerings, non-diagnostic, never pathologising.
5. Intake that sets the coach's posture: a short skippable onboarding preferences Q&A (durable) + a one-tap daily intent pick (today's posture), with the ceiling rule (pick sets the max engagement; safety only lowers it).
6. Recurring-entity observations with the user-labels-it rule and third-party privacy, rendered as a **graph** (confirmed triggers styled as such).
7. Two dashboards from the same data: user (gentle / anti-addiction) and therapist (detailed / clinically-worded but non-diagnostic).
8. Consent controls: solo-mode default, vault for unanalysed entries, per-item sharing, delete.
9. Crisis-aware safety layer that routes distress to human help, never counsels.
10. Customisable theming.

**Secondary (design / mockup / partial):**
11. Therapist dashboard: consent-gated brief + entity graph + therapist-view mockup + the prompt-shaping mechanism.

---

## 11. Deliverables

1. **me** — the user web app (Laravel + Vue): daily journaling, weekly analysis, coach, entity observations, consent, theming.
2. The extraction + longitudinal analysis pipeline with schema-validated JSON.
3. The reflective-coach design: prompt set, response schema, hold-back + crisis guardrails, worked examples.
4. Intake: onboarding preferences Q&A + daily intent pick, wired to coach posture with the ceiling rule.
5. Consent system (solo default, vault, per-item share, delete) + the anti-over-analysis limits.
6. Therapist interface: brief generator + mockup + prompt-shaping.
7. A short validation note: how response quality and extraction accuracy were checked; declared success criteria.
8. This *sujet* + a brief architecture / consent-and-privacy document.

---

## 12. Four-week timeline

| Week | Focus |
|------|-------|
| **1** | Laravel + Vue scaffold; auth; onboarding preferences Q&A; daily journaling loop + storage; data schema + consent model; seed synthetic entries so weekly/longitudinal features can be demoed. |
| **2** | LLM extraction → schema-validated JSON (mood, sentiment, four entity types); the coach v1 — classify-then-respond across the five modes; daily intent pick + ceiling rule; theming system. |
| **3** | Weekly analysis job on Laravel scheduler; longitudinal aggregation + entity **graph** on the user dashboard; anti-over-analysis limits + reminders; consent controls + vault + delete; crisis layer. *(If tight: graph falls back to a ranked list — never cut the coach.)* |
| **4** | Therapist interface (brief generator + mockup + prompt-shaping); coach hold-back behaviour + guardrail testing; validation note; polish + demo. |

---

## 13. Declared success criteria

**Success** if, by month's end:
- A user journals daily and receives a coherent weekly analysis (mood trajectory + recurring entities).
- The coach demonstrably *holds back* in looping/distress cases, not just engages.
- Every surfaced pattern is correctable and corrections persist.
- Solo mode is fully self-contained; nothing is shareable without an explicit per-item action.
- The crisis layer reliably routes clear distress to human resources in testing.

**Partial success** if the user app + coach + weekly analysis ship but the therapist interface remains a mockup.

---

## 14. Risks and ethical considerations

- **The over-analysis risk is the product's own failure mode** — designed against in §4, but must be tested, not just asserted.
- **Sensitive-data product, with an honest privacy limit.** All analysis runs through a third-party LLM API, so **entry text leaves the device** — **me** does *not* claim on-device privacy. It is *consent-first*: encryption in transit and at rest, a provider agreement of no-retention / no-training on entry data, identifier-stripping where feasible, user-controlled deletion. In Tunisia this data falls under Law 2004-63 and the INPDP; full clinical deployment raises medical-device and data-protection questions out of scope for v1 but acknowledged.
- **Crisis handling is non-optional and conservative.** The safety layer errs toward surfacing human help; it never attempts to counsel. A false negative is far worse than a false positive.
- **The therapist dashboard must describe, not diagnose.** Its blunt, clinically-worded framing is descriptive only; the app never assigns disorders. Directness is calibrated to the reader's training, but the non-diagnostic line holds on both dashboards.
- **Non-diagnostic line.** **me** supports self-reflection and therapy; it does not diagnose or replace a clinician, and says so plainly.

---

## 15. Future scope (out of build for v1)

- Live, multi-user therapist portal with real onboarding.
- Validated study of whether the weekly brief improves session quality.
- On-device / local LLM to remove third-party exposure of entry text.
- Deeper analysis: entity co-occurrence networks; adaptive reminder tuning.
