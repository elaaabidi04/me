<script setup>
import { ref, computed, onMounted, nextTick } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import api from '../lib/api'
import SpotlightTour from '../components/SpotlightTour.vue'
import {
  humanize, warm, NEED_WORDS, PROTECTIVE_WORDS, BEHAVIOR_WORDS,
  THINKING_PATTERN_WORDS, EMOTION_TONE, BEHAVIOR_TONE,
} from '../utils/taxonomyDisplay'

const router = useRouter()
const auth = useAuthStore()

// 2026-11-07: guided spotlight tour -- auto-starts once for demo accounts,
// replayable for everyone from the "tour" button in the top bar.
const tour = ref(null)
const tourSteps = [
  { title: 'learn it by doing.',
    body: "a fake journal, four months deep. click anything, nothing breaks." },
  { target: '[data-tour="picks"]', advanceOnClick: true,
    title: 'set the vibe',
    body: 'dig in: honest takes. just listen: quiet support.',
    try: 'pick one' },
  { target: '[data-tour="note"]', interactive: true,
    title: 'me noticed something',
    body: "it asks before remembering anything. your call.",
    try: 'confirm or skip it, then next' },
  { target: '.card.strats',
    title: "what's helped before",
    body: 'things that worked for her, kept for hard days.' },
  { target: '.constellation .category-bubble', advanceOnClick: true,
    title: 'threads you\'ve named',
    body: 'people, places and topics from her writing.',
    try: 'tap a bubble' },
  { target: '.constellation .node-group', advanceOnClick: true, wait: 900,
    title: 'go one level deeper',
    body: 'each dot is something she keeps writing about.',
    try: 'tap a name' },
  { target: '.thread-detail', interactive: true, wait: 1200,
    title: 'its story',
    body: 'sort it, see how it feels and what helped.',
    try: 'scroll it, then next' },
  { target: '[data-tour="breakdown"] .pictorial-btn', advanceOnClick: true,
    title: 'feelings, counted',
    body: 'pulled from her entries. no questionnaires.',
    try: 'tap a feeling' },
  { target: '.emotion-drawer', interactive: true, wait: 1200,
    title: 'the why',
    body: 'what came with it, and what followed.' },
  { target: '[data-tour="patterns"] .chain-link-btn', advanceOnClick: true,
    title: 'patterns',
    body: 'trigger, then feeling, then reaction, on repeat.',
    try: 'tap a trigger' },
  { target: '.pattern-drawer', interactive: true, wait: 1200,
    title: 'the receipts',
    body: 'the actual entries behind the pattern.' },
  { target: ['[data-tour="nav-journal"]', '.phone-menu-btn'],
    title: 'your turn',
    body: 'the journal: write anything, the coach replies in seconds.' },
  { target: '[data-tour="nav-monthly"]',
    title: 'zoom out',
    body: 'months of trends at a glance.' },
  { target: ['[data-tour="tour-btn"]', '.phone-menu-btn'], doneLabel: "let's go",
    title: 'replay anytime',
    body: 'right here, or in the menu on a phone.' },
]

/* ---- theming ------------------------------------------------------ */
const themes = ['dusk', 'paper', 'night']
const theme = ref('dusk')
function cycleTheme() {
  theme.value = themes[(themes.indexOf(theme.value) + 1) % themes.length]
}

// 2026-08-19: auth.logout() only clears the store's user and posts
// /api/logout -- it never navigates. Clicking "log out" used to leave
// this exact page mounted with everything it already fetched still on
// screen and no redirect to /login, so nothing ever looked "logged
// out" until a manual browser refresh. Routing away here forces a real
// unmount, so the next login is guaranteed a fresh component + a fresh
// fetch rather than possibly-stale state left over from before.
async function handleLogout() {
  await auth.logout()
  router.push({ name: 'home' })
}

/* ---- daily intent pick -------------------------------------------- */
// Collapsed from 4 picks to 2 (2026-08-12) — 'light'/'rest' retired, see
// CoachService::PICK_TO_MODE in the API repo for why.
const picks = [
  { id: 'engage',  label: 'dig in' },
  { id: 'witness', label: 'just listen' },
]
const todaysPick = ref(null)
async function choosePick(id) {
  const next = todaysPick.value === id ? null : id
  todaysPick.value = next
  if (next) {
    await api.post('/api/intent', { pick: next }).catch(() => {})
  }
}

/* ---- entities (threads + pending notes) --------------------------- */
const entities = ref([])
const entitiesLoading = ref(true)

const confirmedEntities = computed(() =>
  entities.value.filter(e => e.trigger_status === 'confirmed')
)
// Still valence-based (not category) — this specifically feeds the weekly
// triggers panel's "tends to weigh" cross-reference below, unrelated to
// the category bubbles this file also has (see CATEGORY_META and
// categoryGroups further down). Kept independent on purpose: valence and
// category are two different columns now, see Entity::CATEGORIES's
// docblock in the API repo.
const negativeEntities = computed(() =>
  confirmedEntities.value.filter(e => e.valence === 'negative')
)

// Entity awaiting trigger confirmation (appeared multiple times, not yet confirmed)
const pendingTrigger = computed(() =>
  entities.value.find(e => e.trigger_status === 'unconfirmed' && e.entries_count >= 2) ?? null
)
// Entity awaiting valence confirmation
const pendingValence = computed(() =>
  entities.value.find(e => e.valence_suggested && e.trigger_status === 'confirmed') ?? null
)
// Entity awaiting category confirmation (2026-08-17) — checked after
// valence so a thread never asks about two suggestions in the same visit;
// deliberately independent of valence's own pending state (an entity can
// have a confirmed valence and still be awaiting a category, or vice versa).
const pendingCategory = computed(() =>
  entities.value.find(e => e.category_suggested && e.trigger_status === 'confirmed') ?? null
)

// 30 domain categories. Color cycles through --chart-1..8 (8 hues, 30
// categories = ~4 cycles). 'uncategorized' gets --muted so it visually
// reads as "not yet sorted," not as a real category. Groups are used by
// the category picker UI to avoid a flat wall of 30 unlabeled chips.
const CATEGORY_KEYS = [
  // work & career
  'work', 'career', 'business',
  // relationships
  'family', 'partner', 'kids', 'parents', 'friends', 'social',
  // inner life
  'self', 'mental', 'spirituality',
  // learning
  'study',
  // body & health
  'health', 'fitness', 'sleep', 'nutrition',
  // free time
  'hobbies', 'music', 'art', 'reading', 'gaming', 'travel', 'nature', 'passion',
  // life logistics
  'finances', 'housing',
  // community
  'community', 'pets',
  // catch-all
  'other',
]
const CATEGORY_META = Object.fromEntries(
  CATEGORY_KEYS.map((key, i) => [key, { label: key, colorVar: `var(--chart-${(i % 8) + 1})` }])
)
CATEGORY_META.uncategorized = { label: 'uncategorized', colorVar: 'var(--muted)' }

// Grouped for the category picker — keeps 30 options readable by giving
// them section headers instead of dumping them in a flat list.
const CATEGORY_GROUPS = [
  { section: 'work & career',  keys: ['work', 'career', 'business'] },
  { section: 'relationships',  keys: ['family', 'partner', 'kids', 'parents', 'friends', 'social'] },
  { section: 'inner life',     keys: ['self', 'mental', 'spirituality'] },
  { section: 'learning',       keys: ['study'] },
  { section: 'body & health',  keys: ['health', 'fitness', 'sleep', 'nutrition'] },
  { section: 'free time',      keys: ['hobbies', 'music', 'art', 'reading', 'gaming', 'travel', 'nature', 'passion'] },
  { section: 'life logistics', keys: ['finances', 'housing'] },
  { section: 'community',      keys: ['community', 'pets'] },
  { section: '',               keys: ['other'] },
]

/* ---- strategies --------------------------------------------------- */
const strategies = ref([])
const pendingStrategy = computed(() =>
  strategies.value.find(s => s.status === 'suggested') ?? null
)

/* ---- emotion detail drawer ---------------------------------------- */
const emotionDrawer     = ref(null)  // { label } while loading, then merged with detail
const emotionDrawerOpen = ref(false)

async function openEmotion(label) {
  emotionDrawer.value     = { label, loading: true }
  emotionDrawerOpen.value = true
  try {
    const { data } = await api.get(`/api/reflections/emotion-detail/${encodeURIComponent(label)}`)
    emotionDrawer.value = { ...emotionDrawer.value, ...data, loading: false }
  } catch {
    emotionDrawer.value = { ...emotionDrawer.value, loading: false, error: true }
  }
}
function closeEmotion() {
  emotionDrawerOpen.value = false
  emotionDrawer.value     = null
}

/* ---- thread detail panel ------------------------------------------ */
const threadPanel     = ref(null)  // entity object being shown
const threadDetail    = ref(null)
const threadDetailErr = ref(false)

async function openThread(entity) {
  if (threadPanel.value?.id === entity.id) {
    threadPanel.value        = null
    threadDetail.value       = null
    threadCategoryEditMode.value = false
    return
  }
  threadPanel.value        = entity
  threadDetail.value       = null
  threadDetailErr.value    = false
  threadCategoryEditMode.value = false
  try {
    const { data } = await api.get(`/api/entities/${entity.id}`)
    threadDetail.value = data
  } catch {
    threadDetailErr.value = true
  }
}
function closeThread() {
  threadPanel.value        = null
  threadDetail.value       = null
  threadCategoryEditMode.value = false
}

/* ---- trigger note entry preview ----------------------------------- */
const noteEntriesOpen    = ref(false)
const noteEntries        = ref([])
const noteEntriesLoading = ref(false)

async function toggleNoteEntries() {
  const note = activeNote.value
  if (note?.type !== 'trigger') return
  if (noteEntriesOpen.value) { noteEntriesOpen.value = false; return }
  noteEntriesOpen.value    = true
  noteEntriesLoading.value = true
  try {
    const { data } = await api.get(`/api/entities/${note.entity.id}`)
    noteEntries.value = data.recent_entries ?? []
  } catch {
    noteEntries.value = []
  } finally {
    noteEntriesLoading.value = false
  }
}

function formatNoteDate(iso) {
  return new Date(iso).toLocaleDateString([], { month: 'short', day: 'numeric' })
}

/* ---- pending thinking-pattern correction -------------------------- */
const pendingPattern = ref(null)
async function loadPendingPattern() {
  try {
    const { data } = await api.get('/api/analysis-corrections/pending-thinking-pattern')
    pendingPattern.value = data ?? null
  } catch {
    pendingPattern.value = null
  }
}

/* ---- pending canon-event suggestion (2026-08-12, see
   docs/ai-behavior.md §11) — same suggest/confirm shape as strategies
   above, just a longer-lived kind of memory. ------------------------- */
const pendingCanonEvent  = ref(null)
const contextMode        = ref(false)
const contextText        = ref('')

function openContext() {
  const note = activeNote.value
  if (note?.type !== 'canon_event') return
  contextText.value = note.event?.description ?? ''
  contextMode.value = true
}

function cancelContext() {
  contextMode.value = false
  contextText.value = ''
}

function confirmWithContext() {
  const note = activeNote.value
  if (note?.type !== 'canon_event') return
  const desc = contextText.value.trim()
  if (!desc) return
  pendingCanonEvent.value = null
  contextMode.value = false
  contextText.value = ''
  api.patch(`/api/canon-events/${note.event.id}`, {
    status: 'confirmed',
    description: desc,
  }).finally(loadCanonEvent)
}

async function loadCanonEvent(attempt = 0) {
  if (attempt > 20) return  // safety: stop if many consecutive blank suggestions
  try {
    const res = await api.get('/api/canon-events/pending')
    // 2026-10-15: "nothing pending" comes back as {} (Laravel serialises a
    // null response()->json() as an empty object), which is truthy -- so
    // without the id check this PATCHed /api/canon-events/undefined 21
    // times on every dashboard load.
    const data = res.data?.id ? res.data : null
    if (data && !data.description) {
      // no description to surface — dismiss silently and try the next one
      await api.patch(`/api/canon-events/${data.id}`, { status: 'dismissed' }).catch(() => {})
      return loadCanonEvent(attempt + 1)
    }
    pendingCanonEvent.value = data ?? null
    contextMode.value = false
    contextText.value = ''
  } catch {
    pendingCanonEvent.value = null
  }
}

/* ---- live weekly stats (always shown, not gated to Sunday) -------- */
const liveStats      = ref(null)
const liveLoading    = ref(true)

async function loadLiveStats() {
  try {
    const { data } = await api.get('/api/reflections/live')
    liveStats.value = data
  } catch {
    liveStats.value = null
  } finally {
    liveLoading.value = false
  }
}

/* ---- weekly reflection (Sunday ritual sidebar card) --------------- */
const reflection = ref(null)
const reflectionSealed = ref(false)
const reflectionSealedUntil = ref(null)
const reflectionError = ref('')

/* ---- category bubbles (2026-08-17) ---------------------------------
   Replaces the old single-level constellation (one ring of every
   confirmed entity at once) + the valence-grouped tag lists below it.
   Two levels now: category bubbles (positive/negative/work/family/
   health/social/other/uncategorized) sized by how many threads live in
   each; clicking one opens it into the individual named threads within,
   orbiting the clicked bubble. Clicking a thread bubble still opens the
   same detail drawer as before via openThread()/threadPanel, untouched.
   User: "i want to make the threads you've named into categories
   positive negative work family ext[c] ... when i click at it i see the
   componant ... css to be smart when i click at a circle another circle
   of the actual threads appear." */
const CATEGORY_ORDER = [...CATEGORY_KEYS, 'uncategorized']

const categoryGroups = computed(() => {
  const buckets = {}
  for (const e of confirmedEntities.value) {
    const key = e.category ?? 'uncategorized'
    ;(buckets[key] ??= []).push(e)
  }
  return CATEGORY_ORDER
    .filter(key => buckets[key]?.length)
    .map(key => ({ key, ...CATEGORY_META[key], entities: buckets[key], count: buckets[key].length }))
})

// Which category (if any) is currently expanded. Toggling closes any open
// thread-detail drawer too — it belonged to a bubble that's about to move
// or vanish, keeping it open would point at nothing.
const activeCategoryKey = ref(null)
const activeCategoryGroup = computed(() =>
  categoryGroups.value.find(g => g.key === activeCategoryKey.value) ?? null
)
function toggleCategory(key) {
  activeCategoryKey.value = activeCategoryKey.value === key ? null : key
  closeThread()
}

