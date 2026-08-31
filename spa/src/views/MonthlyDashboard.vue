<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import api from '../lib/api'
import {
  warm, humanize, BEHAVIOR_WORDS,
  PROTECTIVE_WORDS, NEED_WORDS, THINKING_PATTERN_WORDS,
} from '../utils/taxonomyDisplay'

const router = useRouter()
const auth   = useAuthStore()
async function handleLogout() { await auth.logout(); router.push({ name: 'home' }) }

const themes = ['dusk', 'paper', 'night']
const theme  = ref('dusk')
function cycleTheme() { theme.value = themes[(themes.indexOf(theme.value) + 1) % themes.length] }

/* ---- load ------------------------------------------------------------
   Month-over-month trends (2026-08-17, see MonthlyDashboardController's
   docblock for the design tradeoffs). Always live -- no seal/lock ritual,
   same spirit as /reflections/live.

   Third follow-up, same day: "i want a zoom for every case in monthly
   also" -- zoom used to be one control for the whole page (a single
   windowMonths driving one fetch). Now every panel has its own
   independent 2/3/6/12mo zoom, since /api/monthly/trends returns ALL six
   fields' trends (plus their shared `weeks` axis) in one response for a
   given `months` value, per-panel zoom means per-panel `weeks`, so a
   panel zoomed differently from its neighbors genuinely needs its own
   fetch of that `months` value. `cache` is keyed by months (2/3/6/12) and
   holds the whole multi-field response for that window at the current
   focusMonth; panels that happen to share a zoom level share one cached
   response instead of each firing their own request. The cache is wiped
   entirely on month-nav (prev/next), since every cached response was
   computed for the OLD focus month. navBusy only gates month-nav (a
   global operation, refetching every zoom level currently in use);
   an individual panel's zoom change shows its own small pending state
   instead of blocking the rest of the grid. */
const WINDOW_OPTIONS = [2, 3, 6, 12]
// 2026-08-19, user: "make the trends show between 2 months by default" --
// was 6. Every panel still starts independent and can be zoomed to any
// WINDOW_OPTIONS value per-panel, this only changes the starting point.
const DEFAULT_WINDOW = 2

const focusMonth     = ref(null)   // 'YYYY-MM', synced from the server once the first response lands
const isCurrentMonth = ref(true)
const loading        = ref(true)   // first paint only
const navBusy        = ref(false)
const loadError      = ref('')

// months -> { status: 'loading' | 'ready' | 'error', payload }
const cache = reactive({})

async function fetchWindow(months) {
  const existing = cache[months]
  if (existing && (existing.status === 'ready' || existing.status === 'loading')) return
  cache[months] = { status: 'loading', payload: null }
  try {
    const params = { months }
    if (focusMonth.value) params.end_month = focusMonth.value
    const { data: res } = await api.get('/api/monthly/trends', { params })
    cache[months] = { status: 'ready', payload: res }
    if (focusMonth.value === null) focusMonth.value = res.focus_month
    isCurrentMonth.value = res.is_current_month
    loadError.value = ''
  } catch {
    cache[months] = { status: 'error', payload: null }
    // Only surface a page-level error if nothing at all has loaded yet --
    // once at least one panel has data, a single failed zoom fetch is that
    // panel's problem to show (see the panel-state text in the template),
    // not a reason to blank the whole page.
    if (!Object.values(cache).some(e => e.status === 'ready')) {
      loadError.value = 'could not load your monthly trends — try reloading.'
    }
  }
}

function ensureLoaded() {
  const wanted = new Set(Object.values(panelWindows))
  return Promise.all([...wanted].map(fetchWindow))
}

function apiErrorMessage(err, fallback) {
  return err?.response?.data?.message || fallback
}

