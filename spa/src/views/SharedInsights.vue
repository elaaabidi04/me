<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '../lib/api'
import { useAuthStore } from '../stores/auth'
import { EMOTION_TONE, humanize } from '../utils/taxonomyDisplay'

const route  = useRoute()
const router = useRouter()
const auth   = useAuthStore()

const themes = ['dusk', 'paper', 'night']
const theme  = ref('dusk')
function cycleTheme() { theme.value = themes[(themes.indexOf(theme.value) + 1) % themes.length] }

const role = computed(() => auth.user?.role ?? 'client')

// Therapist reaches a specific client's shares via ?client_id= (+ ?name=
// for the header, since forClient() itself doesn't return the client's
// name — same query-param handoff pattern as Therapist.vue -> CrisisThread.vue).
const clientId   = computed(() => route.query.client_id ?? null)
const clientName = computed(() => route.query.name ?? 'this client')

/* 2026-08-18 — see docs/therapist-bridge.md §1 and Dashboard.vue's
   share-to-therapist docblock. A client sees their own full share
   history here (including revoked, greyed out, so "did I already share
   that" stays answerable) and can revoke any active one; a therapist
   sees only the currently-active shares for one linked client,
   read-only — a revoked share simply isn't in that response at all. */
const items     = ref([])
const loading   = ref(true)
const loadError = ref('')

async function load() {
  if (role.value === 'therapist' && !clientId.value) {
    loadError.value = 'no client selected.'
    loading.value = false
    return
  }
  loading.value = true
  loadError.value = ''
  try {
    const url = role.value === 'therapist'
      ? `/api/shared-insights/client/${clientId.value}`
      : '/api/shared-insights'
    const { data } = await api.get(url)
    items.value = data ?? []
  } catch (e) {
    loadError.value = e.response?.status === 403
      ? "you don't have access to this."
      : 'could not load shared insights — try reloading.'
  } finally {
    loading.value = false
  }
}
onMounted(load)

async function handleLogout() { await auth.logout(); router.push({ name: 'home' }) }

const revokingId = ref(null)
async function revoke(item) {
  if (revokingId.value || item.revoked_at) return
  revokingId.value = item.share_id
  try {
    const { data } = await api.delete(`/api/shared-insights/${item.share_id}`)
    item.revoked_at = data.revoked_at
  } catch {
    // non-critical -- leave it as-is, the button just stays clickable to retry
  } finally {
    revokingId.value = null
  }
}

function formatDate(iso) {
  if (!iso) return ''
  return new Date(iso).toLocaleDateString([], { month: 'short', day: 'numeric', year: 'numeric' })
}

const activeItems  = computed(() => items.value.filter(i => !i.revoked_at))
const revokedItems = computed(() => items.value.filter(i => i.revoked_at))

// ── Per-panel chart helpers ──────────────────────────────────────────────

// Recompute behavior pie from stored `share` values (same geometry as
// Dashboard.vue's behaviorPie computed — kept in sync manually).
function behaviorPieForItem(item) {
  const rows = item.rows ?? []
  if (!rows.length) return null
  const total = rows.reduce((s, r) => s + (r.share ?? 0), 0) || 1
  const R = 78, CX = 90, CY = 90
  let angle = -Math.PI / 2
  const toneCounts = {}
  const slices = rows.map(r => {
    const frac = (r.share ?? 0) / total
    const a0 = angle, a1 = angle + frac * 2 * Math.PI
    angle = a1
    const large = a1 - a0 > Math.PI ? 1 : 0
    const x0 = CX + R * Math.cos(a0), y0 = CY + R * Math.sin(a0)
    const x1 = CX + R * Math.cos(a1), y1 = CY + R * Math.sin(a1)
    const tone = r.tone ?? 'accent'
    const idx = toneCounts[tone] ?? 0
    toneCounts[tone] = idx + 1
    return { label: r.label, tone, freq: r.detail, opacity: Math.max(0.45, 1 - idx * 0.22),
      path: `M${CX},${CY} L${x0},${y0} A${R},${R} 0 ${large},1 ${x1},${y1} Z` }
  })
  return { slices }
}

// Recompute mood ring from stored distribution rows (each row = one
// sentiment with its `width` as a percentage 0–100).
const MOOD_RING_C = 2 * Math.PI * 64
function moodRingForItem(item) {
  const rows = item.rows ?? []
  if (!rows.length) return null
  let offset = 0
  const segments = rows.map(r => {
    const frac = (r.width ?? 0) / 100
    const seg = { sentiment: r.label, dash: `${frac * MOOD_RING_C} ${MOOD_RING_C}`, dashoffset: -(offset * MOOD_RING_C) }
    offset += frac
    return seg
  })
  return { segments }
}

function moodSegmentColor(s) {
  if (s === 'positive') return 'var(--accent)'
  if (s === 'negative') return 'var(--ochre)'
  if (s === 'neutral')  return 'var(--muted)'
  return 'color-mix(in srgb, var(--accent) 50%, var(--ochre))'
}

// ── Therapist AI chatbot ─────────────────────────────────────────────────

const chatMessages = ref([])   // { role: 'user'|'assistant', text: string }
const chatInput    = ref('')
const chatBusy     = ref(false)
const chatError    = ref('')
const chatOpen     = ref(false)
function toggleChat() { chatOpen.value = !chatOpen.value }

async function sendChat() {
  const q = chatInput.value.trim()
  if (!q || chatBusy.value || !clientId.value) return
  chatMessages.value.push({ role: 'user', text: q })
  chatInput.value = ''
  chatBusy.value  = true
  chatError.value = ''
  try {
    const { data } = await api.post(`/api/shared-insights/chat/${clientId.value}`, { question: q })
    chatMessages.value.push({ role: 'assistant', text: data.reply })
  } catch {
    chatError.value = "couldn't get a response — try again."
    chatMessages.value.pop()
  } finally {
    chatBusy.value = false
  }
}

// 2026-08-19 — therapist-authored private note per shared insight (see
// docs/therapist-bridge.md §17). Separate from the client's own `note`;
// only the therapist who owns the annotation can see or edit it, so this
// whole block is a no-op for role === 'client'.
const editingNoteId = ref(null)
const draftNote     = ref('')
const savingNoteId  = ref(null)

function startNote(item) {
  editingNoteId.value = item.share_id
  draftNote.value = item.therapist_note ?? ''
}
function cancelNote() {
  editingNoteId.value = null
  draftNote.value = ''
}
async function saveNote(item) {
  if (savingNoteId.value) return
  savingNoteId.value = item.share_id
  try {
    const { data } = await api.patch(`/api/shared-insights/${item.share_id}/note`, {
      therapist_note: draftNote.value.trim(),
    })
    item.therapist_note = data.therapist_note
    editingNoteId.value = null
  } catch {
    // non-critical -- leave the editor open so the therapist can retry
  } finally {
    savingNoteId.value = null
  }
}

