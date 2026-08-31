<?php

namespace App\Support;

class ExtractionTaxonomy
{
    public const EMOTIONS = [
        'joy', 'sadness', 'anger', 'fear', 'disgust', 'surprise',
        'love', 'shame', 'guilt', 'anxiety', 'calm', 'hope',
        // Added 2026-08-12 after a real extraction returned "amusement",
        // which had no home in the original 12 and no near-enough alias
        // target either — the closest existing value (joy) would have
        // flattened a genuinely distinct, lighter register.
        'amusement',
        // Added 2026-08-17 for the same reason as amusement above, found
        // the same way (a real extraction, not a hypothetical gap): the
        // model tagged a mundane "woke up tired, hair didn't quite work
        // out" entry with "frustration," which the ALIASES map below used
        // to collapse into "anger" — a meaningfully stronger, more alarming
        // word than what the model actually said, and the person's own
        // read of the entry ("just annoyance... not anger") confirmed the
        // collapse was actively wrong, not just imprecise. "anger" and
        // "annoyance" are different intensities/registers, the same way
        // "amusement" and "joy" are — folding the milder one into the
        // stronger one loses real signal and can make an ordinary day read
        // as more emotionally severe than it was.
        'annoyance',
        // Added 2026-08-17, same pass — the user supplied a broader
        // reference list of common journaling emotions and asked for
        // "more entities... be careful about redundancy." Each of these 13
        // was checked against the existing 14 and against every OTHER
        // taxonomy field before being added as its own slot, not folded
        // into an existing one:
        //   - excitement: was previously aliased into 'joy' below; split
        //     out because it's a higher-arousal register than joy/calm,
        //     the same distinction that already justifies keeping 'joy'
        //     and 'calm' separate.
        //   - embarrassment: was previously aliased into 'shame'; split
        //     out because it's situational/social ("that was awkward"),
        //     not the global self-judgment 'shame' and 'guilt' already
        //     capture — a real three-way distinction, not a duplicate.
        //   - loneliness, jealousy, disappointment, gratitude, pride,
        //     confusion, relief, boredom, nostalgia, curiosity, overwhelm:
        //     none had a close-enough existing home. 'relief' specifically
        //     fixes a real gap the prompt's own worked example exposed —
        //     "relieved my friend called" had nowhere to go but 'hope',
        //     which is forward-looking, not the resolved-tension feeling
        //     'relief' actually is.
        // Deliberately NOT added, to avoid redundancy the user asked to
        // watch for: "happiness"/"calmness" (already 'joy'/'calm'),
        // "frustration"/"stress" (already aliased to 'annoyance'/'anxiety'
        // below), "contentment" (stays folded into 'joy' — too close to
        // split further), "resentment" (already aliased to 'anger'),
        // "envy" (aliased to the new 'jealousy' below rather than given
        // its own slot — the two are hard to reliably tell apart in a
        // single journal sentence, so one slot serves better than two
        // near-indistinguishable ones), "insecurity" (aliased to 'anxiety'
        // below — already tracked structurally via the stressors 'self' /
        // 'self_doubt' subcategory and the 'validation' need, so a third
        // representation would just fragment the same signal).
        'excitement', 'loneliness', 'jealousy', 'disappointment', 'gratitude',
        'pride', 'embarrassment', 'confusion', 'relief', 'boredom',
        'nostalgia', 'curiosity', 'overwhelm',
    ];

    // Subcategories 'school' (work), 'social_pressure' (relationships), and
    // 'failure'/'life_transition' (self) added 2026-08-17 from a
    // user-supplied reference list of common stressors, cross-checked
    // against what was already covered: "Deadlines," "Money," "Family
    // conflicts," "Friendship problems," "Workload," and "Conflict" all
    // mapped onto existing subcategories and weren't re-added.
    public const STRESSOR_CATEGORIES = [
        'work'          => ['deadline', 'workload', 'coworker', 'promotion', 'meetings', 'job_security', 'school'],
        'relationships' => ['partner', 'family', 'friendship', 'conflict', 'isolation', 'social_pressure'],
        'health'        => ['illness', 'sleep', 'energy', 'body_image'],
        'finances'      => ['money', 'debt', 'expenses'],
        'self'          => ['uncertainty', 'identity', 'self_doubt', 'failure', 'life_transition'],
        'external'      => ['politics', 'environment', 'technology'],
    ];