/* ---- share to therapist (2026-08-18) --------------------------------
   "for every diagram... to have a little button in the upper side to
   share it to the therapist in monthly too." Same mechanism as
   Dashboard.vue's weekly panels (see its docblock + docs/therapist-
   bridge.md §1) -- one shared confirm overlay for all six trend panels,
   since they're already rendered from one v-for loop here. scope
   'month' distinguishes these from the weekly shares in the client's
   own /therapist/insights history. */
const myShares        = ref([])
const sharingPanel    = ref(null)   // { key, title, periodLabel, rows } while overlay is open
const sharingSelected = ref([])     // parallel boolean array — which rows the user wants to include
const shareNote       = ref('')
const shareBusy       = ref(false)
const shareError      = ref('')

async function loadShareState() {
  const { data } = await api.get('/api/shared-insights').catch(() => ({ data: [] }))
  myShares.value = data ?? []
}

function isShared(panelKey, periodLabel) {
  return myShares.value.some(s => s.panel_key === panelKey && s.period_label === periodLabel && !s.revoked_at)
}

function openShareConfirm(panel) {
  if (!panel.lines?.length) return
  shareError.value = ''
  shareNote.value = ''
  const rows = panel.lines.map(l => ({ label: l.warmLabel, detail: l.phrase }))
  sharingSelected.value = rows.map(() => true) // all checked by default
  sharingPanel.value = { key: panel.key, title: panel.title, periodLabel: panel.windowLabel, rows }
}
function cancelShare() {
  sharingPanel.value = null
}
async function confirmShare() {
  if (!sharingPanel.value || shareBusy.value) return
  const selectedRows = sharingPanel.value.rows.filter((_, i) => sharingSelected.value[i])
  if (!selectedRows.length) return
  shareBusy.value = true
  shareError.value = ''
  try {
    const { data } = await api.post('/api/shared-insights', {
      panel_key:    sharingPanel.value.key,
      scope:        'month',
      title:        sharingPanel.value.title,
      period_label: sharingPanel.value.periodLabel,
      rows:         selectedRows,
      note:         shareNote.value.trim() || null,
    })
    myShares.value.unshift({
      share_id: data.share_id, panel_key: sharingPanel.value.key,
      period_label: sharingPanel.value.periodLabel, revoked_at: null,
    })
    sharingPanel.value = null
  } catch (e) {
    shareError.value = apiErrorMessage(e, "couldn't share that — try again in a moment.")
  } finally {
    shareBusy.value = false
  }
}

onMounted(() => {
  ensureLoaded().finally(() => { loading.value = false })
  loadShareState()
})

function shiftMonth(key, delta) {
  const [y, m] = key.split('-').map(Number)
  const d = new Date(Date.UTC(y, m - 1 + delta, 1))
  return `${d.getUTCFullYear()}-${String(d.getUTCMonth() + 1).padStart(2, '0')}`
}
async function goPrev() {
  if (navBusy.value || !focusMonth.value) return
  navBusy.value = true
  focusMonth.value = shiftMonth(focusMonth.value, -1)
  Object.keys(cache).forEach(k => delete cache[k])
  await ensureLoaded()
  navBusy.value = false
}
async function goNext() {
  if (navBusy.value || !focusMonth.value || isCurrentMonth.value) return
  navBusy.value = true
  focusMonth.value = shiftMonth(focusMonth.value, 1)
  Object.keys(cache).forEach(k => delete cache[k])
  await ensureLoaded()
  navBusy.value = false
}
function setPanelWindow(panelKey, n) {
  if (navBusy.value || panelWindows[panelKey] === n) return
  panelWindows[panelKey] = n
  fetchWindow(n)
}
const focusMonthLabel = computed(() => {
  if (!focusMonth.value) return ''
  const [y, m] = focusMonth.value.split('-').map(Number)
  return new Date(Date.UTC(y, m - 1, 1))
    .toLocaleDateString('en-US', { month: 'long', year: 'numeric', timeZone: 'UTC' })
})

