<?php

namespace Database\Seeders;

use App\Models\AssignedTask;
use App\Models\CoachMessage;
use App\Models\DailyIntent;
use App\Models\Entity;
use App\Models\Entry;
use App\Models\EntryAnalysis;
use App\Models\TherapistClient;
use App\Models\TherapySession;
use App\Models\User;
use App\Models\WeeklyReflection;
use App\Support\DemoSandbox;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Public portfolio demo (2026-10-15, per-visitor since 2026-10-25). Builds
 * ONE private sandbox for one visitor's browser -- App\Support\DemoSandbox
 * calls this with a random token the first time a browser clicks
 * "test user" / "test therapist":
 *
 *   1. creates the demo client (maya.<token>@sandbox.demo, onboarding done),
 *   2. seeds ~14 weeks of fictional history BEFORE the 3-week arc that
 *      DemoDataSeeder already tells, so the monthly trends page (and its
 *      zoom-out) has real ups and downs instead of three weeks of data,
 *   3. runs DemoDataSeeder against that client with its own therapist
 *      (amel.<token>@sandbox.demo) -- narrative, pattern chains, therapist
 *      link + crisis threads,
 *   4. runs TherapistPatientsSeeder with the same token (that therapist's
 *      own copy of the 4-client caseload),
 *   5. unlocks this week's reflection so visitors don't hit the Sunday seal,
 *   6. gives every account in the sandbox a random password -- the only way
 *      in is POST /api/demo/login from the browser holding the token.
 *
 * All dates are relative to now(), so every sandbox looks current. Sandboxes
 * are deleted 24h after creation (demo:prune, routes/console.php).
 *
 * Every entry here is fictional. The persona: works at an office, has a
 * rough autumn (workload, poor sleep, pulling away from people), climbs
 * out of it (walking, reconnecting with Sarah, starting therapy), and
 * then hits the deadline arc DemoDataSeeder picks up from there.
 */
class PublicDemoSeeder extends Seeder
{
    private const MARKER = 'public_demo_history';

    /**
     * 2026-10-25: builds ONE private sandbox (client + therapist + the 4
     * caseload patients), all with emails tagged by $token -- see
     * App\Support\DemoSandbox, which is what calls this. Nothing shared
     * between visitors.
     */
    public function run(string $token): void
    {
        DB::transaction(function () use ($token) {
            $client = User::create([
                'name'     => 'Maya Haddad',
                'email'    => DemoSandbox::email('maya', $token),
                'password' => Hash::make(Str::random(40)),
                'role'     => 'client',
            ]);

            $client->preferences()->create([
                'tone'                 => 'gentle',
                'nudge_level'          => 2,
                'language'             => 'en',
                'product_mode'         => 'bridge',
                'theme'                => 'dusk',
                'top_values'           => ['connection', 'growth', 'calm'],
                'onboarding_completed' => true,
            ]);

            mt_srand(20261015); // same history every reset, only the dates move
            $this->seedHistory($client);

            $therapistEmail = DemoSandbox::email('amel', $token);
            $this->callWith(DemoDataSeeder::class, [
                'userId'         => $client->id,
                'therapistEmail' => $therapistEmail,
                'inviteCode'     => 'DEMO-'.strtoupper($token),
            ]);
            $this->tagLegacyNarrative($client);
            $this->callWith(TherapistPatientsSeeder::class, ['therapistEmail' => $therapistEmail, 'token' => $token]);

            $this->fleshOutTherapistLink($client);

            $this->unlockThisWeeksReflection($client);
            $this->scramblePasswords($token);
        });
    }