    public const NEEDS = [
        'rest', 'validation', 'connection', 'autonomy',
        'certainty', 'achievement', 'security', 'meaning',
    ];

    public const PROTECTIVE_FACTORS = [
        'exercise', 'friends', 'family', 'nature', 'music', 'faith',
        'therapy', 'pets', 'reading', 'sleep', 'hobbies', 'journaling',
    ];

    // 17 values added 2026-08-17 from a user-supplied reference list,
    // filtered for redundancy against BOTH the existing 14 here AND the
    // other five taxonomy fields (a behavior slot that just restates an
    // existing thinking_pattern or protective_factor doesn't add real
    // coverage, it just lets the same signal get double-counted). Skipped
    // as pure duplicates of existing values: procrastination, avoidance,
    // isolation/social withdrawal, exercising, oversleeping, setting
    // boundaries, seeking support. Skipped as cross-field duplicates:
    // overthinking and rumination (already thinking_patterns/rumination),
    // self-criticism (already thinking_patterns/self_criticism),
    // journaling/self-reflection (already protective_factors/journaling).
    // Skipped as already-covered-closely-enough: overworking (the existing
    // overcommitted + worked_late pair already spans this territory; a
    // third slot would fragment, not add, signal).
    public const BEHAVIORS = [
        'procrastinated', 'avoided', 'exercised', 'socialized', 'studied',
        'slept_early', 'overslept', 'worked_late', 'isolated', 'reached_out',
        'rested', 'overcommitted', 'asked_for_help', 'set_boundary',
        'sought_reassurance', 'people_pleasing', 'social_comparison',
        'perfectionism', 'acted_impulsively', 'emotional_eating',
        'trouble_sleeping', 'excessive_screen_time', 'expressed_emotions',
        'suppressed_emotions', 'confronted', 'solved_problem', 'disengaged',
        'planned_ahead', 'helped_others', 'sought_distraction', 'tried_new_things',
    ];

    public const THINKING_PATTERNS = [
        'catastrophizing', 'self_criticism', 'rumination', 'black_and_white',
        'overgeneralization', 'mind_reading', 'should_statements', 'personalization',
    ];

    /**
     * Categories for the two-stage memory pipeline (2026-08-14, see
     * docs/ai-behavior.md §11 and MemoryEvaluatorService). Not an
     * "extraction taxonomy" in the same sense as the fields above (there's
     * no synonym-aliasing pass for this one -- the model picks a category
     * directly, and a wrong pick is a labeling nuance, not a dropped
     * signal), but kept here anyway so EntryExtractionService, the
     * evaluator, and CanonEventController all validate against one shared
     * list instead of three copies that could drift.
     *
     * - fact: a stable fact about the writer's life/identity/circumstances
     *   ("I'm a CS student", "I work at a bakery").
     * - preference: a stable like/dislike/taste ("doesn't enjoy horror
     *   movies").
     * - goal: something the writer is working toward or building.
     * - pattern: a repeatedly-demonstrated behavioral/emotional pattern
     *   distinct from the clinical thinking_patterns field above -- this is
     *   plain-language self-knowledge ("tends to shut down after conflict"),
     *   not a CBT-style cognitive distortion tag.
     * - event: a significant, durable one-time happening -- the original
     *   (and only, pre-2026-08-14) shape this table held.
     */
    public const MEMORY_CATEGORIES = ['fact', 'preference', 'goal', 'pattern', 'event'];