/* ---- panel configuration -----------------------------------------------
   Same six tagged fields WeeklyReflectionController surfaces, same warm
   dictionaries the weekly view and entry inspector use (shared via
   ../utils/taxonomyDisplay.js). No per-label TONE lookup here (that's
   deliberate, see the CHART PALETTE comment below) -- just which
   dictionary to use for warm phrasing. */
const PANELS = [
  { key: 'emotion_trends',           title: 'emotions',                   words: null },
  { key: 'stressor_trends',          title: "what's weighed on you",      words: null },
  { key: 'protective_factor_trends', title: "what's been helping",        words: PROTECTIVE_WORDS },
  { key: 'behavior_trends',          title: 'behaviors',                  words: BEHAVIOR_WORDS },
  { key: 'need_trends',              title: "what you've needed more of", words: NEED_WORDS },
  { key: 'thinking_pattern_trends',  title: 'thinking patterns',          words: THINKING_PATTERN_WORDS },
]

// Independent zoom per panel (see the load-section comment above) --
// every panel starts at the same DEFAULT_WINDOW, but each can move on
// its own from there.
const panelWindows = reactive(
  Object.fromEntries(PANELS.map(p => [p.key, DEFAULT_WINDOW]))
)

/* ---- chart palette -------------------------------------------------------
   Follow-up fix, same day: the original design colored every line in a
   panel by semantic tone (positive/negative/accent) -- fine for a single
   bar or pie slice, but wrong for a multi-line chart, since e.g. every
   stressor category is 'negative' and every protective factor is
   'positive', so ALL lines in those two panels rendered the exact same
   color and were impossible to tell apart ("the css sucks in what
   you've needed more of and what's been helping and what's weighed on
   you... i want each entrie to have a color"). Switched to a categorical
   palette instead: each line gets a distinct color by its rank position
   in the panel (index 0..4, since TOP_LABELS=5 on the backend), not by
   what it means -- CHART_PALETTE_SIZE is 8 so there's headroom if that
   cap ever grows. The semantic tone dictionaries (EMOTION_TONE etc.)
   still exist and are still used elsewhere (Dashboard.vue, EntryAnalysis.vue)
   where there's exactly one visual element per category; a multi-line
   trend chart is a different visualization problem. */
const CHART_PALETTE_SIZE = 8

/* ---- chart geometry ----------------------------------------------------
   Hand-rolled SVG, matching the rest of this app (no charting library
   anywhere — see Dashboard.vue's mood ring / pie / radar). viewBox is a
   fixed 320x130; evenly-spaced x positions (one per WEEK in the window,
   not per month — see MonthlyDashboardController's docblock for why),
   y maps 0.0-1.0 rate onto a 100px-tall plot area. Null weeks (no
   reliable entries that week, distinct from a 0.0 rate) split the line
   into separate segments rather than interpolating across a gap that
   isn't real data. */
const PLOT_X0 = 10, PLOT_X1 = 310, PLOT_Y0 = 10, PLOT_Y1 = 110
function xFor(i, count) {
  return count <= 1 ? PLOT_X0 : PLOT_X0 + (i * (PLOT_X1 - PLOT_X0)) / (count - 1)
}
function yFor(rate) {
  return PLOT_Y1 - rate * (PLOT_Y1 - PLOT_Y0)
}
function segmentsFor(rates) {
  const segments = []
  let current = []
  rates.forEach((r, i) => {
    if (r === null || r === undefined) {
      if (current.length) segments.push(current)
      current = []
    } else {
      current.push({ i, r })
    }
  })
  if (current.length) segments.push(current)
  return segments
}
function deltaPhrase(direction, delta) {
  if (direction === 'insufficient_data' || delta === null || delta === undefined) {
    return 'not enough entries yet to compare'
  }
  const pts = Math.round(Math.abs(delta) * 100)
  // "last week," not "last month" -- direction/delta now compares the two
  // most recent WEEKS with enough entries (see MonthlyDashboardController's
  // directionFor()), following the same weekly-granularity switch as the
  // chart lines themselves.
  if (direction === 'up')   return `up ${pts} points from last week`
  if (direction === 'down') return `down ${pts} points from last week`
  return 'about the same as last week'
}
const DIRECTION_ARROW = { up: '▲', down: '▼', stable: '–', insufficient_data: '·' }

