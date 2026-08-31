// Shared taxonomy display helpers — extracted 2026-08-17 while building the
// monthly dashboard, which would otherwise have been a THIRD hand-written
// copy of these dictionaries (Dashboard.vue and EntryAnalysis.vue each
// already carry their own). Two copies already required manually keeping
// six dictionaries in sync by hand across two files every time the backend
// taxonomy changes (see ExtractionTaxonomy.php and docs/dashboard-logic.md
// §7 #14, which flagged this exact drift risk) — a third copy was the point
// where closing that gap was worth it instead of compounding it.
//
// Mirrors App\Support\ExtractionTaxonomy: EMOTION_TONE/BEHAVIOR_TONE cover
// every value in ExtractionTaxonomy::EMOTIONS/::BEHAVIORS, and BEHAVIOR_WORDS/
// PROTECTIVE_WORDS/NEED_WORDS/THINKING_PATTERN_WORDS give a warm phrase for
// every value in their respective backend consts. If the backend taxonomy
// grows again, these are the six dictionaries that need a matching update —
// now in exactly one place instead of two or three.

export function humanize(s) {
  return s ? s.replace(/_/g, ' ') : s
}

export function warm(dict, label) {
  return dict[label] ?? humanize(label)
}

// Short on purpose — these sit as labels around a radar chart, not in a
// sentence, so the long, warm phrasing used elsewhere (e.g. "a bit more
// room to do things your way") just runs off the edge of the chart.
export const NEED_WORDS = {
  rest: 'rest',
  validation: 'understood',
  connection: 'connection',
  autonomy: 'freedom',
  certainty: 'certainty',
  achievement: 'achievement',
  security: 'security',
  meaning: 'purpose',
}

export const PROTECTIVE_WORDS = {
  exercise: 'moving your body',
  friends: 'your friends',
  family: 'family',
  nature: 'getting outside',
  music: 'music',
  faith: 'faith',
  therapy: 'therapy',
  pets: 'your pets',
  reading: 'reading',
  sleep: 'good sleep',
  hobbies: 'your hobbies',
  journaling: 'writing things down',
}

export const BEHAVIOR_WORDS = {
  procrastinated: 'putting things off',
  avoided: 'avoiding something',
  exercised: 'moving your body',
  socialized: 'spending time with people',
  studied: 'studying',
  slept_early: 'getting to bed early',
  overslept: 'sleeping in more than planned',
  worked_late: 'working late',
  isolated: 'keeping to yourself',
  reached_out: 'reaching out to someone',
  rested: 'resting',
  overcommitted: 'taking on too much',
  asked_for_help: 'asking for help',
  set_boundary: 'setting a boundary',
  // Added 2026-08-17 alongside the backend taxonomy expansion (see
  // ExtractionTaxonomy::BEHAVIORS's docblock) — same warm voice as above.
  sought_reassurance: 'looking for reassurance',
  people_pleasing: "putting others' needs ahead of your own",
  social_comparison: 'comparing yourself to others',
  perfectionism: 'holding yourself to an impossibly high bar',
  acted_impulsively: 'acting on impulse',
  emotional_eating: 'eating to cope with how you felt',
  trouble_sleeping: 'struggling to fall or stay asleep',
  excessive_screen_time: 'losing time to your phone',
  expressed_emotions: 'letting yourself feel it and say so',
  suppressed_emotions: 'holding your feelings in',
  confronted: 'facing something head-on',
  solved_problem: 'working through a problem',
  disengaged: 'checking out',
  planned_ahead: 'planning ahead',
  helped_others: 'showing up for someone else',
  sought_distraction: 'distracting yourself',
  tried_new_things: 'trying something new',
}

export const THINKING_PATTERN_WORDS = {
  catastrophizing: 'expecting the worst',
  self_criticism: 'being hard on yourself',
  rumination: 'replaying things in your head',
  black_and_white: 'seeing things as all-or-nothing',
  overgeneralization: 'letting one moment feel like the whole story',
  mind_reading: 'assuming what others were thinking',
  should_statements: 'holding yourself to a lot of "shoulds"',
  personalization: 'taking on things that aren’t fully yours to carry',
}

// Emotions are already plain words, so no phrasing dictionary — only a tone
// for coloring bars/charts, reusing the app's existing positive/negative/
// accent palette rather than inventing new hues.
export const EMOTION_TONE = {
  joy: 'positive', love: 'positive', hope: 'positive', calm: 'positive',
  // amusement added 2026-08-12 alongside the backend taxonomy — see
  // docs/ai-behavior.md §7 (13th emotion, not aliased into joy).
  amusement: 'positive',
  sadness: 'negative', anger: 'negative', fear: 'negative', disgust: 'negative',
  shame: 'negative', guilt: 'negative', anxiety: 'negative',
  // annoyance added 2026-08-17 — split off from anger on the backend
  // (see ExtractionTaxonomy::EMOTIONS's docblock); same negative tone as
  // anger, it's a register distinction, not a valence one.
  annoyance: 'negative',
  surprise: 'accent',
  // 13 more added 2026-08-17, same pass as annoyance's backend split —
  // see ExtractionTaxonomy::EMOTIONS's docblock for the full reasoning.
  excitement: 'positive', gratitude: 'positive', pride: 'positive', relief: 'positive',
  curiosity: 'positive',
  loneliness: 'negative', jealousy: 'negative', disappointment: 'negative',
  embarrassment: 'negative', boredom: 'negative', overwhelm: 'negative',
  // nostalgia and confusion are genuinely mixed-valence in practice (a
  // fond memory vs. a painful one; not-knowing isn't inherently bad) —
  // 'accent' rather than forcing either into positive or negative.
  nostalgia: 'accent', confusion: 'accent',
}

// Rough self-supportive vs self-undermining split, used to color the
// behaviors pie chart — turns it into a genuinely useful "how much of this
// week was kind to you vs hard on you" read, still only using the theme's
// existing hues.
export const BEHAVIOR_TONE = {
  exercised: 'positive', reached_out: 'positive', socialized: 'positive',
  slept_early: 'positive', asked_for_help: 'positive', set_boundary: 'positive',
  rested: 'positive', studied: 'positive',
  procrastinated: 'negative', avoided: 'negative', isolated: 'negative',
  overcommitted: 'negative', worked_late: 'negative', overslept: 'negative',
  // Added 2026-08-17 alongside the 17 new BEHAVIORS values. 'accent' for
  // the two that are genuinely mixed coping strategies rather than cleanly
  // self-supportive or self-undermining.
  expressed_emotions: 'positive', confronted: 'positive', solved_problem: 'positive',
  helped_others: 'positive', planned_ahead: 'positive', tried_new_things: 'positive',
  people_pleasing: 'negative', perfectionism: 'negative', acted_impulsively: 'negative',
  emotional_eating: 'negative', trouble_sleeping: 'negative', excessive_screen_time: 'negative',
  suppressed_emotions: 'negative', social_comparison: 'negative', disengaged: 'negative',
  sought_reassurance: 'accent', sought_distraction: 'accent',
}

/** Tone lookup for stressors/thinking_patterns, which don't get their own
 *  dict — every stressor category and every thinking pattern is treated as
 *  'negative' by convention (both fields exist specifically to name what's
 *  weighing on the writer), so a shared constant tone is correct here
 *  rather than a per-label map. Exported so any view rendering these two
 *  fields doesn't need to redeclare the convention inline. */
export const NEGATIVE_TONE_FIELDS = ['stressors', 'thinking_patterns']