// Category bubbles arranged in a ring around the card's centre — same
// cos/sin layout the old single-level constellation used for entities,
// just one level up. Size scales with how many threads live in that
// category, so the ring itself hints at relative weight before anything
// is clicked.
const categoryBubbles = computed(() => {
  const groups = categoryGroups.value
  const n = Math.max(groups.length, 1)
  return groups.map((g, i) => {
    const a = (i / n) * Math.PI * 2 - Math.PI / 2
    return {
      ...g,
      x: 170 + Math.cos(a) * 85,
      y: 100 + Math.sin(a) * 85,
      rad: 20 + Math.min(g.count, 6) * 2.5,
    }
  })
})

// Once a category is active, ITS bubble becomes the hub the individual
// thread-bubbles orbit — so opening one reads as "this circle opened up
// into these," not as an unrelated second diagram appearing elsewhere on
// the card.
const activeHub = computed(() =>
  activeCategoryKey.value ? categoryBubbles.value.find(b => b.key === activeCategoryKey.value) ?? null : null
)
const childBubbles = computed(() => {
  const group = activeCategoryGroup.value
  const hub   = activeHub.value
  if (!group || !hub) return []
  const n = Math.max(group.entities.length, 1)
  return group.entities.map((e, i) => {
    const a = (i / n) * Math.PI * 2 - Math.PI / 2
    const weight = Math.min(e.entries_count, 8)
    const orbit = 45 + weight * 3.5
    return {
      ...e,
      x: hub.x + Math.cos(a) * orbit,
      y: hub.y + Math.sin(a) * orbit,
      rad: 7 + weight * 1.3,
    }
  })
})

// How many confirmed threads are still uncategorized — shown as a live
// counter in the thread detail panel so the user can feel the progress
// as they categorize one by one.
const uncategorizedCount = computed(() =>
  categoryGroups.value.find(g => g.key === 'uncategorized')?.count ?? 0
)

/* ---- current "gentle note" ----------------------------------------
   Priority: trigger confirmation → valence suggestion → category
   suggestion → strategy suggestion → canon-event suggestion → pattern
   correction. Strategy and canon-event sit next to each other on purpose —
   both are "should I remember this" prompts, just different kinds of
   memory. category sits right after valence since both are per-entity
   suggestions computed by the same kind of deterministic heuristic
   (EntryExtractionService), just populating different columns. */
const activeNote = computed(() => {
  if (pendingTrigger.value) {
    const e = pendingTrigger.value
    return {
      type: 'trigger',
      entity: e,
      text: `'${e.name}' has come up a few times. does it feel like something worth tracking?`,
      hint: `tracking means it gets its own thread — me will start noticing how it moves with your mood, and you'll see it in the constellation. you can always remove it later.`,
      confirmLabel: 'yes, keep an eye on it',
      dismissLabel: 'not really',
    }
  }
  if (pendingValence.value) {
    const e = pendingValence.value
    const dir = e.valence_suggested === 'positive' ? 'higher' : 'lower'
    return {
      type: 'valence',
      entity: e,
      text: `days with '${e.name}' tend to run ${dir}-mood — does that feel right?`,
      confirmLabel: 'yes, that fits',
      dismissLabel: 'not quite',
    }
  }
  if (pendingCategory.value) {
    const e = pendingCategory.value
    const label = CATEGORY_META[e.category_suggested]?.label ?? e.category_suggested
    return {
      type: 'category',
      entity: e,
      text: `'${e.name}' looks like it fits under ${label} — sound right?`,
      confirmLabel: 'yes, that fits',
      dismissLabel: 'not quite',
    }
  }
  if (pendingStrategy.value) {
    const s = pendingStrategy.value
    return {
      type: 'strategy',
      strategy: s,
      text: `you mentioned that '${s.description}' helped. want me to remember that?`,
      confirmLabel: 'yes, remember it',
      dismissLabel: 'no, leave it',
    }
  }
  if (pendingCanonEvent.value) {
    const c = pendingCanonEvent.value
    // Copy is category-aware (2026-08-14, two-stage memory pipeline) —
    // this suggestion can now be a fact/preference/goal/pattern, not just
    // an "event", so "you mentioned X, want me to remember that going
    // forward?" only fires for the original event case; everything else
    // gets a shared, category-neutral phrasing that still reads naturally
    // for "I'm a CS student" or "doesn't enjoy horror movies" alike.
    const desc = c.description
    const text = c.category === 'event'
      ? `you mentioned '${desc}' — want me to remember that going forward?`
      : `noticed this: '${desc}' — want me to remember that about you?`
    return {
      type: 'canon_event',
      event: c,
      text,
      // Source quote (2026-08-15) — shown alongside 'pattern' suggestions
      // specifically, since that category is the one most likely to carry
      // an emotional/relational characterization worth confirming against
      // the person's own words rather than just the model's paraphrase.
      // "pattern" is a proxy for "sensitive," not a definition of it — see
      // Memories.vue's matching note and docs/memory.md. Purely additive:
      // `text` above is always set regardless, so a missing quote (older
      // rows, or a failed embed at persist time) never produces a blank
      // confirmation, just one without the extra line.
      quote: (c.category === 'pattern' && c.quote) ? c.quote : null,
      confirmLabel: 'yes, remember it',
      dismissLabel: 'no, leave it',
    }
  }
  if (pendingPattern.value) {
    const p = pendingPattern.value
    const d = new Date(p.entry_date)
    const formattedDate = d.toLocaleDateString([], { month: 'short', day: 'numeric' })
    const warmPhrase = THINKING_PATTERN_WORDS[p.original_value] ?? p.original_value.replace(/_/g, ' ')
    // Confirming/dismissing this one immediately reveals the next queued
    // pattern, worded almost identically — without a visible count that
    // reads as the same suggestion refusing to go away. Naming how many
    // are left makes it clear each click is progress, not a loop.
    const more = p.remaining_after_this > 0
      ? ` (${p.remaining_after_this} more after this one)`
      : ''
    return {
      type: 'pattern_correction',
      item: p,
      text: `on ${formattedDate}, this sounded like ${warmPhrase} — "${p.evidence}". does that feel right?${more}`,
      confirmLabel: "yeah, that's fair",
      dismissLabel: 'not really',
    }
  }
  return null
})

/* 2026-08-17, real bug fix: every branch below used to be
   `api.patch(...).finally(reload)` with no `.catch()` at all. If the
   request failed for any reason — most concretely, a suggested value the
   user's already-loaded page still held that a later backend change made
   invalid (exactly what happened when the category taxonomy changed
   under an open dashboard tab: the PATCH got rejected, but `.finally()`
   still ran the reload, which restored the untouched server truth —
   silently reverting the optimistic update with zero explanation, so it
   just looked like "I clicked yes and it went back to uncategorized").
   Every call now has a `.catch()` that surfaces noteError instead of
   failing invisibly. The reload in `.finally()` is intentionally left
   as-is — on a genuine failure, reverting to server truth is correct,
   the bug was only ever that it happened silently. */
const noteError = ref('')

// When true, the category gentle note shows a full grid of categories
// instead of the normal confirm/dismiss pair.
const categoryPickerMode = ref(false)

// When true, the thread detail panel shows the category picker even for
// threads that already have a category (the user wants to change it).
const threadCategoryEditMode = ref(false)

// Assign (or reassign) a category to any thread.
//
// Two flows:
//   - Thread had NO category (uncategorized): after saving, auto-advance to
//     the next uncategorized thread so the user can work through them in one
//     pass without having to reopen the bubble each time.
//   - Thread ALREADY had a category (the user is changing it): update in
//     place, exit edit mode, stay on the same thread. No auto-advance —
//     the user is editing a specific thread, not doing a batch pass.
async function assignCategory(entityId, key) {
  noteError.value = ''
  const onFail = () => { noteError.value = "couldn't save that — try reloading the page." }

  const wasUncategorized = !threadPanel.value?.category

  // Capture the next uncategorized candidate BEFORE the optimistic update
  // (only needed when auto-advancing after a previously uncategorized thread).
  let nextEntity = null
  if (wasUncategorized) {
    const uncategorizedNow = categoryGroups.value.find(g => g.key === 'uncategorized')?.entities ?? []
    const currentIdx = uncategorizedNow.findIndex(e => e.id === entityId)
    const remaining  = uncategorizedNow.filter(e => e.id !== entityId)
    nextEntity = remaining.length > 0
      ? (remaining[currentIdx] ?? remaining[remaining.length - 1])
      : null
  }

  // Optimistic update — also patch threadPanel directly so the panel header
  // and the "change category" display reflect the new value immediately
  // without waiting for loadEntities() to complete.
  entities.value = entities.value.map(e =>
    e.id === entityId ? { ...e, category: key, category_suggested: null } : e
  )
  if (threadPanel.value?.id === entityId) {
    threadPanel.value = { ...threadPanel.value, category: key, category_suggested: null }
  }
  api.patch(`/api/entities/${entityId}`, { category: key })
    .catch(onFail).finally(loadEntities)

  if (wasUncategorized && nextEntity) {
    await nextTick()
    openThread(nextEntity)
  } else if (wasUncategorized) {
    closeThread()
  } else {
    // Changing an existing category — stay, just close the picker
    threadCategoryEditMode.value = false
  }
}

// Used when the user picks a replacement category from the gentle-note
// picker — same as assignCategory but also clears the picker mode and the
// suggestion fields on the entity.
function pickCategoryForNote(key) {
  const note = activeNote.value
  if (!note || note.type !== 'category') return
  categoryPickerMode.value = false
  assignCategory(note.entity.id, key)
}

function confirmNote() {
  const note = activeNote.value
  if (!note) return
  categoryPickerMode.value = false
  noteEntriesOpen.value = false
  noteEntries.value     = []
  noteError.value       = ''
  const onFail = () => { noteError.value = "couldn't save that — try reloading the page." }

  if (note.type === 'trigger') {
    entities.value = entities.value.map(e =>
      e.id === note.entity.id ? { ...e, trigger_status: 'confirmed' } : e
    )
    api.patch(`/api/entities/${note.entity.id}`, { trigger_status: 'confirmed' })
      .catch(onFail).finally(loadEntities)
  } else if (note.type === 'valence') {
    entities.value = entities.value.map(e =>
      e.id === note.entity.id ? { ...e, valence: e.valence_suggested, valence_suggested: null } : e
    )
    api.patch(`/api/entities/${note.entity.id}`, { valence: note.entity.valence_suggested })
      .catch(onFail).finally(loadEntities)
  } else if (note.type === 'category') {
    entities.value = entities.value.map(e =>
      e.id === note.entity.id ? { ...e, category: e.category_suggested, category_suggested: null } : e
    )
    api.patch(`/api/entities/${note.entity.id}`, { category: note.entity.category_suggested })
      .catch(onFail).finally(loadEntities)
  } else if (note.type === 'strategy') {
    strategies.value = strategies.value.map(s =>
      s.id === note.strategy.id ? { ...s, status: 'confirmed' } : s
    )
    api.patch(`/api/strategies/${note.strategy.id}`, { status: 'confirmed' })
      .catch(onFail).finally(loadStrategies)
  } else if (note.type === 'canon_event') {
    pendingCanonEvent.value = null
    api.patch(`/api/canon-events/${note.event.id}`, { status: 'confirmed' })
      .catch(onFail).finally(loadCanonEvent)
  } else if (note.type === 'pattern_correction') {
    pendingPattern.value = null
    api.post('/api/analysis-corrections', {
      entry_analysis_id: note.item.entry_analysis_id,
      field:             'thinking_patterns',
      original_value:    note.item.original_value,
      action:            'confirmed',
    }).catch(onFail).finally(loadPendingPattern)
  }
}

function dismissNote() {
  const note = activeNote.value
  if (!note) return
  categoryPickerMode.value = false
  noteEntriesOpen.value = false
  noteEntries.value     = []
  noteError.value       = ''
  const onFail = () => { noteError.value = "couldn't save that — try reloading the page." }

  if (note.type === 'trigger') {
    entities.value = entities.value.map(e =>
      e.id === note.entity.id ? { ...e, trigger_status: 'dismissed' } : e
    )
    api.patch(`/api/entities/${note.entity.id}`, { trigger_status: 'dismissed' })
      .catch(onFail).finally(loadEntities)
  } else if (note.type === 'valence') {
    entities.value = entities.value.map(e =>
      e.id === note.entity.id ? { ...e, valence: null, valence_suggested: null } : e
    )
    api.patch(`/api/entities/${note.entity.id}`, { valence: null })
      .catch(onFail).finally(loadEntities)
  } else if (note.type === 'category') {
    entities.value = entities.value.map(e =>
      e.id === note.entity.id ? { ...e, category: null, category_suggested: null } : e
    )
    api.patch(`/api/entities/${note.entity.id}`, { category: null })
      .catch(onFail).finally(loadEntities)
  } else if (note.type === 'strategy') {
    strategies.value = strategies.value.map(s =>
      s.id === note.strategy.id ? { ...s, status: 'dismissed' } : s
    )
    api.patch(`/api/strategies/${note.strategy.id}`, { status: 'dismissed' })
      .catch(onFail).finally(loadStrategies)
  } else if (note.type === 'canon_event') {
    pendingCanonEvent.value = null
    api.patch(`/api/canon-events/${note.event.id}`, { status: 'dismissed' })
      .catch(onFail).finally(loadCanonEvent)
  } else if (note.type === 'pattern_correction') {
    pendingPattern.value = null
    api.post('/api/analysis-corrections', {
      entry_analysis_id: note.item.entry_analysis_id,
      field:             'thinking_patterns',
      original_value:    note.item.original_value,
      action:            'removed',
    }).catch(onFail).finally(loadPendingPattern)
  }
}

/* ---- data loaders ------------------------------------------------- */
async function loadEntities() {
  const { data } = await api.get('/api/entities')
  entities.value = data
}
async function loadStrategies() {
  const { data } = await api.get('/api/strategies')
  strategies.value = data
}
async function loadReflection() {
  reflectionError.value = ''
  try {
    const { data } = await api.get('/api/reflections/current')
    // content may be stored as a JSON string if the model has no array cast
    if (data && typeof data.content === 'string') {
      try { data.content = JSON.parse(data.content) } catch {}
    }
    reflection.value = data
    reflectionSealed.value = false
  } catch (err) {
    if (err.response?.status === 423) {
      reflectionSealed.value = true
      reflectionSealedUntil.value = err.response.data?.sealed_until ?? null
    } else {
      reflectionError.value = 'could not load this week \u2014 try reloading.'
    }
  }
}

const greeting = (() => {
  const h = new Date().getHours()
  return h < 12 ? 'good morning.' : h < 18 ? 'good afternoon.' : 'good evening.'
})()

function daysUntilSunday() {
  const day = new Date().getDay() // 0=sun
  return day === 0 ? 0 : 7 - day
}