// Builds one panel's full render state from whichever cache entry its
// OWN zoom level points to -- since zoom is now per-panel, "weeks" (the
// x-axis), labels, and windowLabel all live on the panel now instead of
// the page, matching the fact that two panels can legitimately be
// looking at different-length windows side by side.
function buildPanel(panel) {
  const months = panelWindows[panel.key]
  const entry = cache[months]
  const empty = {
    key: panel.key, title: panel.title, months,
    status: entry?.status ?? 'loading',
    hasEntries: false, isEmpty: false,
    weeks: [], weekLabels: [], visibleWeekLabels: [], windowLabel: '', lines: [],
  }
  if (!entry || entry.status !== 'ready') return empty

  const weeks  = entry.payload.weeks
  const trends = entry.payload[panel.key] ?? []
  const hasEntries = weeks.some(w => w.entry_count > 0)

  // One label per WEEK (see MonthlyDashboardController's docblock for the
  // weekly-granularity switch) -- e.g. "Aug 4", already short since the
  // backend formats it as 'M j', not the full "Mon Aug 4, 2026".
  const weekLabels = weeks.map(w => w.label)
  // A wide window (12mo = up to ~52 weekly buckets) would otherwise crowd
  // every week's label into an unreadable pile along a fixed-width axis --
  // thin them out to roughly 8 evenly-spaced labels, always keeping the
  // last (focus) week visible regardless of the step. Dots/lines still
  // plot every week; this only affects which x-axis text labels render.
  const step = Math.max(1, Math.ceil(weekLabels.length / 8))
  const visibleWeekLabels = weekLabels
    .map((w, i) => ({ w, i }))
    .filter(({ i }) => i % step === 0 || i === weekLabels.length - 1)
  const first = weeks[0]?.label
  const last  = weeks[weeks.length - 1]?.label
  const windowLabel = first === last ? first : `${first} – ${last}`

  const lines = trends.map((t, i) => {
    const segs = segmentsFor(t.rates).map(seg =>
      seg.map(p => `${xFor(p.i, weeks.length)},${yFor(p.r)}`).join(' ')
    )
    const dots = t.rates
      .map((r, idx) => (r === null || r === undefined) ? null : { x: xFor(idx, weeks.length), y: yFor(r) })
      .filter(Boolean)
    return {
      label: t.label,
      warmLabel: panel.words ? warm(panel.words, t.label) : humanize(t.label),
      colorVar: `var(--chart-${(i % CHART_PALETTE_SIZE) + 1})`,
      segs,
      dots,
      lastRate: t.rates[t.rates.length - 1],
      direction: t.direction,
      delta: t.delta,
      phrase: deltaPhrase(t.direction, t.delta),
    }
  })

  return {
    key: panel.key, title: panel.title, months,
    status: 'ready', hasEntries, isEmpty: lines.length === 0,
    weeks, weekLabels, visibleWeekLabels, windowLabel, lines,
  }
}

const panels = computed(() => PANELS.map(buildPanel))
</script>

