<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '../lib/api'
import {
  humanize, warm, PROTECTIVE_WORDS, BEHAVIOR_WORDS,
  THINKING_PATTERN_WORDS, EMOTION_TONE, BEHAVIOR_TONE,
} from '../utils/taxonomyDisplay'

const route  = useRoute()
const router = useRouter()

const themes = ['dusk', 'paper', 'night']
const theme  = ref('dusk')
function cycleTheme() { theme.value = themes[(themes.indexOf(theme.value) + 1) % themes.length] }

/* ---- load -------------------------------------------------------- */
const entry      = ref(null)
const loading    = ref(true)
const loadError  = ref('')
const correctionError = ref('')

async function load() {
  loading.value   = true
  loadError.value = ''
  try {
    const { data } = await api.get(`/api/entries/${route.params.id}`)
    entry.value = data
  } catch (err) {
    loadError.value = err.response?.status === 404
      ? "couldn't find that entry."
      : 'could not load this entry — try reloading.'
  } finally {
    loading.value = false
  }
}
onMounted(load)

const analysis = computed(() => entry.value?.analysis ?? null)

function formatStamp(iso) {
  const d = new Date(iso)
  return d.toLocaleDateString([], { weekday: 'long', month: 'long', day: 'numeric' })
    + ' · ' + d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
}

/* ---- humanizing dictionaries — now shared with Dashboard.vue and
   MonthlyDashboard.vue via ../utils/taxonomyDisplay.js (extracted
   2026-08-17; previously kept local here since nothing else in this app
   shared composables across views — a third copy for the new monthly
   page was the point that stopped being worth it, see that file's
   header comment). ---- */

// Mirrors App\Support\ExtractionTaxonomy — the exact closed vocab the API
// accepts for a "change to…" correction, so the dropdown never offers a
// value the backend would reject.
const TAXONOMY = {
  // 'amusement' and 'annoyance' were both missing here (found 2026-08-17
  // while adding annoyance to the backend taxonomy) -- this list had
  // drifted out of sync with ExtractionTaxonomy::EMOTIONS since amusement
  // was added 2026-08-12; the "change to..." dropdown for emotions has
  // been silently missing both this whole time as a result. See
  // dashboard-logic.md #14's existing note about this file's dictionaries
  // not being programmatically linked to the backend taxonomy.
  // 13 more emotions and 17 more behaviors added 2026-08-17 — mirrors
  // ExtractionTaxonomy::EMOTIONS / ::BEHAVIORS after that pass.
  emotions: ['joy', 'sadness', 'anger', 'annoyance', 'fear', 'disgust', 'surprise', 'love', 'shame', 'guilt', 'anxiety', 'calm', 'hope', 'amusement', 'excitement', 'loneliness', 'jealousy', 'disappointment', 'gratitude', 'pride', 'embarrassment', 'confusion', 'relief', 'boredom', 'nostalgia', 'curiosity', 'overwhelm'],
  stressors: ['work', 'relationships', 'health', 'finances', 'self', 'external'],
  protective_factors: ['exercise', 'friends', 'family', 'nature', 'music', 'faith', 'therapy', 'pets', 'reading', 'sleep', 'hobbies', 'journaling'],
  needs: ['rest', 'validation', 'connection', 'autonomy', 'certainty', 'achievement', 'security', 'meaning'],
  behaviors: ['procrastinated', 'avoided', 'exercised', 'socialized', 'studied', 'slept_early', 'overslept', 'worked_late', 'isolated', 'reached_out', 'rested', 'overcommitted', 'asked_for_help', 'set_boundary', 'sought_reassurance', 'people_pleasing', 'social_comparison', 'perfectionism', 'acted_impulsively', 'emotional_eating', 'trouble_sleeping', 'excessive_screen_time', 'expressed_emotions', 'suppressed_emotions', 'confronted', 'solved_problem', 'disengaged', 'planned_ahead', 'helped_others', 'sought_distraction', 'tried_new_things'],
  thinking_patterns: ['catastrophizing', 'self_criticism', 'rumination', 'black_and_white', 'overgeneralization', 'mind_reading', 'should_statements', 'personalization'],
}

/* ---- text grounding -------------------------------------------------
   Only two things get tied to actual words in the entry: entity names
   (extracted verbatim from the text) and thinking-pattern evidence
   quotes (validated server-side to be a real substring — see
   EntryExtractionService::validateThinkingPatterns). Emotions, stressors,
   needs, protective factors, and behaviors are read from the entry as a
   whole rather than one phrase, so faking a text-span for them would be
   less accurate, not more — they're shown in the breakdown below instead. */