function trendLabel(trend) {
  return { up: '↑ lifted', down: '↓ heavier', stable: '→ steady', insufficient_data: '—' }[trend] ?? '—'
}

/* ---- schema v2 insights (emotions/stressors/needs/protective factors/
   behaviors — see docs/ai-behavior.md §6) ----------------------------
   The backend returns plain category enums (e.g. "catastrophizing",
   "work"), which are fine for aggregation but read like a lab report,
   not like something a caring friend would say. Everything below
   translates those into the same warm, plain voice the coach itself
   uses (see CoachService §4) — and deliberately never prints a raw
   percentage: the bar width carries "how much", the words carry "what"
   and roughly how often, so it reads like an observation, not a report.
   humanize/warm/NEED_WORDS/PROTECTIVE_WORDS/BEHAVIOR_WORDS/
   THINKING_PATTERN_WORDS/EMOTION_TONE/BEHAVIOR_TONE now live in
   ../utils/taxonomyDisplay.js (extracted 2026-08-17 building the monthly
   dashboard — see that file's header comment) so this view, EntryAnalysis.vue,
   and MonthlyDashboard.vue share one copy instead of drifting. */
function frequencyWord(rate) {
  if (rate === null || rate === undefined) return ''
  if (rate <= 0.15) return 'once in a while'
  if (rate <= 0.35) return 'a few times'
  if (rate <= 0.6)  return 'fairly often'
  return 'most days'
}

// Categorical palette for panels where every row previously shared one
// flat color (follow-up, 2026-08-17, user feedback: "the css sucks in
// what you've needed more of and what's been helping and what's weighed
// on you i want each entrie to have a color... i want the color entrie
// chyanges to be on the weekly dashboard"). protectiveRows was always
// tone 'positive', needRows always 'accent', triggerRows hardcoded
// tone-negative in the template — every row in each of those three
// panels rendered the literal same color, the same bug later found and
// fixed on the monthly trends page (see MonthlyDashboard.vue's CHART
// PALETTE comment). Same fix here: --chart-1..8 (defined per theme below,
// identical values to MonthlyDashboard.vue for visual consistency between
// the two pages), assigned by each row's rank position.
//
// Deliberately NOT applied to emotionRows/behaviorRows/behaviorPie —
// those two already vary by real per-label tone (EMOTION_TONE/
// BEHAVIOR_TONE), which is genuinely informative here (a positive vs.
// negative emotion, a self-supportive vs. self-undermining behavior) in
// a way the monthly page's multi-line-chart problem never had space for.
// Swapping those to arbitrary categorical color would trade real
// information for consistency alone, so they're untouched.
const CHART_PALETTE_SIZE = 8

// 'share' (2026-08-18, real usage feedback: "we need an equation that
// make the statistics more accurate like showing the dominant emotions
// with a big percentage and not them having the same value"). Width/dot
// magnitude used to be driven straight off `rate` — plain presence
// (fraction of the week's entries mentioning the label at all, capped
// at 1.0 per entry no matter how many times or how intensely). Two
// emotions each showing up in 2 of 5 entries tied at the same bar width
// even if one was a repeated intensity-9 and the other a single passing
// intensity-2 mention -- nothing about "dominant" survived that. `share`
// (computed server-side in WeeklyReflectionController::tagBreakdown())
// is each label's weighted total as a fraction of the FULL weighted
// total across every label the field produced this week, so a genuinely
// dominant feeling now actually reads as a bigger bar/more filled dots
// than one that was merely equally *present*. `rate` is left untouched
// and still drives frequencyWord() below -- "how often across days" and
// "how much this dominated" are different questions, and conflating them
// into one number was the actual bug. Falls back to rate for any
// already-sealed past week whose cached JSON predates the `share` field.
function breakdownRows(list, dict, toneFn) {
  if (!list?.length) return []
  return list.map((i, idx) => {
    const magnitude = i.share ?? i.rate
    return {
      label: warm(dict, i.label),
      rate: i.rate,
      share: magnitude,
      width: Math.round(magnitude * 100),
      filled: magnitude > 0 ? Math.max(1, Math.round(magnitude * 10)) : 0,
      freq: frequencyWord(i.rate),
      tone: toneFn ? toneFn(i.label) : 'accent',
      colorVar: `var(--chart-${(idx % CHART_PALETTE_SIZE) + 1})`,
    }
  })
}

const emotionRows    = computed(() => breakdownRows(liveStats.value?.emotion_breakdown, {}, l => EMOTION_TONE[l] ?? 'accent'))
const protectiveRows = computed(() => breakdownRows(liveStats.value?.protective_factor_breakdown, PROTECTIVE_WORDS, () => 'positive'))
const behaviorRows   = computed(() => breakdownRows(liveStats.value?.behavior_breakdown, BEHAVIOR_WORDS, l => BEHAVIOR_TONE[l] ?? 'accent'))
const needRows       = computed(() => breakdownRows(liveStats.value?.need_breakdown, NEED_WORDS, () => 'accent'))
// (needs are rendered as the radar chart below, driven directly off
// need_breakdown — there's no separate bar/gauge treatment for them, so
// no needRows here.)

// Concrete triggers — the actual named things you've confirmed as
// weighing on you (from the entities/threads system, not the abstract
// stressor category), each paired with whatever's been specifically
// confirmed to help with *that* trigger via the Strategy model. More
// useful than "work" as a category, because it's the real thing and the
// real thing that's helped it, not a guess at what might.
const triggerRows = computed(() => {
  const list = negativeEntities.value
  if (!list.length) return []
  const max = Math.max(...list.map(e => e.entries_count || 0)) || 1
  return list
    .map(e => ({
      id: e.id,
      label: e.name,
      width: Math.round(((e.entries_count || 0) / max) * 100),
      helper: strategies.value.find(s => s.entity_id === e.id && s.status === 'confirmed')?.description ?? null,
    }))
    .sort((a, b) => b.width - a.width)
    .slice(0, 6)
    // colorVar assigned AFTER sort+slice so rank order matches color
    // order (same convention as breakdownRows above) -- this list isn't
    // built from breakdownRows since it's sourced from entities/
    // strategies, not a *_breakdown array, so it needs its own pass.
    .map((r, idx) => ({ ...r, colorVar: `var(--chart-${(idx % CHART_PALETTE_SIZE) + 1})` }))
})

// "what you've been doing" — a pie chart. Slices are sized relative to
// each other (share of what showed up this week), not literal % of all
// entries, since behaviors aren't mutually exclusive within a day — a
// pie only makes sense as relative share, so that's what this is. Uses
// r.share (weighted dominance, see breakdownRows() above), not r.rate —
// same "same value" problem the emotions panel had: two behaviors tied
// on presence used to draw equal slices even when one was clearly the
// bigger part of the week by how much it actually showed up.
const behaviorPie = computed(() => {
  const rows = behaviorRows.value
  if (!rows.length) return null
  const total = rows.reduce((sum, r) => sum + r.share, 0) || 1
  const R = 78, CX = 90, CY = 90
  let angle = -Math.PI / 2
  const toneCounts = {}
  const slices = rows.map(r => {
    const frac = r.share / total
    const a0 = angle
    const a1 = angle + frac * 2 * Math.PI
    angle = a1
    const large = a1 - a0 > Math.PI ? 1 : 0
    const x0 = CX + R * Math.cos(a0), y0 = CY + R * Math.sin(a0)
    const x1 = CX + R * Math.cos(a1), y1 = CY + R * Math.sin(a1)
    const idx = toneCounts[r.tone] ?? 0
    toneCounts[r.tone] = idx + 1
    return {
      label: r.label,
      tone: r.tone,
      freq: r.freq,
      opacity: Math.max(0.45, 1 - idx * 0.22),
      path: `M${CX},${CY} L${x0},${y0} A${R},${R} 0 ${large},1 ${x1},${y1} Z`,
    }
  })
  return { slices }
})

// Experimental (see docs/ai-behavior.md §6/§7) — a single warm sentence,
// no percentage, no "this is not a diagnosis" disclaimer. If it's wrong,
// it should just read as a person being slightly off, not as a system
// making a clinical claim.
const thinkingPatternLine = computed(() => {
  const top = liveStats.value?.thinking_pattern_breakdown?.[0]
  if (!top) return null
  const freq = frequencyWord(top.rate)
  const lead = freq === 'most days' ? 'Most days' : freq.charAt(0).toUpperCase() + freq.slice(1)
  return `${lead}, your writing leaned toward ${warm(THINKING_PATTERN_WORDS, top.label)}.`
})

/* mood ring — drawn from mood_distribution (sentiment counts), reusing
   the theme's existing positive/negative colors plus muted/accent for
   neutral/mixed rather than introducing new hues. */
const MOOD_RING_R = 64
const MOOD_RING_C = 2 * Math.PI * MOOD_RING_R
const moodRing = computed(() => {
  const dist = liveStats.value?.mood_distribution
  if (!dist) return null
  const total = Object.values(dist).reduce((a, b) => a + b, 0)
  if (!total) return null
  let offset = 0
  const segments = Object.entries(dist).map(([sentiment, count]) => {
    const frac = count / total
    const seg = { sentiment, dash: `${frac * MOOD_RING_C} ${MOOD_RING_C - frac * MOOD_RING_C}`, dashoffset: -(offset * MOOD_RING_C) }
    offset += frac
    return seg
  })
  return { segments }
})

const moodCaption = computed(() => {
  const topEmotion = liveStats.value?.emotion_breakdown?.[0]
  if (topEmotion) {
    return `you've mostly been feeling ${humanize(topEmotion.label)} this week.`
  }
  const dist = liveStats.value?.mood_distribution
  const total = dist ? Object.values(dist).reduce((a, b) => a + b, 0) : 0
  if (!total) return ''
  const positiveRate = (dist.positive ?? 0) / total
  const negativeRate = (dist.negative ?? 0) / total
  if (positiveRate >= 0.6) return 'this week leaned light.'
  if (negativeRate >= 0.6) return 'this week has been a heavier one.'
  return "this week's been a bit of a mix."
})

function moodShareRows() {
  const dist = liveStats.value?.mood_distribution ?? {}
  const total = Math.max(1, Object.values(dist).reduce((a, b) => a + b, 0))
  return Object.entries(dist).map(([sentiment, count]) => ({
    label: sentiment,
    detail: String(count),
    width: Math.round((count / total) * 100),
  }))
}

const hasV2Data = computed(() =>
  !!(emotionRows.value.length || triggerRows.value.length || protectiveRows.value.length || behaviorPie.value || needRows.value.length || thinkingPatternLine.value)
)
// Showcase is driven by live stats — always visible once data loads,
// never gated to Sunday. The reflection sidebar keeps the weekly ritual.
const showV2Showcase = computed(() => !liveLoading.value && hasV2Data.value)

/* ---- pattern chains (all-time, not weekly) --------------------------
   Co-occurrence, not causation: GET /api/patterns reports, for each
   trigger that's shown up often enough, which emotion/thinking-pattern/
   behavior most often appear in the SAME entries — never "X causes Y",
   just "when X shows up, Y tends to show up too." Deliberately all-time,
   not windowed like the showcase above — a single week almost never
   repeats one trigger enough for this to mean anything. Purely a
   re-aggregation of already-extracted tags (PatternController), same
   extraction-vs-inference split as everything else here — nothing on
   this screen was asked of the model. See docs/ai-behavior.md §6
   "Pattern chains". */
const patterns       = ref([])
const patternsLoaded = ref(false)

async function loadPatterns() {
  try {
    const { data } = await api.get('/api/patterns')
    patterns.value = data.chains ?? []
  } catch {
    patterns.value = []
  } finally {
    patternsLoaded.value = true
  }
}

// `raw` on each step is the literal enum value the backend groups on —
// kept alongside the warm display label so a click can call the
// drill-through endpoint (/api/patterns/{trigger}/{field}/{value}) with
// the value the API actually recognizes, not the humanized text on screen.
const chainRows = computed(() => patterns.value.map(c => ({
  trigger:      humanize(c.trigger),
  triggerRaw:   c.trigger,
  triggerCount: c.trigger_count,
  emotion: c.emotion ? {
    label: humanize(c.emotion.label),
    raw:   c.emotion.label,
    tone:  EMOTION_TONE[c.emotion.label] ?? 'accent',
    freq:  frequencyWord(c.emotion.rate),
  } : null,
  pattern: c.thinking_pattern ? {
    label: warm(THINKING_PATTERN_WORDS, c.thinking_pattern.label),
    raw:   c.thinking_pattern.label,
    freq:  frequencyWord(c.thinking_pattern.rate),
  } : null,
  behavior: c.behavior ? {
    label: warm(BEHAVIOR_WORDS, c.behavior.label),
    raw:   c.behavior.label,
    tone:  BEHAVIOR_TONE[c.behavior.label] ?? 'accent',
    freq:  frequencyWord(c.behavior.rate),
  } : null,
})))

/* ---- pattern drill-through ("show me the entries") -------------------
   Clicking any step in a chain calls the same endpoint with different
   specificity: trigger alone lists every reliable entry behind it; with
   a field+value it narrows to entries carrying that specific link. For
   thinking_pattern links the response includes the literal evidence
   quote the model flagged (validated server-side as a real substring —
   see EntryExtractionService::validateThinkingPatterns), so it's
   highlighted in the entry text exactly like EntryAnalysis.vue does,
   letting you check the pattern against the actual writing instead of
   trusting the aggregate. */
const drill = ref(null) // { trigger, field, value, label, loading, error, entries }

async function openDrill(triggerRaw, triggerLabel, field = null, valueRaw = null, valueLabel = null) {
  drill.value = {
    trigger: triggerRaw, triggerLabel, field, value: valueRaw, valueLabel,
    loading: true, error: '', entries: [],
  }
  try {
    const path = field
      ? `/api/patterns/${encodeURIComponent(triggerRaw)}/${field}/${encodeURIComponent(valueRaw)}`
      : `/api/patterns/${encodeURIComponent(triggerRaw)}`
    const { data } = await api.get(path)
    drill.value.entries = data.entries ?? []
  } catch {
    drill.value.error = 'could not load these entries — try again in a moment.'
  } finally {
    if (drill.value) drill.value.loading = false
  }
}
function closeDrill() { drill.value = null }

function drillDate(iso) {
  const d = new Date(iso)
  return d.toLocaleDateString([], { month: 'short', day: 'numeric' }) + ' · ' + d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
}

/* Same grounded-highlighting approach as EntryAnalysis.vue's
   findEvidence/highlightSegments, reused here at drawer scale: only a
   thinking_pattern drill-through highlights anything (evidence is the
   one field genuinely tied to a text span — emotions/behaviors are read
   from the entry as a whole, so faking a span for them would mislead
   rather than help). */