<template>
  <div class="me" :data-theme="theme">
    <header class="bar">
      <img class="wordmark" src="/logo.png" alt="me" />
      <div class="bar-actions">
        <button class="ghost" @click="router.push({ name: 'dashboard' })">dashboard</button>
        <button class="ghost" @click="router.push({ name: 'journal' })">journal</button>
        <button class="ghost" @click="router.push({ name: 'calendar' })">calendar</button>
        <button class="ghost" @click="router.push({ name: 'memories' })">memories</button>
        <button class="ghost current">monthly</button>
        <button class="ghost" @click="router.push({ name: 'my-therapist' })">therapist</button>
        <button class="ghost" @click="cycleTheme">◐ theme</button>
        <button class="ghost" @click="handleLogout">log out</button>
      </div>
    </header>

    <div class="wrap">
      <div class="head">
        <span class="eyebrow">trends over time</span>
        <h1 class="stamp">monthly</h1>
        <p class="lede">
          how your most-mentioned feelings, stressors, and behaviors have moved week by week across the window below —
          not a single month's snapshot, but which way things are trending.
        </p>
      </div>

      <p v-if="loading" class="state-msg">loading…</p>
      <p v-else-if="loadError" class="state-msg">{{ loadError }}</p>

      <template v-else>
        <div class="month-nav">
          <button class="ghost" :disabled="navBusy" @click="goPrev">← earlier</button>
          <span class="month-range" :class="{ busy: navBusy }">{{ focusMonthLabel }}</span>
          <button class="ghost" :disabled="navBusy || isCurrentMonth" @click="goNext">later →</button>
        </div>

        <div class="trends-grid">
          <div class="panel" v-for="p in panels" :key="p.key">
            <div class="panel-head">
              <span class="panel-title">{{ p.title }}</span>
              <div class="panel-zoom">
                <button
                  v-for="n in WINDOW_OPTIONS" :key="n" class="zoom-btn small"
                  :class="{ on: p.months === n }" :disabled="navBusy"
                  @click="setPanelWindow(p.key, n)"
                >{{ n }}mo</button>
                <button
                  v-if="p.status === 'ready' && p.lines.length"
                  class="panel-share-btn" :class="{ shared: isShared(p.key, p.windowLabel) }"
                  title="share with your therapist"
                  @click="openShareConfirm(p)"
                >{{ isShared(p.key, p.windowLabel) ? '✓ shared' : 'share' }}</button>
              </div>
            </div>

            <p v-if="p.status === 'loading'" class="panel-state">loading…</p>
            <p v-else-if="p.status === 'error'" class="panel-state">couldn't load this window — try a different zoom.</p>
            <p v-else-if="!p.hasEntries" class="panel-state">no entries yet in this {{ p.months }}mo window.</p>
            <p v-else-if="p.isEmpty" class="panel-state">nothing tagged here yet — try a wider zoom.</p>

            <template v-else>
              <svg viewBox="0 0 320 130" class="trend-chart" role="img" :aria-label="p.title + ' trend over ' + p.windowLabel">
                <line :x1="PLOT_X0" :x2="PLOT_X1" :y1="PLOT_Y1" :y2="PLOT_Y1" class="axis-line" />
                <g v-for="line in p.lines" :key="line.label" :style="{ '--tone-color': line.colorVar }">
                  <polyline
                    v-for="(seg, i) in line.segs" :key="i"
                    :points="seg" class="trend-line"
                  />
                  <circle v-for="(d, i) in line.dots" :key="i" :cx="d.x" :cy="d.y" r="2.6" class="trend-dot" />
                </g>
                <text
                  v-for="lbl in p.visibleWeekLabels" :key="lbl.w + lbl.i"
                  :x="10 + (lbl.i * 300) / (p.weekLabels.length - 1 || 1)" y="124"
                  class="axis-label" :class="{ 'axis-label-focus': lbl.i === p.weekLabels.length - 1 }"
                  text-anchor="middle"
                >{{ lbl.w }}</text>
              </svg>

              <ul class="trend-legend">
                <li v-for="line in p.lines" :key="line.label" :style="{ '--tone-color': line.colorVar }">
                  <span class="legend-dot"></span>
                  <span class="legend-label">{{ line.warmLabel }}</span>
                  <span class="legend-delta">
                    <span class="delta-arrow">{{ DIRECTION_ARROW[line.direction] ?? '·' }}</span>
                    {{ line.phrase }}
                  </span>
                </li>
              </ul>
            </template>
          </div>
        </div>

        <!-- share-to-therapist confirm strip — one shared overlay, opened
             by whichever panel's share button was clicked (mirrors
             Dashboard.vue's weekly version). -->
        <transition name="drawer">
          <div v-if="sharingPanel" class="share-overlay" @click.self="cancelShare">
            <div class="share-box">
              <span class="share-title">share "{{ sharingPanel.title }}" with your therapist?</span>
              <span class="share-sub">{{ sharingPanel.periodLabel }} · choose which patterns to include. frozen copy, not a live link — you can unshare anytime.</span>

              <ul class="share-patterns">
                <li v-for="(row, i) in sharingPanel.rows" :key="row.label">
                  <label class="share-pattern-row">
                    <input
                      type="checkbox"
                      class="share-checkbox"
                      :checked="sharingSelected[i]"
                      @change="sharingSelected[i] = $event.target.checked"
                    />
                    <span class="share-pattern-name">{{ row.label }}</span>
                    <span class="share-pattern-detail">{{ row.detail }}</span>
                  </label>
                </li>
              </ul>

              <textarea
                v-model="shareNote" rows="2" maxlength="1000"
                placeholder="add a note for them (optional)"
              ></textarea>
              <p v-if="shareError" class="error">{{ shareError }}</p>
              <div class="share-actions">
                <button class="ghost" @click="cancelShare" :disabled="shareBusy">cancel</button>
                <button
                  class="zoom-btn on" @click="confirmShare"
                  :disabled="shareBusy || !sharingSelected.some(Boolean)"
                >{{ shareBusy ? '…' : 'share' }}</button>
              </div>
            </div>
          </div>
        </transition>
      </template>
    </div>
  </div>
