<?php

namespace Database\Seeders;

use App\Models\AssignedTask;
use App\Models\CrisisMessage;
use App\Models\DailyIntent;
use App\Models\Entry;
use App\Models\EntryAnalysis;
use App\Models\InsightSnapshot;
use App\Models\SafetyEvent;
use App\Models\SessionBrief;
use App\Models\Share;
use App\Models\TherapistClient;
use App\Models\TherapySession;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Seeds a realistic 4-patient caseload for the demo therapist account
 * (therapist@example.com). Run after DemoDataSeeder so the therapist row
 * already exists. Idempotent — safe to run multiple times.
 *
 * php artisan db:seed --class=TherapistPatientsSeeder
 */
class TherapistPatientsSeeder extends Seeder
{
    // 2026-10-25: per-visitor sandboxes pass their own therapist email and a
    // token, so each sandbox gets its own copy of the 4 patients (see
    // App\Support\DemoSandbox). No arguments = the old local behaviour.
    private ?string $token = null;

    private function email(string $name): string
    {
        return $this->token ? \App\Support\DemoSandbox::email($name, $this->token) : "{$name}@example.com";
    }

    public function run(?string $therapistEmail = null, ?string $token = null): void
    {
        $this->token = $token;
        $therapist = User::where('email', $therapistEmail ?? 'therapist@example.com')->first();

        if (!$therapist) {
            $this->command->error('therapist@example.com not found — run DemoDataSeeder first.');
            return;
        }

        if (User::where('email', $this->email('nora'))->exists()) {
            $this->command->warn('Therapist patients already seeded — skipping.');
            return;
        }

        $this->command->info('Seeding therapist patient caseload…');

        $this->seedNora($therapist);
        $this->seedKarim($therapist);
        $this->seedLina($therapist);
        $this->seedYassine($therapist);

        $this->command->info("Done — 4 demo patients linked to {$therapist->email}.");
    }

    // ── Client 1: Nora Benyahia ──────────────────────────────────────────────
    // High workload stress, 2 unreviewed crisis flags, pending tasks,
    // sessions coming up. The most "urgent" card in the roster.
    private function seedNora(User $therapist): void
    {
        $client = User::create([
            'name'     => 'Nora Benyahia',
            'email'    => $this->email('nora'),
            'password' => Hash::make('password'),
            'role'     => 'client',
        ]);

        $link = TherapistClient::create([
            'therapist_id'        => $therapist->id,
            'client_id'           => $client->id,
            'live_trends_enabled' => true,
            'created_at'          => now()->subWeeks(3),
            'updated_at'          => now()->subWeeks(3),
        ]);

        $this->task($therapist, $client, 'Breathing exercise — 5 min before bed', 'Use the 4-7-8 pattern. In for 4, hold 7, out for 8. Do it three times each night.', now()->addDays(3)->toDateString());
        $this->task($therapist, $client, 'Note one positive moment each day', 'Keep it short — one sentence is enough. Just one thing.', now()->addDays(7)->toDateString());

        $this->session($therapist, $client, now()->subDays(8), 'attended', 'Good session — Nora opened up about work pressure. Following up on boundaries next time.');
        $this->session($therapist, $client, now()->addDays(6));
        $this->session($therapist, $client, now()->addDays(20));

        $flagA = $this->flag($client, now()->subDays(4), "I can't keep doing this. Everything is falling apart at work and I just don't see a way through it. I keep thinking about just disappearing.");
        $flagB = $this->flag($client, now()->subDays(1), "Another bad night. I'm so exhausted I can barely think straight. Everything feels pointless right now.");

        $this->message($link, $flagB, $client, "I don't know how to explain it but everything just feels too much right now.", now()->subDays(1)->addHours(1));

        $this->command->line('  Seeded: Nora Benyahia (2 unreviewed flags, upcoming sessions)');
    }