    /**
     * Synonym-mapping layer (2026-08-12). The model occasionally reaches for
     * a word that's a near-exact synonym of a canonical value but isn't
     * itself in the closed list — e.g. "worried" instead of "anxiety",
     * "job" instead of "work". Previously that just meant a dropped tag
     * (see EntryExtractionService::rawLabel() for the separate bare-string
     * shape-drift fix this is paired with). Rather than growing every
     * taxonomy to absorb every near-miss word (which erodes the point of a
     * closed vocabulary — see the "every enum is closed on purpose" note
     * in docs/ai-behavior.md §7), most synonyms are normalized here, into
     * an *existing* canonical value, before the enum check runs.
     *
     * Deliberately high-precision, not exhaustive: only words that are
     * genuinely interchangeable with a canonical value are included (the
     * same "narrow over broad" judgment call already made for
     * BANNED_PHRASE_PATTERNS in CoachService). "amusement" specifically
     * was NOT added here — it was distinct enough from every existing
     * emotion that flattening it into "joy" would have lost real signal,
     * so it was added to EMOTIONS above instead of aliased.
     *
     * Scoped to the top-level label of each field only (the value
     * labelKeyForField() points at) — stressor *subcategories* are a
     * second, per-category closed list and are not covered by this pass.
     *
     * @var array<string, array<string, string>>
     */
    public const ALIASES = [
        'emotions' => [
            'amused' => 'amusement', 'amusing' => 'amusement', 'entertained' => 'amusement',
            'happy' => 'joy', 'happiness' => 'joy', 'content' => 'joy', 'contentment' => 'joy',
            'delighted' => 'joy', 'delight' => 'joy', 'glad' => 'joy', 'pleased' => 'joy',
            'cheerful' => 'joy',
            'unhappy' => 'sadness', 'down' => 'sadness', 'blue' => 'sadness', 'melancholy' => 'sadness',
            'grief' => 'sadness', 'grieving' => 'sadness', 'sorrow' => 'sadness', 'heartbroken' => 'sadness',
            'mad' => 'anger', 'furious' => 'anger',
            'rage' => 'anger', 'resentment' => 'anger', 'resentful' => 'anger',
            // Moved off 'anger' 2026-08-17 (see EMOTIONS's docblock for the
            // real entry that surfaced this) — these describe a milder,
            // more everyday register than "anger" implies. 'irritated'/
            // 'irritation' moved here too for the same reason; they were
            // never a great fit for "anger" either.
            'annoyed' => 'annoyance', 'irritated' => 'annoyance', 'irritation' => 'annoyance',
            'frustrated' => 'annoyance', 'frustration' => 'annoyance',
            'afraid' => 'fear', 'scared' => 'fear', 'fearful' => 'fear', 'frightened' => 'fear',
            'terrified' => 'fear', 'terror' => 'fear', 'panic' => 'fear', 'panicked' => 'fear',
            'disgusted' => 'disgust', 'repulsed' => 'disgust', 'revulsion' => 'disgust', 'revolted' => 'disgust',
            'surprised' => 'surprise', 'shocked' => 'surprise', 'shock' => 'surprise',
            'astonished' => 'surprise', 'stunned' => 'surprise',
            'loving' => 'love', 'affection' => 'love', 'affectionate' => 'love',
            'adoration' => 'love', 'fond' => 'love', 'fondness' => 'love',
            'ashamed' => 'shame', 'humiliated' => 'shame', 'humiliation' => 'shame',
            'guilty' => 'guilt', 'remorse' => 'guilt', 'remorseful' => 'guilt',
            // 'stressed'/'stress' stay here rather than getting a slot of
            // their own — close enough to anxiety in practice that a split
            // would just fragment the same signal (unlike annoyance/anger,
            // where the intensity gap was wide enough to matter).
            'worried' => 'anxiety', 'worry' => 'anxiety', 'nervous' => 'anxiety', 'nervousness' => 'anxiety',
            'apprehensive' => 'anxiety', 'apprehension' => 'anxiety', 'uneasy' => 'anxiety', 'unease' => 'anxiety',
            'stressed' => 'anxiety', 'stress' => 'anxiety',
            // Added 2026-08-17 — tracked structurally elsewhere (stressors
            // self/self_doubt, needs/validation) so a third representation
            // as its own emotion slot would just fragment the same signal
            // rather than add real coverage.
            'insecure' => 'anxiety', 'insecurity' => 'anxiety',
            'relaxed' => 'calm', 'relaxation' => 'calm', 'peaceful' => 'calm', 'peace' => 'calm',
            'serene' => 'calm', 'serenity' => 'calm', 'tranquil' => 'calm',
            'hopeful' => 'hope', 'hopefulness' => 'hope', 'optimistic' => 'hope', 'optimism' => 'hope',
            // New canonical values added 2026-08-17 (see EMOTIONS's
            // docblock) — each gets its common inflections aliased the
            // same way every other value above does.
            'excited' => 'excitement',
            'lonely' => 'loneliness', 'alone' => 'loneliness',
            'jealous' => 'jealousy', 'envy' => 'jealousy', 'envious' => 'jealousy',
            'disappointed' => 'disappointment', 'let_down' => 'disappointment',
            'grateful' => 'gratitude', 'thankful' => 'gratitude', 'thankfulness' => 'gratitude',
            'proud' => 'pride',
            'embarrassed' => 'embarrassment',
            'confused' => 'confusion', 'puzzled' => 'confusion', 'bewildered' => 'confusion',
            'relieved' => 'relief',
            'bored' => 'boredom',
            'nostalgic' => 'nostalgia',
            'curious' => 'curiosity',
            'overwhelmed' => 'overwhelm',
        ],
        'stressors' => [
            'job' => 'work', 'career' => 'work', 'employment' => 'work',
            'relationship' => 'relationships', 'social' => 'relationships',
            'wellness' => 'health', 'medical' => 'health', 'physical' => 'health',
            'financial' => 'finances', 'money' => 'finances', 'economic' => 'finances',
            'personal' => 'self', 'identity' => 'self',
            'environment' => 'external', 'world' => 'external', 'society' => 'external',
        ],
        'needs' => [
            'sleep' => 'rest', 'recovery' => 'rest', 'relaxation' => 'rest',
            'recognition' => 'validation', 'approval' => 'validation', 'acknowledgment' => 'validation',
            'belonging' => 'connection', 'companionship' => 'connection',
            'closeness' => 'connection', 'intimacy' => 'connection',
            'independence' => 'autonomy', 'freedom' => 'autonomy', 'control' => 'autonomy',
            'predictability' => 'certainty', 'clarity' => 'certainty',
            'accomplishment' => 'achievement', 'success' => 'achievement', 'productivity' => 'achievement',
            'safety' => 'security', 'stability' => 'security',
            'purpose' => 'meaning', 'fulfillment' => 'meaning',
        ],
        'protective_factors' => [
            'workout' => 'exercise', 'working_out' => 'exercise', 'gym' => 'exercise', 'fitness' => 'exercise',
            'friendship' => 'friends',
            'relatives' => 'family', 'parents' => 'family',
            'outdoors' => 'nature', 'outside' => 'nature',
            'religion' => 'faith', 'prayer' => 'faith', 'spirituality' => 'faith',
            'counseling' => 'therapy', 'counselling' => 'therapy', 'therapist' => 'therapy',
            'pet' => 'pets', 'dog' => 'pets', 'cat' => 'pets',
            'books' => 'reading', 'book' => 'reading',
            'napping' => 'sleep', 'nap' => 'sleep',
            'hobby' => 'hobbies',
            'writing' => 'journaling', 'diary' => 'journaling',
        ],
        'behaviors' => [
            'delayed' => 'procrastinated', 'postponed' => 'procrastinated',
            'dodged' => 'avoided', 'evaded' => 'avoided',
            'withdrew' => 'isolated', 'withdrawn' => 'isolated', 'withdrawal' => 'isolated',
            'connected' => 'reached_out', 'contacted' => 'reached_out',
            'sought_help' => 'asked_for_help',
            'set_boundaries' => 'set_boundary',
            // Added 2026-08-17 alongside the 17 new canonical values above.
            'reassurance_seeking' => 'sought_reassurance',
            'people_pleaser' => 'people_pleasing',
            'compared_self' => 'social_comparison', 'compared_himself' => 'social_comparison',
            'compared_herself' => 'social_comparison',
            'perfectionist' => 'perfectionism',
            'impulsive' => 'acted_impulsively', 'impulsive_decision' => 'acted_impulsively',
            'stress_eating' => 'emotional_eating', 'binge_eating' => 'emotional_eating',
            'insomnia' => 'trouble_sleeping', 'difficulty_sleeping' => 'trouble_sleeping',
            'doomscrolled' => 'excessive_screen_time', 'doomscrolling' => 'excessive_screen_time',
            'phone_addiction' => 'excessive_screen_time',
            'expressed_feelings' => 'expressed_emotions',
            'suppressed_feelings' => 'suppressed_emotions', 'bottled_up' => 'suppressed_emotions',
            'confronting' => 'confronted', 'faced' => 'confronted',
            'problem_solved' => 'solved_problem', 'problem_solving' => 'solved_problem',
            'gave_up' => 'disengaged', 'disengaging' => 'disengaged',
            'planned' => 'planned_ahead', 'organized' => 'planned_ahead',
            'helped_someone' => 'helped_others',
            'distraction' => 'sought_distraction', 'distracted_himself' => 'sought_distraction',
            'distracted_herself' => 'sought_distraction',
            'tried_something_new' => 'tried_new_things',
        ],
        'thinking_patterns' => [
            'catastrophic_thinking' => 'catastrophizing',
            'self_blame' => 'self_criticism', 'self_judgment' => 'self_criticism',
            'ruminating' => 'rumination', 'overthinking' => 'rumination',
            'all_or_nothing' => 'black_and_white', 'black_or_white' => 'black_and_white',
            'generalizing' => 'overgeneralization',
            'assuming' => 'mind_reading', 'jumping_to_conclusions' => 'mind_reading',
            'shoulding' => 'should_statements',
            'taking_it_personally' => 'personalization',
        ],
    ];