</template>

<style scoped>
/* --chart-1..8: categorical palette for multi-line trend charts (see the
   CHART PALETTE comment in <script setup>). --chart-1/2 deliberately
   reuse this theme's existing accent/ochre so the first two lines in any
   panel still feel connected to the rest of the app; --chart-3..8 are new
   muted hues chosen to stay distinguishable from each other and from
   1/2 while keeping the same quiet, low-saturation register. Night's
   values are lightened/saturated up from the other two themes for
   contrast against a dark background, same adjustment already applied
   to --accent/--ochre/--positive/--negative below. */
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
.ghost:disabled { opacity:.4; cursor:default; }
.ghost:disabled:hover { background:none; color:var(--muted); }

.wrap { max-width:1120px; margin:0 auto; padding:40px 40px 100px; }
.state-msg { color:var(--muted); font-size:15px; text-align:center; padding:80px 0 0; }

.head { margin-bottom:28px; }
.eyebrow { display:block; font-size:12px; letter-spacing:.14em; text-transform:uppercase; color:var(--muted); margin-bottom:6px; }
.stamp { font-family:var(--font-display); font-weight:400; font-size:30px; margin:0 0 10px; }
.lede { font-size:14.5px; color:var(--muted); margin:0; max-width:62ch; line-height:1.6; }

.month-nav {
  display:flex; align-items:center; justify-content:center; gap:16px;
  margin:8px 0 36px;
}
.month-range { font-family:var(--font-display); font-size:16px; min-width:220px; text-align:center; transition:opacity .2s ease; }
.month-range.busy { opacity:.5; }

/* Zoom moved from one page-level row to a small control inside each
   panel's header (2026-08-17, third follow-up: "i want a zoom for every
   case in monthly also") -- .zoom-btn.small is the same button, just
   sized to sit next to a panel-title instead of standing alone. */
.zoom-btn {
  background:var(--surface); border:1px solid var(--line); color:var(--ink);
  font:inherit; font-size:12.5px; padding:5px 12px; border-radius:999px; cursor:pointer;
  transition:all .18s ease;
}
.zoom-btn:hover { border-color:var(--accent); }
.zoom-btn.on { background:var(--accent); border-color:var(--accent); color:var(--surface); }
.zoom-btn:disabled { opacity:.4; cursor:default; }
.zoom-btn.small { font-size:11px; padding:3px 9px; }

