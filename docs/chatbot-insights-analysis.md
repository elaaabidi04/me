# Therapist chatbot: how the AI analyses a client's shared dashboard

> **Audience:** supervisor / validator reading this as a validation artifact, or a developer extending the feature.
> **Scope:** the `POST /api/shared-insights/chat/{clientId}` endpoint, the context-building logic in `SharedInsightController::chat()`, and how the frontend (`SharedInsights.vue`) surfaces the chatbot to a therapist. Self-contained; cross-references `therapist-bridge.md` §8 (share-to-therapist) and `ai-prompts.md` (NvidiaClient, model config) rather than duplicating them.
> **Last updated:** 2026-08-26.

---

## 1. Why this exists

A therapist seeing a client's shared panels was originally a read-only view of whatever the client chose to freeze and share — a frozen copy of the same visual panels the client saw on their own dashboard. After §16–§22 of `therapist-bridge.md` added comparison context, trajectory diffing, flag markers, and an emotion day/trigger/time diagram, there was rich structured data on screen, but no way to query it conversationally. A therapist who wanted to know "which triggers showed up most this week?" or "has anxiety been going up across the last few shares?" had to scan all the rows themselves.

The chatbot adds a single open-ended question input at the bottom of `/therapist/insights?client_id=…` so a therapist can ask any clinical question about the client's shared data and get a short, direct, model-generated answer drawn strictly from what's on screen — without needing to re-read every row.

---

## 2. Endpoint

```
POST /api/shared-insights/chat/{clientId}
```

- **Auth:** `auth:sanctum` (same as every other authenticated route).
- **Role check:** therapist-only (`403` if `user->role !== 'therapist'`).
- **Link check:** `TherapistClient::where(therapist_id, clientId)->exists()` — the therapist must currently be linked to this client (`403` if not). Same inline check `forClient()` and `updateTherapistNote()` already use; no Policy layer, consistent with the rest of this app.
- **Input:** `{ question: string, max:2000 }` — a single free-text question.
- **Output:** `{ reply: string }` — a single model-generated answer, or a fallback string if no shares exist or the model call fails.

Listed before `GET /api/shared-insights/client/{clientId}` in `routes/api.php` — two-segment `/chat/{clientId}` path before the two-segment `/client/{clientId}` path, same ordering discipline (`/client/{clientId}` is listed before `/{share}` for the same reason), preventing the router from attempting to match `chat` as the `{clientId}` integer wildcard.

---

## 3. Context building

The chatbot's input is every active (non-revoked) `InsightSnapshot` the client has ever shared with this therapist, formatted as a plain-text bullet list:

```
• how the week felt (week of Aug 18–24): joy: very often, calm: often, sadness: sometimes, ...
• feelings that showed up (week of Aug 18–24): sadness: 38%, joy: 22%, anxiety: 18%, ...
• behaviors (week of Aug 18–24): avoidance: several times (34%), exercise: twice (12%), ...
• pattern: deadline (all-time, 42 entries): emotion: anxiety, thinking pattern: catastrophising, behavior: procrastination
```

Each line is built by `$snap->title`, `$snap->period_label`, and the `rows` array (formatted as `label: detail` pairs joined by `, `). The `rows` array is the same frozen snapshot stored at share time — it already includes:

- `label` — the human-readable name of the item (emotion, behavior, trigger, etc.)
- `detail` — the display string shown on the client's dashboard (e.g. `"38%"`, `"very often"`, `"several times (34%)"`)
- `context` — the baseline comparison sentence added by `attachComparisonContext()` at share time (e.g. `"up from ~8% typically over the past 6 weeks."`) — **not currently included in the context lines sent to the model** (only `label: detail` is sent; see §6 for what this means)
- `trajectory` — the previous-share diff from `attachTrajectoryContext()` — also not in the model context lines currently
- `flag` — `'up'`, `'down'`, or `'new'` classification — also not in the model context lines

The reason `context`, `trajectory`, and `flag` aren't in the model's input is that the current implementation formats rows as a simple `{label}: {detail}` join. A future iteration that included these fields would let the model answer "which feelings have risen since this client's baseline?" without re-deriving it from the raw numbers — see §7.

If there are no active shares at all, the method returns early with a static `'No shared insights available yet for this client.'` string and never calls the model.

---

## 4. What data is available per panel_key

A `panel_key` string identifies which dashboard panel a snapshot came from. The chatbot receives all of them as interleaved bullets; it doesn't branch on `panel_key` itself. The table below documents what `rows` looks like for each panel_key, so a developer extending the context builder knows what's there.

