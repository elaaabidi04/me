<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '../lib/api'
import {
  warm, humanize, BEHAVIOR_WORDS,
  PROTECTIVE_WORDS, NEED_WORDS, THINKING_PATTERN_WORDS,
} from '../utils/taxonomyDisplay'

const route  = useRoute()
const router = useRouter()

const themes = ['dusk', 'paper', 'night']
const theme  = ref('dusk')
function cycleTheme() { theme.value = themes[(themes.indexOf(theme.value) + 1) % themes.length] }

// Requires ?client_id=&name= -- this page is therapist-only and always
// scoped to one specific client. Reached from the "trends →" chip in
// TherapistDashboard.vue's client roster grid.
const clientId   = computed(() => Number(route.query.client_id))
const clientName = computed(() => route.query.name ?? 'this client')

/* ---- data load -----------------------------------------------------------
   Same window/zoom/cache design as MonthlyDashboard.vue, applied here to
   the therapist-facing endpoint. Independent zoom per panel; cache keyed by
   months to share responses across same-zoom panels; wiped on month-nav. */
const WINDOW_OPTIONS = [2, 3, 6, 12]
// 2026-08-19, user: "make the trends show between 2 months by default do
// it to the monthly too" -- matches MonthlyDashboard.vue's same change.
const DEFAULT_WINDOW = 2

const focusMonth     = ref(null)
const isCurrentMonth = ref(true)
const loading        = ref(true)
const navBusy        = ref(false)
const loadError      = ref('')
const accessError    = ref('')

const cache = reactive({})

async function fetchWindow(months) {
  const existing = cache[months]
  if (existing && (existing.status === 'ready' || existing.status === 'loading')) return
  cache[months] = { status: 'loading', payload: null }
  try {
    const params = { months }
    if (focusMonth.value) params.end_month = focusMonth.value
    const { data: res } = await api.get(`/api/monthly/trends/client/${clientId.value}`, { params })
    cache[months] = { status: 'ready', payload: res }
    if (focusMonth.value === null) focusMonth.value = res.focus_month
    isCurrentMonth.value = res.is_current_month
    loadError.value = ''
  } catch (e) {
    if (e.response?.status === 403) {
      accessError.value = e.response?.data?.message ?? 'Live trend sharing is not enabled for this client.'
      cache[months] = { status: 'error', payload: null }
    } else {
      cache[months] = { status: 'error', payload: null }
      if (!Object.values(cache).some(x => x.status === 'ready')) {
        loadError.value = 'could not load trends — try reloading.'
      }
    }
  }
}

function ensureLoaded() {
  const wanted = new Set(Object.values(panelWindows))
  return Promise.all([...wanted].map(fetchWindow))
}

onMounted(() => {
  ensureLoaded().finally(() => { loading.value = false })
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

/* ---- panels -------------------------------------------------------------- */
const PANELS = [
  { key: 'emotion_trends',           title: 'emotions',                   words: null },
  { key: 'stressor_trends',          title: "stressors",                  words: null },
  { key: 'protective_factor_trends', title: "protective factors",         words: PROTECTIVE_WORDS },
  { key: 'behavior_trends',          title: 'behaviors',                  words: BEHAVIOR_WORDS },
  { key: 'need_trends',              title: "expressed needs",            words: NEED_WORDS },
  { key: 'thinking_pattern_trends',  title: 'thinking patterns',          words: THINKING_PATTERN_WORDS },
]

const panelWindows = reactive(
  Object.fromEntries(PANELS.map(p => [p.key, DEFAULT_WINDOW]))
)

const CHART_PALETTE_SIZE = 8
const PLOT_X0 = 10, PLOT_X1 = 310, PLOT_Y0 = 10, PLOT_Y1 = 110
function xFor(i, count) {
  return count <= 1 ? PLOT_X0 : PLOT_X0 + (i * (PLOT_X1 - PLOT_X0)) / (count - 1)
}
function yFor(rate) { return PLOT_Y1 - rate * (PLOT_Y1 - PLOT_Y0) }
function segmentsFor(rates) {
  const segments = []
  let current = []
  rates.forEach((r, i) => {
    if (r === null || r === undefined) {
      if (current.length) segments.push(current)
      current = []
    } else { current.push({ i, r }) }
  })
  if (current.length) segments.push(current)
  return segments
}
function deltaPhrase(direction, delta) {
  if (direction === 'insufficient_data' || delta === null || delta === undefined) return 'not enough data'
  const pts = Math.round(Math.abs(delta) * 100)
  if (direction === 'up')   return `↑ ${pts}pts from last week`
  if (direction === 'down') return `↓ ${pts}pts from last week`
  return '— stable'
}

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

  const weekLabels = weeks.map(w => w.label)
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
      label:     t.label,
      warmLabel: panel.words ? warm(panel.words, t.label) : humanize(t.label),
      colorVar:  `var(--chart-${(i % CHART_PALETTE_SIZE) + 1})`,
      segs, dots,
      lastRate:  t.rates[t.rates.length - 1],
      direction: t.direction,
      phrase:    deltaPhrase(t.direction, t.delta),
    }
  })

  return {
    key: panel.key, title: panel.title, months,
    status: 'ready', hasEntries, isEmpty: lines.length === 0,
    weeks, weekLabels, visibleWeekLabels, windowLabel, lines,
  }
}