    /**
     * DemoDataSeeder links the client "today" with nothing scheduled. For
     * the showcase, backdate the link to when the history says therapy
     * started (~7 weeks ago), turn on live trends so the therapist can
     * open the client's trend view, and add a little session/task history
     * so the roster card isn't empty.
     */
    private function fleshOutTherapistLink(User $client): void
    {
        $link = TherapistClient::where('client_id', $client->id)->first();
        if (! $link) {
            return;
        }

        $since = now()->subDays(49);
        $link->forceFill(['live_trends_enabled' => true, 'created_at' => $since, 'updated_at' => $since]);
        $link->timestamps = false;
        $link->save();

        $therapistId = $link->therapist_id;

        foreach ([
            [now()->subDays(49)->setTime(18, 0), 'attended', 'Intake. Work overload, poor sleep, pulling away from friends. Agreed on a daily walk and one reach-out a week.'],
            [now()->subDays(28)->setTime(18, 0), 'attended', 'Walks are sticking. Reconnected with Sarah. Talked about saying no to the extra workshop — she did it the next day.'],
            [now()->subDays(7)->setTime(18, 0),  'attended', 'Deadline crunch is back. Noticing the catastrophizing faster now. Keep the phone-out-of-the-bedroom rule.'],
            [now()->addDays(4)->setTime(18, 0),  null, null],
        ] as [$at, $attendance, $note]) {
            $s               = new TherapySession();
            $s->therapist_id = $therapistId;
            $s->client_id    = $client->id;
            $s->scheduled_at = $at;
            $s->attendance   = $attendance;
            $s->outcome_note = $note;
            $s->timestamps   = false;
            $s->created_at   = $at->copy()->subDays(7);
            $s->updated_at   = $attendance ? $at->copy()->addHour() : $at->copy()->subDays(7);
            $s->save();
        }

        foreach ([
            ['Morning walk, 10 minutes, 5 days this week', null, now()->subDays(40), now()->subDays(41)],
            ['When the "everything is falling apart" thought shows up, write the next concrete step instead', 'One line is enough. The point is catching it, not fixing it.', now()->addDays(4), null],
        ] as [$title, $instructions, $due, $done]) {
            $t               = new AssignedTask();
            $t->therapist_id = $therapistId;
            $t->client_id    = $client->id;
            $t->title        = $title;
            $t->instructions = $instructions;
            $t->due_date     = $due->toDateString();
            $t->completed_at = $done;
            $t->timestamps   = false;
            $t->created_at   = $due->copy()->subDays(7);
            $t->updated_at   = $done ?? $t->created_at;
            $t->save();
        }
    }

    private function scramblePasswords(string $token): void
    {
        DemoSandbox::users($token)->each(function (User $u) {
            $u->forceFill(['password' => Hash::make(Str::random(40))])->save();
        });
    }

    /**
     * WeeklyReflectionController::current() returns 423 until Sunday
     * night. A row that's still 'sealed' but already past unlock_at gets
     * generated on first view (pure aggregation, no LLM call) and is then
     * cached like any real week -- so no global REFLECTION_DEV_BYPASS
     * needed in production.
     */
    private function unlockThisWeeksReflection(User $client): void
    {
        $weekStart = now()->startOfWeek(Carbon::MONDAY)->toDateString();

        WeeklyReflection::where('user_id', $client->id)->whereDate('week_start', $weekStart)->delete();

        WeeklyReflection::create([
            'user_id'    => $client->id,
            'week_start' => $weekStart,
            'status'     => 'sealed',
            'unlock_at'  => now()->subMinute(),
            'content'    => null,
        ]);
    }

    /**
     * DemoDataSeeder's original 3-week narrative predates extraction schema
     * v2, so those entries carry no emotions/stressors/needs/etc. On the
     * monthly trends page that shows up as three weeks of flat zeros right
     * before "now". For the public demo only, give them the tags real
     * extraction would plausibly have produced, keyed off what each entry
     * is already about (its topics/people + sentiment). DemoDataSeeder
     * itself is left untouched.
     */
    private function tagLegacyNarrative(User $client): void
    {
        $analyses = EntryAnalysis::whereIn('entry_id', $client->entries()->pluck('id'))
            ->where('distress_flag', false)
            ->get()
            ->filter(fn (EntryAnalysis $a) => empty($a->emotions));

        foreach ($analyses as $a) {
            $raw      = $a->raw_json ?? [];
            $topics   = $raw['topics'] ?? [];
            $people   = $raw['people'] ?? [];
            $deadline = in_array('the deadline', $topics, true);
            $walk     = in_array('morning walk', $topics, true);
            $sarah    = in_array('Sarah', $people, true);

            $t = ['emotions' => [], 'stressors' => [], 'protective_factors' => [], 'needs' => [], 'behaviors' => [], 'thinking_patterns' => []];

            if ($deadline && $a->sentiment === 'negative') {
                $t['emotions'][]          = ['emotion' => 'overwhelm', 'intensity' => 7];
                $t['emotions'][]          = ['emotion' => 'anxiety', 'intensity' => 6];
                $t['stressors'][]         = ['category' => 'work', 'subcategory' => 'deadline', 'impact' => 7];
                $t['needs'][]             = ['need' => 'rest', 'moment' => null];
                $t['behaviors'][]         = ['behavior' => 'worked_late', 'impact' => 6];
                $t['thinking_patterns'][] = ['pattern' => 'catastrophizing', 'evidence' => null];
            } elseif ($deadline) {
                $t['emotions'][]  = ['emotion' => 'anxiety', 'intensity' => 4];
                $t['stressors'][] = ['category' => 'work', 'subcategory' => 'deadline', 'impact' => 4];
                $t['needs'][]     = ['need' => 'certainty', 'moment' => null];
            }
            if ($walk) {
                $t['emotions'][]           = ['emotion' => 'calm', 'intensity' => 7];
                $t['protective_factors'][] = ['factor' => 'exercise', 'impact' => 7];
                $t['protective_factors'][] = ['factor' => 'nature', 'impact' => 5];
                $t['behaviors'][]          = ['behavior' => 'exercised', 'impact' => 7];
            }
            if ($sarah) {
                $t['emotions'][]           = ['emotion' => 'gratitude', 'intensity' => 6];
                $t['protective_factors'][] = ['factor' => 'friends', 'impact' => 8];
                $t['needs'][]              = ['need' => 'connection', 'moment' => null];
                $t['behaviors'][]          = ['behavior' => 'reached_out', 'impact' => 7];
            }
            if (! $deadline && ! $walk && ! $sarah) {
                $t['emotions'][] = ['emotion' => $a->sentiment === 'positive' ? 'relief' : 'confusion', 'intensity' => 4];
            }

            foreach ($t as $field => $value) {
                $a->{$field} = $value;
                $raw[$field] = $value;
            }
            $a->raw_json   = $raw;
            $a->timestamps = false;
            $a->save();
        }

        $this->command?->line("  Tagged {$analyses->count()} pre-v2 narrative entries for the monthly trends.");
    }