| `panel_key` | Source in Dashboard.vue | Row shape (label → detail) |
|---|---|---|
| `mood` | Mood ring (weekly sentiment) | Sentiment bucket → frequency word (e.g. `"positive: very often"`) |
| `emotion_breakdown` | Feelings that showed up | Emotion → presence rate (e.g. `"sadness: 38%"`) |
| `stressor_breakdown` | What's been weighing on you | Stressor category → frequency count + share (e.g. `"work: several times (34%)"`) |
| `protective_factor_breakdown` | What's been helping | Protective factor → same count/share format |
| `behavior_breakdown` | Behaviors (pie chart) | Behavior → count/share format |
| `need_breakdown` | Unmet needs | Need → count/share format |
| `thinking_pattern_breakdown` | Thinking patterns | Pattern → count/share format |
| `pattern_<trigger>` | All-time chain for one trigger (Dashboard.vue `chainRows`) | `emotion`, `thinking pattern`, `behavior` → the linked label or `"no clear link yet"` |

The `panel_key` for pattern-chain shares is `pattern_` followed by the raw trigger string (e.g. `pattern_deadline`, `pattern_social_pressure`). No other panel uses a dynamic suffix.

Monthly-scope shares (`scope = 'month'`) from `MonthlyDashboard.vue` carry the same `panel_key`s as weekly ones but their `period_label` says the month name and their `rows` hold trend summaries from `MonthlyDashboardController` rather than weekly-window aggregates.

---

## 5. Model call

```php
$ai->chat([
    [
        'role'    => 'system',
        'content' => 'You are a concise clinical assistant helping a therapist understand
                      their client\'s emotional and behavioral patterns from shared journal
                      insights. Answer only from the data provided. Be clear, brief, and
                      clinically useful — no disclaimers or padding.',
    ],
    [
        'role'    => 'user',
        'content' => "Client's shared insights:\n{$contextLines}\n\nQuestion: {$validated['question']}",
    ],
], ['temperature' => 0.4, 'max_tokens' => 500]);
```

**Temperature 0.4** — lower than the coach's conversational default (0.85, see `ai-prompts.md`), higher than the extraction/evaluator pass (0.3, deterministic JSON). The chatbot needs to stay close to what the data actually says (not invent patterns not in the rows), but still produce readable clinical prose rather than mechanical enumeration.

**Max tokens 500** — matches the context window a short, direct clinical answer needs. The chat endpoint's `$contextLines` can be lengthy when a client has many shares; the model's reply is intentionally kept short. A therapist asking "what's the top trigger?" should get a two-sentence answer, not a paragraph.

**`enable_thinking: false`** — inherited from `NvidiaClient`'s defaults. The model in use (Nemotron reasoning model) defaults to embedding its chain-of-thought trace in `choices[0].message.content` when thinking is enabled, which would consume the whole token budget with internal planning text rather than the actual answer. See `NvidiaClient.php`'s comment block for the discovery story.

**`NvidiaClient::chat()`** returns `null` on any transport failure or non-200 response; the controller maps that to `'Could not get a response — please try again.'` so the frontend always gets a string.

---

## 6. What the model can and cannot answer

**Can answer** (all data is present in `$contextLines`):

- "Which emotion appeared most often this week?" — `emotion_breakdown` rows carry presence-rate percentages.
- "What behaviors does this client repeat?" — `behavior_breakdown` rows.
- "What trigger is linked to this client's anxiety pattern?" — `pattern_<trigger>` rows carry the chain (emotion → thinking pattern → behavior).
- "What's been helping this client?" — `protective_factor_breakdown` rows.
- "Has this client shared anything from multiple weeks?" — multiple bullets from different `period_label`s are in the context; the model can compare across them.

**Cannot answer** (data is available in the snapshot but not sent to the model in current context formatting):

- "Has sadness risen compared to this client's typical baseline?" — `row.context` contains this string (e.g. `"up from ~8% typically"`) but it's not in the joined context lines. The model would have to infer a trend from the raw percentages across multiple period bullets, which it can do approximately but not precisely.
- "Was this different from the previous time this was shared?" — `row.trajectory` is also not in the context lines.
- "Is this a flagged spike?" — `row.flag` is not in the context lines.