// 2026-08-19 — day/trigger/time diagram for a shared emotion_breakdown
// panel (user: "make the insights... detailed by day and what are the
// triggers and the time when the person feels it"), redesigned same day
// against a mockup the user provided (dot-field chart with an "insight
// banner" above it, per-emotion color instead of just tone, and a
// grouped entry-card list below instead of a flat triggered-only list).
// Backend already did the hard part (SharedInsightController::store() ->
// EmotionPatternService::weekly(), moment-linked trigger + hour derived
// from Entry::created_at) -- everything below is presentation over that
// same daily_pattern payload, no new API calls.
const TIME_BUCKETS = ['morning', 'afternoon', 'evening', 'night']
// 2026-08-19: bumped up alongside the page going full-desktop-width
// (user: "use all the desktop space and make the diagram bigger") --
// larger cell coordinates so the diagram has genuine room to breathe at
// the new card width, not just CSS upscaling a small viewBox.
const COL_W = 130, ROW_H = 76, LEFT_M = 90, TOP_M = 30, BOTTOM_M = 10, RIGHT_M = 16
const CHART_PALETTE_SIZE = 8

// One --chart-N color per distinct emotion appearing in this share, in
// first-appearance order (stable across the several places that need it
// -- the diagram dots, the legend, and the entry-card rows all read from
// the same map so a given emotion is always the same color everywhere on
// this one card). Reuses the app's existing 8-color categorical palette
// (Dashboard.vue's per-entry chart coloring) rather than inventing a
// second one just for this diagram.
const colorMapCache = new Map()
function emotionColorMap(item) {
  if (colorMapCache.has(item.share_id)) return colorMapCache.get(item.share_id)
  const map = {}
  let idx = 0
  ;(item.daily_pattern ?? []).forEach(d => {
    d.items.forEach(it => {
      if (!(it.emotion in map)) {
        map[it.emotion] = `var(--chart-${(idx % CHART_PALETTE_SIZE) + 1})`
        idx++
      }
    })
  })
  colorMapCache.set(item.share_id, map)
  return map
}

// Legend grouped by the same positive/negative/accent tone this app
// already assigns every emotion (EMOTION_TONE) -- not a new taxonomy
// dimension, just a friendlier label per group than the raw tone value.
const TONE_GROUP_LABEL = { positive: 'lighter feelings', negative: 'heavier feelings', accent: 'mixed feelings' }
const TONE_GROUP_ORDER = ['negative', 'positive', 'accent']
const legendCache = new Map()
function legendGroups(item) {
  if (legendCache.has(item.share_id)) return legendCache.get(item.share_id)
  const colorMap = emotionColorMap(item)
  const byTone = {}
  Object.keys(colorMap).forEach(emotion => {
    const tone = EMOTION_TONE[emotion] ?? 'accent'
    ;(byTone[tone] ??= []).push({ label: humanize(emotion), color: colorMap[emotion] })
  })
  const groups = Object.keys(byTone)
    .sort((a, b) => TONE_GROUP_ORDER.indexOf(a) - TONE_GROUP_ORDER.indexOf(b))
    .map(tone => ({ tone, label: TONE_GROUP_LABEL[tone] ?? tone, emotions: byTone[tone] }))
  legendCache.set(item.share_id, groups)
  return groups
}

// Memoized by share_id -- the template calls dailyChart() from several
// bindings (viewBox, rows, cols, dots) per card, and daily_pattern is
// immutable once a share loads, so recomputing the same layout on every
// one of those calls would just be wasted work.
const chartCache = new Map()
function dailyChart(item) {
  if (chartCache.has(item.share_id)) return chartCache.get(item.share_id)

  const days = item.daily_pattern
  if (!days?.length) { chartCache.set(item.share_id, null); return null }

  const colorMap = emotionColorMap(item)

  // Only render rows for time-of-day buckets that actually have an item
  // somewhere in the week -- a client who only ever writes in the
  // morning and evening shouldn't see two empty "afternoon"/"night" rows.
  const usedBuckets = TIME_BUCKETS.filter(b => days.some(d => d.items.some(it => it.time_of_day === b)))
  const skippedBuckets = TIME_BUCKETS.filter(b => !usedBuckets.includes(b))

  const cols = days.map((d, i) => ({
    date: d.date,
    label: d.day_label,
    x: LEFT_M + i * COL_W,
  }))
  const rows = usedBuckets.map((bucket, i) => ({
    key: bucket,
    label: bucket,
    y: TOP_M + i * ROW_H,
  }))

  const cells = []
  days.forEach((d, di) => {
    const cx0 = LEFT_M + di * COL_W
    const byBucket = {}
    d.items.forEach(it => { (byBucket[it.time_of_day] ??= []).push(it) })

    usedBuckets.forEach((bucket, bi) => {
      const its = byBucket[bucket] || []
      if (!its.length) return

      const cx = cx0 + COL_W / 2
      const cy = TOP_M + bi * ROW_H + ROW_H / 2
      const dominant = its.reduce((a, b) => (b.intensity ?? 0) > (a.intensity ?? 0) ? b : a)
      const avgIntensity = its.reduce((sum, it) => sum + (it.intensity ?? 5), 0) / its.length

      cells.push({
        key: `${d.date}-${bucket}`,
        cx, cy,
        r: 4 + Math.min(avgIntensity, 10) * 0.8,
        fill: colorMap[dominant.emotion],
        multi: its.length > 1,
        count: its.length,
        popupItems: its.map(it => ({
          emotion: humanize(it.emotion),
          intensity: it.intensity,
          trigger: it.trigger ? humanize(it.trigger) : null,
          hour: formatHour(it.hour),
          color: colorMap[it.emotion],
        })),
      })
    })
  })

  const result = {
    width: LEFT_M + days.length * COL_W + RIGHT_M,
    height: TOP_M + usedBuckets.length * ROW_H + BOTTOM_M,
    cols, rows, cells, skippedBuckets,
  }
  chartCache.set(item.share_id, result)
  return result
}


function formatHour(hour) {
  if (hour === null || hour === undefined) return ''
  const h12 = hour % 12 === 0 ? 12 : hour % 12
  return `${h12}${hour < 12 ? 'am' : 'pm'}`
}

// 2026-08-19 — replaces the earlier "every moment, grouped by day+time"
// entry-card list (user: pasted an example of how long/repetitive that
// list got and said "just tell the most dominant 3 feelings"). Same
// stats the top pick of insightHighlights() computes, just carried
// through for the top 3 instead of only the top 1, plus each one's own
// most common trigger -- a compact summary card instead of a full
// itemized log.
const topFeelingsCache = new Map()
function topFeelings(item) {
  if (topFeelingsCache.has(item.share_id)) return topFeelingsCache.get(item.share_id)

  const days = item.daily_pattern
  const colorMap = emotionColorMap(item)
  const out = []
  if (days?.length) {
    const stats = {}
    days.forEach(d => {
      d.items.forEach(it => {
        const s = (stats[it.emotion] ??= { count: 0, sum: 0, peak: 0, triggers: {} })
        s.count++
        s.sum += it.intensity ?? 0
        s.peak = Math.max(s.peak, it.intensity ?? 0)
        if (it.trigger) {
          it.trigger.split(' & ').forEach(t => { s.triggers[t] = (s.triggers[t] ?? 0) + 1 })
        }
      })
    })

    Object.entries(stats)
      .sort((a, b) => b[1].count - a[1].count || (b[1].sum / b[1].count) - (a[1].sum / a[1].count))
      .slice(0, 3)
      .forEach(([label, s]) => {
        const topTrigger = Object.entries(s.triggers).sort((a, b) => b[1] - a[1])[0]
        out.push({
          label: humanize(label),
          color: colorMap[label],
          count: s.count,
          avg: (s.sum / s.count).toFixed(1),
          peak: s.peak,
          trigger: topTrigger ? humanize(topTrigger[0]) : null,
        })
      })
  }
  topFeelingsCache.set(item.share_id, out)
  return out
}