function escapeRegExp(s) { return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') }

function findAllEntity(body, name) {
  if (!name || name.trim().length < 2) return []
  const re = new RegExp(`\\b${escapeRegExp(name.trim())}\\b`, 'gi')
  const out = []
  let m
  while ((m = re.exec(body))) {
    out.push({ start: m.index, end: m.index + m[0].length })
    if (m[0].length === 0) re.lastIndex++
  }
  return out
}

function findEvidence(body, quote) {
  if (!quote) return -1
  return body.toLowerCase().indexOf(quote.trim().toLowerCase())
}

const patternMatch = computed(() => {
  const body     = entry.value?.body ?? ''
  const patterns = analysis.value?.thinking_patterns ?? []
  return patterns.map(p => {
    const start = findEvidence(body, p.evidence)
    if (start === -1 || !p.evidence) return null
    return { start, end: start + p.evidence.trim().length }
  })
})

const highlightSegments = computed(() => {
  const body = entry.value?.body ?? ''
  if (!body) return []

  const matches = []
  for (const e of entry.value?.entities ?? []) {
    findAllEntity(body, e.name).forEach(({ start, end }) => {
      matches.push({ start, end, kind: 'entity', title: `${e.type}: ${e.name}` })
    })
  }
  patternMatch.value.forEach((m, i) => {
    if (!m) return
    const pattern = analysis.value.thinking_patterns[i].pattern
    matches.push({ start: m.start, end: m.end, kind: 'pattern', index: i, title: `flagged as ${warm(THINKING_PATTERN_WORDS, pattern)}` })
  })

  // Longer matches win on overlap — pattern quotes run longer than most names.
  matches.sort((a, b) => a.start - b.start || (b.end - b.start) - (a.end - a.start))
  const resolved = []
  let cursor = 0
  for (const m of matches) {
    if (m.start < cursor) continue
    resolved.push(m)
    cursor = m.end
  }

  const segments = []
  let pos = 0
  for (const m of resolved) {
    if (m.start > pos) segments.push({ text: body.slice(pos, m.start) })
    segments.push({ text: body.slice(m.start, m.end), mark: m })
    pos = m.end
  }
  if (pos < body.length) segments.push({ text: body.slice(pos) })
  return segments
})

function scrollToPattern(i) {
  const el = document.getElementById('pattern-mark-' + i)
  if (!el) return
  el.scrollIntoView({ behavior: 'smooth', block: 'center' })
  el.classList.add('pulse')
  setTimeout(() => el.classList.remove('pulse'), 1200)
}

/* ---- corrections (POST /api/analysis-corrections) --------------------
   Same mechanism already used by the dashboard's thinking-pattern note,
   generalized here to every extracted field — this page is where you're
   actually looking at the evidence, so it's the natural place to fix
   something the model got wrong. */
const correctionsByKey = computed(() => {
  const map = {}
  for (const c of analysis.value?.corrections ?? []) map[`${c.field}:${c.original_value}`] = c
  return map
})
function correctionFor(field, value) { return correctionsByKey.value[`${field}:${value}`] ?? null }
function correctionBadge(correction) {
  if (!correction) return null
  if (correction.action === 'confirmed') return { text: '✓ confirmed', cls: 'positive' }
  if (correction.action === 'removed')   return { text: '✕ marked wrong', cls: 'negative' }
  if (correction.action === 'corrected') return { text: `→ ${humanize(correction.corrected_value)}`, cls: 'accent' }
  return null
}

const openChanger  = ref(null) // `${field}:${value}` currently showing the "change to" select
const changeTarget = ref('')

function toggleChanger(field, value) {
  const key = `${field}:${value}`
  openChanger.value = openChanger.value === key ? null : key
  changeTarget.value = ''
}

async function sendCorrection(field, originalValue, action, correctedValue = null) {
  correctionError.value = ''
  try {
    const { data } = await api.post('/api/analysis-corrections', {
      entry_analysis_id: analysis.value.id,
      field, original_value: originalValue, action,
      corrected_value: correctedValue,
    })
    if (!analysis.value.corrections) analysis.value.corrections = []
    const idx = analysis.value.corrections.findIndex(c => c.field === field && c.original_value === originalValue)
    if (idx >= 0) analysis.value.corrections[idx] = data
    else analysis.value.corrections.push(data)
    openChanger.value = null
  } catch {
    correctionError.value = 'could not save that — try again in a moment.'
  }
}

/* ---- per-field display rows ------------------------------------- */
const emotionItems = computed(() => (analysis.value?.emotions ?? []).map(i => ({
  ...i, warmLabel: humanize(i.emotion), tone: EMOTION_TONE[i.emotion] ?? 'accent',
  correction: correctionFor('emotions', i.emotion),
})))
const stressorItems = computed(() => (analysis.value?.stressors ?? []).map(i => ({
  ...i, warmLabel: humanize(i.category), subLabel: i.subcategory ? humanize(i.subcategory) : null,
  correction: correctionFor('stressors', i.category),
})))
const protectiveItems = computed(() => (analysis.value?.protective_factors ?? []).map(i => ({
  ...i, warmLabel: warm(PROTECTIVE_WORDS, i.factor),
  correction: correctionFor('protective_factors', i.factor),
})))
const behaviorItems = computed(() => (analysis.value?.behaviors ?? []).map(i => ({
  ...i, warmLabel: warm(BEHAVIOR_WORDS, i.behavior), tone: BEHAVIOR_TONE[i.behavior] ?? 'accent',
  correction: correctionFor('behaviors', i.behavior),
})))
// needs moved off flat strings to {need, moment} objects (2026-08-12) —
// same tagged shape every other field already used, so it can carry a
// moment link too. it.value stays the display key here (rather than
// renaming call sites everywhere) so the taxonomy dropdown/correction UI
// below didn't need touching beyond this mapping.
const needItems = computed(() => (analysis.value?.needs ?? []).map(n => ({
  ...n, value: n.need, warmLabel: humanize(n.need), correction: correctionFor('needs', n.need),
})))
const patternItems = computed(() => (analysis.value?.thinking_patterns ?? []).map((p, i) => ({
  ...p, warmLabel: warm(THINKING_PATTERN_WORDS, p.pattern), index: i,
  found: !!patternMatch.value[i],
  correction: correctionFor('thinking_patterns', p.pattern),
})))

/* ---- moments (2026-08-12) -------------------------------------------
   Every tagged field now carries a "moment" number — see
   EntryExtractionService::systemPrompt() — marking which beat of the
   entry it belongs to, so a two-part entry ("stressed about the deadline,
   then relieved when a friend called") doesn't just get two flat lists
   that lose which emotion/need went with which stressor. This groups
   items by moment purely for display — read-only, no correction
   controls here, since the actual per-field context (impact, evidence,
   the taxonomy dropdown) lives in the detailed panels below and this
   section would just duplicate it. Only shown when the entry actually
   has more than one moment; a single-moment entry is already fully
   represented by the flat panels, and showing "moment 1: everything"
   would just be noise. */
const momentGroups = computed(() => {
  if (!analysis.value) return []
  const groups = {}
  const add = (moment, label, tone) => {
    if (moment === null || moment === undefined) return
    ;(groups[moment] ??= []).push({ label, tone })
  }

  for (const i of analysis.value.emotions ?? [])
    add(i.moment, humanize(i.emotion), EMOTION_TONE[i.emotion] ?? 'accent')
  for (const i of analysis.value.stressors ?? [])
    add(i.moment, humanize(i.subcategory || i.category), 'negative')
  for (const i of analysis.value.needs ?? [])
    add(i.moment, humanize(i.need), 'accent')
  for (const i of analysis.value.behaviors ?? [])
    add(i.moment, warm(BEHAVIOR_WORDS, i.behavior), BEHAVIOR_TONE[i.behavior] ?? 'accent')
  for (const i of analysis.value.protective_factors ?? [])
    add(i.moment, warm(PROTECTIVE_WORDS, i.factor), 'positive')
  for (const i of analysis.value.thinking_patterns ?? [])
    add(i.moment, warm(THINKING_PATTERN_WORDS, i.pattern), 'accent')

  return Object.keys(groups)
    .map(Number)
    .sort((a, b) => a - b)
    .map(m => ({ moment: m, chips: groups[m] }))
})
const showMomentGroups = computed(() => momentGroups.value.length > 1)

/* ---- confidence + raw JSON ----------------------------------------
   0.4 is the same cutoff WeeklyReflectionController uses to exclude an
   entry from that week's pattern math — surfaced here rather than left
   as a hidden rule. */
const confidenceTone = computed(() => {
  const c = analysis.value?.extraction_confidence
  if (c === null || c === undefined) return null
  if (c >= 0.7) return 'positive'
  if (c >= 0.4) return 'accent'
  return 'negative'
})

const issuesList = computed(() => {
  if (!analysis.value?.extraction_issues) return []
  return Object.entries(analysis.value.extraction_issues).map(([field, message]) => ({ field: humanize(field), message }))
})

// What the model actually returned, pre-validation.
const rawJson = computed(() => analysis.value?.raw_json ?? null)
// What survived field-level validation and is actually used everywhere
// else in the app — comparing the two shows exactly what the validator
// dropped or normalized, and why (see issuesList above).
const keptJson = computed(() => {
  if (!analysis.value) return null
  const {
    mood, sentiment, emotions, stressors, protective_factors,
    needs, behaviors, thinking_patterns, recovered, extraction_confidence,
  } = analysis.value
  return {
    mood, sentiment, emotions, stressors, protective_factors,
    needs, behaviors, thinking_patterns, recovered,
    confidence: extraction_confidence,
  }
})
</script>

<template>
  <div class="me" :data-theme="theme">
    <header class="bar">
      <img class="wordmark" src="/logo.png" alt="me" />
      <div class="bar-actions">
        <button class="ghost" @click="router.push('/journal')">← journal</button>
        <button class="ghost" @click="cycleTheme">◐ theme</button>
      </div>
    </header>

    <div class="wrap">
      <p v-if="loading" class="state-msg">loading…</p>
      <p v-else-if="loadError" class="state-msg">{{ loadError }}</p>

      <template v-else-if="entry">
        <div class="head">
          <span class="eyebrow">how this one was read</span>
          <h1 class="stamp">{{ formatStamp(entry.created_at) }}</h1>
        </div>

        <!-- no analysis yet -->
        <section class="card" v-if="entry.in_vault">
          <p class="empty-note">this one's in the vault — it was never sent out for a reading.</p>
        </section>
        <section class="card" v-else-if="!analysis">
          <p class="empty-note">still working through this one — check back in a bit.</p>
          <button class="ghost" @click="load">reload</button>
        </section>

        <template v-else>
          <!-- entry text with grounded highlights -->
          <section class="card text-card">
            <div class="text-top">
              <span class="sent-badge" :class="'tone-' + (analysis.sentiment === 'mixed' ? 'accent' : analysis.sentiment === 'neutral' ? 'muted' : analysis.sentiment)">{{ analysis.sentiment }}</span>
              <span class="mood-text" v-if="analysis.mood">“{{ analysis.mood }}”</span>
            </div>

            <p class="entry-text">
              <template v-for="(seg, i) in highlightSegments" :key="i">
                <mark
                  v-if="seg.mark"
                  :id="seg.mark.kind === 'pattern' ? 'pattern-mark-' + seg.mark.index : undefined"
                  :class="['mk', 'mk-' + seg.mark.kind]"
                  :title="seg.mark.title"
                >{{ seg.text }}</mark>
                <template v-else>{{ seg.text }}</template>
              </template>
            </p>

            <p class="legend muted">
              <span class="legend-item"><span class="mk mk-entity legend-swatch"></span>people, places &amp; topics found in the text</span>
              <span class="legend-item"><span class="mk mk-pattern legend-swatch"></span>the exact line behind a thinking-pattern flag</span>
            </p>
            <p class="legend-note muted">emotions, stressors, needs, protective factors, and behaviors are read from the entry as a whole rather than one phrase — that's why they're broken out below instead of highlighted above.</p>

            <div class="confidence-block" v-if="analysis.extraction_confidence !== null && analysis.extraction_confidence !== undefined">
              <div class="confidence-row">
                <span class="confidence-label">model confidence</span>
                <span class="confidence-num">{{ analysis.extraction_confidence.toFixed(2) }}</span>
              </div>
              <div class="confidence-track"><div class="confidence-fill" :class="'tone-' + confidenceTone" :style="{ width: (analysis.extraction_confidence * 100) + '%' }"></div></div>
              <p class="confidence-note muted" v-if="confidenceTone === 'negative'">below 0.4 — this entry is automatically left out of your weekly patterns.</p>
            </div>

            <div class="issues-block" v-if="issuesList.length">
              <span class="issues-title">flagged during validation</span>
              <p class="issue-row" v-for="iss in issuesList" :key="iss.field"><strong>{{ iss.field }}</strong> — {{ iss.message }}</p>
            </div>
          </section>

          <p v-if="correctionError" class="send-error">{{ correctionError }}</p>

          <!-- moments — grouped read-only view of which tags belong to the
               same beat of the entry; corrections still happen below -->
          <section class="card panel-wide moments-card" v-if="showMomentGroups">
            <span class="panel-title">moments in this entry</span>
            <p class="empty-note small moments-hint">grouped by which part of the entry each tag came from — not a correction surface, just a way to check the linkage feels right. fix individual tags in the panels below.</p>
            <div class="moment-row" v-for="g in momentGroups" :key="g.moment">
              <span class="moment-num">moment {{ g.moment }}</span>
              <div class="moment-chips">
                <span class="moment-chip" v-for="(c, i) in g.chips" :key="i" :class="'tone-' + c.tone">{{ c.label }}</span>
              </div>
            </div>
          </section>

          <!-- breakdown grid -->
          <div class="breakdown-grid">
            <!-- emotions -->
            <section class="card panel">
              <span class="panel-title">emotions</span>
              <p v-if="!emotionItems.length" class="empty-note small">nothing flagged here.</p>
              <div class="tag-chip" v-for="it in emotionItems" :key="'em-' + it.emotion">
                <div class="tag-main">
                  <span class="tag-dot" :class="'tone-' + it.tone"></span>
                  <span class="tag-label" :class="{ struck: it.correction?.action === 'removed' }">{{ it.warmLabel }}</span>
                  <span class="tag-meta">intensity {{ it.intensity }}/10</span>
                </div>
                <div class="tag-controls">
                  <span v-if="correctionBadge(it.correction)" class="corr-badge" :class="'tone-' + correctionBadge(it.correction).cls">{{ correctionBadge(it.correction).text }}</span>
                  <button class="mini-btn" title="this is right" @click="sendCorrection('emotions', it.emotion, 'confirmed')">✓</button>
                  <button class="mini-btn" title="change to a different emotion" @click="toggleChanger('emotions', it.emotion)">⇄</button>
                  <button class="mini-btn" title="this wasn't really here" @click="sendCorrection('emotions', it.emotion, 'removed')">✕</button>
                </div>
                <div class="changer" v-if="openChanger === 'emotions:' + it.emotion">
                  <select v-model="changeTarget">
                    <option value="" disabled>choose the right one…</option>
                    <option v-for="opt in TAXONOMY.emotions" :key="opt" :value="opt" :disabled="opt === it.emotion">{{ humanize(opt) }}</option>
                  </select>
                  <button class="mini-btn save" :disabled="!changeTarget" @click="sendCorrection('emotions', it.emotion, 'corrected', changeTarget)">save</button>
                </div>
              </div>
            </section>

            <!-- stressors -->
            <section class="card panel">
              <span class="panel-title">stressors</span>
              <p v-if="!stressorItems.length" class="empty-note small">nothing flagged here.</p>
              <div class="tag-chip" v-for="it in stressorItems" :key="'st-' + it.category">
                <div class="tag-main">
                  <span class="tag-dot tone-negative"></span>
                  <span class="tag-label" :class="{ struck: it.correction?.action === 'removed' }">{{ it.warmLabel }}<span v-if="it.subLabel" class="tag-sub"> · {{ it.subLabel }}</span></span>
                  <span class="tag-meta">impact {{ it.impact }}/10</span>
                </div>
                <div class="tag-controls">
                  <span v-if="correctionBadge(it.correction)" class="corr-badge" :class="'tone-' + correctionBadge(it.correction).cls">{{ correctionBadge(it.correction).text }}</span>
                  <button class="mini-btn" title="this is right" @click="sendCorrection('stressors', it.category, 'confirmed')">✓</button>
                  <button class="mini-btn" title="change to a different category" @click="toggleChanger('stressors', it.category)">⇄</button>
                  <button class="mini-btn" title="this wasn't really here" @click="sendCorrection('stressors', it.category, 'removed')">✕</button>
                </div>
                <div class="changer" v-if="openChanger === 'stressors:' + it.category">
                  <select v-model="changeTarget">
                    <option value="" disabled>choose the right one…</option>
                    <option v-for="opt in TAXONOMY.stressors" :key="opt" :value="opt" :disabled="opt === it.category">{{ humanize(opt) }}</option>
                  </select>
                  <button class="mini-btn save" :disabled="!changeTarget" @click="sendCorrection('stressors', it.category, 'corrected', changeTarget)">save</button>
                </div>
              </div>
            </section>

            <!-- needs -->
            <section class="card panel">
              <span class="panel-title">needs</span>
              <p v-if="!needItems.length" class="empty-note small">nothing flagged here.</p>
              <div class="tag-chip" v-for="it in needItems" :key="'nd-' + it.value">
                <div class="tag-main">
                  <span class="tag-dot tone-accent"></span>
                  <span class="tag-label" :class="{ struck: it.correction?.action === 'removed' }">{{ it.warmLabel }}</span>
                </div>
                <div class="tag-controls">
                  <span v-if="correctionBadge(it.correction)" class="corr-badge" :class="'tone-' + correctionBadge(it.correction).cls">{{ correctionBadge(it.correction).text }}</span>
                  <button class="mini-btn" title="this is right" @click="sendCorrection('needs', it.value, 'confirmed')">✓</button>
                  <button class="mini-btn" title="change to a different need" @click="toggleChanger('needs', it.value)">⇄</button>
                  <button class="mini-btn" title="this wasn't really here" @click="sendCorrection('needs', it.value, 'removed')">✕</button>
                </div>
                <div class="changer" v-if="openChanger === 'needs:' + it.value">
                  <select v-model="changeTarget">
                    <option value="" disabled>choose the right one…</option>
                    <option v-for="opt in TAXONOMY.needs" :key="opt" :value="opt" :disabled="opt === it.value">{{ humanize(opt) }}</option>
                  </select>
                  <button class="mini-btn save" :disabled="!changeTarget" @click="sendCorrection('needs', it.value, 'corrected', changeTarget)">save</button>
                </div>
              </div>
            </section>

            <!-- protective factors -->
            <section class="card panel">
              <span class="panel-title">what seemed to help</span>
              <p v-if="!protectiveItems.length" class="empty-note small">nothing flagged here.</p>
              <div class="tag-chip" v-for="it in protectiveItems" :key="'pf-' + it.factor">
                <div class="tag-main">
                  <span class="tag-dot tone-positive"></span>
                  <span class="tag-label" :class="{ struck: it.correction?.action === 'removed' }">{{ it.warmLabel }}</span>
                  <span class="tag-meta">impact {{ it.impact }}/10</span>
                </div>
                <div class="tag-controls">
                  <span v-if="correctionBadge(it.correction)" class="corr-badge" :class="'tone-' + correctionBadge(it.correction).cls">{{ correctionBadge(it.correction).text }}</span>
                  <button class="mini-btn" title="this is right" @click="sendCorrection('protective_factors', it.factor, 'confirmed')">✓</button>
                  <button class="mini-btn" title="change to a different factor" @click="toggleChanger('protective_factors', it.factor)">⇄</button>
                  <button class="mini-btn" title="this wasn't really here" @click="sendCorrection('protective_factors', it.factor, 'removed')">✕</button>
                </div>
                <div class="changer" v-if="openChanger === 'protective_factors:' + it.factor">
                  <select v-model="changeTarget">
                    <option value="" disabled>choose the right one…</option>
                    <option v-for="opt in TAXONOMY.protective_factors" :key="opt" :value="opt" :disabled="opt === it.factor">{{ humanize(opt) }}</option>
                  </select>
                  <button class="mini-btn save" :disabled="!changeTarget" @click="sendCorrection('protective_factors', it.factor, 'corrected', changeTarget)">save</button>
                </div>
              </div>
            </section>

            <!-- behaviors -->
            <section class="card panel">
              <span class="panel-title">what you did</span>
              <p v-if="!behaviorItems.length" class="empty-note small">nothing flagged here.</p>
              <div class="tag-chip" v-for="it in behaviorItems" :key="'bh-' + it.behavior">
                <div class="tag-main">
                  <span class="tag-dot" :class="'tone-' + it.tone"></span>
                  <span class="tag-label" :class="{ struck: it.correction?.action === 'removed' }">{{ it.warmLabel }}</span>
                  <span class="tag-meta">impact {{ it.impact }}/10</span>
                </div>
                <div class="tag-controls">
                  <span v-if="correctionBadge(it.correction)" class="corr-badge" :class="'tone-' + correctionBadge(it.correction).cls">{{ correctionBadge(it.correction).text }}</span>
                  <button class="mini-btn" title="this is right" @click="sendCorrection('behaviors', it.behavior, 'confirmed')">✓</button>
                  <button class="mini-btn" title="change to a different behavior" @click="toggleChanger('behaviors', it.behavior)">⇄</button>
                  <button class="mini-btn" title="this wasn't really here" @click="sendCorrection('behaviors', it.behavior, 'removed')">✕</button>
                </div>
                <div class="changer" v-if="openChanger === 'behaviors:' + it.behavior">
                  <select v-model="changeTarget">
                    <option value="" disabled>choose the right one…</option>
                    <option v-for="opt in TAXONOMY.behaviors" :key="opt" :value="opt" :disabled="opt === it.behavior">{{ humanize(opt) }}</option>
                  </select>
                  <button class="mini-btn save" :disabled="!changeTarget" @click="sendCorrection('behaviors', it.behavior, 'corrected', changeTarget)">save</button>
                </div>
              </div>
            </section>

            <!-- recovered -->
            <section class="card panel" v-if="analysis.recovered !== null && analysis.recovered !== undefined">
              <span class="panel-title">by the end of this entry</span>
              <p class="recovered-line">{{ analysis.recovered ? 'moved toward feeling better within the entry itself.' : 'still sitting in it as of this entry.' }}</p>
            </section>
          </div>

          <!-- thinking patterns — experimental, full width, evidence-linked -->
          <section class="card panel-wide" v-if="patternItems.length">
            <span class="panel-title">thinking patterns <span class="exp-tag">experimental</span></span>
            <div class="tag-chip pattern-chip" v-for="it in patternItems" :key="'tp-' + it.pattern">
              <div class="tag-main">
                <span class="tag-dot tone-accent"></span>
                <span class="tag-label" :class="{ struck: it.correction?.action === 'removed' }">{{ it.warmLabel }}</span>
                <button v-if="it.found" class="show-in-text" @click="scrollToPattern(it.index)">show in text ↑</button>
                <span v-else class="tag-meta">exact wording not found verbatim — shown as extracted</span>
              </div>
              <p class="evidence-quote" v-if="it.evidence">“{{ it.evidence }}”</p>
              <div class="tag-controls">
                <span v-if="correctionBadge(it.correction)" class="corr-badge" :class="'tone-' + correctionBadge(it.correction).cls">{{ correctionBadge(it.correction).text }}</span>
                <button class="mini-btn" title="this is right" @click="sendCorrection('thinking_patterns', it.pattern, 'confirmed')">✓</button>
                <button class="mini-btn" title="change to a different pattern" @click="toggleChanger('thinking_patterns', it.pattern)">⇄</button>
                <button class="mini-btn" title="this wasn't really here" @click="sendCorrection('thinking_patterns', it.pattern, 'removed')">✕</button>
              </div>
              <div class="changer" v-if="openChanger === 'thinking_patterns:' + it.pattern">
                <select v-model="changeTarget">
                  <option value="" disabled>choose the right one…</option>
                  <option v-for="opt in TAXONOMY.thinking_patterns" :key="opt" :value="opt" :disabled="opt === it.pattern">{{ humanize(opt) }}</option>
                </select>
                <button class="mini-btn save" :disabled="!changeTarget" @click="sendCorrection('thinking_patterns', it.pattern, 'corrected', changeTarget)">save</button>
              </div>
            </div>
          </section>
          <section class="card panel-wide" v-else>
            <span class="panel-title">thinking patterns <span class="exp-tag">experimental</span></span>
            <p class="empty-note small">nothing flagged here — expected for most entries while this taxonomy is still being tuned.</p>
          </section>

          <!-- raw JSON -->
          <section class="card panel-wide json-card">
            <details>
              <summary>see the raw model output (JSON)</summary>
              <pre class="raw-json">{{ JSON.stringify(rawJson, null, 2) }}</pre>
            </details>
            <details>
              <summary>see what was kept after validation</summary>
              <pre class="raw-json">{{ JSON.stringify(keptJson, null, 2) }}</pre>
            </details>
          </section>
        </template>
      </template>
    </div>
  </div>
</template>

<style scoped>
.me[data-theme='dusk'] {
  --paper:#F1F2F0; --surface:#FBFBFA; --ink:#26282B; --muted:#6B6F72;
  --accent:#7FA08A; --accent-deep:#57735F; --ochre:#C6A063; --line:#E3E4E1;
  --positive:#7FA08A; --negative:#C6A063;
}
.me[data-theme='paper'] {
  --paper:#F7F4EE; --surface:#FFFFFF; --ink:#33302A; --muted:#7A736A;
  --accent:#9A8C74; --accent-deep:#6E624E; --ochre:#B98B54; --line:#EAE5DC;
  --positive:#9A8C74; --negative:#B98B54;
}
.me[data-theme='night'] {
  --paper:#1B1D20; --surface:#232629; --ink:#E9E9E6; --muted:#9BA0A2;
  --accent:#89AE97; --accent-deep:#B6D0BF; --ochre:#D3AE74; --line:#31353A;
  --positive:#89AE97; --negative:#D3AE74;
}
.me {
  --font-display:'Fraunces', Georgia, serif;
  --font-ui:'Instrument Sans', system-ui, sans-serif;
  background:var(--paper); color:var(--ink);
  font-family:var(--font-ui); line-height:1.5;
  min-height:100vh;
  transition:background .4s ease, color .4s ease;
}
* { box-sizing:border-box; }

.bar {
  display:flex; align-items:center; justify-content:space-between;
  padding:18px 40px; border-bottom:1px solid var(--line);
}
.wordmark { height: 80px; width: auto; display: block; }
.bar-actions { display:flex; gap:6px; }
.ghost {
  background:none; border:none; color:var(--muted); font:inherit; font-size:13px;
  padding:6px 10px; border-radius:8px; cursor:pointer;
}
.ghost:hover { color:var(--ink); background:var(--surface); }

.wrap { max-width:980px; margin:0 auto; padding:40px 40px 100px; }
.state-msg { color:var(--muted); font-size:15px; text-align:center; padding:80px 0 0; }

.head { margin-bottom:24px; }
.eyebrow { display:block; font-size:12px; letter-spacing:.14em; text-transform:uppercase; color:var(--muted); margin-bottom:6px; }
.stamp { font-family:var(--font-display); font-weight:400; font-size:30px; margin:0; }

.card {
  background:var(--surface); border:1px solid var(--line); border-radius:16px;
  padding:26px 28px; margin-bottom:24px;
}
.empty-note { color:var(--muted); font-size:15px; margin:0 0 10px; }
.empty-note.small { font-size:13.5px; margin:4px 0 0; }

/* tone → color, shared across everything below */
.tone-positive { --tone-color:var(--positive); }
.tone-negative { --tone-color:var(--negative); }
.tone-accent   { --tone-color:var(--accent); }
.tone-muted    { --tone-color:var(--muted); }

/* text card */
.text-top { display:flex; align-items:center; gap:12px; margin-bottom:18px; }
.sent-badge {
  font-size:11px; letter-spacing:.08em; text-transform:uppercase;
  padding:4px 11px; border-radius:999px; background:var(--tone-color, var(--muted)); color:var(--surface);
}
.mood-text { font-size:14px; color:var(--muted); font-style:italic; }

.entry-text {
  font-size:18px; line-height:1.8; white-space:pre-wrap; word-break:break-word;
  margin:0 0 20px;
}
.mk { border-radius:4px; padding:1px 3px; cursor:help; }
.mk-entity { background:color-mix(in srgb, var(--accent) 22%, transparent); }
.mk-pattern { background:color-mix(in srgb, var(--ochre) 35%, transparent); }
.mk-pattern.pulse { animation:pulse-flash 1.2s ease; }
@keyframes pulse-flash {
  0%, 100% { background:color-mix(in srgb, var(--ochre) 35%, transparent); }
  30% { background:color-mix(in srgb, var(--ochre) 75%, transparent); }
}

.legend { display:flex; flex-wrap:wrap; gap:18px; font-size:12.5px; margin:0 0 6px; }
.legend-item { display:flex; align-items:center; gap:7px; }
.legend-swatch { width:14px; height:14px; display:inline-block; padding:0; }
.legend-note { font-size:12px; margin:0 0 4px; line-height:1.5; }
.muted { color:var(--muted); }

.confidence-block { margin-top:20px; padding-top:18px; border-top:1px solid var(--line); }
.confidence-row { display:flex; justify-content:space-between; font-size:12.5px; margin-bottom:6px; }
.confidence-label { text-transform:uppercase; letter-spacing:.1em; color:var(--muted); }
.confidence-num { font-family:var(--font-display); font-size:15px; }
.confidence-track { height:6px; border-radius:999px; background:var(--paper); overflow:hidden; }
.confidence-fill { height:100%; border-radius:999px; background:var(--tone-color, var(--accent)); transition:width .4s ease; }
.confidence-note { font-size:12px; margin:8px 0 0; }

.issues-block { margin-top:18px; padding:14px 16px; border-radius:12px; background:var(--paper); border:1px solid var(--line); }
.issues-title { display:block; font-size:11.5px; text-transform:uppercase; letter-spacing:.1em; color:var(--muted); margin-bottom:6px; }
.issue-row { font-size:13px; margin:4px 0; color:var(--ink); }

.send-error { font-size:13px; color:#b3543f; margin:-12px 0 20px; }

/* breakdown grid */
.breakdown-grid { display:grid; grid-template-columns:1fr 1fr; gap:0 24px; }
.breakdown-grid .card { grid-column:span 1; }
.panel-title { display:block; font-size:12px; letter-spacing:.12em; text-transform:uppercase; color:var(--muted); margin-bottom:14px; }
.exp-tag { font-size:10px; letter-spacing:.06em; text-transform:none; color:var(--ochre); border:1px solid var(--ochre); border-radius:999px; padding:1px 8px; margin-left:8px; }
.panel-wide { grid-column:1 / -1; }

/* moments — read-only grouped view, see momentGroups computed */
.moments-card { padding-bottom:20px; }
.moments-hint { margin:0 0 18px; }
.moment-row { display:flex; align-items:baseline; gap:16px; padding:10px 0; border-bottom:1px solid var(--line); }
.moment-row:last-child { border-bottom:none; padding-bottom:0; }
.moment-num { flex:0 0 auto; width:78px; font-size:12px; color:var(--muted); text-transform:uppercase; letter-spacing:.08em; }
.moment-chips { display:flex; flex-wrap:wrap; gap:8px; }
.moment-chip {
  font-size:13px; padding:5px 12px; border-radius:999px;
  background:color-mix(in srgb, var(--tone-color, var(--muted)) 16%, transparent);
  color:var(--tone-color, var(--ink));
  border:1px solid color-mix(in srgb, var(--tone-color, var(--muted)) 35%, transparent);
}

/* chips */
.tag-chip { padding:12px 0; border-bottom:1px solid var(--line); }
.tag-chip:last-of-type { border-bottom:none; padding-bottom:2px; }
.tag-main { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
.tag-dot { width:9px; height:9px; border-radius:50%; background:var(--tone-color, var(--accent)); flex:0 0 auto; }
.tag-label { font-size:14.5px; }
.tag-label.struck { text-decoration:line-through; opacity:.55; }
.tag-sub { color:var(--muted); font-weight:400; }
.tag-meta { font-size:12px; color:var(--muted); margin-left:auto; }

.tag-controls { display:flex; align-items:center; gap:6px; margin-top:8px; }
.corr-badge {
  font-size:11px; padding:2px 9px; border-radius:999px; margin-right:4px;
  background:color-mix(in srgb, var(--tone-color, var(--muted)) 18%, transparent);
  color:var(--tone-color, var(--muted));
}
.mini-btn {
  background:var(--paper); border:1px solid var(--line); color:var(--ink);
  font-size:12px; width:26px; height:26px; border-radius:8px; cursor:pointer;
  display:inline-flex; align-items:center; justify-content:center;
  transition:border-color .15s ease;
}
.mini-btn:hover { border-color:var(--accent); }
.mini-btn.save { width:auto; padding:0 12px; }
.mini-btn:disabled { opacity:.4; cursor:default; }

.changer { display:flex; gap:8px; margin-top:10px; }
.changer select {
  flex:1; font:inherit; font-size:13px; padding:6px 10px; border-radius:8px;
  border:1px solid var(--line); background:var(--surface); color:var(--ink);
}

/* thinking patterns */
.pattern-chip .tag-label { text-transform:capitalize; }
.show-in-text {
  background:none; border:none; color:var(--accent-deep); font:inherit; font-size:12px;
  cursor:pointer; padding:0; text-decoration:underline;
}
.evidence-quote { font-size:14px; font-style:italic; color:var(--muted); margin:8px 0 0 19px; line-height:1.5; }

.recovered-line { font-size:14.5px; margin:0; }

/* raw json */
.json-card details { margin-bottom:10px; }
.json-card details:last-child { margin-bottom:0; }
.json-card summary { cursor:pointer; font-size:13.5px; color:var(--accent-deep); padding:4px 0; }
.raw-json {
  background:var(--paper); border:1px solid var(--line); border-radius:10px;
  padding:16px; font-size:12.5px; line-height:1.6; overflow-x:auto;
  margin:10px 0 0; white-space:pre; font-family:'SF Mono', Consolas, monospace;
}

@media (max-width:860px) {
  .bar { padding:16px 20px; }
  .wrap { padding:24px 20px 70px; }
  .breakdown-grid { grid-template-columns:1fr; }
  .stamp { font-size:24px; }
  .entry-text { font-size:16.5px; }
}
@media (prefers-reduced-motion: reduce) { .me, * { transition:none !important; } }
</style>