    // ── Client 2: Karim Ouali ────────────────────────────────────────────────
    // Doing well — no flags, tasks completed, shared insights, upcoming session.
    private function seedKarim(User $therapist): void
    {
        $client = User::create([
            'name'     => 'Karim Ouali',
            'email'    => $this->email('karim'),
            'password' => Hash::make('password'),
            'role'     => 'client',
        ]);

        $link = TherapistClient::create([
            'therapist_id'        => $therapist->id,
            'client_id'           => $client->id,
            'live_trends_enabled' => false,
            'created_at'          => now()->subWeeks(6),
            'updated_at'          => now()->subWeeks(6),
        ]);

        $this->task($therapist, $client, 'Walk outside for 20 minutes, 3× this week', null, now()->subDays(3)->toDateString(), now()->subDays(2));
        $this->task($therapist, $client, 'Write down one worry and one counter-thought', 'For each worry you catch yourself repeating, write it out and then write a realistic counter to it.', now()->subDays(5)->toDateString(), now()->subDays(4));
        $this->task($therapist, $client, 'Read one chapter of the book we discussed', null, now()->addDays(10)->toDateString());

        $this->session($therapist, $client, now()->subDays(10), 'attended', 'Karim is making real progress. Discussed the worry-log technique. Plan to review next session.');
        $this->session($therapist, $client, now()->addDays(11));

        $snapshot = new InsightSnapshot();
        $snapshot->user_id      = $client->id;
        $snapshot->panel_key    = 'mood_trend';
        $snapshot->scope        = 'month';
        $snapshot->title        = 'Mood trend — last 30 days';
        $snapshot->period_label = 'past month';
        $snapshot->caption      = 'Mostly positive days this month, with two dips mid-week.';
        $snapshot->rows         = [['label' => 'positive', 'value' => 18], ['label' => 'neutral', 'value' => 8], ['label' => 'negative', 'value' => 4]];
        $snapshot->timestamps   = false;
        $snapshot->created_at   = now()->subDays(5);
        $snapshot->updated_at   = now()->subDays(5);
        $snapshot->save();
        $this->share($client, InsightSnapshot::class, $snapshot->id, now()->subDays(5));

        $upcomingSession = TherapySession::where('client_id', $client->id)
            ->where('therapist_id', $therapist->id)
            ->where('scheduled_at', '>', now())
            ->orderBy('scheduled_at')
            ->first();

        $brief = new SessionBrief();
        $brief->client_id           = $client->id;
        $brief->therapist_id        = $therapist->id;
        $brief->therapy_session_id  = $upcomingSession?->id;
        $brief->content             = "Feeling much better this week. The worry-log is helping — I caught myself in a loop twice and was able to step back. Still struggling a bit with sleep but it's better. Wanted to talk about what happens when the routine breaks (travel coming up next month).";
        $brief->timestamps          = false;
        $brief->created_at          = now()->subDays(2);
        $brief->updated_at          = now()->subDays(2);
        $brief->save();
        $this->share($client, SessionBrief::class, $brief->id, now()->subDays(2));

        $this->command->line('  Seeded: Karim Ouali (stable, 2 tasks done, insight + brief shared)');
    }

    // ── Client 3: Lina Marchand ──────────────────────────────────────────────
    // Had a reviewed crisis weeks ago, making steady progress. One no-show.
    private function seedLina(User $therapist): void
    {
        $client = User::create([
            'name'     => 'Lina Marchand',
            'email'    => $this->email('lina'),
            'password' => Hash::make('password'),
            'role'     => 'client',
        ]);

        $link = TherapistClient::create([
            'therapist_id'        => $therapist->id,
            'client_id'           => $client->id,
            'live_trends_enabled' => true,
            'created_at'          => now()->subMonths(2),
            'updated_at'          => now()->subMonths(2),
        ]);

        $this->task($therapist, $client, 'Journal for 10 minutes every morning', 'Just write — no structure needed. Whatever comes to mind first.', now()->addDays(14)->toDateString());
        $this->task($therapist, $client, 'Reach out to one friend this week', null, now()->addDays(5)->toDateString());
        $this->task($therapist, $client, 'Try a 10-minute guided meditation', 'Any app is fine. We discussed Headspace but anything works.', now()->subDays(7)->toDateString(), now()->subDays(5));

        $this->session($therapist, $client, now()->subWeeks(5), 'attended', 'First session after the crisis event. Lina was guarded but engaged. Agreed to weekly check-ins.');
        $this->session($therapist, $client, now()->subWeeks(3), 'no_show');
        $this->session($therapist, $client, now()->subDays(9), 'attended', 'Much more open today. Talked through the incident with her manager. Good progress on distress tolerance.');
        $this->session($therapist, $client, now()->addDays(5));

        $reviewedAt = now()->subWeeks(6);
        $flag = $this->flag($client, $reviewedAt, "I feel completely alone and I don't see the point of anything anymore. I've been having thoughts I'm scared of. I don't want to act on them but they're there.", $reviewedAt->copy()->addHours(3));

        $this->message($link, $flag, $therapist, "Lina, I saw your check-in from last night. I want you to know I read it and I'm here. Can we find time to talk today?", $reviewedAt->copy()->addHours(3));
        $this->message($link, $flag, $client, "Thank you for reaching out. I'm okay, it just got very dark for a moment. I think I need to talk.", $reviewedAt->copy()->addHours(4));
        $this->message($link, $flag, $therapist, "Of course. I'm free this afternoon at 3 — does that work? And whatever you need between now and then, write it here.", $reviewedAt->copy()->addHours(4)->addMinutes(10));
        $this->message($link, $flag, $client, "3 works. Thank you.", $reviewedAt->copy()->addHours(4)->addMinutes(30));

        $brief = new SessionBrief();
        $brief->client_id          = $client->id;
        $brief->therapist_id       = $therapist->id;
        $brief->therapy_session_id = null;
        $brief->content            = "Things have been steadier. I had a rough moment Tuesday but I used the grounding technique and it helped — first time I actually caught myself in time. Still anxious about work but not as paralyzed. Want to talk about the conversation I need to have with my manager.";
        $brief->timestamps         = false;
        $brief->created_at         = now()->subDays(3);
        $brief->updated_at         = now()->subDays(3);
        $brief->save();
        $this->share($client, SessionBrief::class, $brief->id, now()->subDays(3));

        $this->command->line('  Seeded: Lina Marchand (reviewed flag, no-show session, brief shared)');
    }

