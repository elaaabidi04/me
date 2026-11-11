# me

**A journaling companion that knows when to stay quiet.**

You write about your day. **me** replies like a thoughtful friend, not a therapist and not a chatbot. It then quietly turns months of entries into patterns you can actually use. If you work with a therapist, you can choose, item by item, what they get to see.

**Live demo:** [me-demo.onrender.com](https://me-demo.onrender.com). Click **test user** or **test therapist**: no sign-up, months of fictional data, your own private copy. The free server sleeps when idle, so the first visit can take about a minute to wake up.

---

## Why it's different

Most AI journals analyse everything you write. **me** is designed around *how the AI responds*, and it designs against over-analysis, because daily self-reflection can slide into rumination. Five principles drive every decision (full reasoning in [`docs/sujet.md`](docs/sujet.md)):

1. **Reflections, not verdicts.** Patterns are offered ("does that ring true?"), never declared. You confirm, edit or dismiss them.
2. **Depth by invitation.** Follow-ups are doors you can decline at no cost.
3. **Guard against over-analysis.** The coach can decide *not* to dig, and suggest rest instead.
4. **The response is the product.** Tone and timing matter more than feature count.
5. **Consent gates every boundary.** Nothing reaches a therapist without an explicit, per-item yes.

---

## What it does

### For the person journaling
- **Journal + coach.** Every entry gets a reply in one of five modes, chosen by the AI with a safety ceiling: *engage*, *offer a door*, *witness*, *hold back*, *crisis*. You choose the day's mood: **dig in** (curious, best-friend energy) or **just listen**.
- **Crisis safety.** Signs of acute distress stop the coaching and surface human help. It deliberately over-triggers rather than miss something.
- **Memory.** The app notices things worth remembering across days ("should I remember this?"). It only keeps them if you confirm.
- **Dashboard.** Today's gentle note, your recurring threads (people, places, topics), what has helped before, the week's feelings and *trigger → feeling → what followed* pattern chains.
- **Monthly trends, calendar, daily breakdowns** of each day's entries.

### For the therapist
- **Code-invite linking.** The client shares a code, so no email lookup is needed.
- **Crisis chat.** One persistent thread per client, with flags surfaced inline.
- **Shared insights.** Only the dashboard panels the client chose to share, plus an AI assistant that answers questions about that shared data only.
- **Tasks, scheduled sessions, session notes, pre-session briefs** and live client trends.

### For the public demo
- **One click, private copy.** Every visitor gets their own sandbox of fictional data, deleted after 24h. Copies are pre-built so login is instant.
- **Limits.** Visitors get 3 journal entries each, plus per-visitor AI rate limits.
- **Prompt-injection blocking**, with an email alert to the admin.
- **Admin page.** What visitors wrote, how often each test button was clicked, and unlimited test sessions for the owner.

---

## Tech stack

| Layer | Tech |
|---|---|
| API | **Laravel 12** (PHP 8.2+), Sanctum cookie auth, database queue (AI work runs in background jobs) |
| Frontend | **Vue 3** (`<script setup>`), Pinia, Vue Router, Vite |
| AI | **NVIDIA NIM** (OpenAI-compatible): a large model for coach replies, a fast model for extraction, plus an embedding model |
| Database | **PostgreSQL** (production / Supabase); SQLite also works locally |
| Hosting | One Docker container on **Render** (API + built Vue app on the same address) + **Supabase** Postgres |

---

## Repository layout

```
me/
├── api/                 Laravel API
│   ├── app/Services/    CoachService (replies), EntryExtractionService (breakdowns),
│   │                    CanonMemoryService + MemoryEvaluatorService (memory),
│   │                    EmotionPatternService, TagBreakdownService, NvidiaClient
│   ├── app/Jobs/        ExtractEntryJob, GenerateCoachMessageJob (queued AI work)
│   ├── app/Support/     DemoSandbox (per-visitor copies), InjectionGuard
│   ├── config/          demo.php, nvidia.php, crisis.php, …
│   └── routes/          api.php (HTTP), console.php (artisan commands + schedule)
├── spa/                 Vue 3 app
│   └── src/views/       Journal, Dashboard, MonthlyDashboard, Calendar, Memories,
│                        TherapistDashboard, SharedInsights, Sessions, Tasks, Admin, …
├── website/             static landing page
├── docs/                design + technical documentation (guide below)
├── deploy/start.sh      container entrypoint (migrations, queue worker, scheduler, server)
├── Dockerfile           builds the Vue app + Laravel into one image
└── render.yaml          Render blueprint
```

---

## Run it locally

**Requirements:** PHP 8.2+ with `pdo_pgsql` (or `pdo_sqlite`), Composer, Node 20+, and an [NVIDIA NIM API key](https://build.nvidia.com).

### 1. API

```bash
cd api
composer install
cp .env.example .env
php artisan key:generate
```

In `api/.env`, set:
- **Database:** for PostgreSQL, set the `DB_*` variables (create the database and user first). For the quickest start, use `DB_CONNECTION=sqlite` and create the file with `touch database/database.sqlite`.
- **`NVIDIA_API_KEY`**: without it the coach falls back to fixed replies.

```bash
php artisan migrate
php artisan db:seed --class=DemoDataSeeder          # Maya, a client with months of entries
php artisan db:seed --class=TherapistPatientsSeeder # a therapist with a 5-client caseload
```

Then keep **two terminals** running:

```bash
php artisan serve          # the API on http://localhost:8000
php artisan queue:listen   # the AI replies and breakdowns, which come from here
```

> If entries save but the coach never answers, the queue isn't running. `queue:listen` picks up code changes automatically; `queue:work` does not.

### 2. Frontend

```bash
cd spa
npm install
cp .env.example .env       # VITE_API_URL=http://localhost:8000
npm run dev                # http://localhost:5173
```

### Useful commands

| Command | What it does |
|---|---|
| `php artisan coach:try "text" --mode=engage --times=3` | Test the coach on any text without the UI |
| `php artisan demo:reset` | Delete every demo sandbox |
| `php artisan demo:fill-pool` | Pre-build demo copies |
| `php artisan admin:create` | Create or update the `/admin` account (`ADMIN_EMAIL` in `.env`) |

---

## Deploy

The live demo runs on **Render (free) + Supabase (free)**. Step by step, including every environment variable and the free-plan pitfalls: **[`docs/deploy.md`](docs/deploy.md)**. What demo mode changes and how it's protected: **[`docs/public-demo.md`](docs/public-demo.md)**.

---

## Documentation guide

Every file in [`docs/`](docs/), and what to read it for.

### Start here
| Doc | Read it to… |
|---|---|
| [`sujet.md`](docs/sujet.md) | Understand the project: the problem, the five principles, designing against over-analysis, the two dashboards, the competitive landscape. **Read this first.** |
| [`deploy.md`](docs/deploy.md) | Put it online: Supabase + Render, every variable, speed settings. |
| [`public-demo.md`](docs/public-demo.md) | Learn how the demo works: per-visitor sandboxes, the admin page, the 3-entry limit, prompt-injection blocking, rate limits. |

### How the AI works
| Doc | Read it to… |
|---|---|
| [`coach-decision-spec.md`](docs/coach-decision-spec.md) | See how the coach picks one of its five modes, the "ceiling rule" that keeps it safe, crisis handling, worked examples. |
| [`ai-prompts.md`](docs/ai-prompts.md) | See exactly what the model is told: identity, mode rules, tone, crisis text, the extraction schema, the memory prompts, injection defences. |
| [`memory.md`](docs/memory.md) | Follow cross-day memory end to end: candidate extraction, the evaluator, the consent notification, retrieval into replies. |
| [`chatbot-insights-analysis.md`](docs/chatbot-insights-analysis.md) | Understand the therapist's AI assistant: what data it can see (only what the client shared) and what it refuses. |

### How the app works
| Doc | Read it to… |
|---|---|
| [`dashboard-logic.md`](docs/dashboard-logic.md) | See everything that isn't a prompt: mode resolution in code, extraction validation, weekly aggregation, pattern chains, the monthly dashboard, daily breakdowns. |
| [`dashboard-mockup.md`](docs/dashboard-mockup.md) | Read the original design spec for the user dashboard: the "anti-dashboard" thesis, design tokens, layout. |
| [`class-diagram.md`](docs/class-diagram.md) | Get the data model at a glance (renders as a diagram on GitHub). |

### The therapist side
| Doc | Read it to… |
|---|---|
| [`therapist-bridge.md`](docs/therapist-bridge.md) | Understand linking, crisis chat, share-to-therapist, tasks, sessions, the API surface. |
| [`therapist-roadmap.md`](docs/therapist-roadmap.md) | See what's built and what's next: assessments, case-formulation reference, collaborative strategies. |

### Project history
| Doc | Read it to… |
|---|---|
| [`build-roadmap.md`](docs/build-roadmap.md) | See the original 4-week plan: must-haves vs. optional, the cut list. |
| [`dev-log.md`](docs/dev-log.md) | Read the day-by-day build log and the decisions behind it. |

---

## Safety notes

- **me is not a medical device and does not diagnose.** Crisis language always routes to human help, and in demo mode it only points to local emergency services.
- Demo visitors are told on screen that what they write is visible to the developer and deleted after 24h.
- Secrets live only in `.env` files (git-ignored) and the host's environment settings. Never commit keys or database passwords.