function findEvidenceIdx(body, quote) {
  if (!quote) return -1
  return body.toLowerCase().indexOf(quote.trim().toLowerCase())
}
function entrySegments(entry) {
  const body = entry.body || ''
  if (drill.value?.field !== 'thinking_pattern' || !entry.items?.length) {
    return [{ text: body }]
  }
  const marks = []
  for (const it of entry.items) {
    if (!it.evidence) continue
    const start = findEvidenceIdx(body, it.evidence)
    if (start === -1) continue
    marks.push({ start, end: start + it.evidence.trim().length })
  }
  marks.sort((a, b) => a.start - b.start)
  const resolved = []
  let cursor = 0
  for (const m of marks) {
    if (m.start < cursor) continue
    resolved.push(m)
    cursor = m.end
  }
  const segments = []
  let pos = 0
  for (const m of resolved) {
    if (m.start > pos) segments.push({ text: body.slice(pos, m.start) })
    segments.push({ text: body.slice(m.start, m.end), mark: true })
    pos = m.end
  }
  if (pos < body.length) segments.push({ text: body.slice(pos) })
  return segments
}
// True when this entry has thinking-pattern evidence that couldn't be
// found verbatim in the body — same honesty EntryAnalysis.vue surfaces
// per-entry, worth flagging here too rather than silently showing nothing.
function hasUnmatchedEvidence(entry) {
  if (drill.value?.field !== 'thinking_pattern') return false
  return (entry.items ?? []).some(it => it.evidence && findEvidenceIdx(entry.body || '', it.evidence) === -1)
}

function apiErrorMessage(err, fallback) {
  return err?.response?.data?.message || fallback
}

/* ---- share to therapist (2026-08-18) --------------------------------
   A little button in the upper corner of every panel below, per the
   user's own framing ("for every diagram... a little button in the
   upper side to share it to the therapist"). Builds on the day-one
   Share/InsightSnapshot scaffolding rather than a new mechanism — see
   docs/therapist-bridge.md §1. Each share freezes exactly what's on
   screen right now for one panel into a snapshot, then grants it to
   whichever therapist is currently linked. Nothing crosses without this
   explicit per-panel action, and it's revocable afterward from
   /therapist/insights (see SharedInsights.vue). */
const myShares   = ref([])
const sharingPanel = ref(null) // { key, title, rows, caption } while the confirm strip is open
const shareNote   = ref('')
const shareBusy   = ref(false)
const shareError  = ref('')

// Static "week of Aug 11" label rather than a relative "this week" —
// the live weekly panels always show the current week in progress, but
// a share is a frozen moment that should still read correctly once the
// week has moved on (in the client's own /therapist/insights history,
// and to the therapist). Monday-start, matching daysUntilSunday() above.
const weekPeriodLabel = computed(() => {
  const now = new Date()
  const day = now.getDay() // 0=sun..6=sat
  const diffToMonday = day === 0 ? 6 : day - 1
  const monday = new Date(now)
  monday.setDate(now.getDate() - diffToMonday)
  return 'week of ' + monday.toLocaleDateString('en-US', { month: 'short', day: 'numeric' })
})

async function loadShareState() {
  const { data } = await api.get('/api/shared-insights').catch(() => ({ data: [] }))
  myShares.value = data ?? []
}

// 2026-08-18: therapist-assigned tasks -- just a count here so it's
// visible the moment the dashboard loads ("the patient cross them" only
// works if they notice there's something to cross); the actual list
// lives on Tasks.vue.
const pendingTaskCount = ref(0)
async function loadTaskCount() {
  const { data } = await api.get('/api/tasks').catch(() => ({ data: [] }))
  pendingTaskCount.value = (data ?? []).filter(t => !t.completed_at).length
}

// 2026-08-18: same in-app "day before" nudge as Sessions.vue/Therapist.vue
// (see TherapySession's docblock -- no real push/email exists in this
// app) -- only shown once a session is within 24h, so it reads as "your
// session is coming up," not a permanent fixture cluttering the header.
const soonSession = ref(null)
async function loadSoonSession() {
  const { data } = await api.get('/api/sessions').catch(() => ({ data: [] }))
  soonSession.value = (data ?? []).find(s => s.is_soon) ?? null
}

function isShared(panelKey) {
  return myShares.value.some(s => s.panel_key === panelKey && s.period_label === weekPeriodLabel.value && !s.revoked_at)
}
// Thread panel_key is entity-scoped (thread_<id>), not period-scoped.
function isEntityShared(entityId) {
  return myShares.value.some(s => s.panel_key === `thread_${entityId}` && !s.revoked_at)
}
// Pattern-chain shares are all-time, not period-scoped either -- same
// "any active share of this exact panel_key" check as isEntityShared.
function isChainShared(triggerRaw) {
  return myShares.value.some(s => s.panel_key === `pattern_${triggerRaw}` && !s.revoked_at)
}

// 2026-08-19 (user: "i want to be able to share patterns across your
// entries and choose which one to share") -- shares ONE specific chain
// row, not the whole patterns panel, so the therapist gets exactly the
// trigger->emotion->thinking_pattern->behavior link the client chose,
// not every established pattern at once. Reuses the generic
// openShareConfirm() every other panel already uses (scope='pattern',
// added to SharedInsightController::SCOPES) -- rows carry only the 3
// downstream links since the trigger itself is already the title.
function shareChain(c) {
  const rows = [
    { label: 'emotion', detail: c.emotion ? `${c.emotion.label} — ${c.emotion.freq}` : 'no clear link yet' },
    { label: 'thinking pattern', detail: c.pattern ? `${c.pattern.label} — ${c.pattern.freq}` : 'no clear link yet' },
    { label: 'behavior', detail: c.behavior ? `${c.behavior.label} — ${c.behavior.freq}` : 'no clear link yet' },
  ]
  openShareConfirm(
    `pattern_${c.triggerRaw}`,
    `pattern: ${c.trigger}`,
    rows,
    'pattern',
    `${c.triggerCount} entries, all-time`,
    'not cause and effect — just what tends to show up together when this comes up.',
  )
}

// scope defaults to 'week' and periodLabel to the current week so all
// existing call sites are unaffected. Thread shares pass scope='thread'
// and their own "as of" string.
function openShareConfirm(key, title, rows, scope = 'week', periodLabel = null, caption = null) {
  if (!rows?.length) return
  shareError.value = ''
  shareNote.value = ''
  sharingPanel.value = { key, title, rows, scope, periodLabel: periodLabel ?? weekPeriodLabel.value, caption }
}
function cancelShare() {
  sharingPanel.value = null
}
async function confirmShare() {
  if (!sharingPanel.value || shareBusy.value) return
  shareBusy.value = true
  shareError.value = ''
  try {
    const { data } = await api.post('/api/shared-insights', {
      panel_key:    sharingPanel.value.key,
      scope:        sharingPanel.value.scope,
      title:        sharingPanel.value.title,
      period_label: sharingPanel.value.periodLabel,
      caption:      sharingPanel.value.caption,
      rows:         sharingPanel.value.rows,
      note:         shareNote.value.trim() || null,
    })
    myShares.value.unshift({
      share_id:     data.share_id,
      panel_key:    sharingPanel.value.key,
      period_label: sharingPanel.value.periodLabel,
      revoked_at:   null,
    })
    sharingPanel.value = null
  } catch (e) {
    shareError.value = apiErrorMessage(e, "couldn't share that — try again in a moment.")
  } finally {
    shareBusy.value = false
  }
}

onMounted(async () => {
  // loadCanonEvent may loop through several dismissals sequentially —
  // fire it without blocking so it doesn't hold up the main content.
  loadCanonEvent()
  try {
    const [intentRes] = await Promise.all([
      api.get('/api/intent').catch(() => ({ data: null })),
      loadEntities(),
      loadStrategies(),
      loadReflection(),
      loadPendingPattern(),
      loadLiveStats(),
      loadPatterns(),
      loadShareState(),
      loadTaskCount(),
      loadSoonSession(),
    ])
    todaysPick.value = intentRes.data?.pick ?? null
  } finally {
    entitiesLoading.value = false
  }
})
</script>