    // ── History (days 22–119 ago) ─────────────────────────────────────────

    private function seedHistory(User $user): void
    {
        $entities = [
            'office'   => Entity::create(['user_id' => $user->id, 'name' => 'the office',   'type' => 'place', 'trigger_status' => 'confirmed']),
            'deadline' => Entity::create(['user_id' => $user->id, 'name' => 'the deadline', 'type' => 'topic', 'trigger_status' => 'confirmed', 'valence' => 'negative']),
            'walk'     => Entity::create(['user_id' => $user->id, 'name' => 'morning walk', 'type' => 'topic', 'trigger_status' => 'confirmed', 'valence' => 'positive']),
            'sarah'    => Entity::create(['user_id' => $user->id, 'name' => 'Sarah',        'type' => 'person', 'trigger_status' => 'unconfirmed']),
            'sister'   => Entity::create(['user_id' => $user->id, 'name' => 'my sister',    'type' => 'person', 'trigger_status' => 'unconfirmed']),
            'manager'  => Entity::create(['user_id' => $user->id, 'name' => 'Hédi (manager)', 'type' => 'person', 'trigger_status' => 'confirmed', 'valence' => 'negative']),
            'flat'     => Entity::create(['user_id' => $user->id, 'name' => 'the new flat', 'type' => 'place', 'trigger_status' => 'unconfirmed']),
        ];

        $phases = [
            // [first day-ago, last day-ago, template pool, entries per week]
            [119, 92, $this->settlingIn(),   3],
            [91,  57, $this->roughPatch(),   4],
            [56,  22, $this->climbingOut(),  4],
        ];

        $count = 0;
        foreach ($phases as [$from, $to, $pool, $perWeek]) {
            $queue = $pool;
            for ($weekEnd = $from; $weekEnd > $to; $weekEnd -= 7) {
                $days = $this->spreadDays(max($weekEnd - 6, $to + 1), $weekEnd, $perWeek);
                foreach ($days as $daysAgo) {
                    if (empty($queue)) {
                        $queue = $pool; // recycle only after the whole pool is used
                    }
                    $t = array_shift($queue);
                    $this->seedEntry($user, $entities, $t, $daysAgo);
                    $count++;
                }
            }
        }

        $this->command?->line("  Seeded: {$count} history entries (settling in → rough patch → climbing out).");
    }

    /** $n distinct days in [$lo, $hi] (days ago), newest last. */
    private function spreadDays(int $lo, int $hi, int $n): array
    {
        $range = range($lo, $hi);
        shuffle($range);
        $picked = array_slice($range, 0, min($n, count($range)));
        rsort($picked);
        return $picked;
    }

