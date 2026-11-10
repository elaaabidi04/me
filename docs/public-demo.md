# Public demo (portfolio hosting)

Added 2026-10-15. Lets visitors explore the app with months of fictional data in one click, without publishing any credentials.

## What a visitor gets

**Every visitor's browser gets its own private copy (sandbox)** of all the demo accounts below (added 2026-10-25). The first click on a test button seeds a fresh copy in about 2–4 seconds. That copy's token is stored in an encrypted, httpOnly cookie, so the same browser comes back to the same copy. Other browsers and devices can never see it. Each copy is deleted 24h after it was created. A MAC address can't be used for this: browsers never expose it, and an IP address is shared by everyone on the same Wi-Fi.

Sandbox accounts look like `maya.<token>@sandbox.demo`. The names below are the characters in every copy.


- **Login page** in demo mode has no email/password form, just two buttons: **test user** and **test therapist**. The homepage hero also has "Try the live demo". One click logs them in.
- **Client demo**: Maya Haddad (`demo@me.app`) has ~80 fictional entries over ~4 months. The arc runs: settling in, then a rough patch (workload, sleep, isolation), then climbing out (walks, Sarah, starting therapy), then the deadline arc from `DemoDataSeeder`. The weekly reflection is already unlocked, and the monthly trends have real ups and downs at every zoom level.
- **Therapist demo**: Dr. Amel Chraiti (`therapist@example.com`) has a 5-client caseload: Maya plus the 4 from `TherapistPatientsSeeder`. It includes crisis threads, tasks, sessions, shared insights and briefs, and Maya's live trends are enabled.
- A green banner on every page: *"Demo — your own private copy with fictional data, deleted after 24 hours."*

## Pieces

| File | What it does |
|---|---|
| `api/config/demo.php` | On/off switch, the demo account list, AI cap |
| `api/database/seeders/PublicDemoSeeder.php` | Deletes **only** the demo accounts, then rebuilds them with dates relative to today. Every demo account gets a random password. |
| `api/routes/console.php` | `demo:prune` (hourly: deletes sandboxes older than 24h), `demo:reset` (deletes all sandboxes), `admin:create` |
| `api/app/Http/Controllers/Api/DemoController.php` | `POST /api/demo/login {role}` (404 unless `DEMO_MODE=true`) |
| `api/app/Http/Middleware/BlockInDemo.php` | Demo users can't unlink the therapist, link elsewhere, or regenerate the invite code |
| `api/app/Providers/AppServiceProvider.php` | `demo-ai` limiter: demo users get N LLM calls/hour per IP (new entry, confirm memory, shared-insight chat) |
| `api/config/crisis.php` | With `DEMO_MODE=true` the crisis message **never** includes `CRISIS_CONTACT_NUMBER`, only "contact local emergency services" |
| `spa/src/App.vue`, `Login.vue`, `Home.vue`, `stores/auth.js` | Banner + demo buttons (shown only when `VITE_DEMO_MODE=true`) |

Everything is inert with `DEMO_MODE=false`, which is the local default.

## Deploy checklist

API `.env` on the host:

```
APP_ENV=production
APP_DEBUG=false
DEMO_MODE=true
DEMO_AI_PER_HOUR=15
FRONTEND_URL=https://<your-spa-domain>
SANCTUM_STATEFUL_DOMAINS=<your-spa-domain>
SESSION_DOMAIN=.<shared-parent-domain>   # SPA and API must share a parent domain for the session cookie
SESSION_SECURE_COOKIE=true
NVIDIA_API_KEY=...                        # set a spending limit on this key
```

Then:

```
php artisan migrate --force
php artisan demo:reset
```

Keep two processes running besides the web server:

- `php artisan queue:work`: the entry extraction and coach reply jobs
- `php artisan schedule:work`, or a cron entry `* * * * * php artisan schedule:run`: the nightly reset

SPA build env: `VITE_API_URL=https://<your-api-domain>` and `VITE_DEMO_MODE=true`.

## Notes

- `demo:reset` is safe to run any time. It only deletes users listed in `config('demo.emails')`, and everything else cascades off `users.id`.
- A visitor whose sandbox expires while they're logged in just gets logged out. Their next click creates a fresh copy.
- Fixed alongside this: `Dashboard.vue`'s `loadCanonEvent()` treated the API's empty `{}` ("nothing pending") as a memory. It fired `PATCH /api/canon-events/undefined` 21 times on every dashboard load. That was harmless locally, but on the demo it used up the visitor's AI allowance.

## Admin page (added 2026-10-25)