<template>
  <div class="me" :data-theme="theme">
    <header class="bar">
      <img class="wordmark" src="/logo.png" alt="me" />
      <div class="bar-actions">
        <button class="ghost current">dashboard</button>
        <button class="ghost" data-tour="nav-journal" @click="router.push({ name: 'journal' })">journal</button>
        <button class="ghost" @click="router.push({ name: 'calendar' })">calendar</button>
        <button class="ghost" data-tour="nav-memories" @click="router.push({ name: 'memories' })">memories</button>
        <button class="ghost" data-tour="nav-monthly" @click="router.push({ name: 'monthly' })">monthly</button>
        <button class="ghost" data-tour="nav-therapist" @click="router.push({ name: 'my-therapist' })">therapist</button>
        <button
          v-if="pendingTaskCount > 0" class="ghost task-pill"
          @click="router.push({ name: 'tasks' })"
        >{{ pendingTaskCount }} task{{ pendingTaskCount === 1 ? '' : 's' }}</button>
        <button
          v-if="soonSession" class="ghost task-pill"
          @click="router.push({ name: 'sessions' })"
        >session soon</button>
        <button class="ghost" data-tour="tour-btn" @click="tour?.start()">tour</button>
        <button class="ghost" @click="handleLogout">log out</button>
      </div>
    </header>

    <div class="layout">
      <!-- main column -->
      <div class="main-col">
        <section class="intro">
          <h1 class="greeting">{{ greeting }}{{ auth.user ? ' ' + auth.user.name.split(' ')[0].toLowerCase() : '' }}</h1>
          <p class="ask">what do you want from me today?</p>
          <div class="picks" data-tour="picks" role="group" aria-label="today's intent">
            <button
              v-for="p in picks" :key="p.id"
              class="pill" :class="{ on: todaysPick === p.id }"
              @click="choosePick(p.id)"
            >{{ p.label }}</button>
          </div>
        </section>

        <!-- gentle note: trigger / valence / strategy / canon-event -->
        <section class="note" data-tour="note" v-if="activeNote">
          <span class="note-label">a gentle note</span>
          <template v-if="!contextMode">
            <p class="note-text">{{ activeNote.text }}</p>
            <p v-if="activeNote.quote" class="note-quote">"{{ activeNote.quote }}"</p>
            <p v-if="activeNote.hint" class="note-hint">{{ activeNote.hint }}</p>

            <!-- Category picker (shown when user clicked "not quite" on a category note) -->
            <template v-if="categoryPickerMode && activeNote.type === 'category'">
              <p class="note-subtext">which category fits better?</p>

              <!-- AI recommendation banner — always visible since this note
                   only exists when category_suggested is set -->
              <div class="cat-ai-rec" v-if="activeNote.entity.category_suggested">
                <div class="cat-ai-rec-inner">
                  <span class="cat-ai-label">AI suggests</span>
                  <button
                    class="cat-ai-chip"
                    @click="pickCategoryForNote(activeNote.entity.category_suggested)"
                  >{{ CATEGORY_META[activeNote.entity.category_suggested]?.label }}</button>
                </div>
                <span class="cat-ai-sub">or pick a different one below</span>
              </div>

              <div class="cat-picker-wrap">
                <div class="cat-group" v-for="g in CATEGORY_GROUPS" :key="g.section">
                  <span v-if="g.section" class="cat-group-label">{{ g.section }}</span>
                  <div class="cat-group-chips">
                    <button
                      v-for="key in g.keys"
                      :key="key"
                      class="cat-chip"
                      :class="{ 'cat-chip-rec': key === activeNote.entity.category_suggested }"
                      @click="pickCategoryForNote(key)"
                    >{{ CATEGORY_META[key].label }}</button>
                  </div>
                </div>
              </div>
              <div class="door" style="margin-top:12px">
                <button class="pill" @click="categoryPickerMode = false">← back</button>
                <button class="pill muted-pill" @click="dismissNote">skip for now</button>
              </div>
            </template>

            <!-- Normal confirm/dismiss buttons -->
            <template v-else>
              <div class="door">
                <button class="pill" @click="confirmNote">{{ activeNote.confirmLabel }}</button>
                <!-- For category notes, "not quite" opens the picker instead of dismissing -->
                <button
                  v-if="activeNote.type === 'category'"
                  class="pill"
                  @click="categoryPickerMode = true"
                >{{ activeNote.dismissLabel }}</button>
                <button v-else class="pill" @click="dismissNote">{{ activeNote.dismissLabel }}</button>
                <button v-if="activeNote.type === 'trigger'" class="pill" @click="toggleNoteEntries">
                  {{ noteEntriesOpen ? 'hide entries' : 'see where it came up' }}
                </button>
                <button v-if="activeNote.type === 'canon_event'" class="pill" @click="openContext">what do you mean?</button>
              </div>
            </template>
            <p v-if="noteError" class="note-error">{{ noteError }}</p>
            <div v-if="noteEntriesOpen && activeNote.type === 'trigger'" class="note-entries">
              <p v-if="noteEntriesLoading" class="note-entries-loading">loading…</p>
              <div v-else-if="!noteEntries.length" class="note-entries-loading">nothing found.</div>
              <div v-else v-for="e in noteEntries" :key="e.id" class="note-entry">
                <span class="note-entry-date">{{ formatNoteDate(e.created_at) }}</span>
                <p class="note-entry-body">{{ e.body }}</p>
                <button class="note-entry-link" @click="router.push('/analysis/' + e.id)">breakdown →</button>
              </div>
            </div>
          </template>
          <template v-else>
            <p class="note-text">how would you put it in your own words?</p>
            <textarea v-model="contextText" class="context-input" placeholder="describe it…" rows="2" />
            <div class="door">
              <button class="pill" :disabled="!contextText.trim()" @click="confirmWithContext">remember it</button>
              <button class="pill" @click="cancelContext">cancel</button>
            </div>
          </template>
        </section>

        <!-- confirmed strategies list (compact, below the note) -->
        <section class="card strats" v-if="strategies.filter(s => s.status === 'confirmed').length">
          <span class="card-label">what's helped before</span>
          <ul class="strat-list">
            <li v-for="s in strategies.filter(s => s.status === 'confirmed')" :key="s.id">
              {{ s.description }}
              <span v-if="s.entity" class="strat-entity">· {{ s.entity.name }}</span>
            </li>
          </ul>
        </section>

        <!-- threads constellation -->
        <section class="card threads" data-tour="threads">
          <span class="threads-label">threads you've named</span>

          <div v-if="entitiesLoading" class="threads-empty">loading…</div>
          <div v-else-if="!confirmedEntities.length" class="threads-empty muted">
            nothing confirmed yet — notes will appear above as things recur.
          </div>
          <template v-else>
            <button v-if="activeCategoryGroup" class="category-back" @click="toggleCategory(activeCategoryKey)">
              ‹ all categories
            </button>

            <!-- viewBox generous on purpose, combined with overflow:visible below —
                 child bubbles orbiting an off-centre hub can extend past the nominal
                 box; same reasoning the old single-level version documented here. -->
            <svg class="constellation" viewBox="-30 -60 400 340" overflow="visible" role="img" aria-label="your named threads, grouped into categories">
              <!-- level 0: one bubble per category, dimmed (not removed) once one is active
                   so the ring stays legible as "there are others" rather than vanishing. -->
              <g
                v-for="b in categoryBubbles" :key="'cat' + b.key"
                class="category-bubble"
                :class="{ 'category-bubble-active': activeCategoryKey === b.key, 'category-bubble-dim': activeCategoryKey && activeCategoryKey !== b.key }"
                @click="toggleCategory(b.key)"
              >
                <circle :cx="b.x" :cy="b.y" :r="b.rad" :style="{ fill: b.colorVar }" />
                <text :x="b.x" :y="b.y + b.rad + 14" class="category-label">{{ b.label }} ({{ b.count }})</text>
              </g>

              <!-- level 1: the individual named threads inside the active category,
                   orbiting that category's own bubble position. -->
              <line
                v-for="c in childBubbles" :key="'edge' + c.id"
                class="edge"
                :x1="activeHub?.x" :y1="activeHub?.y"
                :x2="c.x" :y2="c.y"
              />
              <g
                v-for="c in childBubbles" :key="'thread' + c.id"
                class="node-group"
                :class="{ 'node-active': threadPanel?.id === c.id }"
                @click.stop="openThread(c)"
              >
                <circle
                  :cx="c.x" :cy="c.y" :r="c.rad"
                  :class="['node', { 'node-positive': c.valence === 'positive', 'node-negative': c.valence === 'negative' }]"
                />
                <text :x="c.x" :y="c.y + c.rad + 12" class="node-label">{{ c.name }}</text>
              </g>
            </svg>

            <!-- thread detail panel -->
            <transition name="drawer">
              <div v-if="threadPanel" class="thread-detail">
                <div class="thread-detail-top">
                  <button class="drawer-close" @click="closeThread">✕</button>
                  <!-- Live counter while working through uncategorized threads -->
                  <span
                    v-if="!threadPanel.category && uncategorizedCount > 0"
                    class="thread-progress"
                  >{{ uncategorizedCount }} left to sort</span>
                </div>

                <!-- Slide transition keyed on the thread id so switching
                     to the next thread animates the content in/out. -->
                <transition name="thread-slide" mode="out-in">
                  <div :key="threadPanel.id" class="thread-slide-body">

                    <div v-if="!threadDetail && !threadDetailErr" class="drawer-loading">loading…</div>
                    <div v-else-if="threadDetailErr" class="drawer-loading muted">couldn't load.</div>
                    <template v-else-if="threadDetail">
                      <div class="drawer-head">
                        <span class="drawer-name">{{ threadPanel.name }}</span>
                        <button
                          class="panel-share-btn thread-share-btn"
                          :class="{ shared: isEntityShared(threadPanel.id) }"
                          title="share this thread with your therapist"
                          @click="openShareConfirm(
                            `thread_${threadPanel.id}`,
                            threadPanel.name,
                            [
                              { label: 'entries this week', detail: String(threadDetail.week_count) },
                              { label: 'entries total',     detail: String(threadDetail.entries_count) },
                              ...(threadDetail.strategies ?? []).map(s => ({ label: 'what helped', detail: s })),
                            ],
                            'thread',
                            `as of ${new Date().toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}`
                          )"
                        >{{ isEntityShared(threadPanel.id) ? '✓ shared' : 'share thread' }}</button>
                      </div>
                      <div class="thread-stats">
                        <div class="thread-stat">
                          <span class="tstat-num">{{ threadDetail.week_count }}</span>
                          <span class="tstat-label">this week</span>
                        </div>
                        <div class="thread-stat">
                          <span class="tstat-num">{{ threadDetail.entries_count }}</span>
                          <span class="tstat-label">all time</span>
                        </div>
                      </div>
                      <div v-if="threadDetail.strategies?.length" class="drawer-section">
                        <span class="drawer-key">what's helped with this</span>
                        <ul class="drawer-list">
                          <li v-for="(s, i) in threadDetail.strategies" :key="i">{{ s }}</li>
                        </ul>
                      </div>

                      <!-- Category row — always shown.
                           Uncategorized threads show the picker immediately.
                           Categorized threads show the current label + a
                           "change" button that opens the picker inline. -->
                      <div class="drawer-section">
                        <span class="drawer-key">category</span>

                        <!-- Currently categorized: show label + change button -->
                        <template v-if="threadPanel.category && !threadCategoryEditMode">
                          <div class="thread-cat-row">
                            <span
                              class="thread-cat-badge"
                              :style="{ background: CATEGORY_META[threadPanel.category]?.colorVar }"
                            >{{ CATEGORY_META[threadPanel.category]?.label }}</span>
                            <button class="thread-cat-change" @click="threadCategoryEditMode = true">change</button>
                          </div>
                        </template>

                        <!-- Picker (uncategorized OR edit mode) -->
                        <template v-if="!threadPanel.category || threadCategoryEditMode">
                          <!-- AI recommendation banner -->
                          <div class="cat-ai-rec" v-if="threadPanel.category_suggested">
                            <div class="cat-ai-rec-inner">
                              <span class="cat-ai-label">AI suggests</span>
                              <button
                                class="cat-ai-chip"
                                @click="assignCategory(threadPanel.id, threadPanel.category_suggested)"
                              >{{ CATEGORY_META[threadPanel.category_suggested]?.label }}</button>
                            </div>
                            <span class="cat-ai-sub">or pick below</span>
                          </div>

                          <div class="cat-picker-wrap cat-picker-wrap-sm">
                            <div class="cat-group" v-for="g in CATEGORY_GROUPS" :key="g.section">
                              <span v-if="g.section" class="cat-group-label">{{ g.section }}</span>
                              <div class="cat-group-chips">
                                <button
                                  v-for="key in g.keys"
                                  :key="key"
                                  class="cat-chip"
                                  :class="{
                                    'cat-chip-rec': key === threadPanel.category_suggested,
                                    'cat-chip-current': key === threadPanel.category,
                                  }"
                                  @click="assignCategory(threadPanel.id, key)"
                                >{{ CATEGORY_META[key].label }}</button>
                              </div>
                            </div>
                          </div>

                          <button
                            v-if="threadCategoryEditMode"
                            class="thread-cat-cancel"
                            @click="threadCategoryEditMode = false"
                          >cancel</button>
                        </template>
                      </div>
                    </template>
                  </div>
                </transition>
              </div>
            </transition>
          </template>

          <p class="threads-foot muted">you named these. me never labels them for you.</p>
        </section>
      </div>

      <!-- side column -->
      <aside class="side-col">

        <!-- weekly stats card — always shows live data; insights only unlock Sunday -->
        <section class="card reflect" data-tour="week">
          <span class="seal-eyebrow">this week</span>

          <template v-if="liveLoading">
            <p class="seal-text muted">loading…</p>
          </template>
          <template v-else-if="liveStats">
            <div class="reflect-body">
              <div class="reflect-stat" v-if="liveStats.entry_count !== undefined">
                <span class="stat-num">{{ liveStats.entry_count }}</span>
                <span class="stat-label">entries</span>
              </div>
              <div class="reflect-stat" v-if="liveStats.trend && liveStats.trend !== 'insufficient_data'">
                <span class="stat-num">{{ trendLabel(liveStats.trend) }}</span>
                <span class="stat-label">mood trend</span>
              </div>
            </div>
            <!-- end-of-week insights only available after Sunday unlock -->
            <template v-if="reflection && !reflectionSealed && reflection.content?.insights?.length">
              <p v-for="(ins, i) in reflection.content.insights" :key="i" class="insight">{{ ins }}</p>
            </template>
            <p v-else-if="reflectionSealed" class="muted" style="font-size:13px; margin:8px 0 0;">
              weekly insights unlock sunday · {{ daysUntilSunday() }} day{{ daysUntilSunday() !== 1 ? 's' : '' }} away
            </p>
            <p v-else-if="hasV2Data" class="muted" style="font-size:13px; margin:8px 0 0;">see the fuller picture below ↓</p>
          </template>
          <template v-else>
            <p class="seal-text muted">not enough data yet.</p>
          </template>
        </section>

      </aside>
    </div>

    <!-- live weekly stats — full width, always visible once data loads -->
    <div class="showcase-wrap" v-if="showV2Showcase">
      <section class="card v2-showcase" data-tour="breakdown">
        <h2 class="showcase-title">this week, so far</h2>

        <div class="showcase-grid">
          <!-- mood ring -->
          <div class="panel panel-mood" v-if="moodRing">
            <div class="panel-head">
              <span class="panel-title">how the week felt</span>
              <button
                class="panel-share-btn" :class="{ shared: isShared('mood') }"
                title="share with your therapist"
                @click="openShareConfirm('mood', 'how the week felt', moodShareRows(), 'week', null, moodCaption)"
              >{{ isShared('mood') ? '✓ shared' : 'share' }}</button>
            </div>
            <svg class="mood-ring" viewBox="0 0 160 160" aria-hidden="true">
              <circle class="ring-track" cx="80" cy="80" r="64" />
              <g transform="rotate(-90 80 80)">
                <circle
                  v-for="(seg, i) in moodRing.segments" :key="i"
                  class="ring-seg" :class="'ring-' + seg.sentiment"
                  cx="80" cy="80" r="64"
                  :stroke-dasharray="seg.dash"
                  :stroke-dashoffset="seg.dashoffset"
                />
              </g>
            </svg>
            <p class="mood-caption">{{ moodCaption }}</p>
          </div>

          <!-- pictorial fraction chart: feelings — rows are clickable -->
          <div class="panel panel-pictorial" v-if="emotionRows.length">
            <div class="panel-head">
              <span class="panel-title">the feelings that showed up</span>
              <button
                class="panel-share-btn" :class="{ shared: isShared('emotion_breakdown') }"
                title="share with your therapist"
                @click="openShareConfirm('emotion_breakdown', 'the feelings that showed up', emotionRows.map(r => ({ label: r.label, detail: r.freq, rate: r.rate, width: r.width, filled: r.filled, tone: r.tone, share: r.share })))"
              >{{ isShared('emotion_breakdown') ? '✓ shared' : 'share' }}</button>
            </div>
            <button
              class="pictorial-row pictorial-btn"
              v-for="r in emotionRows" :key="r.label"
              @click="openEmotion(r.label)"
              :class="{ 'row-active': emotionDrawer?.label === r.label }"
            >
              <span class="pictorial-label">{{ r.label }}</span>
              <div class="pictorial-dots" :class="'tone-' + r.tone">
                <span v-for="n in 10" :key="n" class="dot" :class="{ filled: n <= r.filled }"></span>
              </div>
              <span class="pictorial-freq">{{ r.freq }}</span>
            </button>

            <!-- inline emotion detail drawer -->
            <transition name="drawer">
              <div v-if="emotionDrawerOpen && emotionDrawer" class="emotion-drawer">
                <button class="drawer-close" @click="closeEmotion">✕</button>
                <p v-if="emotionDrawer.loading" class="drawer-loading">loading…</p>
                <p v-else-if="emotionDrawer.error" class="drawer-loading muted">couldn't load details.</p>
                <template v-else>
                  <div class="drawer-head">
                    <span class="drawer-name">{{ emotionDrawer.label }}</span>
                    <span class="drawer-count">{{ emotionDrawer.count }} time{{ emotionDrawer.count !== 1 ? 's' : '' }} this week</span>
                  </div>
                  <div v-if="emotionDrawer.avg_intensity != null" class="drawer-row">
                    <span class="drawer-key">avg intensity</span>
                    <div class="drawer-bar-wrap">
                      <div class="drawer-bar" :style="{ width: (emotionDrawer.avg_intensity / 10 * 100) + '%' }"></div>
                    </div>
                    <span class="drawer-val">{{ emotionDrawer.avg_intensity }}/10</span>
                  </div>
                  <div v-if="emotionDrawer.top_stressors?.length" class="drawer-section">
                    <span class="drawer-key">came up with</span>
                    <ul class="drawer-list">
                      <li v-for="s in emotionDrawer.top_stressors" :key="s">{{ humanize(s) }}</li>
                    </ul>
                  </div>
                  <div v-if="emotionDrawer.top_behaviors?.length" class="drawer-section">
                    <span class="drawer-key">often followed by</span>
                    <ul class="drawer-list">
                      <li v-for="b in emotionDrawer.top_behaviors" :key="b">{{ warm(BEHAVIOR_WORDS, b) }}</li>
                    </ul>
                  </div>
                  <div v-if="emotionDrawer.top_patterns?.length" class="drawer-section">
                    <span class="drawer-key">thinking patterns</span>
                    <ul class="drawer-list">
                      <li v-for="p in emotionDrawer.top_patterns" :key="p">{{ warm(THINKING_PATTERN_WORDS, p) }}</li>
                    </ul>
                  </div>
                  <button class="drawer-link" :disabled="auth.user?.is_demo" :title="auth.user?.is_demo ? 'off in the demo' : null" @click="router.push('/calendar')">explore in calendar →</button>
                </template>
              </div>
            </transition>
          </div>

          <!-- horizontal bar chart: what's been helping -->
          <div class="panel panel-protective" v-if="protectiveRows.length">
            <div class="panel-head">
              <span class="panel-title">what's been helping</span>
              <button
                class="panel-share-btn" :class="{ shared: isShared('protective_factor_breakdown') }"
                title="share with your therapist"
                @click="openShareConfirm('protective_factor_breakdown', 'what\'s been helping', protectiveRows.map(r => ({ label: r.label, detail: r.freq, rate: r.rate, width: r.width, filled: r.filled, tone: r.tone })))"
              >{{ isShared('protective_factor_breakdown') ? '✓ shared' : 'share' }}</button>
            </div>
            <div class="protective-row" v-for="r in protectiveRows" :key="r.label">
              <span class="protective-label">{{ r.label }}</span>
              <div class="protective-track">
                <div class="protective-fill" :style="{ width: r.width + '%', background: r.colorVar }"></div>
              </div>
              <span class="protective-freq">{{ r.freq }}</span>
            </div>
          </div>

          <!-- pie chart: what you've been doing -->
          <div class="panel panel-pie" v-if="behaviorPie">
            <div class="panel-head">
              <span class="panel-title">what you've been doing</span>
              <button
                class="panel-share-btn" :class="{ shared: isShared('behavior_breakdown') }"
                title="share with your therapist"
                @click="openShareConfirm('behavior_breakdown', 'what you\'ve been doing', behaviorRows.map(r => ({ label: r.label, detail: r.freq, rate: r.rate, width: r.width, share: r.share, tone: r.tone })))"
              >{{ isShared('behavior_breakdown') ? '✓ shared' : 'share' }}</button>
            </div>
            <div class="pie-wrap">
              <svg viewBox="0 0 180 180" class="pie-chart">
                <path
                  v-for="(s, i) in behaviorPie.slices" :key="i"
                  :d="s.path" class="pie-slice" :class="'tone-' + s.tone"
                  :style="{ opacity: s.opacity }"
                />
              </svg>
              <ul class="pie-legend">
                <li v-for="(s, i) in behaviorPie.slices" :key="'l' + i">
                  <span class="legend-dot" :class="'tone-' + s.tone" :style="{ opacity: s.opacity }"></span>
                  <span class="legend-label">{{ s.label }}</span>
                  <span class="legend-freq">{{ s.freq }}</span>
                </li>
              </ul>
            </div>
          </div>

          <!-- concrete triggers + what's specifically helped each one -->
          <div class="panel panel-triggers" v-if="triggerRows.length">
            <div class="panel-head">
              <span class="panel-title">what's been weighing on you</span>
              <button
                class="panel-share-btn" :class="{ shared: isShared('stressor_breakdown') }"
                title="share with your therapist"
                @click="openShareConfirm('stressor_breakdown', 'what\'s been weighing on you', triggerRows.map(t => ({ label: t.label, detail: t.helper ? ('helped by: ' + t.helper) : 'no strategy confirmed yet', width: t.width })))"
              >{{ isShared('stressor_breakdown') ? '✓ shared' : 'share' }}</button>
            </div>
            <div class="trigger-row" v-for="t in triggerRows" :key="t.id">
              <div class="trigger-main">
                <span class="bar-label">{{ t.label }}</span>
                <div class="bar-track"><div class="bar-fill" :style="{ width: t.width + '%', background: t.colorVar }"></div></div>
              </div>
              <p class="trigger-helper" v-if="t.helper">↳ what's helped: {{ t.helper }}</p>
            </div>
          </div>

          <!-- needs breakdown: what you might need more of -->
          <div class="panel panel-needs" v-if="needRows.length">
            <div class="panel-head">
              <span class="panel-title">what you might need more of</span>
              <button
                class="panel-share-btn" :class="{ shared: isShared('need_breakdown') }"
                title="share with your therapist"
                @click="openShareConfirm('need_breakdown', 'what you might need more of', needRows.map(r => ({ label: r.label, detail: r.freq, rate: r.rate, width: r.width, filled: r.filled, tone: r.tone })))"
              >{{ isShared('need_breakdown') ? '✓ shared' : 'share' }}</button>
            </div>
            <div class="need-row" v-for="r in needRows" :key="r.label">
              <span class="need-label">{{ r.label }}</span>
              <div class="need-track">
                <div class="need-fill" :style="{ width: r.width + '%', background: r.colorVar }"></div>
              </div>
              <span class="need-freq">{{ r.freq }}</span>
            </div>
          </div>
        </div>

        <div v-if="thinkingPatternLine" class="panel panel-thinking">
          <div class="panel-head">
            <span class="panel-title">thinking patterns</span>
            <button
              class="panel-share-btn" :class="{ shared: isShared('thinking_pattern_breakdown') }"
              title="share with your therapist"
              @click="openShareConfirm(
                'thinking_pattern_breakdown',
                'thinking patterns this week',
                (liveStats?.thinking_pattern_breakdown ?? []).map(p => ({
                  label: warm(THINKING_PATTERN_WORDS, p.label),
                  detail: frequencyWord(p.rate),
                  rate: p.rate,
                  width: Math.round((p.share ?? p.rate) * 100),
                }))
              )"
            >{{ isShared('thinking_pattern_breakdown') ? '✓ shared' : 'share' }}</button>
          </div>
          <p class="v2-thinking">{{ thinkingPatternLine }}</p>
        </div>
      </section>
    </div>

    <!-- share-to-therapist confirm strip — one shared overlay for all six
         panels above rather than six duplicated popovers, opened by
         whichever panel's share button was clicked (sharingPanel carries
         the key/title/rows so this stays panel-agnostic). -->
    <transition name="drawer">
      <div v-if="sharingPanel" class="share-overlay" @click.self="cancelShare">
        <div class="share-box">
          <span class="share-title">share "{{ sharingPanel.title }}" with your therapist?</span>
          <span class="share-sub">{{ sharingPanel.periodLabel }} — a frozen copy of what's shown now, not a live link. you can unshare anytime from the therapist page.</span>
          <textarea
            v-model="shareNote" rows="2" maxlength="1000"
            placeholder="add a note for them (optional)"
          ></textarea>
          <p v-if="shareError" class="error">{{ shareError }}</p>
          <div class="share-actions">
            <button class="ghost" @click="cancelShare" :disabled="shareBusy">cancel</button>
            <button class="pill on" @click="confirmShare" :disabled="shareBusy">{{ shareBusy ? '…' : 'share' }}</button>
          </div>
        </div>
      </div>
    </transition>

    <!-- patterns across your entries — all-time, not weekly. Co-occurrence,
         not causation: shown as a flow, described as "tends to show up
         with", never "causes". -->
    <div class="showcase-wrap">
      <section class="card v2-showcase patterns-card" data-tour="patterns">
        <h2 class="showcase-title">patterns across your entries</h2>
        <p class="patterns-sub muted">not cause and effect — just what tends to show up together, the more you write.</p>

        <p v-if="!patternsLoaded" class="empty-note muted">loading…</p>
        <p v-else-if="!chainRows.length" class="empty-note muted">
          still gathering enough entries to show a pattern here — this fills in the more you write.
        </p>

        <template v-if="chainRows.length">
        <p class="patterns-hint muted">tap any step to see the actual entries behind it.</p>

        <div class="chain-list">
          <div class="chain-row" v-for="(c, i) in chainRows" :key="i">
            <div class="chain-flow">
              <div class="chain-step">
                <button
                  class="chain-link chain-link-btn tone-negative"
                  :class="{ 'chain-link-active': drill?.trigger === c.triggerRaw && !drill?.field }"
                  @click="openDrill(c.triggerRaw, c.trigger)"
                >{{ c.trigger }}</button>
                <span class="chain-count muted">{{ c.triggerCount }} entries</span>
              </div>
              <template v-if="c.emotion">
                <span class="chain-arrow">→</span>
                <div class="chain-step">
                  <button
                    class="chain-link chain-link-btn"
                    :class="['tone-' + c.emotion.tone, { 'chain-link-active': drill?.trigger === c.triggerRaw && drill?.field === 'emotion' && drill?.value === c.emotion.raw }]"
                    @click="openDrill(c.triggerRaw, c.trigger, 'emotion', c.emotion.raw, c.emotion.label)"
                  >{{ c.emotion.label }}</button>
                  <span class="chain-count muted">{{ c.emotion.freq }}</span>
                </div>
              </template>
              <template v-if="c.pattern">
                <span class="chain-arrow">→</span>
                <div class="chain-step">
                  <button
                    class="chain-link chain-link-btn tone-accent"
                    :class="{ 'chain-link-active': drill?.trigger === c.triggerRaw && drill?.field === 'thinking_pattern' && drill?.value === c.pattern.raw }"
                    @click="openDrill(c.triggerRaw, c.trigger, 'thinking_pattern', c.pattern.raw, c.pattern.label)"
                  >{{ c.pattern.label }}</button>
                  <span class="chain-count muted">{{ c.pattern.freq }}</span>
                </div>
              </template>
              <template v-if="c.behavior">
                <span class="chain-arrow">→</span>
                <div class="chain-step">
                  <button
                    class="chain-link chain-link-btn"
                    :class="['tone-' + c.behavior.tone, { 'chain-link-active': drill?.trigger === c.triggerRaw && drill?.field === 'behavior' && drill?.value === c.behavior.raw }]"
                    @click="openDrill(c.triggerRaw, c.trigger, 'behavior', c.behavior.raw, c.behavior.label)"
                  >{{ c.behavior.label }}</button>
                  <span class="chain-count muted">{{ c.behavior.freq }}</span>
                </div>
              </template>
              <button
                class="chain-share-btn" :class="{ shared: isChainShared(c.triggerRaw) }"
                title="share this pattern with your therapist"
                @click="shareChain(c)"
              >{{ isChainShared(c.triggerRaw) ? '✓ shared' : 'share' }}</button>
            </div>

            <!-- drill-through: the actual entries behind whichever step
                 was clicked in this row -->
            <transition name="drawer">
              <div v-if="drill && drill.trigger === c.triggerRaw" class="pattern-drawer">
                <button class="drawer-close" @click="closeDrill">✕</button>
                <div class="drawer-head">
                  <span class="drawer-name">{{ drill.triggerLabel }}<template v-if="drill.valueLabel"> → {{ drill.valueLabel }}</template></span>
                  <span class="drawer-count" v-if="!drill.loading && !drill.error">{{ drill.entries.length }} entr{{ drill.entries.length === 1 ? 'y' : 'ies' }}</span>
                </div>
                <p v-if="drill.field === 'thinking_pattern'" class="drawer-hint muted">the highlighted line is the exact wording the model flagged — read it against the sentence around it and judge for yourself.</p>

                <p v-if="drill.loading" class="drawer-loading">loading…</p>
                <p v-else-if="drill.error" class="drawer-loading muted">{{ drill.error }}</p>
                <p v-else-if="!drill.entries.length" class="drawer-loading muted">no matching entries found.</p>

                <div v-else class="entry-list">
                  <div class="entry-card" v-for="e in drill.entries" :key="e.id">
                    <div class="entry-card-top">
                      <span class="entry-card-date muted">{{ drillDate(e.created_at) }}</span>
                      <span v-if="drill.field === 'emotion' && e.items?.[0]?.intensity != null" class="entry-card-meta">intensity {{ e.items[0].intensity }}/10</span>
                      <span v-if="drill.field === 'behavior' && e.items?.[0]?.impact != null" class="entry-card-meta">impact {{ e.items[0].impact }}/10</span>
                      <span v-if="e.moment_linked" class="entry-card-badge tone-positive" title="the trigger and this tag were flagged in the same part of the entry, not just somewhere in it">same moment</span>
                      <button class="entry-card-link" :disabled="auth.user?.is_demo" :title="auth.user?.is_demo ? 'off in the demo' : null" @click="router.push('/analysis/' + e.id)">full breakdown →</button>
                    </div>
                    <p class="entry-card-text">
                      <template v-for="(seg, si) in entrySegments(e)" :key="si">
                        <mark v-if="seg.mark" class="mk mk-pattern">{{ seg.text }}</mark>
                        <template v-else>{{ seg.text }}</template>
                      </template>
                    </p>
                    <p v-if="hasUnmatchedEvidence(e)" class="entry-card-note muted">flagged wording not found verbatim in this text — shown as extracted.</p>
                  </div>
                </div>
              </div>
            </transition>
          </div>
        </div>
        </template>
      </section>
    </div>

    <SpotlightTour ref="tour" tour-id="client" :steps="tourSteps" :user-id="auth.user?.id" :auto-start="!!auth.user?.is_demo" />
  </div>