    private function seedEntry(User $user, array $entities, array $t, int $daysAgo): void
    {
        $date = Carbon::now()->subDays($daysAgo)->setTime($t['hour'], mt_rand(3, 57));

        $intent = DailyIntent::firstOrCreate(
            ['user_id' => $user->id, 'date' => $date->toDateString()],
            ['pick' => $t['pick']]
        );

        $entry                  = new Entry();
        $entry->user_id         = $user->id;
        $entry->daily_intent_id = $intent->id;
        $entry->body            = $t['body'];
        $entry->in_vault        = false;
        $entry->timestamps      = false;
        $entry->created_at      = $date;
        $entry->updated_at      = $date;
        $entry->save();

        $keys     = $t['entities'] ?? [];
        $focusId  = isset($t['focus']) ? $entities[$t['focus']]->id : null;
        $people   = [];
        $places   = [];
        $topics   = [];
        foreach ($keys as $k) {
            $e = $entities[$k];
            match ($e->type) {
                'person' => $people[] = $e->name,
                'place'  => $places[] = $e->name,
                default  => $topics[] = $e->name,
            };
        }

        $fields = ['emotions', 'stressors', 'protective_factors', 'needs', 'behaviors', 'thinking_patterns'];

        $a                        = new EntryAnalysis();
        $a->entry_id              = $entry->id;
        $a->mood                  = $t['mood'];
        $a->sentiment             = $t['sentiment'];
        $a->rumination_score      = $t['sentiment'] === 'negative' ? 0.004 : 0.002;
        $a->self_ref_density      = 0.350;
        $a->confidence            = 0.850;
        $a->distress_flag         = false;
        $a->openness              = $t['sentiment'] === 'positive' ? 0.700 : 0.500;
        $a->notable_entity_id     = $focusId;
        foreach ($fields as $f) {
            $a->{$f} = $t[$f] ?? [];
        }
        $a->recovered             = $t['recovered'] ?? null;
        $a->extraction_confidence = 0.84;
        $a->raw_json              = array_merge([
            'mood'            => $t['mood'],
            'sentiment'       => $t['sentiment'],
            'people'          => $people,
            'places'          => $places,
            'topics'          => $topics,
            'focus_dominant'  => isset($t['focus']) ? $entities[$t['focus']]->name : null,
            'strategy_hint'   => null,
            'strategy_entity' => null,
            'recovered'       => $t['recovered'] ?? null,
            'confidence'      => 0.84,
        ], array_combine($fields, array_map(fn ($f) => $t[$f] ?? [], $fields)));
        $a->timestamps = false;
        $a->created_at = $date;
        $a->updated_at = $date;
        $a->save();

        $coachAt         = $date->copy()->addMinutes(mt_rand(3, 9));
        $msg             = new CoachMessage();
        $msg->entry_id   = $entry->id;
        $msg->mode       = $t['coach_mode'];
        $msg->body       = $t['coach'];
        $msg->reason     = self::MARKER;
        $msg->timestamps = false;
        $msg->created_at = $coachAt;
        $msg->updated_at = $coachAt;
        $msg->save();

        if ($keys) {
            $entry->entities()->syncWithoutDetaching(array_map(fn ($k) => $entities[$k]->id, $keys));
        }
    }

    // ── Entry templates ───────────────────────────────────────────────────
    // Labels come from App\Support\ExtractionTaxonomy so every tag lands in
    // the same buckets real extraction would produce.