    /**
     * Normalize a raw label through the alias map for $field, if one exists.
     * Case/whitespace-insensitive on the input; returns the value unchanged
     * (still lowercased/trimmed) when there's no alias for it — the caller's
     * own enum() check is what actually rejects an unrecognized value, this
     * only ever narrows toward a canonical spelling, never validates.
     */
    public static function resolveAlias(string $field, string $value): string
    {
        $value = mb_strtolower(trim($value));
        return self::ALIASES[$field][$value] ?? $value;
    }

    /**
     * Flat list of allowed label values for a given field.
     * For stressors this is the top-level category keys (not subcategories).
     */
    public static function enumForField(string $field): array
    {
        return match ($field) {
            'emotions'           => self::EMOTIONS,
            'stressors'          => array_keys(self::STRESSOR_CATEGORIES),
            'protective_factors' => self::PROTECTIVE_FACTORS,
            'needs'              => self::NEEDS,
            'behaviors'          => self::BEHAVIORS,
            'thinking_patterns'  => self::THINKING_PATTERNS,
            default              => [],
        };
    }

    /**
     * The key within each JSON array item that carries the label value.
     * All six fields are tagged-object shapes as of 2026-08-12 — needs moved
     * from a flat string array to {"need": ..., "moment": ...} objects, the
     * same shape as everything else, so every field can carry a "moment"
     * link (see EntryExtractionService::systemPrompt()). No field returns
     * null anymore; kept nullable for callers that still branch on it.
     */
    public static function labelKeyForField(string $field): ?string
    {
        return match ($field) {
            'emotions'           => 'emotion',
            'stressors'          => 'category',
            'protective_factors' => 'factor',
            'needs'              => 'need',
            'behaviors'          => 'behavior',
            'thinking_patterns'  => 'pattern',
            default              => null,
        };
    }
}