</template>

<style scoped>
/* --chart-1..8: categorical palette for panels where every row would
   otherwise share one flat color (protective factors, needs, triggers —
   see the CHART PALETTE comment in <script setup>). Identical values to
   MonthlyDashboard.vue's copy, so the two pages read as the same visual
   language rather than two different systems. */
.me[data-theme='dusk'] {
  --paper:#F1F2F0; --surface:#FBFBFA; --ink:#26282B; --muted:#6B6F72;
  --accent:#7FA08A; --accent-deep:#57735F; --ochre:#C6A063; --line:#E3E4E1;
  --positive:#7FA08A; --negative:#C6A063;
  --chart-1:#7FA08A; --chart-2:#C6A063; --chart-3:#6E8CA6; --chart-4:#B97159;
  --chart-5:#8C7093; --chart-6:#8A8C5E; --chart-7:#4F8C8C; --chart-8:#A6748C;
}
.me[data-theme='paper'] {
  --paper:#F7F4EE; --surface:#FFFFFF; --ink:#33302A; --muted:#7A736A;
  --accent:#9A8C74; --accent-deep:#6E624E; --ochre:#B98B54; --line:#EAE5DC;
  --positive:#9A8C74; --negative:#B98B54;
  --chart-1:#9A8C74; --chart-2:#B98B54; --chart-3:#6E8CA6; --chart-4:#B97159;
  --chart-5:#8C7093; --chart-6:#8A8C5E; --chart-7:#4F8C8C; --chart-8:#A6748C;
}
.me[data-theme='night'] {
  --paper:#1B1D20; --surface:#232629; --ink:#E9E9E6; --muted:#9BA0A2;
  --accent:#89AE97; --accent-deep:#B6D0BF; --ochre:#D3AE74; --line:#31353A;
  --positive:#89AE97; --negative:#D3AE74;
  --chart-1:#89AE97; --chart-2:#D3AE74; --chart-3:#86AEC9; --chart-4:#D48F76;
  --chart-5:#AD93B5; --chart-6:#ABAE7C; --chart-7:#6FB0B0; --chart-8:#C594AC;
}
.me {
  --font-display:'Fraunces', Georgia, serif;
  --font-ui:'Instrument Sans', system-ui, sans-serif;
  background:var(--paper); color:var(--ink);
  font-family:var(--font-ui); line-height:1.5;
  transition:background .4s ease, color .4s ease;
  min-height: 100vh;
}
* { box-sizing:border-box; }

.bar {
  display:flex; align-items:center; justify-content:space-between;
  padding: 18px 40px; border-bottom: 1px solid var(--line);
}
.wordmark { height: 80px; width: auto; display: block; }
.bar-actions { display:flex; gap:6px; }
.ghost { background:none; border:none; color:var(--muted); font:inherit; font-size:13px;
  padding:6px 10px; border-radius:8px; cursor:pointer; }
.ghost:hover { color:var(--ink); background:var(--surface); }
.task-pill {
  border:1px solid var(--accent) !important; color:var(--accent-deep) !important;
  border-radius:999px !important; font-weight:500;
}
.task-pill:hover { background:var(--accent) !important; color:var(--surface) !important; }