// "What stands out" banner above the diagram -- 3 short takeaways
// derived entirely from daily_pattern already on the page (no extra API
// call): the most common trigger, the most frequent emotion (with its
// average/peak intensity), and which day+time-of-day cell is densest.
// Purely a re-aggregation of what's already rendered below it, same
// extraction-vs-inference discipline as everything else in this app --
// nothing here is generated text, just counts and an average.
const activeCell = ref(null)

function openCellPopup(cell, event) {
  event.stopPropagation()
  if (activeCell.value?.key === cell.key) { activeCell.value = null; return }
  activeCell.value = { key: cell.key, items: cell.popupItems, x: event.clientX, y: event.clientY }
}

const highlightCache = new Map()
function insightHighlights(item) {
  if (highlightCache.has(item.share_id)) return highlightCache.get(item.share_id)

  const days = item.daily_pattern
  const out = []
  if (days?.length) {
    const allItems = days.flatMap(d => d.items.map(it => ({ ...it, day_label: d.day_label })))
    const total = allItems.length

    const triggerCounts = {}
    allItems.forEach(it => {
      if (!it.trigger) return
      it.trigger.split(' & ').forEach(t => { triggerCounts[t] = (triggerCounts[t] ?? 0) + 1 })
    })
    const topTrigger = Object.entries(triggerCounts).sort((a, b) => b[1] - a[1])[0]
    if (topTrigger) {
      out.push(`${humanize(topTrigger[0])} is the most common trigger — present in ${topTrigger[1]} of ${total} moments.`)
    }

    const emoStats = {}
    allItems.forEach(it => {
      const s = (emoStats[it.emotion] ??= { count: 0, sum: 0, peak: 0 })
      s.count++
      s.sum += it.intensity ?? 0
      s.peak = Math.max(s.peak, it.intensity ?? 0)
    })
    const topEmotion = Object.entries(emoStats)
      .sort((a, b) => b[1].count - a[1].count || (b[1].sum / b[1].count) - (a[1].sum / a[1].count))[0]
    if (topEmotion) {
      const [label, s] = topEmotion
      out.push(`${humanize(label)} is the most frequent feeling — averaging ${(s.sum / s.count).toFixed(1)}/10, peaking at ${s.peak}.`)
    }

    const bucketCounts = {}
    const cellCounts = {}
    allItems.forEach(it => {
      bucketCounts[it.time_of_day] = (bucketCounts[it.time_of_day] ?? 0) + 1
      const key = `${it.day_label} ${it.time_of_day}`
      cellCounts[key] = (cellCounts[key] ?? 0) + 1
    })
    const sortedBuckets = Object.entries(bucketCounts).sort((a, b) => b[1] - a[1])
    const densestCell = Object.entries(cellCounts).sort((a, b) => b[1] - a[1])[0]
    if (sortedBuckets.length >= 2 && densestCell) {
      const [heavier] = sortedBuckets[0]
      const [lighter] = sortedBuckets[sortedBuckets.length - 1]
      out.push(heavier !== lighter
        ? `${heavier}s run heavier than ${lighter}s — ${densestCell[0]} holds the densest cluster.`
        : `${densestCell[0]} holds the densest cluster this period.`)
    } else if (densestCell) {
      out.push(`${densestCell[0]} holds the densest cluster this period.`)
    }
  }

  highlightCache.set(item.share_id, out)
  return out
}
</script>