    private function settlingIn(): array
    {
        return [
            [
                'hour' => 21, 'pick' => 'light', 'mood' => 'unsettled', 'sentiment' => 'neutral',
                'body' => "First full week in the new flat. Boxes still everywhere and I keep reaching for things in the wrong drawer. It's quieter than I expected — good quiet, mostly.",
                'entities' => ['flat'], 'focus' => 'flat',
                'emotions' => [['emotion' => 'curiosity', 'intensity' => 5], ['emotion' => 'loneliness', 'intensity' => 3]],
                'stressors' => [['category' => 'self', 'subcategory' => 'life_transition', 'impact' => 5]],
                'needs' => [['need' => 'security', 'moment' => null]],
                'behaviors' => [['behavior' => 'planned_ahead', 'impact' => 4]],
                'coach_mode' => 'witness', 'coach' => "Good quiet, mostly — that's an honest way to put a new place.",
            ],
            [
                'hour' => 19, 'pick' => 'engage', 'mood' => 'hopeful', 'sentiment' => 'positive',
                'body' => "Got put on the client project today. Hédi said he picked me because I'm 'calm under pressure'. Not sure that's true but I'll take it.",
                'entities' => ['office'], 'focus' => 'office',
                'emotions' => [['emotion' => 'pride', 'intensity' => 6], ['emotion' => 'anxiety', 'intensity' => 3]],
                'protective_factors' => [['factor' => 'hobbies', 'impact' => 3]],
                'needs' => [['need' => 'achievement', 'moment' => null], ['need' => 'validation', 'moment' => null]],
                'thinking_patterns' => [['pattern' => 'self_criticism', 'evidence' => "not sure that's true"]],
                'coach_mode' => 'offer_door', 'coach' => "Someone saw something in you and said it out loud. You don't have to agree with it yet to let it count.",
            ],
            [
                'hour' => 22, 'pick' => 'witness', 'mood' => 'content', 'sentiment' => 'positive',
                'body' => "Sarah came over and we ate on the floor because I still don't have a table. Laughed more than I have in weeks.",
                'entities' => ['sarah', 'flat'], 'focus' => 'sarah',
                'emotions' => [['emotion' => 'joy', 'intensity' => 8], ['emotion' => 'gratitude', 'intensity' => 6]],
                'protective_factors' => [['factor' => 'friends', 'impact' => 8]],
                'needs' => [['need' => 'connection', 'moment' => null]],
                'behaviors' => [['behavior' => 'socialized', 'impact' => 7]],
                'recovered' => true,
                'coach_mode' => 'witness', 'coach' => "Dinner on the floor sounds like the right kind of housewarming.",
            ],
            [
                'hour' => 8, 'pick' => 'light', 'mood' => 'tired', 'sentiment' => 'neutral',
                'body' => "Commute is longer from here. Forty minutes each way. Started a podcast to make it feel less like lost time.",
                'entities' => ['flat'],
                'emotions' => [['emotion' => 'boredom', 'intensity' => 4], ['emotion' => 'calm', 'intensity' => 3]],
                'stressors' => [['category' => 'health', 'subcategory' => 'energy', 'impact' => 4]],
                'protective_factors' => [['factor' => 'music', 'impact' => 4]],
                'needs' => [['need' => 'rest', 'moment' => null]],
                'coach_mode' => 'hold_back', 'coach' => "Noted.",
            ],
            [
                'hour' => 20, 'pick' => 'engage', 'mood' => 'anxious', 'sentiment' => 'negative',
                'body' => "Presented the first draft to the client and they had a lot of notes. Logically I know that's normal. Still replayed the meeting the whole way home.",
                'entities' => ['office'], 'focus' => 'office',
                'emotions' => [['emotion' => 'embarrassment', 'intensity' => 5], ['emotion' => 'anxiety', 'intensity' => 6]],
                'stressors' => [['category' => 'work', 'subcategory' => 'meetings', 'impact' => 6]],
                'needs' => [['need' => 'validation', 'moment' => null]],
                'thinking_patterns' => [['pattern' => 'rumination', 'evidence' => 'replayed the meeting the whole way home']],
                'coach_mode' => 'offer_door', 'coach' => "Knowing it's normal and still feeling it — both can be true at once. Want to look at what the notes actually said, or let it rest for tonight?",
            ],
            [
                'hour' => 18, 'pick' => 'light', 'mood' => 'warm', 'sentiment' => 'positive',
                'body' => "Called my sister for an hour. She's planning the wedding already and asked my opinion on everything. Felt nice to be needed for something easy.",
                'entities' => ['sister'], 'focus' => 'sister',
                'emotions' => [['emotion' => 'love', 'intensity' => 7], ['emotion' => 'excitement', 'intensity' => 5]],
                'protective_factors' => [['factor' => 'family', 'impact' => 7]],
                'needs' => [['need' => 'connection', 'moment' => null], ['need' => 'meaning', 'moment' => null]],
                'behaviors' => [['behavior' => 'helped_others', 'impact' => 5]],
                'coach_mode' => 'witness', 'coach' => "Needed for something easy — that's a lovely kind of needed.",
            ],
        ];
    }