.layout {
  max-width: 1120px; margin: 0 auto;
  padding: 40px 40px 80px;
  display: grid;
  grid-template-columns: minmax(0, 1fr) 340px;
  gap: 36px; align-items: start;
}
.main-col, .side-col { display: flex; flex-direction: column; gap: 20px; }
.side-col { position: sticky; top: 28px; }

.intro { margin-bottom: 6px; }
.greeting { font-family:var(--font-display); font-weight:400; font-size:34px; margin:0 0 4px; letter-spacing:.2px; }
.ask { margin:0 0 14px; color:var(--muted); font-size:15px; }
.picks { display:flex; flex-wrap:wrap; gap:8px; }

.pill {
  background:var(--surface); border:1px solid var(--line); color:var(--ink);
  font:inherit; font-size:14px; padding:8px 15px; border-radius:999px; cursor:pointer;
  transition:all .18s ease;
}
.pill:hover { border-color:var(--accent); }
.pill.on { background:var(--accent); border-color:var(--accent); color:var(--surface); }

.card {
  background:var(--surface); border:1px solid var(--line); border-radius:16px; padding:22px;
}
.card-label, .note-label, .seal-eyebrow, .threads-label {
  display:block; font-size:12px; letter-spacing:.14em; text-transform:uppercase;
  color:var(--muted); margin-bottom:10px;
}

/* gentle note */
.note { margin: 4px 2px 0; }
.note-text { font-size:16px; margin:0 0 12px; }
.note-quote {
  font-size:13.5px; line-height:1.5; font-style:italic; color:var(--muted);
  margin:-6px 0 12px; padding-left:12px; border-left:2px solid var(--line);
}
.note-hint {
  font-size:13px; line-height:1.55; color:var(--muted);
  margin:-4px 0 14px;
}
.note-error { font-size:13px; color:var(--ochre); margin:10px 0 0; }
.door { display:flex; gap:8px; flex-wrap:wrap; }
.note-entries {
  margin-top:16px; display:flex; flex-direction:column; gap:12px;
}
.note-entries-loading { font-size:13px; color:var(--muted); }
.note-entry {
  background:var(--paper); border:1px solid var(--line);
  border-radius:12px; padding:12px 14px;
}
.note-entry-date {
  display:block; font-size:11px; color:var(--muted);
  text-transform:uppercase; letter-spacing:.07em; margin-bottom:6px;
}
.note-entry-body {
  font-size:14px; line-height:1.6; margin:0 0 8px;
  white-space:pre-wrap; word-break:break-word;
}
.note-entry-link {
  background:none; border:none; font:inherit; font-size:12px;
  color:var(--accent-deep); cursor:pointer; text-decoration:underline;
  text-underline-offset:2px; padding:0;
}
.note-entry-link:hover { color:var(--ink); }
.context-input {
  width:100%; resize:vertical; font:inherit; font-size:14px;
  background:var(--surface); border:1px solid var(--line);
  border-radius:10px; padding:10px 12px; color:var(--ink);
  margin-bottom:10px; outline:none; box-sizing:border-box;
}
.context-input:focus { border-color:var(--accent); }

/* =====================================================================
   CATEGORY PICKER — used in the gentle-note "pick different one" flow
   and the thread detail panel's "assign a category" section
   ===================================================================== */

.note-subtext {
  font-size:14px; color:var(--muted); margin:0 0 12px;
}

/* AI recommendation banner ---------------------------------------- */
.cat-ai-rec {
  display:flex; flex-direction:column; gap:6px;
  background:var(--paper); border:1px solid var(--line);
  border-left:3px solid var(--accent);
  border-radius:10px; padding:11px 14px; margin-bottom:14px;
}
.cat-ai-rec-inner {
  display:flex; align-items:center; gap:10px;
}
.cat-ai-label {
  font-size:10.5px; font-weight:700; letter-spacing:.12em; text-transform:uppercase;
  color:var(--accent); white-space:nowrap;
}
.cat-ai-chip {
  background:var(--accent); border:none; color:var(--surface);
  font:inherit; font-size:13px; font-weight:600;
  padding:5px 16px; border-radius:8px; cursor:pointer;
  transition:opacity .15s ease;
}
.cat-ai-chip:hover { opacity:.85; }
.cat-ai-sub {
  font-size:11.5px; color:var(--muted);
}

/* Picker body: sections + chips ------------------------------------ */
.cat-picker-wrap {
  display:flex; flex-direction:column; gap:10px;
  max-height:320px; overflow-y:auto; padding-right:4px;
  margin-bottom:4px;
}
.cat-picker-wrap-sm { max-height:220px; gap:8px; }

.cat-group { display:flex; flex-direction:column; gap:5px; }
.cat-group-label {
  font-size:10px; font-weight:700; letter-spacing:.12em; text-transform:uppercase;
  color:var(--muted);
}
.cat-group-chips { display:flex; flex-wrap:wrap; gap:5px; }

.cat-chip {
  background:var(--surface); border:1px solid var(--line); color:var(--ink);
  font:inherit; font-size:12.5px; padding:5px 12px; border-radius:8px;
  cursor:pointer; transition:border-color .15s, background .15s;
  white-space:nowrap;
}
.cat-chip:hover { border-color:var(--accent); }

/* Recommended chip — filled accent so it pops out of the grid */
.cat-chip-rec {
  background:var(--accent); border-color:var(--accent); color:var(--surface);
  font-weight:600;
}
.cat-chip-rec:hover { opacity:.88; }

/* "skip for now" muted button */
.muted-pill { color:var(--muted); border-color:var(--line); }
.muted-pill:hover { color:var(--ink); border-color:var(--accent); }

/* Thin scrollbar for the overflowing picker */
.cat-picker-wrap::-webkit-scrollbar { width:3px; }
.cat-picker-wrap::-webkit-scrollbar-track { background:transparent; }
.cat-picker-wrap::-webkit-scrollbar-thumb { background:var(--line); border-radius:3px; }

/* Current category label + change button -------------------------  */
.thread-cat-row {
  display:flex; align-items:center; gap:10px; margin-top:4px;
}
.thread-cat-badge {
  display:inline-block; padding:4px 12px; border-radius:8px;
  font-size:12.5px; font-weight:600; color:var(--surface);
  /* colorVar applied inline via :style */
}
.thread-cat-change {
  background:none; border:none; font:inherit; font-size:12.5px;
  color:var(--muted); cursor:pointer; text-decoration:underline;
  text-underline-offset:2px; padding:0;
}
.thread-cat-change:hover { color:var(--ink); }
.thread-cat-cancel {
  background:none; border:none; font:inherit; font-size:12px;
  color:var(--muted); cursor:pointer; margin-top:8px; padding:0;
  text-decoration:underline; text-underline-offset:2px;
}
.thread-cat-cancel:hover { color:var(--ink); }

/* Currently-active category chip highlighted in the change picker */
.cat-chip-current {
  border-color:var(--accent); color:var(--accent-deep); font-weight:600;
}

/* Thread panel top bar (close + progress counter) ----------------- */
.thread-detail-top {
  display:flex; align-items:center; justify-content:space-between;
  margin-bottom:8px;
}
/* Override the absolute positioning for the close button when it's
   inside the top bar — let it sit in the flex row instead. */
.thread-detail-top .drawer-close {
  position:static; padding:0; font-size:14px;
}
.thread-progress {
  font-size:11.5px; color:var(--accent); font-weight:600;
  letter-spacing:.03em;
}

/* Slide transition between threads -------------------------------- */
.thread-slide-body { /* wrapper div that gets the transition key */ }
.thread-slide-enter-active { transition: opacity .18s ease, transform .18s ease; }
.thread-slide-leave-active { transition: opacity .12s ease, transform .12s ease; }
.thread-slide-enter-from   { opacity:0; transform:translateX(16px); }
.thread-slide-leave-to     { opacity:0; transform:translateX(-16px); }

/* strategies */
.strats { }
.strat-list { list-style:none; margin:0; padding:0; display:flex; flex-direction:column; gap:6px; }
.strat-list li { font-size:14px; color:var(--ink); }
.strat-entity { color:var(--muted); font-size:12px; }

/* threads — two-level category bubbles (2026-08-17) */
.threads-empty { font-size:14px; color:var(--muted); padding:8px 0; }
.category-back {
  display:block; margin:0 0 6px; background:none; border:none; cursor:pointer;
  font:inherit; font-size:12.5px; color:var(--muted); padding:2px 0;
}
.category-back:hover { color:var(--ink); }
.constellation {
  width:100%; height:auto; display:block;
  max-width:460px; margin:0 auto;
  overflow:visible; /* child bubbles orbiting an off-centre hub can extend past the nominal viewBox */
}
.edge { stroke:var(--line); stroke-width:1.5; transition:opacity .3s ease; }

/* level 0: category bubbles. cx/cy/r are plain SVG geometry properties —
   CSS transitions on them (plus opacity) are what makes clicking one
   "smart": the ring smoothly resolves into a hub + satellites rather than
   jumping between two unrelated states. */
.category-bubble circle {
  cursor:pointer; opacity:.92; stroke:var(--surface); stroke-width:2;
  transition:cx .45s ease, cy .45s ease, r .45s ease, opacity .35s ease, stroke-width .2s ease;
}
.category-bubble:hover circle { opacity:1; }
.category-bubble-active circle { stroke:var(--ink); stroke-width:3; }
.category-bubble-dim circle { opacity:.35; }
.category-bubble-dim:hover circle { opacity:.6; }
.category-label {
  fill:var(--ink); font-size:11px; font-weight:500; text-anchor:middle;
  font-family:var(--font-ui); pointer-events:none; transition:x .45s ease, y .45s ease, opacity .35s ease;
}
.category-bubble-dim .category-label { opacity:.5; }

/* level 1: the named threads inside the active category — same node/
   node-group/node-label classes the old single-level view used, so a
   thread bubble's own click/hover/active behavior (and the detail drawer
   it opens) is unchanged, just now scoped to one category at a time. */
/* Thread bubbles are colored by valence, not by their category — this is
   the second-level distinction the category redesign asked for ("when i
   click i'll see the entries positive or negative"). Same var(--positive)/
   var(--negative) the app used before any of this category work existed;
   an entity with no valence yet stays the plain --accent default. */
.node {
  fill:var(--accent); stroke:var(--surface); stroke-width:1.5;
  transition:cx .45s ease, cy .45s ease, r .3s ease, fill .15s ease;
}
.node-positive { fill:var(--positive); }
.node-negative { fill:var(--negative); }
.node-label {
  fill:var(--muted); font-size:10px; text-anchor:middle; font-family:var(--font-ui);
  pointer-events:none; transition:x .45s ease, y .45s ease;
}
.node-group { cursor:pointer; }
.node-group:hover .node { filter:brightness(0.88); }
.node-group.node-active .node { stroke:var(--ink); stroke-width:2; }

.threads-foot { font-size:13px; margin-top:12px; text-align:center; color:var(--muted); }
.muted { color:var(--muted); }

/* weekly reflection */
.reflect {}
.seal-text { font-size:16px; margin:0 0 4px; }
.seal-sub { font-size:13px; margin:0; }
.reflect-body { display:flex; gap:20px; margin-bottom:12px; }
.reflect-stat { display:flex; flex-direction:column; }
.stat-num { font-size:22px; font-family:var(--font-display); font-weight:400; }
.stat-label { font-size:11px; text-transform:uppercase; letter-spacing:.1em; color:var(--muted); }
.insight { font-size:13px; color:var(--muted); margin:6px 0 0; line-height:1.5; }

/* ==================================================================
   THIS WEEK, VISUALLY — full-width showcase below the two-column
   layout. Bigger, desktop-scale panels: a different diagram type per
   category (ring / pictorial / circular gauges / bars / radar), all
   still built from the theme's existing positive/negative/accent/muted
   palette — no new hues introduced anywhere below.
   ================================================================== */
.showcase-wrap { max-width:1120px; margin:0 auto; padding:0 40px 80px; }
.v2-showcase {
  padding:44px 48px 48px;
  border-radius:20px;
  box-shadow: 0 1px 2px rgba(0,0,0,.04), 0 20px 44px -20px rgba(0,0,0,.14);
}
.showcase-title {
  font-family:var(--font-display); font-weight:400; font-size:28px;
  margin:0 0 32px; letter-spacing:.2px;
}
.showcase-grid { display:grid; grid-template-columns: 1fr 1fr; gap:48px 64px; }
.panel { display:flex; flex-direction:column; min-width:0; }
.panel-title {
  display:block; font-size:12px; letter-spacing:.12em; text-transform:uppercase;
  color:var(--muted); margin-bottom:20px;
}

/* 2026-08-18: share-to-therapist button, upper corner of every panel —
   .panel-head wraps the title so the button can sit flush right of it
   without every panel restyling its own title spacing. */
.panel-head { display:flex; align-items:flex-start; justify-content:space-between; gap:12px; width:100%; margin-bottom:20px; }
.panel-head .panel-title { margin-bottom:0; }
.panel-share-btn {
  flex:0 0 auto; font-size:11px; padding:3px 10px; border-radius:999px;
  border:1px solid var(--line); background:var(--surface); color:var(--muted);
  cursor:pointer; white-space:nowrap; font-family:var(--font-ui);
  transition:background .2s ease, color .2s ease, border-color .2s ease;
}
.panel-share-btn:hover { border-color:var(--accent); color:var(--ink); }
.panel-share-btn.shared { border-color:var(--accent); color:var(--accent-deep); }

.share-overlay {
  position:fixed; inset:0; background:rgba(0,0,0,.32);
  display:flex; align-items:center; justify-content:center; z-index:50; padding:20px;
}
.share-box {
  background:var(--surface); border:1px solid var(--line); border-radius:14px;
  padding:22px 24px; max-width:380px; width:100%;
  display:flex; flex-direction:column; gap:10px;
  box-shadow:0 20px 44px -20px rgba(0,0,0,.35);
}
.share-title { font-family:var(--font-display); font-size:17px; line-height:1.3; }
.share-sub { font-size:12.5px; color:var(--muted); line-height:1.4; }
.share-box textarea {
  font:inherit; font-size:13.5px; padding:8px 10px; border:1px solid var(--line);
  border-radius:8px; background:var(--paper); color:var(--ink); resize:vertical;
}
.share-actions { display:flex; justify-content:flex-end; gap:8px; margin-top:4px; }
.error { font-size:13px; color:var(--ochre); margin:0; }

/* Shared tone → color mapping. Every chart below (gauges, pie, legend,
   dots) colors itself off one of these three classes instead of each
   element type repeating its own positive/negative/accent rules — set
   --tone-color once here, everything else just reads it. */