const panels = computed(() => PANELS.map(buildPanel))

// Stat strip derived from the default-window cache entry once ready.
const defaultEntry = computed(() => cache[DEFAULT_WINDOW])
const totalWeeksWithData = computed(() => {
  const e = defaultEntry.value
  if (!e || e.status !== 'ready') return null
  return e.payload.weeks.filter(w => w.entry_count > 0).length
})
const windowWeeks = computed(() => {
  const e = defaultEntry.value
  if (!e || e.status !== 'ready') return null
  return e.payload.weeks.length
})
</script>

<template>
  <div class="me" :data-theme="theme">
    <header class="bar">
      <img class="wordmark" src="/logo.png" alt="me" />
      <div class="bar-actions">
        <button class="ghost" @click="router.push({ name: 'therapist' })">← practice</button>
        <button class="ghost" @click="cycleTheme">◐ theme</button>
      </div>
    </header>

    <div class="wrap">
      <div class="head">
        <span class="eyebrow">live trends</span>
        <h1 class="stamp">{{ clientName }}</h1>
        <p class="head-sub">weekly-granularity trend data — live view, client has opted in.</p>
      </div>

      <p v-if="loading" class="state-msg">loading…</p>

      <!-- 403: live_trends_enabled is false or link is gone -->
      <div v-else-if="accessError" class="access-card">
        <span class="access-title">live access not enabled</span>
        <p class="access-body">{{ clientName }} hasn't turned on live trend sharing yet. Once they enable it from their therapist page, you'll see their weekly-granularity trends here.</p>
      </div>

      <p v-else-if="loadError" class="state-msg">{{ loadError }}</p>

      <template v-else>
        <!-- stat strip: journal activity summary -->
        <section v-if="totalWeeksWithData !== null" class="stat-strip">
          <div class="stat">
            <span class="stat-num">{{ totalWeeksWithData }}</span>
            <span class="stat-label">weeks with entries</span>
          </div>
          <div class="stat">
            <span class="stat-num">{{ windowWeeks }}</span>
            <span class="stat-label">weeks in window</span>
          </div>
          <div class="stat">
            <span class="stat-num">{{ focusMonthLabel }}</span>
            <span class="stat-label">end month</span>
          </div>
        </section>

        <div class="month-nav">
          <button class="chip" :disabled="navBusy" @click="goPrev">← earlier</button>
          <span class="month-range" :class="{ busy: navBusy }">{{ focusMonthLabel }}</span>
          <button class="chip" :disabled="navBusy || isCurrentMonth" @click="goNext">later →</button>
        </div>

        <div class="trends-grid">
          <div class="panel" v-for="p in panels" :key="p.key">
            <div class="panel-head">
              <span class="panel-title">{{ p.title }}</span>
              <div class="panel-zoom">
                <button
                  v-for="n in WINDOW_OPTIONS" :key="n"
                  class="zoom-chip" :class="{ on: p.months === n }" :disabled="navBusy"
                  @click="setPanelWindow(p.key, n)"
                >{{ n }}mo</button>
              </div>
            </div>

            <p v-if="p.status === 'loading'" class="panel-state">loading…</p>
            <p v-else-if="p.status === 'error'" class="panel-state">couldn't load this window.</p>
            <p v-else-if="!p.hasEntries" class="panel-state">no entries in this {{ p.months }}mo window.</p>
            <p v-else-if="p.isEmpty" class="panel-state">nothing tagged — try a wider zoom.</p>

            <template v-else>
              <svg viewBox="0 0 320 130" class="trend-chart" role="img" :aria-label="p.title + ' trend'">
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

              <ul class="legend">
                <li v-for="line in p.lines" :key="line.label" :style="{ '--tone-color': line.colorVar }">
                  <span class="legend-dot"></span>
                  <span class="legend-label">{{ line.warmLabel }}</span>
                  <span class="legend-delta">{{ line.phrase }}</span>
                </li>
              </ul>
            </template>
          </div>
        </div>
      </template>
    </div>
  </div>