<template>
  <div class="me" :data-theme="theme" @click="activeCell = null">
    <header class="bar">
      <div class="bar-left">
        <button class="ghost" @click="router.push({ name: role === 'therapist' ? 'therapist' : 'my-therapist' })">← back</button>
        <img class="wordmark" src="/logo.png" alt="me" />
      </div>
      <div class="bar-actions">
        <!-- Therapist with a client selected: full per-client tab strip -->
        <template v-if="role === 'therapist' && clientId">
          <button class="ghost" @click="router.push({ name: 'crisis-thread', query: { client_id: clientId } })">chat</button>
          <button class="ghost" @click="router.push({ name: 'session-briefs', query: { client_id: clientId, name: clientName } })">briefs</button>
          <button class="ghost current">insights</button>
          <button class="ghost" @click="router.push({ name: 'tasks', query: { client_id: clientId, name: clientName } })">tasks</button>
          <button class="ghost" @click="router.push({ name: 'sessions', query: { client_id: clientId, name: clientName } })">sessions</button>
          <span class="bar-sep"></span>
          <button class="ghost" @click="router.push({ name: 'sessions' })">calendar</button>
          <button class="ghost" @click="router.push({ name: 'therapist-activity' })">activity</button>
          <button class="ghost" @click="handleLogout">log out</button>
        </template>
        <!-- Therapist at /my-insights without a client: simplified nav -->
        <template v-else-if="role === 'therapist'">
          <button class="ghost" @click="router.push({ name: 'sessions' })">calendar</button>
          <button class="ghost" @click="router.push({ name: 'therapist-activity' })">activity</button>
          <button class="ghost" @click="handleLogout">log out</button>
        </template>
        <!-- Client: therapist-bridge tab strip -->
        <template v-else>
          <button class="ghost" @click="router.push({ name: 'crisis-thread' })">chat</button>
          <button class="ghost current">what you've shared</button>
          <button class="ghost" @click="router.push({ name: 'tasks' })">your tasks</button>
          <button class="ghost" @click="router.push({ name: 'session-briefs' })">brief session</button>
          <button class="ghost" @click="router.push({ name: 'sessions' })">sessions</button>
          <button class="ghost" @click="handleLogout">log out</button>
        </template>
      </div>
    </header>

    <div class="wrap">
      <div class="head">
        <span class="eyebrow">shared insights</span>
        <h1 class="stamp">{{ role === 'therapist' ? (clientId ? clientName : 'select a client') : "what you've shared" }}</h1>
        <p class="lede" v-if="role === 'client'">
          frozen copies of panels you chose to share — never a live link into your raw entries.
          revoke any of these any time; it disappears from your therapist's view immediately.
        </p>
        <p class="lede" v-else-if="role === 'therapist' && !clientId">
          open a client from your practice to view their shared insights.
        </p>
      </div>

      <p v-if="loading" class="state-msg">loading…</p>
      <p v-else-if="loadError" class="state-msg">{{ loadError }}</p>

      <template v-else>
        <p v-if="!items.length" class="state-msg quiet">
          {{ role === 'therapist' ? 'nothing shared with you yet.' : "you haven't shared anything yet — look for the “share” button on any panel in your dashboard or monthly view." }}
        </p>

        <div class="insight-list" v-if="activeItems.length">
          <div class="insight-card" :class="'panel-' + item.panel_key" v-for="item in activeItems" :key="item.share_id">
            <div class="insight-top">
              <div>
                <span class="insight-title">{{ item.title }}</span>
                <span class="insight-meta">{{ item.period_label }} · shared {{ formatDate(item.granted_at) }}</span>
              </div>
              <button
                v-if="role === 'client'"
                class="ghost insight-revoke" :disabled="revokingId === item.share_id"
                @click="revoke(item)"
              >{{ revokingId === item.share_id ? '…' : 'unshare' }}</button>
            </div>

            <p v-if="item.caption" class="insight-caption">{{ item.caption }}</p>

            <!-- ── Emotion breakdown: pictorial fraction dots ── -->
            <div v-if="item.panel_key === 'emotion_breakdown'" class="si-pictorial-panel">
              <div class="si-pictorial-row" v-for="r in item.rows" :key="r.label">
                <span class="si-pictorial-label">{{ r.label }}</span>
                <div class="si-pictorial-dots" :class="'tone-' + (r.tone || 'accent')">
                  <span v-for="n in 10" :key="n" class="dot" :class="{ filled: n <= (r.filled || Math.round((r.width || 0) / 10)) }"></span>
                </div>
                <span class="si-pictorial-freq">{{ r.detail }}</span>
              </div>
            </div>

            <!-- ── Mood ring ── -->
            <div v-else-if="item.panel_key === 'mood' && moodRingForItem(item)" class="si-mood-wrap">
              <svg class="si-mood-ring" viewBox="0 0 160 160" aria-hidden="true">
                <circle class="si-ring-track" cx="80" cy="80" r="64" />
                <g transform="rotate(-90 80 80)">
                  <circle
                    v-for="(seg, i) in moodRingForItem(item).segments" :key="i"
                    class="si-ring-seg" :class="'ring-' + seg.sentiment"
                    cx="80" cy="80" r="64"
                    :stroke-dasharray="seg.dash"
                    :stroke-dashoffset="seg.dashoffset"
                  />
                </g>
              </svg>
              <div class="si-mood-legend">
                <div v-for="r in (item.rows ?? []).filter(r => r.width > 0)" :key="r.label" class="si-mood-legend-row">
                  <span class="si-mood-swatch" :style="{ background: moodSegmentColor(r.label) }"></span>
                  <span class="si-mood-sent">{{ r.label }}</span>
                  <span class="si-mood-pct">{{ r.width }}%</span>
                </div>
              </div>
            </div>

            <!-- ── Behavior breakdown: pie chart ── -->
            <div v-else-if="item.panel_key === 'behavior_breakdown' && behaviorPieForItem(item)" class="si-pie-wrap">
              <svg viewBox="0 0 180 180" class="si-pie-chart">
                <path
                  v-for="s in behaviorPieForItem(item).slices" :key="s.label"
                  :d="s.path" :style="{ opacity: s.opacity }"
                  :class="'pie-' + s.tone"
                />
              </svg>
              <div class="si-pie-legend">
                <div v-for="s in behaviorPieForItem(item).slices" :key="s.label" class="si-legend-item">
                  <span class="si-legend-swatch" :class="'pie-' + s.tone" :style="{ opacity: s.opacity }"></span>
                  <span class="si-legend-label">{{ s.label }}</span>
                  <span class="si-legend-freq">{{ s.freq }}</span>
                </div>
              </div>
            </div>

            <!-- ── Pattern chain (trigger → emotion / thinking / behavior) ── -->
            <div v-else-if="item.scope === 'pattern'" class="si-chain-panel">
              <div class="si-chain-row" v-for="r in item.rows" :key="r.label">
                <span class="si-chain-label">{{ r.label }}</span>
                <span class="si-chain-detail">{{ r.detail }}</span>
              </div>
            </div>

            <!-- ── Bar charts: protective, needs, stressors, thinking patterns ── -->
            <div v-else-if="item.rows?.length" class="si-bar-panel">
              <div class="si-bar-row" v-for="(r, idx) in item.rows" :key="r.label">
                <span class="si-bar-label">{{ r.label }}</span>
                <div class="si-bar-track">
                  <div class="si-bar-fill" :style="{ width: (r.width || 0) + '%', background: r.tone === 'positive' ? 'var(--accent)' : r.tone === 'negative' ? 'var(--ochre)' : `var(--chart-${(idx % 8) + 1})` }"></div>
                </div>
                <span class="si-bar-freq">{{ r.detail }}</span>
              </div>
            </div>

            <!-- ── Therapist-only: analysis overlay on top of the chart ── -->
            <template v-if="role === 'therapist'">
              <!-- Baseline/trajectory context per row -->
              <div v-if="item.rows?.some(r => r.context || r.trajectory || r.flag === 'up' || r.flag === 'new')" class="sub-grid" style="margin-top:12px">
                <div class="sub-card">
                  <span class="sub-card-title">row context</span>
                  <ul class="insight-rows">
                    <li v-for="(r, i) in item.rows" :key="i" :class="{ 'row-flagged': r.flag === 'up' || r.flag === 'new' }">
                      <div class="row-main">
                        <span class="row-label">
                          <span v-if="r.flag === 'up' || r.flag === 'new'" class="row-dot" aria-hidden="true"></span>
                          {{ r.label }}
                        </span>
                        <span class="row-detail">{{ r.detail }}</span>
                      </div>
                      <span v-if="r.context" class="row-context">{{ r.context }}</span>
                      <span v-else-if="r.trajectory" class="row-context row-context--trajectory">{{ r.trajectory }}</span>
                    </li>
                  </ul>
                </div>
                <div v-if="insightHighlights(item).length" class="sub-card sub-card-accent">
                  <span class="sub-card-title">what stands out</span>
                  <ul class="insight-banner-list">
                    <li v-for="(h, i) in insightHighlights(item)" :key="i">{{ h }}</li>
                  </ul>
                </div>
                <div v-if="topFeelings(item).length" class="sub-card">
                  <span class="sub-card-title">top 3 feelings</span>
                  <div class="top-feelings">
                    <div v-for="(f, i) in topFeelings(item)" :key="f.label" class="top-feeling-row">
                      <span class="tf-rank">{{ i + 1 }}</span>
                      <span class="tf-dot" :style="{ background: f.color }"></span>
                      <span class="tf-label">{{ f.label }}</span>
                      <span class="tf-count">{{ f.count }}×</span>
                      <span class="tf-stat">avg {{ f.avg }}/10, peak {{ f.peak }}</span>
                      <span v-if="f.trigger" class="tf-trigger">mostly with {{ f.trigger }}</span>
                    </div>
                  </div>
                </div>
              </div>
            </template>

            <!-- 2026-08-19: day/trigger/time diagram, only present on a
                 shared weekly emotion_breakdown panel (see
                 EmotionPatternService's docblock). Full-width sub-card of
                 its own so it can actually be bigger, per the user's
                 request, while the dots inside it went smaller. -->
            <div v-if="role === 'therapist' && item.daily_pattern" class="sub-card sub-card-wide daily-chart">
              <span class="sub-card-title">by day, time of day &amp; trigger</span>

              <div class="chart-legend">
                <div v-for="grp in legendGroups(item)" :key="grp.tone" class="legend-group">
                  <span class="legend-group-name">{{ grp.label }}</span>
                  <span class="legend-items">
                    <span v-for="e in grp.emotions" :key="e.label" class="legend-item">
                      <span class="legend-swatch" :style="{ background: e.color }"></span>{{ e.label }}
                    </span>
                  </span>
                </div>
              </div>

              <!-- no maxWidth cap on the SVG -- width:100% fills this
                   full-width sub-card. The viewBox keeps everything
                   (dots, text, gridlines) scaling together. -->
              <svg
                :viewBox="`0 0 ${dailyChart(item).width} ${dailyChart(item).height}`"
                class="daily-chart-svg"
              >
                <line
                  v-for="row in dailyChart(item).rows" :key="'row-' + row.key"
                  :x1="LEFT_M" :x2="dailyChart(item).width - RIGHT_M" :y1="row.y" :y2="row.y"
                  class="chart-gridline"
                />
                <text
                  v-for="row in dailyChart(item).rows" :key="'rowlabel-' + row.key"
                  :x="LEFT_M - 8" :y="row.y + ROW_H / 2 + 4" class="chart-row-label" text-anchor="end"
                >{{ row.label }}</text>
                <text
                  v-for="col in dailyChart(item).cols" :key="'collabel-' + col.date"
                  :x="col.x + COL_W / 2" :y="TOP_M - 8" class="chart-col-label" text-anchor="middle"
                >{{ col.label }}</text>
                <g
                  v-for="cell in dailyChart(item).cells" :key="cell.key"
                  class="chart-cell"
                  @click.stop="openCellPopup(cell, $event)"
                >
                  <circle
                    :cx="cell.cx" :cy="cell.cy" :r="cell.r"
                    class="chart-dot" :class="{ 'chart-dot-multi': cell.multi }"
                    :style="{ fill: cell.fill }"
                  />
                  <text
                    v-if="cell.multi"
                    :x="cell.cx" :y="cell.cy + 3.5"
                    class="chart-dot-badge" text-anchor="middle"
                  >{{ cell.count }}</text>
                </g>
              </svg>

              <p v-if="dailyChart(item).skippedBuckets.length" class="chart-empty-note">
                no {{ dailyChart(item).skippedBuckets.join(' or ') }} logs this period
              </p>

              <div class="scale-hint">
                <span>dot size = intensity</span>
                <span class="scale-dots">
                  <i class="sd" style="width:7px;height:7px"></i>
                  <i class="sd" style="width:11px;height:11px"></i>
                  <i class="sd" style="width:16px;height:16px"></i>
                </span>
                <span>weak → strong</span>
              </div>
            </div>

            <p v-if="item.note" class="insight-note">note: {{ item.note }}</p>

            <!-- 2026-08-19: therapist-only private annotation, separate
                 from the client's `note` above -- same pattern as
                 TherapySession outcome_note. Never rendered for role
                 === 'client'; a client never sees what their therapist
                 privately wrote about a share. -->
            <div v-if="role === 'therapist'" class="therapist-note">
              <template v-if="editingNoteId === item.share_id">
                <textarea
                  v-model="draftNote" class="therapist-note-input" rows="2"
                  placeholder="private note — only you can see this…"
                  maxlength="1000"
                ></textarea>
                <div class="therapist-note-actions">
                  <button class="ghost" :disabled="savingNoteId === item.share_id" @click="saveNote(item)">
                    {{ savingNoteId === item.share_id ? 'saving…' : 'save' }}
                  </button>
                  <button class="ghost" :disabled="savingNoteId === item.share_id" @click="cancelNote">cancel</button>
                </div>
              </template>
              <template v-else-if="item.therapist_note">
                <p class="therapist-note-text" @click="startNote(item)">{{ item.therapist_note }}</p>
              </template>
              <button v-else class="ghost therapist-note-add" @click="startNote(item)">+ private note</button>
            </div>
          </div>
        </div>

        <template v-if="role === 'client' && revokedItems.length">
          <p class="revoked-heading muted">unshared</p>
          <div class="insight-list">
            <div class="insight-card revoked" v-for="item in revokedItems" :key="item.share_id">
              <div class="insight-top">
                <div>
                  <span class="insight-title">{{ item.title }}</span>
                  <span class="insight-meta">{{ item.period_label }} · unshared {{ formatDate(item.revoked_at) }}</span>
                </div>
              </div>
            </div>
          </div>
        </template>

      </template>
    </div>

    <!-- ── Floating AI chat bubble — therapist only ── -->
    <template v-if="role === 'therapist' && clientId && activeItems.length">
      <!-- FAB trigger -->
      <button class="chat-fab" @click="toggleChat" :aria-label="chatOpen ? 'close AI chat' : 'ask AI about these insights'">
        <span v-if="!chatOpen">◎ ask AI</span>
        <span v-else>✕</span>
      </button>

      <!-- Floating panel, opens above the FAB -->
      <div class="chat-panel" :class="{ open: chatOpen }">
        <div class="chat-panel-head">
          <span class="chat-panel-title">ask about these insights</span>
          <button class="ghost" style="padding:4px 8px;font-size:13px" @click="toggleChat">✕</button>
        </div>
        <div class="chat-messages">
          <p v-if="!chatMessages.length" class="chat-empty">ask anything about {{ clientName }}'s shared panels.</p>
          <div v-for="(m, i) in chatMessages" :key="i" class="chat-msg" :class="m.role">
            <span v-if="m.role === 'assistant'" class="chat-sender">◎ AI</span>
            <p class="chat-text">{{ m.text }}</p>
          </div>
        </div>
        <p v-if="chatError" class="chat-error">{{ chatError }}</p>
        <div class="chat-input-row">
          <input
            v-model="chatInput" class="chat-input"
            placeholder="e.g. what emotions appear most in the morning?"
            @keydown.enter.prevent="sendChat"
            :disabled="chatBusy"
          />
          <button class="ghost" @click="sendChat" :disabled="chatBusy || !chatInput.trim()">
            {{ chatBusy ? '…' : 'ask' }}
          </button>
        </div>
      </div>
    </template>

    <!-- popup shown when a merged chart cell is clicked -->
    <div
      v-if="activeCell"
      class="cell-popup"
      :style="{ top: activeCell.y + 14 + 'px', left: activeCell.x + 14 + 'px' }"
      @click.stop
    >
      <div v-for="it in activeCell.items" :key="it.emotion + it.hour" class="popup-item">
        <span class="popup-swatch" :style="{ background: it.color }"></span>
        <span class="popup-emotion">{{ it.emotion }}</span>
        <span class="popup-intensity">{{ it.intensity }}/10</span>
        <span v-if="it.trigger" class="popup-trigger">{{ it.trigger }}</span>
        <span class="popup-time">{{ it.hour }}</span>
      </div>
    </div>
  </div>
</template>

<style scoped>
.me[data-theme='dusk'] {
  --paper:#F2EDE6; --surface:#FFFFFF; --ink:#2E2B27; --muted:#8C8279;
  --accent:#7FA08A; --accent-deep:#4E7A65; --ochre:#C6A063; --line:#E3DDD5;
  --chart-1:#7FA08A; --chart-2:#C6A063; --chart-3:#6E8CA6; --chart-4:#B97159;
  --chart-5:#8C7093; --chart-6:#8A8C5E; --chart-7:#4F8C8C; --chart-8:#A6748C;
}
.me[data-theme='paper'] {
  --paper:#F7F4EE; --surface:#FFFFFF; --ink:#33302A; --muted:#7A736A;
  --accent:#9A8C74; --accent-deep:#6E624E; --ochre:#B98B54; --line:#EAE5DC;
  --chart-1:#9A8C74; --chart-2:#B98B54; --chart-3:#6E8CA6; --chart-4:#B97159;
  --chart-5:#8C7093; --chart-6:#8A8C5E; --chart-7:#4F8C8C; --chart-8:#A6748C;
}
.me[data-theme='night'] {
  --paper:#1B1D20; --surface:#232629; --ink:#E9E9E6; --muted:#9BA0A2;
  --accent:#89AE97; --accent-deep:#B6D0BF; --ochre:#D3AE74; --line:#31353A;
  --chart-1:#89AE97; --chart-2:#D3AE74; --chart-3:#86AEC9; --chart-4:#D48F76;
  --chart-5:#AD93B5; --chart-6:#ABAE7C; --chart-7:#6FB0B0; --chart-8:#C594AC;
}
.me {
  --font-display:'Fraunces', Georgia, serif;
  --font-ui:'Instrument Sans', system-ui, sans-serif;
  background:var(--paper); color:var(--ink);
  font-family:var(--font-ui); line-height:1.5;
  transition:background .4s ease, color .4s ease;
  min-height:100vh;
}
* { box-sizing:border-box; }

/* ── Nav bar ── */
.bar {
  display:flex; align-items:center; justify-content:space-between;
  padding:14px 40px; background:var(--surface);
  border-bottom:1px solid var(--line);
  position:sticky; top:0; z-index:50;
}
.wordmark { height:72px; width:auto; display:block; }
.bar-left { display:flex; align-items:center; gap:12px; }
.bar-actions { display:flex; align-items:center; gap:4px; }
.bar-sep { width:1px; height:16px; background:var(--line); flex-shrink:0; margin:0 4px; }
.ghost { background:none; border:none; color:var(--muted); font:inherit; font-size:13px; padding:6px 10px; border-radius:8px; cursor:pointer; transition:color .12s, background .12s; }
.ghost:hover { color:var(--ink); background:var(--paper); }
.ghost:disabled { opacity:.4; cursor:default; }
.ghost.current { color:var(--ink); background:var(--paper); font-weight:500; cursor:default; }
.ghost.current:hover { color:var(--ink); background:var(--paper); }

/* ── Page layout ── */
.wrap { max-width:1160px; margin:0 auto; padding:44px 48px 80px; }
.state-msg { color:var(--muted); font-size:15px; text-align:center; padding:80px 0 0; }
.state-msg.quiet { padding:20px 0; text-align:left; font-size:14px; }

/* ── Page header ── */
.head { margin-bottom:32px; }
.eyebrow { display:block; font-size:11px; letter-spacing:.16em; text-transform:uppercase; color:var(--muted); margin-bottom:8px; }
.stamp { font-family:var(--font-display); font-weight:400; font-size:28px; margin:0 0 10px; }
.lede { font-size:13.5px; color:var(--muted); margin:0; max-width:500px; line-height:1.6; }

/* ── Card list ── */
.insight-list { display:flex; flex-direction:column; gap:20px; }
.insight-card {
  background:var(--surface);
  border:1px solid var(--line);
  border-top:3px solid var(--line);
  border-radius:18px;
  padding:24px 28px;
  box-shadow:0 1px 3px rgba(0,0,0,.04), 0 4px 14px rgba(0,0,0,.04);
  transition:box-shadow .2s, transform .18s;
}
.insight-card:hover { box-shadow:0 6px 28px rgba(0,0,0,.09); transform:translateY(-2px); }
.insight-card.revoked { opacity:.42; pointer-events:none; border-top-color:var(--line); }

/* Panel-type accent on top border */
.insight-card.panel-emotion_breakdown           { border-top-color:var(--accent); }
.insight-card.panel-mood                        { border-top-color:color-mix(in srgb,var(--accent) 55%,var(--ochre)); }
.insight-card.panel-protective_factor_breakdown { border-top-color:var(--accent-deep); }
.insight-card.panel-behavior_breakdown          { border-top-color:var(--muted); }
.insight-card.panel-stressor_breakdown          { border-top-color:var(--ochre); }
.insight-card.panel-need_breakdown              { border-top-color:color-mix(in srgb,var(--accent) 50%,var(--muted)); }
.insight-card.panel-thinking_pattern_breakdown  { border-top-color:color-mix(in srgb,var(--ochre) 65%,var(--muted)); }

/* ── Card header ── */
.insight-top { display:flex; align-items:flex-start; justify-content:space-between; gap:12px; margin-bottom:14px; }
.insight-title { display:block; font-family:var(--font-display); font-size:19px; font-weight:400; line-height:1.3; }
.insight-meta { display:block; font-size:12px; color:var(--muted); margin-top:5px; }
.insight-revoke { flex:0 0 auto; font-size:12px; margin-top:2px; }

.insight-caption {
  font-size:13.5px; margin:0 0 16px; color:var(--muted);
  max-width:600px; line-height:1.6;
  padding:9px 13px;
  background:var(--paper); border-radius:10px;
  border-left:3px solid var(--line);
}

/* ── Emotion dots (emotion_breakdown) ── */
.si-pictorial-panel { display:flex; flex-direction:column; gap:0; margin-top:16px; }
.si-pictorial-row {
  display:flex; align-items:center; gap:14px;
  padding:9px 0; border-bottom:1px solid var(--line);
}
.si-pictorial-row:last-child { border-bottom:none; }
.si-pictorial-label { width:130px; font-size:13.5px; font-weight:500; flex-shrink:0; text-transform:capitalize; }
.si-pictorial-dots { display:flex; gap:5px; flex:1; align-items:center; }
.si-pictorial-dots .dot {
  width:13px; height:13px; border-radius:50%; flex-shrink:0;
  background:transparent; border:1.5px solid var(--line);
  transition:background .15s, border-color .15s;
}
.si-pictorial-dots.tone-positive .dot.filled { background:var(--accent);  border-color:var(--accent); }
.si-pictorial-dots.tone-negative .dot.filled { background:var(--ochre);   border-color:var(--ochre); }
.si-pictorial-dots.tone-accent   .dot.filled { background:var(--muted);   border-color:var(--muted); }
.si-pictorial-freq { font-size:12px; color:var(--muted); white-space:nowrap; min-width:84px; text-align:right; }

/* ── Mood ring ── */
.si-mood-wrap { display:flex; align-items:center; gap:32px; padding:20px 0 12px; }
.si-mood-ring { width:130px; height:130px; flex-shrink:0; filter:drop-shadow(0 3px 10px rgba(0,0,0,.08)); }
.si-ring-track { fill:none; stroke:var(--line); stroke-width:20; }
.si-ring-seg   { fill:none; stroke-width:20; }
.ring-positive { stroke:var(--accent); }
.ring-negative { stroke:var(--ochre); }
.ring-neutral  { stroke:var(--muted); }
.ring-mixed    { stroke:color-mix(in srgb,var(--accent) 50%,var(--ochre)); }
.si-mood-legend { display:flex; flex-direction:column; gap:10px; }
.si-mood-legend-row { display:flex; align-items:center; gap:10px; font-size:13.5px; }
.si-mood-swatch { width:11px; height:11px; border-radius:50%; flex-shrink:0; }
.si-mood-sent { text-transform:capitalize; font-weight:500; }
.si-mood-pct { color:var(--muted); font-size:12.5px; margin-left:4px; }

/* ── Behavior pie ── */
.si-pie-wrap { display:flex; gap:32px; align-items:center; flex-wrap:wrap; margin-top:16px; padding:4px 0; }
.si-pie-chart { width:156px; height:156px; flex-shrink:0; filter:drop-shadow(0 3px 10px rgba(0,0,0,.08)); }
.pie-positive { fill:var(--accent); }
.pie-negative { fill:var(--ochre); }
.pie-accent   { fill:var(--muted); }
.si-pie-legend { display:flex; flex-direction:column; gap:11px; }
.si-legend-item { display:flex; align-items:center; gap:10px; font-size:13.5px; }
.si-legend-swatch { width:11px; height:11px; border-radius:3px; flex-shrink:0; }
.si-legend-label { flex:1; font-weight:500; }
.si-legend-freq { color:var(--muted); font-size:12px; }

/* ── Pattern chain (trigger → emotion / thinking / behavior) ── */
.si-chain-panel {
  display:flex; flex-direction:column; gap:0;
  margin-top:16px; border:1px solid var(--line);
  border-radius:14px; overflow:hidden;
}
.si-chain-row {
  display:flex; align-items:center; gap:16px;
  padding:11px 16px; border-bottom:1px solid var(--line);
  background:var(--surface); transition:background .12s;
}
.si-chain-row:last-child { border-bottom:none; }
.si-chain-row:hover { background:var(--paper); }
.si-chain-label {
  font-size:10.5px; font-weight:700; text-transform:uppercase;
  letter-spacing:.09em; color:var(--muted); flex-shrink:0; width:120px;
}
.si-chain-detail { font-size:14px; color:var(--ink); }

/* ── Bar charts (protective / needs / stressors / thinking patterns) ── */
.si-bar-panel { display:flex; flex-direction:column; gap:14px; margin-top:16px; }
.si-bar-row { display:flex; align-items:center; gap:14px; }
.si-bar-label { width:155px; font-size:13px; font-weight:500; flex-shrink:0; text-transform:capitalize; }
.si-bar-track {
  flex:1; height:11px;
  background:var(--paper); border:1px solid var(--line);
  border-radius:6px; overflow:hidden;
}
.si-bar-fill { height:100%; border-radius:6px; transition:width .55s cubic-bezier(.4,0,.2,1); }
.si-bar-freq { width:96px; font-size:12px; color:var(--muted); text-align:right; flex-shrink:0; line-height:1.3; }

/* ── Therapist sub-cards ── */
.sub-grid {
  display:grid; grid-template-columns:repeat(auto-fit, minmax(260px, 1fr));
  gap:14px; margin-top:16px;
}
.sub-card {
  background:var(--paper); border:1px solid var(--line);
  border-radius:14px; padding:18px 20px; min-width:0;
}
.sub-card-accent {
  border-color:color-mix(in srgb,var(--accent) 28%,var(--line));
  background:color-mix(in srgb,var(--accent) 5%,var(--paper));
}
.sub-card-wide { padding:22px 26px 20px; }
.sub-card-title {
  display:block; font-size:10.5px; letter-spacing:.09em; text-transform:uppercase;
  color:var(--muted); font-weight:700; margin:0 0 14px;
}
.sub-card-accent .sub-card-title { color:var(--accent-deep); }
.daily-chart { margin-top:16px; }

/* "What stands out" bullet list */
.insight-banner-list { margin:0; padding:0; list-style:none; display:grid; gap:10px; }
.insight-banner-list li {
  font-size:13.5px; line-height:1.5; color:var(--ink);
  position:relative; padding-left:18px;
}
.insight-banner-list li::before {
  content:""; position:absolute; left:0; top:7px;
  width:7px; height:7px; border-radius:50%; background:var(--accent-deep);
}

/* Row context (baseline / trajectory) */
.insight-rows { list-style:none; margin:0; padding:0; display:flex; flex-direction:column; gap:10px; max-width:640px; }
.insight-rows li { display:flex; flex-direction:column; gap:4px; font-size:13.5px; padding:6px 8px; border-radius:10px; }
.row-main { display:flex; justify-content:space-between; gap:12px; }
.row-label { font-weight:500; display:inline-flex; align-items:center; gap:6px; text-transform:capitalize; }
.row-detail { color:var(--muted); text-align:right; font-size:13px; }
.row-context {
  display:inline-block; font-size:12px; color:var(--accent-deep);
  background:color-mix(in srgb,var(--accent) 11%,var(--surface));
  border:1px solid color-mix(in srgb,var(--accent) 22%,var(--line));
  border-radius:20px; padding:2px 10px; align-self:flex-start; margin-top:2px;
}
.row-context--trajectory {
  color:var(--muted);
  background:color-mix(in srgb,var(--muted) 10%,var(--surface));
  border-color:color-mix(in srgb,var(--muted) 20%,var(--line));
}
.row-flagged { background:color-mix(in srgb,var(--ochre) 8%,transparent); }
.row-dot { width:6px; height:6px; border-radius:50%; flex:0 0 auto; background:var(--ochre); }

/* Top 3 feelings */
.top-feelings { display:flex; flex-direction:column; gap:12px; }
.top-feeling-row { display:flex; align-items:center; gap:8px; flex-wrap:wrap; font-size:13px; }
.tf-rank {
  flex:0 0 auto; width:20px; height:20px; border-radius:50%;
  background:var(--surface); border:1px solid var(--line);
  display:inline-flex; align-items:center; justify-content:center;
  font-size:10.5px; font-weight:700; color:var(--muted);
}
.tf-dot { width:9px; height:9px; border-radius:50%; flex:0 0 auto; }
.tf-label { font-weight:500; text-transform:capitalize; }
.tf-count { color:var(--muted); font-size:12px; }
.tf-stat { color:var(--muted); font-size:12px; }
.tf-trigger {
  font-size:11.5px; color:var(--accent-deep);
  background:color-mix(in srgb,var(--accent) 10%,var(--surface));
  border-radius:20px; padding:1px 9px; margin-left:auto;
}

/* Notes */
.insight-note { font-size:13px; color:var(--muted); margin:12px 0 0; padding-top:12px; border-top:1px dashed var(--line); font-style:italic; max-width:640px; }
.therapist-note { margin:14px 0 0; padding-top:12px; border-top:1px dashed var(--line); max-width:640px; }
.therapist-note-text {
  font-size:13px; color:var(--ink); margin:0; cursor:pointer; line-height:1.55;
  padding:8px 10px; border-radius:10px;
  background:color-mix(in srgb,var(--ochre) 8%,var(--surface));
  transition:background .15s;
}
.therapist-note-text:hover { background:color-mix(in srgb,var(--ochre) 16%,var(--surface)); }
.therapist-note-add { font-size:12px; padding:4px 8px; }
.therapist-note-input {
  width:100%; font:inherit; font-size:13px; color:var(--ink); background:var(--paper);
  border:1px solid var(--line); border-radius:10px; padding:9px 12px; resize:vertical;
}
.therapist-note-input:focus { outline:none; border-color:var(--accent); }
.therapist-note-actions { display:flex; gap:8px; margin-top:8px; }
.therapist-note-actions .ghost { font-size:12px; padding:4px 10px; }

/* Revoked section */
.revoked-heading { font-size:11px; letter-spacing:.12em; text-transform:uppercase; margin:32px 0 12px; color:var(--muted); }
.muted { color:var(--muted); }

/* ── Day/time/trigger chart ── */
.chart-legend { display:flex; flex-wrap:wrap; gap:10px 24px; margin:0 0 16px; }
.legend-group { display:flex; flex-direction:column; gap:5px; min-width:0; }
.legend-group-name { font-size:10px; letter-spacing:.08em; text-transform:uppercase; color:var(--muted); }
.legend-items { display:flex; flex-wrap:wrap; gap:4px 14px; }
.legend-item { display:inline-flex; align-items:center; gap:5px; font-size:12px; color:var(--ink); }
.legend-swatch { width:9px; height:9px; border-radius:50%; flex:0 0 auto; }
.daily-chart-svg { display:block; width:100%; height:auto; }
.chart-gridline { stroke:var(--line); stroke-width:1; }
.chart-row-label { font-size:10px; fill:var(--muted); font-family:var(--font-ui); text-transform:capitalize; }
.chart-col-label { font-size:11px; fill:var(--ink); font-family:var(--font-ui); font-weight:500; }
.chart-dot { stroke:var(--surface); stroke-width:1.5; }
.chart-cell { cursor:pointer; }
.chart-dot-multi { stroke-width:2.5; }
.chart-dot-badge { font-size:9px; font-weight:700; fill:#fff; font-family:var(--font-ui); pointer-events:none; }

/* Cell popup */
.cell-popup {
  position:fixed; z-index:200;
  background:var(--surface); border:1px solid var(--line); border-radius:12px;
  padding:12px 15px; box-shadow:0 6px 28px rgba(0,0,0,.14);
  display:flex; flex-direction:column; gap:8px;
  min-width:190px; max-width:270px;
}
.popup-item { display:flex; align-items:center; gap:7px; flex-wrap:wrap; font-size:12.5px; }
.popup-swatch { width:8px; height:8px; border-radius:50%; flex-shrink:0; }
.popup-emotion { font-weight:600; text-transform:capitalize; }
.popup-intensity { color:var(--muted); font-size:11.5px; }
.popup-trigger {
  font-size:11.5px; color:var(--accent-deep);
  background:color-mix(in srgb,var(--accent) 10%,var(--surface));
  border-radius:6px; padding:1px 7px;
}
.popup-time { font-size:11px; color:var(--muted); margin-left:auto; }
.chart-empty-note { font-size:12px; color:var(--muted); font-style:italic; margin:8px 0 0; text-align:center; }

.scale-hint { display:flex; align-items:center; gap:8px; margin:12px 0 4px; font-size:11.5px; color:var(--muted); }
.scale-dots { display:inline-flex; align-items:center; gap:6px; }
.sd { display:inline-block; border-radius:50%; background:var(--muted); opacity:.5; }

/* ── Floating AI chat bubble ── */
.chat-fab {
  position:fixed; bottom:24px; right:24px; z-index:101;
  background:var(--accent); color:var(--surface);
  border:none; border-radius:24px; padding:10px 18px;
  font:inherit; font-size:13px; font-weight:500; cursor:pointer;
  box-shadow:0 4px 20px rgba(0,0,0,.18);
  transition:background .2s, transform .15s, box-shadow .2s;
  white-space:nowrap;
}
.chat-fab:hover { background:var(--accent-deep); transform:translateY(-2px); box-shadow:0 6px 24px rgba(0,0,0,.22); }
.chat-panel {
  position:fixed; bottom:76px; right:24px; z-index:100;
  width:390px; max-width:calc(100vw - 48px);
  background:var(--surface); border:1px solid var(--line);
  border-radius:18px; box-shadow:0 10px 44px rgba(0,0,0,.18);
  display:flex; flex-direction:column; max-height:500px;
  opacity:0; pointer-events:none;
  transform:translateY(14px) scale(.97); transform-origin:bottom right;
  transition:opacity .2s, transform .2s;
}
.chat-panel.open { opacity:1; pointer-events:auto; transform:none; }
.chat-panel-head {
  display:flex; align-items:center; justify-content:space-between;
  padding:14px 18px 12px; border-bottom:1px solid var(--line); flex-shrink:0;
}
.chat-panel-title { font-size:11px; letter-spacing:.09em; text-transform:uppercase; color:var(--muted); font-weight:700; }
.chat-messages {
  flex:1; overflow-y:auto; display:flex; flex-direction:column;
  gap:10px; padding:16px 18px;
}
.chat-empty { font-size:13px; color:var(--muted); text-align:center; padding:24px 0; font-style:italic; }
.chat-msg {
  max-width:92%; display:flex; flex-direction:column; gap:4px;
  padding:10px 14px; border-radius:14px; font-size:13.5px; line-height:1.55;
}
.chat-msg.user {
  align-self:flex-end;
  background:color-mix(in srgb,var(--accent) 14%,var(--paper));
  border:1px solid color-mix(in srgb,var(--accent) 22%,var(--line));
  border-bottom-right-radius:4px;
}
.chat-msg.assistant {
  align-self:flex-start; background:var(--surface);
  border:1px solid var(--line); border-left:3px solid var(--accent);
  border-bottom-left-radius:4px;
}
.chat-sender { font-size:10px; font-weight:700; letter-spacing:.1em; text-transform:uppercase; color:var(--accent-deep); margin-bottom:2px; }
.chat-text { margin:0; white-space:pre-wrap; word-break:break-word; }
.chat-error { font-size:13px; color:var(--ochre); margin:0 18px 6px; flex-shrink:0; }
.chat-input-row { display:flex; gap:8px; padding:12px 18px 16px; border-top:1px solid var(--line); flex-shrink:0; }
.chat-input {
  flex:1; font:inherit; font-size:13px; color:var(--ink); background:var(--paper);
  border:1px solid var(--line); border-radius:10px; padding:8px 12px;
  transition:border-color .15s;
}
.chat-input:focus { outline:none; border-color:var(--accent); }
.chat-input:disabled { opacity:.5; }

@media (max-width:640px) {
  .bar { padding:12px 20px; }
  .wrap { padding:28px 20px 60px; }
  .insight-card { padding:18px 18px; }
  .si-mood-wrap { flex-direction:column; gap:16px; }
}
</style>