.tone-positive { --tone-color: var(--positive); }
.tone-negative { --tone-color: var(--negative); }
.tone-accent   { --tone-color: var(--accent); }

/* mood ring */
.panel-mood { align-items:center; text-align:center; }
.mood-ring { width:200px; height:200px; }
.ring-track { fill:none; stroke:var(--line); stroke-width:18; }
.ring-seg { fill:none; stroke-width:18; transition:stroke-dasharray .5s ease; }
.ring-positive { stroke:var(--positive); }
.ring-negative { stroke:var(--negative); }
.ring-neutral { stroke:var(--muted); }
.ring-mixed { stroke:var(--accent); opacity:.45; }
.mood-caption { font-family:var(--font-display); font-size:20px; margin:20px 0 0; max-width:280px; line-height:1.35; }

/* pictorial fraction chart — a row of dots per feeling, the filled
   fraction is the same idea as an isotype/"3 in 10 people" chart. */
.pictorial-row { display:flex; align-items:center; gap:16px; margin-bottom:16px; }
.pictorial-row:last-child { margin-bottom:0; }
.pictorial-label { flex:0 0 auto; width:104px; font-size:14.5px; }
.pictorial-dots { display:flex; gap:6px; flex:1; }
.dot { width:16px; height:16px; border-radius:50%; background:var(--line); flex:0 0 auto; transition:background .3s ease; }
.dot.filled { background:var(--tone-color, var(--accent)); }
.pictorial-freq { flex:0 0 auto; width:104px; font-size:12px; color:var(--muted); text-align:right; }

/* pie chart — behaviors, colored by rough self-supportive vs
   self-undermining split (BEHAVIOR_TONE), same-tone slices stepped down
   in opacity (inline style) so individual slices stay distinguishable. */
.pie-wrap { display:flex; align-items:center; gap:28px; }
.pie-chart { width:168px; height:168px; flex:0 0 auto; }
.pie-slice { fill:var(--tone-color, var(--accent)); stroke:var(--surface); stroke-width:2; transition:opacity .3s ease; }
.pie-legend { list-style:none; margin:0; padding:0; display:flex; flex-direction:column; gap:10px; flex:1; min-width:0; }
.pie-legend li { display:flex; align-items:center; gap:10px; font-size:13.5px; }
.legend-dot { width:11px; height:11px; border-radius:50%; flex:0 0 auto; background:var(--tone-color, var(--accent)); }
.legend-label { flex:1; min-width:0; }
.legend-freq { flex:0 0 auto; font-size:11.5px; color:var(--muted); }

/* horizontal bar chart — what's been helping (replaces circular gauges) */
.protective-row {
  display:flex; align-items:center; gap:14px; margin-bottom:16px;
}
.protective-row:last-child { margin-bottom:0; }
.protective-label { flex:0 0 auto; width:130px; font-size:14.5px; }
.protective-track {
  flex:1; height:10px; border-radius:999px;
  background:var(--paper); overflow:hidden;
}
.protective-fill {
  /* background set per-row via inline style (r.colorVar) -- this is just
     the fallback if that's ever missing. */
  height:100%; border-radius:999px; background:var(--accent);
  transition:width .5s ease, background .3s ease;
}
.protective-freq { flex:0 0 auto; width:100px; font-size:12px; color:var(--muted); text-align:right; }

/* emotion detail drawer — slides in below the pictorial chart */
.pictorial-btn {
  width:100%; background:none; border:none; cursor:pointer;
  font:inherit; color:inherit; text-align:left;
  padding:6px 8px; border-radius:8px; margin:0 -8px;
  transition:background .14s ease;
}
.pictorial-btn:hover { background:var(--paper); }
.pictorial-btn.row-active { background:var(--paper); }

.emotion-drawer, .thread-detail {
  position:relative;
  margin-top:20px;
  padding:20px 22px;
  background:var(--paper);
  border:1px solid var(--line);
  border-radius:14px;
}
/* .pattern-drawer defines the same chrome itself (see "pattern
   drill-through drawer" below) since it lives in a different part of the
   template — kept here as a comment so the duplication is intentional,
   not missed. */
.drawer-close {
  position:absolute; top:12px; right:14px;
  background:none; border:none; font:inherit; font-size:14px;
  color:var(--muted); cursor:pointer; line-height:1; padding:2px 4px;
}
.drawer-close:hover { color:var(--ink); }
.drawer-loading { font-size:14px; color:var(--muted); }
.drawer-head {
  display:flex; flex-direction:row; align-items:center;
  justify-content:space-between; gap:10px; margin-bottom:16px;
}
.drawer-name {
  font-family:var(--font-display); font-size:20px; font-weight:400; letter-spacing:.2px;
  text-transform:capitalize;
}
.drawer-count { font-size:13px; color:var(--muted); }
.drawer-row {
  display:flex; align-items:center; gap:12px; margin-bottom:14px;
}
.drawer-bar-wrap {
  flex:1; height:8px; border-radius:999px; background:var(--line); overflow:hidden;
}
.drawer-bar {
  height:100%; border-radius:999px; background:var(--accent); transition:width .4s ease;
}
.drawer-val { font-size:13px; color:var(--muted); white-space:nowrap; }
.drawer-section { margin-bottom:14px; }
.drawer-section:last-of-type { margin-bottom:0; }
.drawer-key {
  display:block; font-size:11px; text-transform:uppercase; letter-spacing:.1em;
  color:var(--muted); margin-bottom:6px;
}
.drawer-list {
  list-style:none; margin:0; padding:0; display:flex; flex-direction:column; gap:4px;
}
.drawer-list li { font-size:14px; color:var(--ink); }
.drawer-list li::before { content:'· '; color:var(--muted); }
.drawer-link {
  display:inline-block; margin-top:16px;
  background:none; border:none; font:inherit; font-size:13px;
  color:var(--accent-deep); cursor:pointer; text-decoration:underline;
  text-underline-offset:3px; padding:0;
}
.drawer-link:hover { color:var(--ink); }

/* transition */
.drawer-enter-active, .drawer-leave-active { transition:opacity .2s ease, transform .2s ease; }
.drawer-enter-from, .drawer-leave-to { opacity:0; transform:translateY(-6px); }

/* thread detail */
.thread-stats { display:flex; gap:28px; margin-bottom:16px; }
.thread-stat  { display:flex; flex-direction:column; }
.tstat-num    { font-size:26px; font-family:var(--font-display); font-weight:400; }
.tstat-label  { font-size:11px; text-transform:uppercase; letter-spacing:.1em; color:var(--muted); }

/* concrete triggers — real named things (from the entities/threads
   system), each with whatever's been specifically confirmed to help it. */
.panel-triggers .bar-label { flex:0 0 auto; width:150px; font-size:14.5px; }
.panel-triggers .bar-track { flex:1; height:10px; border-radius:999px; background:var(--paper); overflow:hidden; }
.panel-triggers .bar-fill {
  /* background set per-row via inline style (t.colorVar) -- this is just
     the fallback if that's ever missing. */
  height:100%; border-radius:999px; background:var(--negative);
  transition:width .5s ease, background .3s ease;
}
.trigger-row { margin-bottom:18px; }
.trigger-row:last-child { margin-bottom:0; }
.trigger-main { display:flex; align-items:center; gap:14px; }
.trigger-helper { margin:8px 0 0 164px; font-size:12.5px; color:var(--muted); font-style:italic; line-height:1.4; }

/* needs breakdown bar chart */
.need-row {
  display:flex; align-items:center; gap:14px; margin-bottom:16px;
}
.need-row:last-child { margin-bottom:0; }
.need-label { flex:0 0 auto; width:104px; font-size:14.5px; }
.need-track {
  flex:1; height:10px; border-radius:999px;
  background:var(--paper); overflow:hidden;
}
.need-fill {
  /* background set per-row via inline style (r.colorVar) -- this is just
     the fallback if that's ever missing. */
  height:100%; border-radius:999px; background:var(--accent);
  transition:width .5s ease, background .3s ease;
}
.need-freq { flex:0 0 auto; width:104px; font-size:12px; color:var(--muted); text-align:right; }

/* thinking pattern line — now inside .panel-thinking; remove the
   large standalone margin, keep the quiet italic style */
.panel-thinking { }  /* inherits .panel; no extra rules needed */
.v2-thinking { font-size:14.5px; color:var(--muted); margin:8px 0 0; line-height:1.6; font-style:italic; }

/* Share button inside the thread detail drawer head */
.thread-share-btn {
  flex:0 0 auto; font-size:11px; padding:3px 10px;
  border-radius:999px; border:1px solid var(--line); background:var(--surface);
  color:var(--muted); cursor:pointer; white-space:nowrap; font-family:var(--font-ui);
  transition:background .2s ease, color .2s ease, border-color .2s ease;
}

/* ==================================================================
   PATTERNS ACROSS YOUR ENTRIES — all-time chain flows. Same card/tone
   system as the weekly showcase above, no new hues.
   ================================================================== */
.patterns-card { margin-top: 32px; }
.patterns-sub { font-size:13.5px; margin:-20px 0 32px; }
.empty-note { font-size:14px; margin:0; }

.patterns-hint { font-size:12.5px; margin:-24px 0 22px; }

.chain-list { display:flex; flex-direction:column; gap:20px; }
.chain-row { display:flex; flex-direction:column; }
.chain-flow { display:flex; align-items:flex-start; flex-wrap:wrap; gap:10px; }
.chain-step { display:flex; flex-direction:column; align-items:center; gap:5px; }
.chain-link {
  font-size:13.5px; padding:7px 15px; border-radius:999px;
  background:var(--tone-color, var(--muted)); color:var(--surface);
  white-space:nowrap;
}
/* chain links are buttons now (drill-through) — reset button chrome,
   keep the exact pill look .chain-link already had. */
.chain-link-btn {
  border:2px solid transparent; font:inherit; cursor:pointer;
  transition:border-color .15s ease, transform .1s ease;
}
.chain-link-btn:hover { transform:translateY(-1px); }
.chain-link-active { border-color:var(--ink); }
.chain-count { font-size:11px; }
.chain-arrow { color:var(--muted); font-size:16px; align-self:center; margin-top:9px; }
/* 2026-08-19: per-chain share button (user: "share patterns across your
   entries and choose which one to share") -- same pill language as
   .panel-share-btn, just sitting inline at the end of the flow instead
   of a .panel-head corner, since a chain row has no title bar of its
   own. */
.chain-share-btn {
  flex:0 0 auto; align-self:center; margin-top:9px; margin-left:auto;
  font-size:11px; padding:3px 10px; border-radius:999px;
  border:1px solid var(--line); background:var(--surface); color:var(--muted);
  cursor:pointer; white-space:nowrap; font-family:var(--font-ui);
  transition:background .2s ease, color .2s ease, border-color .2s ease;
}
.chain-share-btn:hover { border-color:var(--accent); color:var(--ink); }
.chain-share-btn.shared { border-color:var(--accent); color:var(--accent-deep); }

/* pattern drill-through drawer — same generic .drawer-* chrome as the
   emotion/thread drawers above, plus its own list of entry excerpts. */
.pattern-drawer { position:relative; margin-top:16px; padding:20px 22px;
  background:var(--paper); border:1px solid var(--line); border-radius:14px; }
.drawer-hint { font-size:12.5px; margin:-8px 0 16px; line-height:1.5; }

.entry-list { display:flex; flex-direction:column; gap:14px; }
.entry-card {
  background:var(--surface); border:1px solid var(--line); border-radius:12px;
  padding:16px 18px;
}
.entry-card-top { display:flex; align-items:center; gap:10px; margin-bottom:8px; flex-wrap:wrap; }
.entry-card-date { font-size:12px; }
.entry-card-meta { font-size:12px; color:var(--muted); }
.entry-card-badge {
  font-size:10.5px; letter-spacing:.04em; padding:2px 9px; border-radius:999px;
  background:color-mix(in srgb, var(--tone-color, var(--accent)) 16%, transparent);
  color:var(--tone-color, var(--accent-deep));
}
.entry-card-link {
  margin-left:auto; background:none; border:none; font:inherit; font-size:12px;
  color:var(--accent-deep); cursor:pointer; text-decoration:underline;
  text-underline-offset:3px; padding:0; white-space:nowrap;
}
.entry-card-link:hover { color:var(--ink); }
/* 2026-11-10: off in the demo */
.drawer-link:disabled, .entry-card-link:disabled { opacity:.4; cursor:not-allowed; text-decoration:none; }
.drawer-link:disabled:hover, .entry-card-link:disabled:hover { color:inherit; }
.entry-card-text {
  font-size:14.5px; line-height:1.7; white-space:pre-wrap; word-break:break-word;
  margin:0;
}
.entry-card-note { font-size:12px; margin:8px 0 0; }
.mk { border-radius:4px; padding:1px 3px; }
.mk-pattern { background:color-mix(in srgb, var(--ochre) 35%, transparent); }

@media (max-width: 860px) {
  .bar { padding: 16px 20px; }
  .layout { grid-template-columns: 1fr; padding: 24px 20px 60px; max-width: 560px; }
  .side-col { position: static; }
  .greeting { font-size: 28px; }

  .showcase-wrap { padding:0 20px 60px; }
  .v2-showcase { padding:28px 22px 32px; }
  .showcase-title { font-size:22px; margin-bottom:24px; }
  .showcase-grid { grid-template-columns: 1fr; gap:36px; }
  .panel-triggers .bar-label { width:120px; font-size:13px; }
  .trigger-helper { margin-left:134px; }
  .pie-wrap { flex-direction:column; align-items:flex-start; gap:20px; }
}
/* phones (2026-11-07): nothing in the dashboard may be wider than the
   screen -- the dot rows had a fixed 104px + 104px + 214px of dots, and
   pattern chips never wrapped their (sometimes long) labels. */
@media (max-width: 640px) {
  .layout { grid-template-columns: minmax(0, 1fr); }
  .showcase-grid { grid-template-columns: minmax(0, 1fr); }
  .pictorial-row { gap: 8px; }
  .pictorial-label { width: 84px; font-size: 13.5px; }
  .pictorial-dots { gap: 3px; flex: 1 1 auto; min-width: 0; }
  .pictorial-dots .dot { width: 10px; height: 10px; }
  .pictorial-freq { width: 60px; font-size: 11px; white-space: normal; line-height: 1.3; }
  .chain-flow { flex-wrap: wrap; row-gap: 8px; }
  .chain-step { max-width: 100%; }
  .chain-link { white-space: normal; text-align: center; max-width: 100%; }
}
@media (prefers-reduced-motion: reduce) { .me, * { transition:none !important; } }
</style>