    private function roughPatch(): array
    {
        return [
            [
                'hour' => 23, 'pick' => 'rest', 'mood' => 'drained', 'sentiment' => 'negative',
                'body' => "Stayed at the office until 9 again. Third time this week. I keep saying it's temporary.",
                'entities' => ['office', 'deadline'], 'focus' => 'office',
                'emotions' => [['emotion' => 'overwhelm', 'intensity' => 7], ['emotion' => 'sadness', 'intensity' => 4]],
                'stressors' => [['category' => 'work', 'subcategory' => 'workload', 'impact' => 8]],
                'needs' => [['need' => 'rest', 'moment' => null]],
                'behaviors' => [['behavior' => 'worked_late', 'impact' => 7], ['behavior' => 'overcommitted', 'impact' => 6]],
                'thinking_patterns' => [['pattern' => 'should_statements', 'evidence' => "I keep saying it's temporary"]],
                'coach_mode' => 'offer_door', 'coach' => "Third late night, and the word 'temporary' doing a lot of work. No need to fix it tonight — just noticing it with you.",
            ],
            [
                'hour' => 2, 'pick' => 'rest', 'mood' => 'restless', 'sentiment' => 'negative',
                'body' => "Can't sleep. Brain is running through tomorrow's tasks on a loop. Gave up and got my phone out which obviously didn't help.",
                'entities' => [],
                'emotions' => [['emotion' => 'anxiety', 'intensity' => 7], ['emotion' => 'annoyance', 'intensity' => 4]],
                'stressors' => [['category' => 'health', 'subcategory' => 'sleep', 'impact' => 7], ['category' => 'work', 'subcategory' => 'workload', 'impact' => 5]],
                'needs' => [['need' => 'rest', 'moment' => null], ['need' => 'certainty', 'moment' => null]],
                'behaviors' => [['behavior' => 'trouble_sleeping', 'impact' => 7], ['behavior' => 'excessive_screen_time', 'impact' => 5]],
                'thinking_patterns' => [['pattern' => 'rumination', 'evidence' => "running through tomorrow's tasks on a loop"]],
                'coach_mode' => 'hold_back', 'coach' => "Writing it down is already something. Rest well if you can.",
            ],
            [
                'hour' => 21, 'pick' => 'witness', 'mood' => 'flat', 'sentiment' => 'negative',
                'body' => "Sarah texted asking if I wanted to get dinner Friday. Said I was busy. I wasn't really, I just didn't have it in me.",
                'entities' => ['sarah'], 'focus' => 'sarah',
                'emotions' => [['emotion' => 'loneliness', 'intensity' => 6], ['emotion' => 'guilt', 'intensity' => 5]],
                'stressors' => [['category' => 'relationships', 'subcategory' => 'isolation', 'impact' => 6]],
                'needs' => [['need' => 'connection', 'moment' => null], ['need' => 'rest', 'moment' => null]],
                'behaviors' => [['behavior' => 'avoided', 'impact' => 6], ['behavior' => 'isolated', 'impact' => 6]],
                'coach_mode' => 'witness', 'coach' => "Not having it in you is real information, not a failing.",
            ],
            [
                'hour' => 19, 'pick' => 'engage', 'mood' => 'frustrated', 'sentiment' => 'negative',
                'body' => "Hédi moved the deadline up a week without asking anyone. Everybody just nodded. I nodded too. I hate that I nodded.",
                'entities' => ['manager', 'deadline', 'office'], 'focus' => 'manager',
                'emotions' => [['emotion' => 'anger', 'intensity' => 6], ['emotion' => 'shame', 'intensity' => 4]],
                'stressors' => [['category' => 'work', 'subcategory' => 'deadline', 'impact' => 8], ['category' => 'work', 'subcategory' => 'coworker', 'impact' => 5]],
                'needs' => [['need' => 'autonomy', 'moment' => null]],
                'behaviors' => [['behavior' => 'suppressed_emotions', 'impact' => 6], ['behavior' => 'people_pleasing', 'impact' => 5]],
                'thinking_patterns' => [['pattern' => 'self_criticism', 'evidence' => 'I hate that I nodded']],
                'coach_mode' => 'engage', 'coach' => "Being handed a decision you didn't get a say in, and then being hard on yourself for not objecting in the moment — that's two weights, not one. If you had said something, what would it have been?",
            ],
            [
                'hour' => 22, 'pick' => 'rest', 'mood' => 'numb', 'sentiment' => 'negative',
                'body' => "Ate cereal for dinner and watched three episodes of something I won't remember. Weekend gone.",
                'entities' => ['flat'],
                'emotions' => [['emotion' => 'boredom', 'intensity' => 5], ['emotion' => 'disappointment', 'intensity' => 5]],
                'stressors' => [['category' => 'health', 'subcategory' => 'energy', 'impact' => 6]],
                'needs' => [['need' => 'meaning', 'moment' => null]],
                'behaviors' => [['behavior' => 'disengaged', 'impact' => 6], ['behavior' => 'excessive_screen_time', 'impact' => 5]],
                'coach_mode' => 'hold_back', 'coach' => "Sometimes a weekend is just recovery, even when it doesn't feel like it.",
            ],
            [
                'hour' => 20, 'pick' => 'witness', 'mood' => 'heavy', 'sentiment' => 'negative',
                'body' => "Made a small mistake in the report and spent the whole evening convinced everyone thinks I'm not good enough for this project.",
                'entities' => ['office'],
                'emotions' => [['emotion' => 'shame', 'intensity' => 6], ['emotion' => 'fear', 'intensity' => 5]],
                'stressors' => [['category' => 'self', 'subcategory' => 'self_doubt', 'impact' => 7], ['category' => 'self', 'subcategory' => 'failure', 'impact' => 5]],
                'needs' => [['need' => 'validation', 'moment' => null], ['need' => 'security', 'moment' => null]],
                'behaviors' => [['behavior' => 'perfectionism', 'impact' => 6]],
                'thinking_patterns' => [['pattern' => 'mind_reading', 'evidence' => "convinced everyone thinks I'm not good enough"], ['pattern' => 'catastrophizing', 'evidence' => 'small mistake → not good enough for this project']],
                'coach_mode' => 'offer_door', 'coach' => "A small mistake, and a whole evening of what others might be thinking. I'm curious whether anyone actually said anything — no need to answer.",
            ],
            [
                'hour' => 18, 'pick' => 'light', 'mood' => 'okay', 'sentiment' => 'neutral',
                'body' => "Walked home instead of taking the bus because the weather was nice. Didn't fix anything but my head was quieter for a bit.",
                'entities' => ['walk'], 'focus' => 'walk',
                'emotions' => [['emotion' => 'calm', 'intensity' => 5], ['emotion' => 'relief', 'intensity' => 3]],
                'protective_factors' => [['factor' => 'nature', 'impact' => 5], ['factor' => 'exercise', 'impact' => 4]],
                'needs' => [['need' => 'rest', 'moment' => null]],
                'behaviors' => [['behavior' => 'exercised', 'impact' => 4]],
                'recovered' => true,
                'coach_mode' => 'witness', 'coach' => "Quieter for a bit counts.",
            ],
            [
                'hour' => 1, 'pick' => 'rest', 'mood' => 'lonely', 'sentiment' => 'negative',
                'body' => "Realised I haven't talked to anyone outside work in like ten days. The flat feels too big tonight.",
                'entities' => ['flat'],
                'emotions' => [['emotion' => 'loneliness', 'intensity' => 8], ['emotion' => 'sadness', 'intensity' => 6]],
                'stressors' => [['category' => 'relationships', 'subcategory' => 'isolation', 'impact' => 8], ['category' => 'health', 'subcategory' => 'sleep', 'impact' => 4]],
                'needs' => [['need' => 'connection', 'moment' => null]],
                'behaviors' => [['behavior' => 'isolated', 'impact' => 7], ['behavior' => 'trouble_sleeping', 'impact' => 4]],
                'thinking_patterns' => [['pattern' => 'overgeneralization', 'evidence' => "haven't talked to anyone"]],
                'coach_mode' => 'offer_door', 'coach' => "Ten days is a long stretch to carry alone. Is there one person it would feel okay to send a single line to tomorrow?",
            ],
        ];
    }