</template>

<style scoped>
/* Categorical chart palette (same hues as MonthlyDashboard.vue but defined
   independently -- this page redefines them, following the codebase's
   self-contained-views convention). */
.me[data-theme='dusk'] {
  --paper:#F1F2F0; --surface:#FBFBFA; --ink:#26282B; --muted:#6B6F72;
  --accent:#7FA08A; --accent-deep:#57735F; --ochre:#C6A063; --line:#E3E4E1;
  --positive:#7FA08A; --negative:#C6A063;
  --shadow:0 1px 2px rgba(38,40,43,.04), 0 6px 16px -8px rgba(38,40,43,.14);
  --chart-1:#7FA08A; --chart-2:#C6A063; --chart-3:#6E8CA6; --chart-4:#B97159;
  --chart-5:#8C7093; --chart-6:#8A8C5E; --chart-7:#4F8C8C; --chart-8:#A6748C;
}
.me[data-theme='paper'] {
  --paper:#F7F4EE; --surface:#FFFFFF; --ink:#33302A; --muted:#7A736A;
  --accent:#9A8C74; --accent-deep:#6E624E; --ochre:#B98B54; --line:#EAE5DC;
  --positive:#9A8C74; --negative:#B98B54;
  --shadow:0 1px 2px rgba(51,48,42,.04), 0 6px 16px -8px rgba(51,48,42,.14);
  --chart-1:#9A8C74; --chart-2:#B98B54; --chart-3:#6E8CA6; --chart-4:#B97159;
  --chart-5:#8C7093; --chart-6:#8A8C5E; --chart-7:#4F8C8C; --chart-8:#A6748C;
}
.me[data-theme='night'] {
  --paper:#1B1D20; --surface:#232629; --ink:#E9E9E6; --muted:#9BA0A2;
  --accent:#89AE97; --accent-deep:#B6D0BF; --ochre:#D3AE74; --line:#31353A;
  --positive:#89AE97; --negative:#D3AE74;
  --shadow:0 1px 2px rgba(0,0,0,.2), 0 6px 16px -8px rgba(0,0,0,.4);
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
.ghost:disabled { opacity:.4; cursor:default; }

.wrap { max-width:1080px; margin:0 auto; padding:40px 40px 100px; }
.state-msg { color:var(--muted); font-size:15px; text-align:center; padding:80px 0 0; }

.head { margin-bottom:24px; }
.eyebrow { display:block; font-size:12px; letter-spacing:.14em; text-transform:uppercase; color:var(--muted); margin-bottom:6px; }
.stamp { font-family:var(--font-display); font-weight:400; font-size:30px; margin:0 0 6px; }
.head-sub { font-size:13px; color:var(--muted); margin:0; }

/* 403 / access card */
.access-card {
  background:var(--surface); border:1px solid var(--line); border-radius:14px;
  padding:28px 24px; max-width:480px; margin-top:20px;
}
.access-title { display:block; font-family:var(--font-display); font-size:18px; margin-bottom:8px; }
.access-body { font-size:14px; color:var(--muted); margin:0; line-height:1.6; }

/* stat strip — matches TherapistDashboard.vue's pattern */
.stat-strip {
  display:grid; grid-template-columns:repeat(3, 1fr); gap:14px;
  margin-bottom:24px;
}
.stat {
  background:var(--surface); border:1px solid var(--line); border-left:3px solid var(--accent);
  border-radius:14px; box-shadow:var(--shadow);
  padding:16px 18px; display:flex; flex-direction:column; gap:3px;
}
.stat-num { font-family:var(--font-display); font-size:22px; line-height:1.1; color:var(--accent-deep); }
.stat-label { font-size:11.5px; letter-spacing:.06em; text-transform:uppercase; color:var(--muted); }

/* month nav */
.month-nav {
  display:flex; align-items:center; justify-content:center; gap:14px;
  margin:0 0 28px;
}
.month-range {
  font-family:var(--font-display); font-size:15px; min-width:200px;
  text-align:center; transition:opacity .2s ease;
}
.month-range.busy { opacity:.5; }

/* chips — smaller pill buttons, matching TherapistDashboard.vue */
.chip {
  background:var(--paper); border:1px solid var(--line); color:var(--muted);
  font:inherit; font-size:11.5px; padding:4px 10px; border-radius:999px; cursor:pointer;
  transition:border-color .15s ease, color .15s ease;
}
.chip:hover { border-color:var(--accent); color:var(--ink); }
.chip:disabled { opacity:.45; cursor:default; }

/* zoom chips per panel */
.zoom-chip {
  background:var(--surface); border:1px solid var(--line); color:var(--muted);
  font:inherit; font-size:10.5px; padding:2px 8px; border-radius:999px; cursor:pointer;
  transition:all .15s ease;
}
.zoom-chip:hover { border-color:var(--accent); }
.zoom-chip.on { background:var(--accent); border-color:var(--accent); color:var(--surface); }
.zoom-chip:disabled { opacity:.4; cursor:default; }

/* trends grid — same 2-col layout as MonthlyDashboard but using card styling
   consistent with TherapistDashboard.vue */
.trends-grid { display:grid; grid-template-columns:1fr 1fr; gap:20px; }
.panel {
  background:var(--surface); border:1px solid var(--line); border-radius:14px;
  box-shadow:var(--shadow); padding:18px 20px;
  display:flex; flex-direction:column; gap:0;
}
.panel-head {
  display:flex; align-items:center; justify-content:space-between; gap:8px;
  margin-bottom:12px;
}
.panel-title {
  font-size:11.5px; letter-spacing:.1em; text-transform:uppercase; color:var(--muted);
}
.panel-zoom { display:flex; gap:3px; flex:0 0 auto; align-items:center; }
.panel-state { color:var(--muted); font-size:12.5px; padding:20px 0; text-align:center; margin:0; }

/* SVG charts */
.trend-chart { width:100%; height:auto; display:block; overflow:visible; }
.axis-line { stroke:var(--line); stroke-width:1; }
.axis-label { fill:var(--muted); font-size:9px; font-family:var(--font-ui); }
.axis-label-focus { fill:var(--ink); font-weight:600; }
.trend-line { fill:none; stroke:var(--tone-color, var(--accent)); stroke-width:2; stroke-linecap:round; stroke-linejoin:round; opacity:.85; }
.trend-dot { fill:var(--tone-color, var(--accent)); }

/* legend */
.legend { list-style:none; margin:10px 0 0; padding:0; display:flex; flex-direction:column; gap:7px; }
.legend li { display:flex; align-items:center; gap:7px; font-size:12.5px; flex-wrap:wrap; }
.legend-dot { width:8px; height:8px; border-radius:50%; flex:0 0 auto; background:var(--tone-color, var(--accent)); }
.legend-label { flex:0 0 auto; font-weight:500; font-size:12px; }
.legend-delta { color:var(--muted); font-size:11.5px; }

@media (max-width: 860px) {
  .trends-grid { grid-template-columns:1fr; }
  .stat-strip { grid-template-columns:1fr; }
  .wrap { padding:32px 20px 80px; }
}
</style>