`/admin` shows what visitors typed into their sandboxes: journal entries (with mood and the coach's reply), crisis-chat messages, therapist tasks, session notes and session briefs. Seeded data is hidden. It **only** reads the demo accounts, never real users' journals. Sandboxes are deleted after 24h, so it covers roughly the last day.

Setup, once per machine or server:

1. In `api/.env`: `ADMIN_EMAIL=you@example.com`
2. `php artisan config:clear`
3. `php artisan admin:create`. It asks for a password (12+ characters). Run it again to change the password.

Then on `/login`, click the small **admin** link under the test buttons and sign in. Anyone else who reaches `/admin` is sent to the homepage, and the API answers 404. `POST /api/login` is limited to 10 attempts a minute.

Visitors are told on the demo screen that their copy is private from other visitors, visible to the developer, and deleted after 24h.

## Sandbox commands (added 2026-10-25)

- `php artisan demo:prune` deletes sandboxes older than `DEMO_SANDBOX_HOURS` (default 24). The scheduler runs it **hourly**, so the host still needs `php artisan schedule:work` or the cron entry.
- `php artisan demo:reset` deletes **every** sandbox right now (and the old shared `demo@me.app` / `therapist@example.com`… accounts if they still exist). Visitors get a fresh copy on their next click.
- `DEMO_MAX_SANDBOXES_PER_IP=20`: the maximum number of new sandboxes one IP can create per day. Returning visitors reuse theirs and don't count.
- Code: `api/app/Support/DemoSandbox.php` (create, find, prune) and `api/app/Http/Controllers/Api/DemoController.php` (cookie and login). `PublicDemoSeeder` now builds one sandbox per call, and `DemoDataSeeder` / `TherapistPatientsSeeder` take optional arguments (therapist email, token). Run without arguments, they behave exactly as before for local dev.
- The admin page labels each item with its sandbox (`visitor abc123`), so you can tell visitors apart.

## Prompt-injection blocking (added 2026-11-07)

`api/app/Support/InjectionGuard.php` is a strict pattern list placed in front of the AI. It covers "ignore your instructions", requests for the system prompt, forged `[TRUSTED_SYSTEM]` tags, jailbreak personas, a fake "I'm the developer", and French and Tunisian Darija variants. When an entry matches:

- The entry is saved, but **no AI is called**. The user gets a fixed reply, and demo users also get a note that it's logged.
- `ADMIN_EMAIL` is emailed through `App\Mail\InjectionAttemptMail`, which is queued. Demo visitors' emails include their text. **Real accounts get an alert without the text.** At most one email per account per `INJECTION_MAIL_COOLDOWN` minutes (default 10). Every attempt also shows on `/admin` as **blocked attempt**.
- **Crisis language always wins.** An entry that is both an attack and a crisis goes through normal crisis handling.
- The therapist "insights chat" uses the same guard. A blocked question returns 422 and is never sent.

The rules favor precision over recall: the test set blocks 13/13 attacks and passes 14/14 harmless lookalikes ("ignore my inner critic", "the rules of dating", "ignored the instructions on the exam"). Subtler attempts still face the soft detector, the system prompt's rules and the reply leak filter.

Sending real email needs SMTP in `api/.env`. `MAIL_MAILER=log` (the default) writes emails to `storage/logs/laravel.log` instead.

## PostgreSQL + per-visitor limits (added 2026-11-08)

**Database: PostgreSQL instead of SQLite.** SQLite locks the whole file on every write, so the web server, the queue workers and several demo visitors writing at once can hit "database is locked". All 37 migrations, every API route (client and therapist), the queue jobs, sandbox creation and `demo:prune` / `demo:reset` were run against PostgreSQL 16 with **no code changes and zero SQL errors**. Only `.env` changes:

```
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=me
DB_USERNAME=me
DB_PASSWORD=...
```

PHP needs the `pdo_pgsql` and `pgsql` extensions enabled in `php.ini`. Then run `php artisan migrate`.

**Limits are counted per visitor, not per network.** Everyone on one school or office Wi-Fi shares one IP, so the old per-IP caps let one person block a whole room:

| Limit | Before | Now |
|---|---|---|
| AI requests (entry, memory confirm, insights chat) | 15/hour **per network** | 15/hour **per demo copy** (`DEMO_AI_PER_HOUR`), plus a 300/hour per-network ceiling (`DEMO_AI_PER_HOUR_PER_NETWORK`) against API-key farming |
| One-click demo logins | 10/min per network | 60/min per network (`DEMO_LOGINS_PER_MINUTE`) |
| New demo copies | 20/day per network | 150/day per network (`DEMO_MAX_SANDBOXES_PER_IP`) |

## 3 tries per visitor + links off in the demo (added 2026-11-10)

- Each demo copy can write **3 journal entries** (`DEMO_ENTRIES`, default 3) over its whole 24h life. Each entry gets an AI reply. The journal shows "N tries left", and at 0 the compose box is closed. The API enforces it too (429). **Crisis entries are never blocked**, even at 0. The counter is `DemoSandbox::triesLeft()`, and `/api/user` returns it as `demo_tries_left`.
- For demo users, **full breakdown →** (pattern drawer, journal review) and **explore in calendar →** (feeling drawer) are greyed out.