    // ── Client 4: Yassine Farhat ─────────────────────────────────────────────
    // Brand new — linked 4 days ago. One unreviewed flag, intake session scheduled.
    private function seedYassine(User $therapist): void
    {
        $client = User::create([
            'name'     => 'Yassine Farhat',
            'email'    => $this->email('yassine'),
            'password' => Hash::make('password'),
            'role'     => 'client',
        ]);

        TherapistClient::create([
            'therapist_id'        => $therapist->id,
            'client_id'           => $client->id,
            'live_trends_enabled' => false,
            'created_at'          => now()->subDays(4),
            'updated_at'          => now()->subDays(4),
        ]);

        $this->task($therapist, $client, 'Track your mood at the end of each day this week', 'Even one word is fine — just build the habit of noticing.', now()->addDays(7)->toDateString());

        $this->session($therapist, $client, now()->addDays(3));

        $this->flag($client, now()->subDays(2), "I've been feeling really low for weeks and I finally said something out loud. I don't think I'm okay.");

        $this->command->line('  Seeded: Yassine Farhat (new client, 1 unreviewed flag, intake session upcoming)');
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function task(User $therapist, User $client, string $title, ?string $instructions, string $dueDate, ?Carbon $completedAt = null): AssignedTask
    {
        $t = new AssignedTask();
        $t->therapist_id = $therapist->id;
        $t->client_id    = $client->id;
        $t->title        = $title;
        $t->instructions = $instructions;
        $t->due_date     = $dueDate;
        $t->completed_at = $completedAt;
        $t->timestamps   = false;
        $t->created_at   = now()->subDays(rand(1, 3));
        $t->updated_at   = $completedAt ?? $t->created_at;
        $t->save();
        return $t;
    }

    private function session(User $therapist, User $client, Carbon $scheduledAt, ?string $attendance = null, ?string $outcomeNote = null): TherapySession
    {
        $s = new TherapySession();
        $s->therapist_id = $therapist->id;
        $s->client_id    = $client->id;
        $s->scheduled_at = $scheduledAt;
        $s->attendance   = $attendance;
        $s->outcome_note = $outcomeNote;
        $s->timestamps   = false;
        $s->created_at   = $scheduledAt->copy()->subDays(7);
        $s->updated_at   = $attendance ? $scheduledAt->copy()->addHours(1) : $scheduledAt->copy()->subDays(7);
        $s->save();
        return $s;
    }

    private function flag(User $client, Carbon $at, string $entryBody, ?Carbon $reviewedAt = null): SafetyEvent
    {
        $intent = DailyIntent::firstOrCreate(
            ['user_id' => $client->id, 'date' => $at->toDateString()],
            ['pick' => 'witness']
        );

        $entry = new Entry();
        $entry->user_id         = $client->id;
        $entry->daily_intent_id = $intent->id;
        $entry->body            = $entryBody;
        $entry->in_vault        = false;
        $entry->timestamps      = false;
        $entry->created_at      = $at;
        $entry->updated_at      = $at;
        $entry->save();

        $analysis = new EntryAnalysis();
        $analysis->entry_id          = $entry->id;
        $analysis->mood              = 'overwhelmed';
        $analysis->sentiment         = 'negative';
        $analysis->rumination_score  = 0.006;
        $analysis->self_ref_density  = 0.600;
        $analysis->confidence        = 0.800;
        $analysis->distress_flag     = true;
        $analysis->openness          = 0.200;
        $analysis->emotions          = [['emotion' => 'sadness', 'intensity' => 8], ['emotion' => 'anxiety', 'intensity' => 6]];
        $analysis->needs             = [['need' => 'safety', 'moment' => null]];
        $analysis->extraction_confidence = 0.80;
        $analysis->raw_json          = ['mood' => 'overwhelmed', 'sentiment' => 'negative', 'confidence' => 0.80];
        $analysis->timestamps        = false;
        $analysis->created_at        = $at;
        $analysis->updated_at        = $at;
        $analysis->save();

        $flag = new SafetyEvent();
        $flag->user_id     = $client->id;
        $flag->entry_id    = $entry->id;
        $flag->reviewed_at = $reviewedAt;
        $flag->timestamps  = false;
        $flag->created_at  = $at;
        $flag->updated_at  = $at;
        $flag->save();

        return $flag;
    }

    private function message(TherapistClient $link, SafetyEvent $event, User $sender, string $body, Carbon $at): void
    {
        $m = new CrisisMessage();
        $m->therapist_client_id = $link->id;
        $m->safety_event_id    = $event->id;
        $m->sender_id          = $sender->id;
        $m->body               = $body;
        $m->timestamps         = false;
        $m->created_at         = $at;
        $m->updated_at         = $at;
        $m->save();
    }

    private function share(User $user, string $type, int $id, Carbon $at): void
    {
        $s = new Share();
        $s->user_id        = $user->id;
        $s->shareable_type = $type;
        $s->shareable_id   = $id;
        $s->granted_at     = $at;
        $s->timestamps     = false;
        $s->created_at     = $at;
        $s->updated_at     = $at;
        $s->save();
    }
}
