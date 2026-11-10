# Deploying the public demo (Supabase + Render)

Added 2026-11-10.

**What runs where**

- **Supabase (free)**: the PostgreSQL database.
- **Render (free)**: one Docker container. It runs the Laravel API and serves the built Vue app **from the same address**, so the login cookie is first-party. It also runs the queue worker (AI replies) and the scheduler (hourly `demo:prune`).

The files that make this work:

| File | What it does |
|---|---|
| `Dockerfile` | Builds the Vue app with `VITE_API_URL=""` (same address) and `VITE_DEMO_MODE=true`, then installs Laravel with `pdo_pgsql` and copies the Vue build into `api/public/` as `spa.html` and `assets/` |
| `deploy/start.sh` | `config:cache`, `migrate --force`, the queue worker (restarted hourly), `schedule:work`, then `php artisan serve` on `$PORT` (4 workers) |
| `render.yaml` | Render Blueprint: free plan, Frankfurt region, all environment variables (secrets are asked for in the dashboard) |
| `api/routes/web.php` | When `public/spa.html` exists, every non-API URL returns the Vue app. Locally (no `spa.html`) nothing changes. |
| `api/bootstrap/app.php` | `trustProxies(at: '*')`: Render handles HTTPS in front of the app |
| `spa/src/lib/api.js` | An empty `VITE_API_URL` means "same address as the page". Unset still means `http://localhost:8000`. |

Local development is unchanged: `php artisan serve`, `php artisan queue:listen`, `npm run dev`.

## 1. Supabase

1. **Turn off the Data API** under Project Settings → Data API. Laravel's tables live in the `public` schema. Without this, anyone holding the project's public key could read them over Supabase's REST API, including the `users` table. Laravel connects straight to Postgres and doesn't need the Data API.
2. Click **Connect**, open the **Session pooler** tab, and copy the parameters:
   - host: `aws-…-eu-central-1.pooler.supabase.com`
   - port: `5432`
   - database: `postgres`
   - user: `postgres.jpdprxoolhojsilkhgkc`

   Don't use "Direct connection": it's IPv6-only, and Render can't reach it. Don't use the transaction pooler (port 6543) either: Laravel's prepared statements break on it.
3. Use the database password you set when you created the project. You can reset it under Project Settings → Database.

## 2. Render

1. Push the repo, including `Dockerfile`, `render.yaml` and `deploy/`.
2. On render.com: **New → Blueprint**, pick `elaaabidi04/me`, and Render reads `render.yaml`.
3. Fill in the secret variables:

| Variable | Value |
|---|---|
| `APP_KEY` | Output of `php artisan key:generate --show`, run in `api/` locally |
| `APP_URL`, `FRONTEND_URL` | `https://me-demo.onrender.com`, or whatever name Render gives the service |
| `SANCTUM_STATEFUL_DOMAINS` | The same address **without** `https://`, e.g. `me-demo.onrender.com` |
| `DB_HOST`, `DB_USERNAME`, `DB_PASSWORD` | From Supabase, step 1 |
| `ADMIN_EMAIL` | Your email (for `/admin`) |
| `NVIDIA_API_KEY` | Your key. Also copy the `NVIDIA_MODEL…` lines from your local `api/.env` as extra variables. |

4. Deploy. The first build takes about 5–10 minutes. `migrate --force` creates the tables in Supabase on boot.
5. Create the admin account once: in the Render service, open **Shell** and run `php artisan admin:create`.

The Shell tab may not be available on the free plan. If so, run the same command locally with your `api/.env` pointing at the Supabase pooler.

## Free-plan limits

- **Render free sleeps after about 15 minutes with no visitors.** The next visit takes about a minute to wake it. The queue worker sleeps with it, which is fine because nothing is waiting. The $7/month plan never sleeps.
- **Supabase free pauses a project after about a week with no activity.** Resume it from the dashboard.
- **Email**: `MAIL_MAILER=log` writes injection alerts to the Render logs. For real emails, set the SMTP variables (`MAIL_MAILER=smtp`, `MAIL_HOST` …). If they never arrive, the host may be blocking SMTP ports, so use an email API provider instead.
- **Logs**: `LOG_CHANNEL=stderr` sends Laravel's log to the Render **Logs** tab.
