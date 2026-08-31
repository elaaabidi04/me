<?php

namespace Database\Seeders;

use App\Models\CanonEvent;
use App\Models\CoachMessage;
use App\Models\CrisisMessage;
use App\Models\DailyIntent;
use App\Models\Entry;
use App\Models\EntryAnalysis;
use App\Models\Entity;
use App\Models\SafetyEvent;
use App\Models\Strategy;
use App\Models\TherapistClient;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::first();

        if (!$user) {
            $this->command->error('No users found — register an account first, then reseed.');
            return;
        }

        $this->command->info("Seeding demo data for: {$user->email}");

        // ── Entities ─────────────────────────────────────────────────────────
        // Use updateOrCreate so existing entities (already extracted from real
        // entries) get their valence/trigger_status set correctly rather than
        // firstOrCreate silently returning the old row unchanged.
        $walk = Entity::updateOrCreate(
            ['user_id' => $user->id, 'name' => 'morning walk', 'type' => 'topic'],
            ['trigger_status' => 'confirmed', 'valence' => 'positive']
        );
        $deadline = Entity::updateOrCreate(
            ['user_id' => $user->id, 'name' => 'the deadline', 'type' => 'topic'],
            ['trigger_status' => 'confirmed', 'valence' => 'negative']
        );
        // Sarah: only set unconfirmed if she isn't already confirmed from real
        // extraction — we don't want to undo the user's real data.
        $sarah = Entity::firstOrCreate(
            ['user_id' => $user->id, 'name' => 'Sarah', 'type' => 'person'],
            ['trigger_status' => 'unconfirmed']
        );
        $office = Entity::firstOrCreate(
            ['user_id' => $user->id, 'name' => 'the office', 'type' => 'place'],
            ['trigger_status' => 'confirmed', 'valence' => null]
        );
        // New for the schema-v2 showcase batch — a family relationship, distinct
        // from the friendship (Sarah) one, so stressors/needs have something to
        // differentiate relationships/family from relationships/friendship.
        $sister = Entity::firstOrCreate(
            ['user_id' => $user->id, 'name' => 'my sister', 'type' => 'person'],
            ['trigger_status' => 'unconfirmed']
        );

        $entityMap = [
            'walk'     => $walk,
            'deadline' => $deadline,
            'sarah'    => $sarah,
            'office'   => $office,
            'sister'   => $sister,
        ];

        // ── Strategies (confirmed, tied to the negative / social entities) ────
        Strategy::firstOrCreate(
            ['user_id' => $user->id, 'description' => 'taking a short walk outside'],
            ['entity_id' => $deadline->id, 'source_entry_id' => null, 'status' => 'confirmed']
        );
        Strategy::firstOrCreate(
            ['user_id' => $user->id, 'description' => 'talking it through with Sarah'],
            ['entity_id' => $sarah->id, 'source_entry_id' => null, 'status' => 'confirmed']
        );

        // ── Canon events (confirmed memories, deliberately no embedding) ──────
        // Real embeddings only ever come from a live NvidiaClient::embed() call
        // at confirm time (see CanonEventController::update()) — seeding a
        // made-up vector here would be fake data pretending to be a real API
        // response, and it wouldn't correspond to the description's actual
        // meaning anyway. These rows exist so "what me remembers" has
        // something to show in a demo; they won't be retrievable by
        // CoachService/CanonMemoryService until re-confirmed against a real
        // embedding call, which is expected and documented, not a bug.
        CanonEvent::firstOrCreate(
            ['user_id' => $user->id, 'description' => 'started training for a half marathon this spring'],
            ['entity_id' => null, 'source_entry_id' => null, 'status' => 'confirmed', 'confirmed_at' => now()->subWeeks(2)]
        );
        CanonEvent::firstOrCreate(
            ['user_id' => $user->id, 'description' => "sister's wedding is in October — she asked me to be maid of honor"],
            ['entity_id' => $sister->id, 'source_entry_id' => null, 'status' => 'confirmed', 'confirmed_at' => now()->subWeeks(1)]
        );

        // Clear the current week's cached reflection so GET /api/reflections/current
        // regenerates it from all the seeded data rather than serving old content.
        \App\Models\WeeklyReflection::where('user_id', $user->id)
            ->where('week_start', now()->startOfWeek(\Carbon\Carbon::MONDAY)->toDateString())
            ->delete();

        // ── Therapist bridge demo (2026-08-17, "feed it seeder demo so i can
        // see it clearly") ──────────────────────────────────────────────────
        // Only meaningful if the main demo user is a client — skip entirely
        // for a therapist-role account (nothing to link them to themselves).
        if ($user->role === 'client') {
            $this->seedTherapistBridge($user);
        }

        // Two independent batches, two independent idempotency guards — so
        // adding the new schema-v2 batch later doesn't re-insert (and
        // duplicate) the original 3-week narrative that's already there.
        $inserted = 0;

        if (!$this->alreadySeeded($user->id, 'demo_seeder')) {
            $this->seedEntries($user, $entityMap, $this->entryData(), 'demo_seeder');
            $inserted += count($this->entryData());
            $this->command->line('  Seeded: 3-week deadline/walk/Sarah narrative.');
        } else {
            $this->command->warn('  3-week narrative already seeded — skipping.');
        }

        if (!$this->alreadySeeded($user->id, 'demo_seeder_v2')) {
            $this->seedEntries($user, $entityMap, $this->entryDataThisWeek(), 'demo_seeder_v2');
            $inserted += count($this->entryDataThisWeek());
            $this->command->line('  Seeded: schema-v2 showcase batch (this week — emotions, stressors, needs, protective factors, behaviors, thinking_patterns).');
        } else {
            $this->command->warn('  Schema-v2 showcase batch already seeded — skipping.');
        }

        if (!$this->alreadySeeded($user->id, 'demo_seeder_v3')) {
            $this->seedEntries($user, $entityMap, $this->entryDataPatternDemo(), 'demo_seeder_v3');
            $inserted += count($this->entryDataPatternDemo());
            $this->command->line('  Seeded: pattern-chain demo batch (repeated uncertainty/conflict triggers, spread over the past 2 weeks).');
        } else {
            $this->command->warn('  Pattern-chain demo batch already seeded — skipping.');
        }

        if ($inserted === 0) {
            $this->command->line('Nothing new to seed. Delete coach_messages with reason="demo_seeder" or "demo_seeder_v2" to reseed a batch.');
            return;
        }

        $this->command->info("Done — {$inserted} demo entries added.");
        $this->command->newLine();
        $this->command->line('  Make sure <comment>REFLECTION_DEV_BYPASS=true</comment> in api/.env to see');
        $this->command->line('  this week\'s reflection without waiting for Sunday.');
    }

    /**
     * Idempotency guard: demo_seeder coach messages are the reliable marker
     * because entity rows may already exist from real extraction runs.
     */
    private function alreadySeeded(int $userId, string $reason): bool
    {
        return CoachMessage::whereHas('entry', fn ($q) => $q->where('user_id', $userId))
            ->where('reason', $reason)
            ->exists();
    }

    /**
     * Therapist bridge demo (2026-08-17) — a second account (role=therapist)
     * linked to the main demo user, plus two crisis threads showing both
     * states the feature can actually be in: one already reviewed with a
     * short reply exchange, one still sitting untouched. Guarded by
     * TherapistClient existing for this client, same idempotency shape as
     * alreadySeeded() above.
     *
     * The two "crisis" journal entries below are fictional demo content in
     * an academic mockup (see config/crisis.php's MOCKUP NOTICE) written to
     * trip CoachService::CRISIS_PATTERNS the same way a real entry would —
     * restrained, no method or plan described, matching the tone the rest
     * of this seeder already uses rather than anything graphic.
     */
    private function seedTherapistBridge(User $client): void
    {
        if (TherapistClient::where('client_id', $client->id)->exists()) {
            $this->command->warn('  Therapist bridge demo already seeded — skipping.');
            return;
        }

        $therapist = User::firstOrCreate(
            ['email' => 'therapist@example.com'],
            [
                'name'     => 'Dr. Amel Chraiti',
                'role'     => 'therapist',
                'password' => Hash::make('password'),
            ]
        );
        // firstOrCreate only sets these on first INSERT — force them so a
        // partially-run prior seed (e.g. the account existed from
        // elsewhere) still ends up with the right demo role/code.
        $therapist->forceFill(['role' => 'therapist', 'invite_code' => 'DEMOCARE'])->save();

        $link = TherapistClient::create([
            'therapist_id' => $therapist->id,
            'client_id'    => $client->id,
        ]);

        // ── Thread A: 5 days ago, reviewed, with a short reply exchange ────
        $atA = now()->subDays(5)->setTime(22, 40);
        $entryA = $this->crisisEntry(
            $client, $atA,
            "I don't know how much more of this I can take. Everything feels heavy and pointless right now — honestly there's no reason to live like this some days. I'm just so tired.",
            'exhausted', -0.6
        );
        $eventA = new SafetyEvent();
        $eventA->user_id     = $client->id;
        $eventA->entry_id    = $entryA->id;
        $eventA->reviewed_at = $atA->copy()->addHours(10);
        $eventA->timestamps  = false;
        $eventA->created_at  = $atA;
        $eventA->updated_at  = $atA;
        $eventA->save();

        $this->crisisMessage($link, $eventA, $therapist, "Hi — I saw your check-in from last night and wanted to reach out directly. How are you doing today?", $atA->copy()->addHours(11));
        $this->crisisMessage($link, $eventA, $client, "Thank you for reaching out. Today's a bit better honestly, just needed to get it out of my head last night.", $atA->copy()->addHours(12));
        $this->crisisMessage($link, $eventA, $therapist, "I'm really glad it's a bit lighter today. Let's still find time to talk properly this week — does Thursday work for a call?", $atA->copy()->addHours(13));

        // ── Thread B: yesterday, unreviewed, no replies yet ────────────────
        $atB = now()->subDay()->setTime(23, 10);
        $entryB = $this->crisisEntry(
            $client, $atB,
            "Another rough night. I keep telling myself it'll pass but I want to disappear forever some nights, if I'm honest. Not planning anything, just so tired of feeling like this.",
            'drained', -0.7
        );
        $eventB = new SafetyEvent();
        $eventB->user_id  = $client->id;
        $eventB->entry_id = $entryB->id;
        $eventB->timestamps = false;
        $eventB->created_at = $atB;
        $eventB->updated_at = $atB;
        $eventB->save();

        $this->command->line("  Seeded: therapist bridge demo — log in as therapist@example.com / password to see the therapist side (client is already linked, invite code DEMOCARE).");
    }

    /**
     * One journal entry + minimal analysis + coach acknowledgment, shaped
     * like a crisis-flagged entry would actually look after going through
     * EntryExtractionService and CoachService::handleCrisis() — distinct
     * from seedEntries()'s normal batches, which never set distress_flag.
     */
    private function crisisEntry(User $client, Carbon $at, string $body, string $mood, float $sentimentScore): Entry
    {
        $intent = DailyIntent::firstOrCreate(
            ['user_id' => $client->id, 'date' => $at->toDateString()],
            ['pick' => 'witness']
        );

        $entry                  = new Entry();
        $entry->user_id         = $client->id;
        $entry->daily_intent_id = $intent->id;
        $entry->body            = $body;
        $entry->in_vault        = false;
        $entry->timestamps      = false;
        $entry->created_at      = $at;
        $entry->updated_at      = $at;
        $entry->save();

        $analysis                        = new EntryAnalysis();
        $analysis->entry_id              = $entry->id;
        $analysis->mood                  = $mood;
        $analysis->sentiment             = 'negative';
        $analysis->rumination_score      = 0.006;
        $analysis->self_ref_density      = 0.600;
        $analysis->confidence            = 0.800;
        $analysis->distress_flag         = true;
        $analysis->openness              = 0.300;
        $analysis->emotions              = [['emotion' => 'sadness', 'intensity' => 8], ['emotion' => 'anxiety', 'intensity' => 5]];
        $analysis->needs                 = [['need' => 'safety', 'moment' => null], ['need' => 'connection', 'moment' => null]];
        $analysis->extraction_confidence = 0.75;
        $analysis->raw_json              = [
            'mood' => $mood, 'sentiment' => 'negative', 'people' => [], 'places' => [], 'topics' => [],
            'emotions' => $analysis->emotions, 'needs' => $analysis->needs, 'confidence' => 0.75,
        ];
        $analysis->timestamps = false;
        $analysis->created_at = $at;
        $analysis->updated_at = $at;
        $analysis->save();

        // Mirrors the shape of CoachService::handleCrisis()'s reply (brief
        // acknowledgment + pointing toward the config/crisis.php resource
        // line) without hardcoding that env-driven contact info here.
        $coachTime       = $at->copy()->addMinutes(2);
        $msg             = new CoachMessage();
        $msg->entry_id   = $entry->id;
        $msg->mode       = 'crisis';
        $msg->body       = "That's a lot to be carrying right now. If you're in immediate danger, please reach out to local emergency services or a crisis line — you don't have to carry this alone tonight.";
        $msg->reason     = 'demo_seeder_crisis';
        $msg->timestamps = false;
        $msg->created_at = $coachTime;
        $msg->updated_at = $coachTime;
        $msg->save();

        return $entry;
    }

    /**
     * 2026-08-17 redesign: messages anchor on the therapist_client LINK
     * (the one persistent conversation), with safety_event_id kept as
     * optional context for which flag, if any, a message is a direct
     * reply about — see CrisisMessage's docblock.
     */
    private function crisisMessage(TherapistClient $link, SafetyEvent $event, User $sender, string $body, Carbon $at): void
    {
        $msg                       = new CrisisMessage();
        $msg->therapist_client_id  = $link->id;
        $msg->safety_event_id      = $event->id;
        $msg->sender_id            = $sender->id;
        $msg->body                 = $body;
        $msg->timestamps           = false;
        $msg->created_at           = $at;
        $msg->updated_at           = $at;
        $msg->save();
    }

    /**
     * Shared entry/analysis/coach-message creation loop, used for both the
     * original narrative batch and the schema-v2 showcase batch. $marker
     * becomes the CoachMessage.reason, which is also what alreadySeeded()
     * checks against, so each batch can be added independently.
     */
    private function seedEntries(User $user, array $entityMap, array $entryData, string $marker): void
    {
        foreach ($entryData as $ed) {
            $date = Carbon::now()
                ->subDays($ed['days_ago'])
                ->setTime($ed['hour'], rand(5, 55));

            // A "days_ago => 0" entry with a fixed hour later than the actual
            // current time would otherwise get a created_at in the future.
            // That doesn't just look odd — it actively breaks the journal
            // thread: a real entry typed earlier today sorts *before* a
            // fake seeded one claiming to be from later today, so on
            // refresh the real entry gets displaced from the bottom of the
            // thread into the middle of it (and looks like it vanished,
            // since the view scrolls to the new, seeded "latest" entry
            // instead). Clamp to a few minutes before now instead.
            if ($date->isFuture()) {
                $date = Carbon::now()->subMinutes(rand(1, 90));
            }

            // One daily intent per calendar day (idempotent)
            $intent = DailyIntent::firstOrCreate(
                ['user_id' => $user->id, 'date' => $date->toDateString()],
                ['pick' => $ed['pick']]
            );

            // Entry — body encrypted by the model's 'encrypted' cast automatically
            $entry                  = new Entry();
            $entry->user_id         = $user->id;
            $entry->daily_intent_id = $intent->id;
            $entry->body            = $ed['body'];
            $entry->in_vault        = false;
            $entry->timestamps      = false; // prevent Eloquent from overwriting our dates
            $entry->created_at      = $date;
            $entry->updated_at      = $date;
            $entry->save();

            // Resolve entity IDs and the notable entity for this entry
            $entityIds       = [];
            $notableEntityId = null;

            foreach ($ed['entities'] as $key) {
                $entityIds[] = $entityMap[$key]->id;
            }
            if (!empty($ed['focus'])) {
                $notableEntityId = $entityMap[$ed['focus']]->id;
            }

            $people = in_array('sarah', $ed['entities']) ? ['Sarah'] : [];
            if (in_array('sister', $ed['entities'])) {
                $people[] = 'my sister';
            }
            $topics = array_values(array_filter(array_map(
                fn ($k) => match ($k) {
                    'walk'     => 'morning walk',
                    'deadline' => 'the deadline',
                    default    => null,
                },
                $ed['entities']
            )));

            // EntryAnalysis — raw_json mirrors what the extraction LLM would
            // return under schema v2 (see EntryExtractionService::systemPrompt()).
            // Entries without v2 data (the original narrative batch) simply
            // default every v2 field to empty/null, same as a real extraction
            // would for an entry with nothing notable in those categories.
            $analysis                     = new EntryAnalysis();
            $analysis->entry_id           = $entry->id;
            $analysis->mood               = $ed['mood'];
            $analysis->sentiment          = $ed['sentiment'];
            $analysis->rumination_score   = $ed['rumination'] ?? 0.002;
            $analysis->self_ref_density   = $ed['self_ref']   ?? 0.300;
            $analysis->confidence         = 0.850;
            $analysis->distress_flag      = false;
            $analysis->openness           = $ed['openness']   ?? 0.550;
            $analysis->notable_entity_id  = $notableEntityId;
            $analysis->emotions           = $ed['emotions']           ?? [];
            $analysis->stressors          = $ed['stressors']          ?? [];
            $analysis->protective_factors = $ed['protective_factors'] ?? [];
            $analysis->needs              = $ed['needs']              ?? [];
            $analysis->behaviors          = $ed['behaviors']          ?? [];
            $analysis->thinking_patterns  = $ed['thinking_patterns']  ?? [];
            $analysis->recovered          = $ed['recovered']          ?? null;
            $analysis->extraction_confidence = $ed['extraction_confidence'] ?? 0.85;
            $analysis->raw_json           = [
                'mood'                => $ed['mood'],
                'sentiment'           => $ed['sentiment'],
                'people'              => $people,
                'places'              => in_array('office', $ed['entities']) ? ['the office'] : [],
                'topics'              => $topics,
                'focus_dominant'      => $ed['focus_label'] ?? null,
                'strategy_hint'       => null,
                'strategy_entity'     => null,
                'emotions'            => $ed['emotions']           ?? [],
                'stressors'           => $ed['stressors']          ?? [],
                'protective_factors'  => $ed['protective_factors'] ?? [],
                'needs'               => $ed['needs']              ?? [],
                'behaviors'           => $ed['behaviors']          ?? [],
                'thinking_patterns'   => $ed['thinking_patterns']  ?? [],
                'recovered'           => $ed['recovered']          ?? null,
                'confidence'          => $ed['extraction_confidence'] ?? 0.85,
            ];
            $analysis->timestamps = false;
            $analysis->created_at = $date;
            $analysis->updated_at = $date;
            $analysis->save();

            // CoachMessage — body written in the coach's voice (warm, non-clinical,
            // tentative, matches the OFFER_DOOR / ENGAGE / WITNESS / HOLD_BACK modes)
            $coachTime       = $date->copy()->addMinutes(rand(3, 10));
            $msg             = new CoachMessage();
            $msg->entry_id   = $entry->id;
            $msg->mode       = $ed['coach_mode'];
            $msg->body       = $ed['coach_body'];
            $msg->reason     = $marker;
            $msg->timestamps = false;
            $msg->created_at = $coachTime;
            $msg->updated_at = $coachTime;
            $msg->save();

            // Pivot: link entities to this entry
            if (!empty($entityIds)) {
                $entry->entities()->syncWithoutDetaching($entityIds);
            }
        }
    }

    // ── Entry data ─────────────────────────────────────────────────────────────
    // Three weeks of narrative: low-grade deadline stress starts in week -2,
    // peaks and dominates week -1 (more negative → lower positive rate), then
    // resolves in week 0 with walking and reconnecting (high positive rate →
    // trend = "up" in the weekly reflection).
    //
    // Entity occurrences:
    //   morning walk  → 6 entries, all positive  → valence confirmed positive ✓
    //   the deadline  → 7 entries, mostly negative → valence confirmed negative ✓
    //   Sarah         → 2 entries, unconfirmed    → triggers the gentle-note flow ✓
    //   the office    → 3 entries, confirmed, no valence
    //
    // Sentiment breakdown:
    //   Week -2: 2 positive, 1 neutral, 1 negative  (positive rate 50 %)
    //   Week -1: 1 positive, 0 neutral, 3 negative  (positive rate 25 %)
    //   Week  0: 4 positive, 1 neutral, 0 negative  (positive rate 80 %)
    //   → week 0 vs week -1: 80 % > 25 % + 10 % threshold → trend = "up"
    private function entryData(): array
    {
        return [
            // ── Week −2 (days 14–20 ago) ──────────────────────────────────────
            [
                'days_ago'    => 20,
                'hour'        => 18,
                'pick'        => 'light',
                'body'        => "Big project deadline coming up next month and already the office feels different. Everyone's a bit on edge and I can feel myself starting to brace for it even though we've got plenty of time.",
                'mood'        => 'braced',
                'sentiment'   => 'negative',
                'rumination'  => 0.003,
                'openness'    => 0.500,
                'entities'    => ['deadline', 'office'],
                'focus'       => 'deadline',
                'focus_label' => 'the deadline',
                'coach_mode'  => 'offer_door',
                'coach_body'  => "That low hum of anticipation before a deadline — it tends to arrive earlier than the deadline itself. One thing that stands out: you noticed you're already bracing. Sometimes that awareness gives it a little less grip.",
            ],
            [
                'days_ago'    => 18,
                'hour'        => 7,
                'pick'        => 'witness',
                'body'        => "Went for a walk before work this morning and it genuinely changed the day. I don't know why I don't do that every day — everything just felt a bit lighter afterward.",
                'mood'        => 'clear',
                'sentiment'   => 'positive',
                'rumination'  => 0.001,
                'openness'    => 0.750,
                'entities'    => ['walk'],
                'focus'       => 'walk',
                'focus_label' => 'morning walk',
                'coach_mode'  => 'witness',
                'coach_body'  => "That clarity is worth holding onto. You noticed it — that's the part that actually matters.",
            ],
            [
                'days_ago'    => 16,
                'hour'        => 20,
                'pick'        => 'light',
                'body'        => "The deadline is starting to take up more mental space. I'm not panicking yet, but I'm aware of it in the background of everything. Trying to stay focused on one thing at a time.",
                'mood'        => 'measured',
                'sentiment'   => 'neutral',
                'rumination'  => 0.002,
                'openness'    => 0.600,
                'entities'    => ['deadline'],
                'focus'       => 'deadline',
                'focus_label' => 'the deadline',
                'coach_mode'  => 'offer_door',
                'coach_body'  => "Staying with one thing at a time while something bigger hums in the background — that's a pretty steady way to hold it. I wonder if the awareness itself is doing some of the work.",
            ],
            [
                'days_ago'    => 14,
                'hour'        => 8,
                'pick'        => 'engage',
                'body'        => "Another morning walk. It's becoming a thing. Met a neighbor I'd never spoken to and we talked for five minutes about nothing in particular and I felt completely human.",
                'mood'        => 'connected',
                'sentiment'   => 'positive',
                'rumination'  => 0.001,
                'openness'    => 0.800,
                'entities'    => ['walk'],
                'focus'       => 'walk',
                'focus_label' => 'morning walk',
                'coach_mode'  => 'witness',
                'coach_body'  => "Five minutes of nothing in particular — that's something, actually.",
            ],

            // ── Week −1 (days 7–13 ago) ───────────────────────────────────────
            [
                'days_ago'    => 12,
                'hour'        => 19,
                'pick'        => 'witness',
                'body'        => "Today was rough. The deadline moved up by almost two weeks and the whole team is scrambling. Sat in on a meeting that ran two hours longer than it should have and left feeling wrung out.",
                'mood'        => 'wrung out',
                'sentiment'   => 'negative',
                'rumination'  => 0.004,
                'openness'    => 0.400,
                'entities'    => ['deadline', 'office'],
                'focus'       => 'deadline',
                'focus_label' => 'the deadline',
                'coach_mode'  => 'offer_door',
                'coach_body'  => "That's a lot to absorb in one day — both the news and the meeting on top of it. 'Wrung out' is a specific feeling, not vague stress. Something there might be worth sitting with, if you want, or you can leave it here.",
            ],
            [
                'days_ago'    => 10,
                'hour'        => 18,
                'pick'        => 'light',
                'body'        => "Another brutal day at the office. I keep getting pulled into things that aren't my problem and it's eating the time I need for the actual deadline work. Feeling spread thin.",
                'mood'        => 'spread thin',
                'sentiment'   => 'negative',
                'rumination'  => 0.003,
                'openness'    => 0.350,
                'entities'    => ['deadline', 'office'],
                'focus'       => 'deadline',
                'focus_label' => 'the deadline',
                'coach_mode'  => 'engage',
                'coach_body'  => "Being pulled in when you need to stay focused — there might be something in why it's hard to hold that boundary in those moments, if you're curious about it.",
            ],
            [
                'days_ago'    => 9,
                'hour'        => 20,
                'pick'        => 'engage',
                'body'        => "Had coffee with Sarah after work and she just let me talk. Went for a walk on the way home and by the time I got back I felt like myself again. Some days that's what it takes.",
                'mood'        => 'restored',
                'sentiment'   => 'positive',
                'rumination'  => 0.002,
                'openness'    => 0.700,
                'entities'    => ['sarah', 'walk'],
                'focus'       => 'walk',
                'focus_label' => 'morning walk',
                'coach_mode'  => 'witness',
                'coach_body'  => "Some days that really is what it takes — the right person and a bit of space. You found both.",
            ],
            [
                'days_ago'    => 7,
                'hour'        => 22,
                'pick'        => 'rest',
                'body'        => "Crunch time. Staying late every day this week and even when I'm home I'm thinking about the project. Hard to switch off. I know this is temporary but temporary is still happening right now.",
                'mood'        => 'grinding',
                'sentiment'   => 'negative',
                'rumination'  => 0.004,
                'openness'    => 0.300,
                'entities'    => ['deadline'],
                'focus'       => 'deadline',
                'focus_label' => 'the deadline',
                'coach_mode'  => 'hold_back',
                'coach_body'  => "Temporary is still happening right now. That's an honest thing to say. It's okay to set this down for tonight.",
            ],

            // ── Week 0 (days 0–6 ago, this week) ─────────────────────────────
            [
                'days_ago'    => 5,
                'hour'        => 7,
                'pick'        => 'witness',
                'body'        => "Made myself go for a walk even though I was exhausted. Within ten minutes my shoulders dropped. I keep being surprised by how reliable that is.",
                'mood'        => 'relieved',
                'sentiment'   => 'positive',
                'rumination'  => 0.001,
                'openness'    => 0.700,
                'entities'    => ['walk'],
                'focus'       => 'walk',
                'focus_label' => 'morning walk',
                'coach_mode'  => 'engage',
                'coach_body'  => "You keep being surprised by it — I'm curious what it would take for it to stop feeling like a surprise.",
            ],
            [
                'days_ago'    => 4,
                'hour'        => 7,
                'pick'        => 'light',
                'body'        => "Walked again this morning. The deadline is still there but it feels like something I'm moving alongside rather than something chasing me. That's new.",
                'mood'        => 'steady',
                'sentiment'   => 'positive',
                'rumination'  => 0.002,
                'openness'    => 0.650,
                'entities'    => ['walk', 'deadline'],
                'focus'       => 'walk',
                'focus_label' => 'morning walk',
                'coach_mode'  => 'offer_door',
                'coach_body'  => "Moving alongside it rather than being chased — that shift sometimes means something changed before the situation did. Worth noticing.",
            ],
            [
                'days_ago'    => 3,
                'hour'        => 19,
                'pick'        => 'witness',
                'body'        => "Submitted the main deliverable. It's not perfect but it's done and that matters more right now. Strange feeling — relief, and also this weird low-level flatness I wasn't expecting.",
                'mood'        => 'flat',
                'sentiment'   => 'neutral',
                'rumination'  => 0.002,
                'openness'    => 0.600,
                'entities'    => ['deadline'],
                'focus'       => 'deadline',
                'focus_label' => 'the deadline',
                'coach_mode'  => 'offer_door',
                'coach_body'  => "There's something about the flatness after a long push — almost like the body doesn't know what to do when the pressure lifts. You can sit with that, or just let it pass.",
            ],
            [
                'days_ago'    => 1,
                'hour'        => 13,
                'pick'        => 'engage',
                'body'        => "Lunch with Sarah today. It's easy to forget how much I get from actually talking to people when I've been in my own head for too long. She asked good questions.",
                'mood'        => 'lightened',
                'sentiment'   => 'positive',
                'rumination'  => 0.001,
                'openness'    => 0.800,
                'entities'    => ['sarah'],
                'focus'       => 'sarah',
                'focus_label' => 'Sarah',
                'coach_mode'  => 'witness',
                'coach_body'  => "Easy to forget, and then suddenly you remember. That's a good kind of reminder.",
            ],
            [
                'days_ago'    => 0,
                'hour'        => 7,
                'pick'        => 'light',
                'body'        => "Morning walk, first one since the deadline crunch ended. Everything looked the same as always and somehow better. Back to myself a bit.",
                'mood'        => 'grounded',
                'sentiment'   => 'positive',
                'rumination'  => 0.001,
                'openness'    => 0.750,
                'entities'    => ['walk'],
                'focus'       => 'walk',
                'focus_label' => 'morning walk',
                'coach_mode'  => 'offer_door',
                'coach_body'  => "Back to yourself a bit. That's enough for today.",
            ],
        ];
    }

    // ── Schema-v2 showcase batch ─────────────────────────────────────────────
    // All dated today (days_ago = 0) since today is the first day of the ISO
    // week — there's no earlier day in *this* week to backdate into. Spread
    // across different hours to look like a day's worth of entries. This is
    // what exercises WeeklyReflectionController's new dominant_emotion /
    // top_stressor / top_protective_factor / top_behavior / top_need /
    // top_thinking_pattern aggregates, since only current-week entries feed
    // GET /api/reflections/current.
    //
    // Expected aggregate winners with this data (weighted by impact/intensity,
    // ties broken by insertion order — see WeeklyReflectionController::topTag()):
    //   dominant_emotion      → calm   (appears in 3/6 reliable entries)
    //   top_stressor          → work   (meetings + workload + deadline)
    //   top_protective_factor → friends or exercise (close — both weight 7-8, one mention each)
    //   top_behavior          → exercised / reached_out / procrastinated (three-way tie at weight 7)
    //   top_need              → rest   (appears in 3/6 reliable entries)
    //   top_thinking_pattern  → rumination (tied with self_criticism, wins on insertion order)
    // The 7th entry ("eh.") has confidence 0.15, below the 0.4 floor, so it's
    // excluded from all six — a deliberate demonstration of the confidence filter.
    private function entryDataThisWeek(): array
    {
        return [
            [
                'days_ago'   => 0,
                'hour'       => 10,
                'pick'       => 'light',
                'body'       => "New sprint kicked off today and already back-to-back meetings all morning. Feels like the deadline crunch never really ended, just changed shape.",
                'mood'       => 'overwhelmed',
                'sentiment'  => 'negative',
                'entities'   => ['deadline'],
                'focus'      => 'deadline',
                'focus_label'=> 'the deadline',
                'emotions'   => [['emotion' => 'anxiety', 'intensity' => 7], ['emotion' => 'sadness', 'intensity' => 3]],
                'stressors'  => [
                    ['category' => 'work', 'subcategory' => 'meetings', 'impact' => 7],
                    ['category' => 'work', 'subcategory' => 'workload', 'impact' => 6],
                ],
                'needs'      => [['need' => 'rest', 'moment' => null], ['need' => 'certainty', 'moment' => null]],
                'behaviors'  => [['behavior' => 'overcommitted', 'impact' => 6]],
                'thinking_patterns' => [['pattern' => 'catastrophizing', 'evidence' => 'feels like the deadline crunch never really ended']],
                'recovered'  => false,
                'extraction_confidence' => 0.82,
                'coach_mode' => 'offer_door',
                'coach_body' => "One shape of pressure replaced by another — that makes sense as a reason it doesn't feel over. No pressure to solve that today.",
            ],
            [
                'days_ago'   => 0,
                'hour'       => 12,
                'pick'       => 'witness',
                'body'       => "Snuck out for a walk at lunch even with the chaos. Ten minutes outside completely reset my head. Should really protect that time every day.",
                'mood'       => 'refreshed',
                'sentiment'  => 'positive',
                'entities'   => ['walk'],
                'focus'      => 'walk',
                'focus_label'=> 'morning walk',
                'emotions'   => [['emotion' => 'calm', 'intensity' => 7], ['emotion' => 'joy', 'intensity' => 4]],
                'protective_factors' => [
                    ['factor' => 'exercise', 'impact' => 7],
                    ['factor' => 'nature', 'impact' => 5],
                ],
                'needs'      => [['need' => 'rest', 'moment' => null]],
                'behaviors'  => [['behavior' => 'exercised', 'impact' => 7]],
                'recovered'  => true,
                'extraction_confidence' => 0.88,
                'coach_mode' => 'witness',
                'coach_body' => "Ten minutes and a full reset — you already know what works.",
            ],
            [
                'days_ago'   => 0,
                'hour'       => 14,
                'pick'       => 'engage',
                'body'       => "Argued with my sister on the phone about something small that turned into something bigger. I know I got defensive faster than I needed to. Still thinking about it hours later.",
                'mood'       => 'irritable',
                'sentiment'  => 'negative',
                'entities'   => ['sister'],
                'focus'      => 'sister',
                'focus_label'=> 'my sister',
                'emotions'   => [['emotion' => 'anger', 'intensity' => 6], ['emotion' => 'guilt', 'intensity' => 4]],
                'stressors'  => [
                    ['category' => 'relationships', 'subcategory' => 'family', 'impact' => 7],
                    ['category' => 'relationships', 'subcategory' => 'conflict', 'impact' => 6],
                ],
                'needs'      => [['need' => 'connection', 'moment' => null], ['need' => 'validation', 'moment' => null]],
                'thinking_patterns' => [['pattern' => 'rumination', 'evidence' => 'still thinking about it hours later']],
                'recovered'  => false,
                'extraction_confidence' => 0.79,
                'coach_mode' => 'engage',
                'coach_body' => "Getting defensive faster than you meant to, and noticing it after the fact — that noticing is worth something on its own. What usually happens right before that switch flips, if you're curious?",
            ],
            [
                'days_ago'   => 0,
                'hour'       => 17,
                'pick'       => 'witness',
                'body'       => "Told Sarah about the fight with my sister and she just listened without trying to fix it. Felt so much lighter after. I forget how much I need that sometimes.",
                'mood'       => 'relieved',
                'sentiment'  => 'positive',
                'entities'   => ['sarah'],
                'focus'      => 'sarah',
                'focus_label'=> 'Sarah',
                'emotions'   => [['emotion' => 'calm', 'intensity' => 6], ['emotion' => 'love', 'intensity' => 5]],
                'protective_factors' => [['factor' => 'friends', 'impact' => 8]],
                'needs'      => [['need' => 'connection', 'moment' => null]],
                'behaviors'  => [['behavior' => 'reached_out', 'impact' => 7]],
                'recovered'  => true,
                'extraction_confidence' => 0.86,
                'coach_mode' => 'witness',
                'coach_body' => "Being heard without being fixed — that's a specific kind of relief, and you found it today.",
            ],
            [
                'days_ago'   => 0,
                'hour'       => 19,
                'pick'       => 'light',
                'body'       => "Kept opening the report and closing it again all evening. Ended up scrolling my phone instead. I know exactly what I should be doing and somehow that makes it worse.",
                'mood'       => 'stuck',
                'sentiment'  => 'negative',
                'entities'   => ['deadline'],
                'focus'      => 'deadline',
                'focus_label'=> 'the deadline',
                'emotions'   => [['emotion' => 'shame', 'intensity' => 5], ['emotion' => 'anxiety', 'intensity' => 4]],
                'stressors'  => [['category' => 'work', 'subcategory' => 'deadline', 'impact' => 5]],
                'needs'      => [['need' => 'rest', 'moment' => null], ['need' => 'achievement', 'moment' => null]],
                'behaviors'  => [
                    ['behavior' => 'procrastinated', 'impact' => 7],
                    ['behavior' => 'avoided', 'impact' => 6],
                ],
                'thinking_patterns' => [['pattern' => 'self_criticism', 'evidence' => 'I know exactly what I should be doing and somehow that makes it worse']],
                'recovered'  => null,
                'extraction_confidence' => 0.81,
                'coach_mode' => 'offer_door',
                'coach_body' => "Knowing what to do and still not doing it isn't a discipline problem as often as it feels like one. One thing that stands out: you noticed the loop while you were in it.",
            ],
            [
                'days_ago'   => 0,
                'hour'       => 21,
                'pick'       => 'rest',
                'body'       => "Tired. Going to bed early tonight.",
                'mood'       => 'tired',
                'sentiment'  => 'neutral',
                'entities'   => [],
                'focus'      => null,
                'focus_label'=> null,
                'emotions'   => [['emotion' => 'calm', 'intensity' => 3]],
                'protective_factors' => [['factor' => 'sleep', 'impact' => 6]],
                'needs'      => [['need' => 'rest', 'moment' => null]],
                'behaviors'  => [['behavior' => 'slept_early', 'impact' => 6]],
                'recovered'  => null,
                'extraction_confidence' => 0.55,
                'coach_mode' => 'hold_back',
                'coach_body' => "Good call. This will still be here tomorrow.",
            ],
            [
                'days_ago'   => 0,
                'hour'       => 22,
                'pick'       => 'rest',
                'body'       => "eh.",
                'mood'       => 'unclear',
                'sentiment'  => 'neutral',
                'entities'   => [],
                'focus'      => null,
                'focus_label'=> null,
                'emotions'   => [],
                'extraction_confidence' => 0.15, // deliberately below the 0.4 aggregate floor
                'coach_mode' => 'hold_back',
                'coach_body' => "Noted. No need to say more than that tonight.",
            ],
        ];
    }

    // ── Pattern-chain demo batch ─────────────────────────────────────────────
    // PatternController requires a trigger (stressor subcategory) to show up
    // in >= 3 reliable entries before it'll compute a chain for it, and each
    // linked emotion/thinking_pattern/behavior needs >= 2 co-occurrences to be
    // reported — neither the 3-week narrative nor the this-week showcase batch
    // repeats any single stressor subcategory that often, so without this,
    // GET /api/patterns returns an empty list on a fresh seed. Spread across
    // the past two weeks (not today) so it sits alongside, not on top of, the
    // this-week showcase batch.
    //
    // Two deliberate chains:
    //   self/uncertainty  -> anxiety   -> catastrophizing (2/3) -> procrastinated
    //   relationships/conflict -> anger -> self_criticism (2/3) -> isolated
    private function entryDataPatternDemo(): array
    {
        return [
            // ── "uncertainty" chain (3 entries) ──────────────────────────────
            [
                'days_ago'   => 2,
                'hour'       => 9,
                'pick'       => 'engage',
                'body'       => "Still not sure I made the right call turning down that project. Keep running the decision back through my head and I can't tell anymore if it was smart or just scared.",
                'mood'       => 'unsettled',
                'sentiment'  => 'negative',
                'entities'   => [],
                'focus'      => null,
                'focus_label'=> null,
                'emotions'   => [['emotion' => 'anxiety', 'intensity' => 7]],
                'stressors'  => [['category' => 'self', 'subcategory' => 'uncertainty', 'impact' => 7]],
                'needs'      => [['need' => 'certainty', 'moment' => null]],
                'thinking_patterns' => [['pattern' => 'catastrophizing', 'evidence' => "can't tell anymore if it was smart or just scared"]],
                'behaviors'  => [['behavior' => 'procrastinated', 'impact' => 6]],
                'recovered'  => false,
                'extraction_confidence' => 0.8,
                'coach_mode' => 'engage',
                'coach_body' => "Replaying a decision looking for the 'right' answer often just means it didn't come with a guarantee attached — most don't. What's making this one feel so high-stakes to sit with?",
            ],
            [
                'days_ago'   => 6,
                'hour'       => 21,
                'pick'       => 'light',
                'body'       => "Don't know what's happening with the lease renewal and it's sitting in the back of my mind all week. Keep putting off calling to ask because I think I already know it's bad news.",
                'mood'       => 'anxious',
                'sentiment'  => 'negative',
                'entities'   => [],
                'focus'      => null,
                'focus_label'=> null,
                'emotions'   => [['emotion' => 'anxiety', 'intensity' => 6], ['emotion' => 'fear', 'intensity' => 3]],
                'stressors'  => [['category' => 'self', 'subcategory' => 'uncertainty', 'impact' => 6]],
                'needs'      => [['need' => 'certainty', 'moment' => null], ['need' => 'security', 'moment' => null]],
                'thinking_patterns' => [['pattern' => 'catastrophizing', 'evidence' => "I think I already know it's bad news"]],
                'behaviors'  => [['behavior' => 'avoided', 'impact' => 6]],
                'recovered'  => false,
                'extraction_confidence' => 0.78,
                'coach_mode' => 'offer_door',
                'coach_body' => "Not knowing yet and assuming the worst yet tend to travel together. One thing that stands out: the call itself might take five minutes; carrying the unknown around has taken a week.",
            ],
            [
                'days_ago'   => 11,
                'hour'       => 20,
                'pick'       => 'light',
                'body'       => "Waiting to hear back about the apartment application and it's driving me a little crazy. Every hour that passes without an email feels like it means something.",
                'mood'       => 'on edge',
                'sentiment'  => 'negative',
                'entities'   => [],
                'focus'      => null,
                'focus_label'=> null,
                'emotions'   => [['emotion' => 'anxiety', 'intensity' => 8]],
                'stressors'  => [['category' => 'self', 'subcategory' => 'uncertainty', 'impact' => 8]],
                'needs'      => [['need' => 'certainty', 'moment' => null]],
                'behaviors'  => [['behavior' => 'procrastinated', 'impact' => 5]],
                'recovered'  => null,
                'extraction_confidence' => 0.83,
                'coach_mode' => 'offer_door',
                'coach_body' => "Waiting rooms are their own kind of hard — nothing to do with the outcome yet, just the not-knowing. That part's real even before any answer comes back.",
            ],

            // ── "conflict" chain (3 entries) ─────────────────────────────────
            [
                'days_ago'   => 3,
                'hour'       => 19,
                'pick'       => 'witness',
                'body'       => "Snapped at my roommate over something small tonight and immediately felt awful about it. She wasn't even really wrong. I don't know why I go straight to defensive.",
                'mood'       => 'regretful',
                'sentiment'  => 'negative',
                'entities'   => [],
                'focus'      => null,
                'focus_label'=> null,
                'emotions'   => [['emotion' => 'anger', 'intensity' => 6], ['emotion' => 'guilt', 'intensity' => 4]],
                'stressors'  => [['category' => 'relationships', 'subcategory' => 'conflict', 'impact' => 7]],
                'needs'      => [['need' => 'connection', 'moment' => null]],
                'thinking_patterns' => [['pattern' => 'self_criticism', 'evidence' => "I don't know why I go straight to defensive"]],
                'behaviors'  => [['behavior' => 'isolated', 'impact' => 5]],
                'recovered'  => false,
                'extraction_confidence' => 0.81,
                'coach_mode' => 'witness',
                'coach_body' => "That gap between the reaction and how you actually feel about it after — you clearly see it clearly, even in the moment it happened.",
            ],
            [
                'days_ago'   => 8,
                'hour'       => 22,
                'pick'       => 'engage',
                'body'       => "Got into it with my brother again over the phone. Same argument as always. I ended up just going quiet and hanging up instead of actually saying what I meant.",
                'mood'       => 'frustrated',
                'sentiment'  => 'negative',
                'entities'   => [],
                'focus'      => null,
                'focus_label'=> null,
                'emotions'   => [['emotion' => 'anger', 'intensity' => 5]],
                'stressors'  => [['category' => 'relationships', 'subcategory' => 'conflict', 'impact' => 6]],
                'needs'      => [['need' => 'connection', 'moment' => null], ['need' => 'validation', 'moment' => null]],
                'thinking_patterns' => [['pattern' => 'self_criticism', 'evidence' => "instead of actually saying what I meant"]],
                'behaviors'  => [['behavior' => 'isolated', 'impact' => 6]],
                'recovered'  => false,
                'extraction_confidence' => 0.77,
                'coach_mode' => 'engage',
                'coach_body' => "Going quiet instead of saying it — that's worth being curious about, if you want: what usually happens right before you decide it's not worth finishing the sentence?",
            ],
            [
                'days_ago'   => 13,
                'hour'       => 18,
                'pick'       => 'light',
                'body'       => "Disagreement with a coworker turned tense fast today. I just shut down and let it drop instead of pushing back, even though I don't think I was wrong.",
                'mood'       => 'flat',
                'sentiment'  => 'negative',
                'entities'   => [],
                'focus'      => null,
                'focus_label'=> null,
                'emotions'   => [['emotion' => 'anger', 'intensity' => 7], ['emotion' => 'sadness', 'intensity' => 3]],
                'stressors'  => [['category' => 'relationships', 'subcategory' => 'conflict', 'impact' => 7]],
                'needs'      => [['need' => 'connection', 'moment' => null]],
                'behaviors'  => [['behavior' => 'isolated', 'impact' => 4]],
                'recovered'  => null,
                'extraction_confidence' => 0.76,
                'coach_mode' => 'offer_door',
                'coach_body' => "Letting it drop even while still believing you were right — there's a real cost to that kind of quiet. No pressure to resolve it tonight, just naming it.",
            ],
        ];
    }
}
