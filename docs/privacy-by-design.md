# Privacy by design: private demo copies

Added 2026-11-17.

A journaling app asks people to write about how they feel, so the public demo was designed so that **nobody has to trust it blindly**:

- Every visitor gets their own isolated copy.
- The server enforces that isolation.
- Copies are deleted on a schedule.
- Visitors are told plainly what is kept and who can read it.

This page explains how each piece works, and what was tested.

> Building AI isn't just about teaching it what to say. It's also about deciding what it should remember, what it should ask permission for, and when it should stay quiet.

---

## 1. The problem

A portfolio demo usually means one shared test account. For a journal, that's a privacy bug:

- Every visitor would read every other visitor's entries.
- The AI's memory and "what helped before" would mix strangers' lives together.
- One visitor could delete or edit what the next one sees.

Using a MAC address or the IP address to tell visitors apart doesn't work. Browsers never expose a MAC address, and an IP is shared by everyone on the same Wi-Fi (a whole classroom, an office).

## 2. The design: one private copy per browser

```
first click on "test user"
        │
        ▼
 claim a ready-made copy  ──(pool empty?)──▶ build one now (~700 rows)
        │
        ▼
 12-char random token ──▶ encrypted, httpOnly cookie  "demo_sandbox"
        │
        ▼
 log in as  maya.<token>@sandbox.demo   (its own user account)
```

| Piece | How | Where |
|---|---|---|
| **Separate accounts** | Each copy is a full set of real user accounts (the client Maya, her therapist, 4 other clients) with emails like `maya.k3x9…@sandbox.demo`. Separate copies are separate users in the database, so they share no rows. | `App\Support\DemoSandbox::create()`, `PublicDemoSeeder` |
| **Unguessable token** | 12 random lowercase letters and digits (`[a-z0-9]{12}`, about 4.7 × 10¹⁸ possibilities). Anything that doesn't match that format is rejected before any lookup. | `DemoSandbox::isValidToken()` |
| **Private cookie** | The token lives in a cookie that is **encrypted** (Laravel's cookie encryption, keyed by `APP_KEY`), **httpOnly** (page scripts can't read it), and **Secure** in production (HTTPS only). It expires together with the copy. | `DemoController::login()` |
| **Same browser, same copy** | A returning visitor's cookie leads back to their own copy, so their entries are still there. A different browser or device gets a different copy. | `DemoController::login()` |

## 3. The server enforces it, not just the cookie

A cookie only decides *which account you log in as*. What actually protects the data is that **every API request is checked against the logged-in account**:

- Journal data is read through the logged-in user (`$request->user()->entries()`…).
- Records fetched by ID go through ownership checks, and therapist routes require an active therapist–client link.

So even a visitor who guesses another copy's IDs gets nothing back.

**Tested.** Visitor B, logged into their own copy, tried 11 requests against visitor A's data, as both B's client and B's therapist:

| Request from visitor B | Result |
|---|---|
| `GET /api/entries/{A's entry}` | 404 |
| `GET` / `PATCH /api/entities/{A's thread}` | 403 / 403 |
| `PATCH /api/canon-events/{A's memory}` | 403 |
| `PATCH /api/strategies/{A's strategy}` | 403 |
| `GET` monthly trends / session briefs / sessions / shared insights / tasks for A's client | 403 ×5 |
| `POST /api/shared-insights/chat/{A's client}` (ask the AI about A) | 403 |

**11 of 11 refused.** None returned A's data. The test is `tests/Feature/SandboxIsolationTest.php`.

## 4. Copies really expire

- Every copy is deleted **24 hours** after it's opened. A ready-made copy's clock starts when a visitor claims it, not when it was built.
- `php artisan demo:prune` runs **hourly** through the scheduler and deletes expired copies. `demo:reset` deletes all of them at once.
- Deletion **cascades** through the database: deleting the accounts removes everything that belongs to them.

**Tested.** One copy held 221 rows across 15 tables. After the prune: 0 rows, 0 accounts, and no orphaned analyses, coach replies or thread links.

## 5. What is kept, and who can see it

Being honest about this is part of the design. Visitors are told on screen, in the banner and on the login page:

> "Demo: your own private copy with fictional data, reset after 24 hours. What you write is kept and visible to the developer."

- **The developer can read what visitors write.** It shows on `/admin`, which only ever reads `@sandbox.demo` accounts, never real users. It's for spotting bugs and abuse.
- **The text outlives the 24h copy.** Before a copy is deleted, what the visitor wrote is copied into `demo_archive`: the visitor label, the text, the coach's reply and mood. It has no link back to any account. `DEMO_ARCHIVE_DAYS` can set a retention limit; 0 means keep it.
- **The AI provider processes entries.** Each entry is sent to NVIDIA's API to generate the reply and the breakdown.
- **Clicks are counted, not tracked.** `/admin` counts clicks on "test user" and "test therapist", storing only the button and the time. No IP and no identity.

That's why the demo asks visitors not to enter real personal details.

## 6. Guardrails around the copies

| Guardrail | Why |
|---|---|
| **3 journal entries per copy** (`DEMO_ENTRIES`) | Keeps the AI cost bounded. Crisis messages are **never** blocked by this limit. |
| **AI rate limits:** 15/hour per copy, plus 300/hour per network | Counted per visitor, so one classroom on one Wi-Fi isn't blocked by one person. |
| **New copies:** 150 per network per day; **logins:** 60/minute per network | Stops someone from creating thousands of copies. The IP is used only as a short-lived rate-limit key, never stored with any data. |
| **Prompt-injection guard** | "Ignore your instructions"-style entries are blocked before reaching the AI, and the admin gets an alert. |
| **No real crisis number in demo mode** | The crisis message points to local emergency services, never a personal phone number. |
| **Locked account actions** | Demo users can't unlink their therapist or regenerate invite codes, so one visitor can't break a copy's setup. |

## 7. What this does *not* claim

- **It isn't anonymity.** The developer can read demo text (and visitors are told so).
- **It isn't end-to-end encryption.** The server and the AI provider see the text in order to reply.
- **Deleting a copy doesn't delete the archived text.** That's deliberate, and disclosed.

## Files

| File | Role |
|---|---|
| `api/app/Support/DemoSandbox.php` | create, claim, find, prune and wipe copies; token validation |
| `api/app/Http/Controllers/Api/DemoController.php` | the cookie, login, rate limits |
| `api/app/Support/DemoActivity.php` | what visitors wrote: live feed + archive |
| `api/config/demo.php` | every limit and duration, overridable by env |
| `tests/Feature/SandboxIsolationTest.php` | the cross-visitor test above |

See also [`public-demo.md`](public-demo.md) for setup and admin details.
