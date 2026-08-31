# **me** — Coach Decision Spec

> Companion to the *sujet*. Defines exactly how the coach picks a response mode and generates a reply. This is the "the response is the product" section made concrete — the piece to show a supervisor.

---

## 1. The one-line model

```
for each entry:
    context   = aggregate(user, entry)      # your Laravel code, cross-entry
    mode      = classify(entry, context, daily_pick, prefs)   # LLM call #1
    reply     = respond(mode, entry, context, prefs)          # LLM call #2
```

Two LLM calls, not one. The first **classifies** the situation; the second **writes** in the chosen mode. Splitting them is what makes behaviour inspectable and testable — you can log the mode and check it was right, independently of the wording.

---

## 2. The five modes

| Mode | When | What the reply does |
|------|------|---------------------|
| **engage** | Live thread, person is open and exploring | Reflects, asks one deepening question, mirrors a pattern back |
| **offer_door** | Something notable, but no invitation yet | Names the thing once, offers a choice, stops. *The safe default.* |
| **witness** | Venting that wants to be heard | Acknowledges the feeling. No analysis, no question, no door. |
| **hold_back** | The person is looping / analysing without benefit | Gently declines to dig. Suggests rest. |
| **crisis** | Signs of acute distress | Stops coaching. Surfaces human help. Over-triggers on purpose. |

---

## 3. What the classifier sees

The classifier is only as good as the context your own code feeds it. A single entry cannot tell reflection from rumination — the difference lives *across* entries.

```
entry            the raw text just written
daily_pick       engage | witness | light | rest | none   (user-declared posture)
prefs            tone (gentle|direct), nudge_level, language, mode (solo|bridge)
context:         # built by Laravel aggregation, NOT the LLM
  theme_streak       how many days running this theme has appeared
  mood_on_theme      mood trend on entries mentioning it (rising | flat | falling)
  self_ref_density   share of self-referential / self-critical phrasing this week
  analysis_requests  how many times deep-analysis was requested today
  notable_entity     the entity most implicated this entry, or null
```

`theme_streak`, `mood_on_theme`, and `analysis_requests` are the rumination signals. None of them are visible from one entry — they are exactly the "not a wrapper" layer.

---

## 4. Signals per mode

**crisis** — checked first, highest precedence.
- Explicit or implicit acute distress: hopelessness with intent, self-harm ideation, "I can't do this anymore" with finality.
- **Over-trigger:** if it *might* be crisis, treat it as crisis. A false alarm costs a gentle over-reach; a miss costs far more.

**hold_back** — checked second.
- `theme_streak` high **and** `mood_on_theme` falling → returning to the same wound with no movement.
- `self_ref_density` high with rising self-criticism ("why do I always…", absolutist always/never).
- `analysis_requests` over the daily limit → compulsive checking.
- Fires **even if** `daily_pick = engage` (see ceiling rule).

**engage** — only if not crisis / not hold_back.
- `daily_pick = engage`, **and** the entry shows openness: a question, "I've been wondering", a *new* angle on a known theme (movement, not repetition).

**witness**
- `daily_pick = witness`, or an emotional-venting entry with no question and no request to understand.

**offer_door** — the default.
- Something notable is present but there is no clear invitation, or `daily_pick = none`. When the classifier is unsure between engage and something gentler, it lands here.

---

## 5. Precedence — the ceiling rule

```
crisis  >  hold_back  >  (pick-driven: engage | witness | offer_door)  >  offer_door
```

- The **daily pick sets the ceiling**: it can raise the coach *up to* engagement, never past safety.
- `crisis` and `hold_back` **override the pick downward**. Picking "dig deep" while spiralling still yields hold_back.
- **Prefs never change the mode** — only the *wording*. A "just validate me, never worry about me" preference cannot disable hold_back or crisis. Preferences tune tone; they do not touch the safety floor.

---

## 6. Classifier output (LLM call #1)

Strict JSON, schema-validated in Laravel. If it fails validation → default to `offer_door` (the safe mode), never to engage.

```json
{
  "mode": "engage | offer_door | witness | hold_back | crisis",
  "confidence": 0.0,
  "distress_flag": false,
  "rumination_score": 0.0,
  "openness": 0.0,
  "notable_entity": null,
  "reason": "one short line — for logging, never shown to the user"
}
```

Validation rules:
- `distress_flag = true` → `mode` is forced to `crisis` regardless of what the model wrote.
- `rumination_score` above threshold → `mode` is forced to at most `offer_door`, and to `hold_back` if the streak/mood signals agree.
- Low `confidence` → fall back to `offer_door`.

---

## 7. Response generation (LLM call #2)

One prompt per mode, sharing a common preamble (tone from prefs, language, non-diagnostic + never-pathologise rules). Mode-specific guidance:

- **engage** — reflect one pattern; ask **one** question; never stack questions; never prescribe.
- **offer_door** — name the thing once, offer a real choice, then stop. Two buttons: *sit with it* / *leave it*.
- **witness** — acknowledge the feeling in one or two sentences. No "have you considered", no question, no door.
- **hold_back** — validate that they have done enough for today; suggest rest; offer to set it down. No new analysis.
- **crisis** — see §8.

Global response guardrails: no diagnosis, no disorder labels, no method details, one question maximum, plain language, matches the user's language and chosen tone.

---

## 8. Crisis handling (detail)

When `mode = crisis`, the coach stops being a coach.

- **Does:** acknowledge the pain plainly; say they deserve support from a person; surface locally-appropriate crisis resources; stay brief and warm.
- **Never:** analyse, offer coping "techniques", ask assessment questions, name any method, or minimise.
- **Resources are not hardcoded from memory.** Crisis numbers change and vary by country — the app pulls current, verified resources (for Tunisian users, a confirmed national line; an international fallback otherwise). Verify before launch; do not ship a guessed number.
- Crisis state is never overridden by pick or prefs, and is logged for the safety review, not surfaced as analysis.

---

## 9. Worked examples

Each shows: daily pick + context → classifier output → coach reply.

### 9.1 engage
- **pick:** dig in · **context:** theme "career change" streak 2, mood rising, new angle in entry
- **entry:** *"I keep coming back to leaving my job — but today it felt less like fear and more like actual curiosity about what else I could do."*
```json
{ "mode": "engage", "confidence": 0.82, "distress_flag": false,
  "rumination_score": 0.2, "openness": 0.85, "notable_entity": "job",
  "reason": "known theme returning with a new, forward-looking angle" }
```
- **reply:** *"That shift from fear to curiosity is worth noticing. When you imagine 'what else' — is there a first small thing that pulls at you?"*

### 9.2 offer_door
- **pick:** none · **context:** entity "David" streak 4, mood flat
- **entry:** *"Long day. David again in the standup, then a headache all afternoon."*
```json
{ "mode": "offer_door", "confidence": 0.7, "distress_flag": false,
  "rumination_score": 0.35, "openness": 0.4, "notable_entity": "David",
  "reason": "recurring entity, no invitation to go deeper" }
```
- **reply:** *"David has come up a few times this week. Want to sit with that, or leave it for now?"*  ·  **[ sit with it ] [ leave it ]**

### 9.3 witness
- **pick:** just listen · **context:** one-off, high emotion
- **entry:** *"My grandmother would have turned 80 today. I just miss her."*
```json
{ "mode": "witness", "confidence": 0.9, "distress_flag": false,
  "rumination_score": 0.1, "openness": 0.3, "notable_entity": null,
  "reason": "grief being expressed, wants to be heard not analysed" }
```
- **reply:** *"Eighty. That's a day that would have meant something. It makes sense that you miss her today."*

### 9.4 hold_back  *(pick says engage — safety overrides down)*
- **pick:** dig in · **context:** theme "the breakup" streak 6, mood falling, self_ref high, 3rd analysis request today
- **entry:** *"Analysing this again. Why do I always ruin everything. What is wrong with me."*
```json
{ "mode": "hold_back", "confidence": 0.88, "distress_flag": false,
  "rumination_score": 0.86, "openness": 0.2, "notable_entity": "the breakup",
  "reason": "6-day falling streak, rising self-criticism, over daily analysis limit" }
```
- **reply:** *"You've turned this over a lot today, and it's getting heavier, not lighter. You don't have to solve it tonight. Maybe let it rest — it'll still be here to look at when you're steadier."*

> Note: the pick was *dig in*. The ceiling rule pulled it down to hold_back because the signals said digging would hurt.

### 9.5 crisis
- **any pick · any prefs** — distress overrides everything.
- **entry:** *(clear acute distress)*
```json
{ "mode": "crisis", "confidence": 0.95, "distress_flag": true,
  "rumination_score": 0.0, "openness": 0.0, "notable_entity": null,
  "reason": "acute distress signal" }
```
- **reply:** brief, warm acknowledgement + "you deserve support from a person right now" + current verified crisis resources. No analysis, no technique, no question.

---

## 10. How to validate it (for the sujet's validation note)

- Hand-label ~30 seeded entries with the mode you'd expect. Run the classifier. Report agreement — especially that it **never** misses a crisis and **rarely** engages when it should hold back (the two asymmetric failures that matter).
- Log every `mode` + `reason`. The demo isn't "the AI said something nice" — it's "here are five entries, here is the mode it chose and why, here is where the ceiling rule pulled engagement down to hold_back."

That log *is* the proof the response is designed, not improvised.