.trends-grid { display:grid; grid-template-columns: 1fr 1fr; gap:40px 56px; }
.panel {
  display:flex; flex-direction:column; min-width:0;
  background:var(--surface); border:1px solid var(--line); border-radius:16px; padding:22px;
}
.panel-head {
  display:flex; align-items:center; justify-content:space-between; gap:10px;
  margin-bottom:14px;
}
.panel-title {
  display:block; font-size:12px; letter-spacing:.12em; text-transform:uppercase;
  color:var(--muted);
}
.panel-zoom { display:flex; gap:4px; flex:0 0 auto; align-items:center; }
.panel-state { color:var(--muted); font-size:13px; padding:24px 0; text-align:center; }

/* 2026-08-18: share-to-therapist, same button/overlay as Dashboard.vue's
   weekly panels (see its style block for the parallel comment). */
.panel-share-btn {
  flex:0 0 auto; font-size:11px; padding:3px 10px; border-radius:999px;
  border:1px solid var(--line); background:var(--surface); color:var(--muted);
  cursor:pointer; white-space:nowrap; font-family:var(--font-ui); margin-left:4px;
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
.share-patterns {
  list-style:none; margin:0; padding:8px 12px;
  border:1px solid var(--line); border-radius:10px; background:var(--paper);
  display:flex; flex-direction:column; gap:2px;
  max-height:200px; overflow-y:auto;
}
.share-pattern-row {
  display:flex; align-items:baseline; gap:8px; cursor:pointer;
  padding:5px 4px; border-radius:6px;
}
.share-pattern-row:hover { background:var(--surface); }
.share-checkbox { flex:0 0 auto; accent-color:var(--accent); cursor:pointer; margin-top:1px; }
.share-pattern-name { font-size:13.5px; font-weight:500; flex:0 0 auto; }
.share-pattern-detail { font-size:12px; color:var(--muted); line-height:1.4; }

.share-box textarea {
  font:inherit; font-size:13.5px; padding:8px 10px; border:1px solid var(--line);
  border-radius:8px; background:var(--paper); color:var(--ink); resize:vertical;
}
.share-actions { display:flex; justify-content:flex-end; gap:8px; margin-top:4px; }
.error { font-size:13px; color:var(--ochre); margin:0; }

.drawer-enter-active, .drawer-leave-active { transition:opacity .2s ease, transform .2s ease; }
.drawer-enter-from, .drawer-leave-to { opacity:0; transform:translateY(-6px); }

.trend-chart { width:100%; height:auto; display:block; overflow:visible; }
.axis-line { stroke:var(--line); stroke-width:1; }
.axis-label { fill:var(--muted); font-size:9px; font-family:var(--font-ui); }
.axis-label-focus { fill:var(--ink); font-weight:600; }
.trend-line { fill:none; stroke:var(--tone-color, var(--accent)); stroke-width:2; stroke-linecap:round; stroke-linejoin:round; opacity:.85; }
.trend-dot { fill:var(--tone-color, var(--accent)); }

.trend-legend { list-style:none; margin:14px 0 0; padding:0; display:flex; flex-direction:column; gap:9px; }
.trend-legend li { display:flex; align-items:center; gap:8px; font-size:13px; flex-wrap:wrap; }
.legend-dot { width:9px; height:9px; border-radius:50%; flex:0 0 auto; background:var(--tone-color, var(--accent)); }
.legend-label { flex:0 0 auto; font-weight:500; }
.legend-delta { color:var(--muted); font-size:12px; }
.delta-arrow { color:var(--tone-color, var(--muted)); }

@media (max-width: 860px) {
  .wrap { padding: 32px 20px 80px; }
  .trends-grid { grid-template-columns: 1fr; gap:28px; }
}
</style>