**Cannot answer** (data simply doesn't exist at this endpoint):

- "What did this client write in their journal?" — raw entry text never crosses to the therapist; the chatbot only sees the same frozen aggregates the therapist already sees on screen.
- "What happened after this client's crisis flag?" — crisis thread messages aren't loaded here; that's a separate endpoint.
- Questions about clients other than the one named in `{clientId}` — the context builder is scoped to one client.

---

## 7. How to extend the context

The context-building step is the single most impactful thing to change if the model's answers feel shallow. The current join (`{label}: {detail}`) is the minimum viable representation; each `row` already carries richer fields:

**Include `context` (baseline comparison):**
```php
$parts = ["{$r['label']}: {$r['detail']}"];
if (!empty($r['context'])) $parts[] = "({$r['context']})";
return implode(' ', $parts);
```
This would let the model answer "which feelings have risen?" precisely rather than inferring a trend from raw numbers across bullets.

**Include `trajectory` (since-last-share diff):**
```php
if (!empty($r['trajectory'])) $parts[] = "[{$r['trajectory']}]";
```

**Include `daily_pattern` (day/trigger/time diagram):**
`InsightSnapshot::daily_pattern` is a structured JSON array (produced by `EmotionPatternService::weekly()` for `emotion_breakdown` shares) carrying every emotion occurrence with its day, time-of-day, trigger, and intensity. Summarizing this — e.g. "Mon: sadness × 3 (trigger: self_doubt), Wed: amusement × 2 (no trigger)" — would let the model answer questions like "when does this client most often feel anxious?" directly from data, without a therapist having to read the SVG diagram.

Any of these additions is a pure PHP change in `chat()`'s context-building block; no migration, no frontend change, no model change required.

---

## 8. Frontend integration

`SharedInsights.vue` renders the chatbot panel at the bottom of the therapist view, below the share cards, only when all three conditions hold: `role === 'therapist'` and `clientId` is set (i.e. the URL has `?client_id=`) and `activeItems.length > 0` (there are actual shares to ask about). A therapist without a selected client, or with a client who hasn't shared anything yet, never sees the panel — there's nothing useful to ask about.

```vue
<div v-if="role === 'therapist' && clientId && activeItems.length" class="chat-panel">
  <div class="chat-messages">
    <div v-for="(m, i) in chatMessages" :key="i" class="chat-msg" :class="m.role">
      {{ m.text }}
    </div>
  </div>
  <div class="chat-input-row">
    <input v-model="chatInput" @keydown.enter.prevent="sendChat"
           placeholder="ask about this client's shared data…" :disabled="chatBusy" />
    <button @click="sendChat" :disabled="chatBusy || !chatInput.trim()">ask</button>
  </div>
  <p v-if="chatError" class="chat-error">{{ chatError }}</p>
</div>
```

`sendChat()` POSTs `{ question: chatInput }` to `/api/shared-insights/chat/{clientId}`, appends both the user's question and the model's reply to `chatMessages` (role `'user'` / role `'assistant'`), and clears the input. The message history is client-side only — it resets on page navigation and is never persisted anywhere. Each question is a fresh, independent API call; the model has no memory of prior turns in the same session.

**Chat history is not sent to the model.** Each `sendChat()` call constructs exactly the two-message `[system, user]` payload described in §5 — the previous `chatMessages` are displayed in the UI for the therapist's own reference, but are not appended to the model's message array. This keeps the context short and the cost low; a therapist asking 5 questions in a session doesn't send an ever-growing conversation. The tradeoff is that follow-up questions ("tell me more about that") don't work — each question must be self-contained. That's an acceptable v1 limitation given how the use case is expected to work in practice (a therapist consulting it for specific lookups before or during a session, not carrying on an extended dialogue).

---

## 9. Limitations and known gaps (v1)

- **No conversation memory.** Each question is independent; see §8.
- **Context is text-only.** The `daily_pattern` JSON (emotion day/trigger/time diagram data) is not included in the context lines sent to the model — a therapist can ask about it verbally but the model will be answering from the flat row labels/details, not from the richer per-day, per-trigger breakdown. See §7 for how to add it.
- **`context`/`trajectory`/`flag` not in model input.** The baseline comparison and trajectory sentences are stored in each `row` but not currently sent to the model. See §7.
- **No streaming.** The reply is returned in one JSON object when the model finishes. For a 500-token answer at standard latency this is typically 2–5 seconds; no progressive-display / streaming is built.
- **No rate limit on this endpoint.** The `POST /entries` route is throttled at 20/min (each entry triggers two LLM calls). `/chat/{clientId}` has no equivalent throttle yet — a therapist can fire many rapid questions. Each call is one model inference; this is worth throttling (e.g. `middleware('throttle:30,1')` in `api.php`) before any production exposure beyond demo.
- **Only active shares are in context.** A revoked share (`revoked_at` set) is excluded from the query — the model can only answer about data the client has explicitly chosen to keep shared. If a client revokes a panel mid-session, the model's next answer won't reference it.
- **Single client per call.** The chatbot is scoped to one `{clientId}` per request. There's no "across my whole caseload" query mode.