    private function climbingOut(): array
    {
        return [
            [
                'hour' => 21, 'pick' => 'engage', 'mood' => 'nervous', 'sentiment' => 'neutral',
                'body' => "Finally texted Sarah back and told her I'd been in a weird place. She just said 'I figured. Coffee Saturday?' I almost cried.",
                'entities' => ['sarah'], 'focus' => 'sarah',
                'emotions' => [['emotion' => 'relief', 'intensity' => 7], ['emotion' => 'gratitude', 'intensity' => 7]],
                'protective_factors' => [['factor' => 'friends', 'impact' => 8]],
                'needs' => [['need' => 'connection', 'moment' => null], ['need' => 'validation', 'moment' => null]],
                'behaviors' => [['behavior' => 'reached_out', 'impact' => 8], ['behavior' => 'expressed_emotions', 'impact' => 6]],
                'recovered' => true,
                'coach_mode' => 'witness', 'coach' => "'I figured. Coffee Saturday?' — that's what being known looks like.",
            ],
            [
                'hour' => 7, 'pick' => 'light', 'mood' => 'clear', 'sentiment' => 'positive',
                'body' => "Morning walk before work, day four in a row. It's becoming the part of the day I actually look forward to.",
                'entities' => ['walk'], 'focus' => 'walk',
                'emotions' => [['emotion' => 'calm', 'intensity' => 7], ['emotion' => 'hope', 'intensity' => 5]],
                'protective_factors' => [['factor' => 'exercise', 'impact' => 7], ['factor' => 'nature', 'impact' => 6]],
                'needs' => [['need' => 'rest', 'moment' => null]],
                'behaviors' => [['behavior' => 'exercised', 'impact' => 7], ['behavior' => 'slept_early', 'impact' => 4]],
                'coach_mode' => 'witness', 'coach' => "Four days in a row — and looking forward to it. That's the good kind of habit.",
            ],
            [
                'hour' => 18, 'pick' => 'engage', 'mood' => 'shaky', 'sentiment' => 'neutral',
                'body' => "Had my first session with a therapist today. Mostly talked about work and sleep. Weird to say it all out loud to a stranger but she didn't make it weird.",
                'entities' => [],
                'emotions' => [['emotion' => 'anxiety', 'intensity' => 4], ['emotion' => 'hope', 'intensity' => 6]],
                'protective_factors' => [['factor' => 'therapy', 'impact' => 7]],
                'needs' => [['need' => 'validation', 'moment' => null], ['need' => 'certainty', 'moment' => null]],
                'behaviors' => [['behavior' => 'asked_for_help', 'impact' => 8]],
                'coach_mode' => 'offer_door', 'coach' => "Saying it all out loud for the first time takes something. How did you feel walking out?",
            ],
            [
                'hour' => 20, 'pick' => 'engage', 'mood' => 'proud', 'sentiment' => 'positive',
                'body' => "Told Hédi I couldn't take on the extra workshop on top of the project. He was fine with it?? I spent a week dreading a conversation that took two minutes.",
                'entities' => ['manager', 'office'], 'focus' => 'manager',
                'emotions' => [['emotion' => 'pride', 'intensity' => 7], ['emotion' => 'surprise', 'intensity' => 5], ['emotion' => 'relief', 'intensity' => 6]],
                'stressors' => [['category' => 'work', 'subcategory' => 'workload', 'impact' => 3]],
                'needs' => [['need' => 'autonomy', 'moment' => null]],
                'behaviors' => [['behavior' => 'set_boundary', 'impact' => 8], ['behavior' => 'confronted', 'impact' => 5]],
                'thinking_patterns' => [['pattern' => 'catastrophizing', 'evidence' => 'a week dreading a conversation that took two minutes']],
                'recovered' => true,
                'coach_mode' => 'engage', 'coach' => "A week of dread, two minutes of conversation. Worth remembering that ratio next time something feels huge in advance.",
            ],
            [
                'hour' => 22, 'pick' => 'witness', 'mood' => 'low', 'sentiment' => 'negative',
                'body' => "Bad day. Slept badly, snapped at a coworker over nothing, skipped the walk. Trying not to read it as 'back to square one'.",
                'entities' => ['office', 'walk'],
                'emotions' => [['emotion' => 'annoyance', 'intensity' => 6], ['emotion' => 'disappointment', 'intensity' => 5]],
                'stressors' => [['category' => 'health', 'subcategory' => 'sleep', 'impact' => 6], ['category' => 'work', 'subcategory' => 'coworker', 'impact' => 4]],
                'needs' => [['need' => 'rest', 'moment' => null]],
                'behaviors' => [['behavior' => 'acted_impulsively', 'impact' => 4]],
                'thinking_patterns' => [['pattern' => 'black_and_white', 'evidence' => "back to square one"]],
                'coach_mode' => 'offer_door', 'coach' => "One rough day after a good stretch is a rough day, not a reset. You already caught the 'square one' thought — that's new.",
            ],
            [
                'hour' => 13, 'pick' => 'light', 'mood' => 'light', 'sentiment' => 'positive',
                'body' => "Coffee with Sarah turned into a three-hour walk around the old town. We made a standing Saturday plan.",
                'entities' => ['sarah', 'walk'], 'focus' => 'sarah',
                'emotions' => [['emotion' => 'joy', 'intensity' => 8], ['emotion' => 'amusement', 'intensity' => 6]],
                'protective_factors' => [['factor' => 'friends', 'impact' => 9], ['factor' => 'exercise', 'impact' => 5]],
                'needs' => [['need' => 'connection', 'moment' => null]],
                'behaviors' => [['behavior' => 'socialized', 'impact' => 8], ['behavior' => 'planned_ahead', 'impact' => 5]],
                'coach_mode' => 'witness', 'coach' => "A standing Saturday plan. That's something to protect.",
            ],
            [
                'hour' => 21, 'pick' => 'light', 'mood' => 'settled', 'sentiment' => 'positive',
                'body' => "Phone stays in the kitchen after 11 now. Third night trying it. Falling asleep faster, I think.",
                'entities' => ['flat'],
                'emotions' => [['emotion' => 'calm', 'intensity' => 6], ['emotion' => 'pride', 'intensity' => 4]],
                'protective_factors' => [['factor' => 'sleep', 'impact' => 7]],
                'needs' => [['need' => 'rest', 'moment' => null]],
                'behaviors' => [['behavior' => 'slept_early', 'impact' => 7]],
                'coach_mode' => 'hold_back', 'coach' => "Sounds like it's working.",
            ],
            [
                'hour' => 19, 'pick' => 'witness', 'mood' => 'grateful', 'sentiment' => 'positive',
                'body' => "Helped my sister pick invitations over video call. She said I seem lighter lately. I think she's right.",
                'entities' => ['sister'], 'focus' => 'sister',
                'emotions' => [['emotion' => 'love', 'intensity' => 7], ['emotion' => 'gratitude', 'intensity' => 6]],
                'protective_factors' => [['factor' => 'family', 'impact' => 7]],
                'needs' => [['need' => 'connection', 'moment' => null], ['need' => 'meaning', 'moment' => null]],
                'behaviors' => [['behavior' => 'helped_others', 'impact' => 6]],
                'coach_mode' => 'witness', 'coach' => "Lighter — and someone close enough to notice.",
            ],
        ];
    }
}
