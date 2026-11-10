<script setup>
import { ref, computed, watch, onMounted, nextTick } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import api from '../lib/api'

const router = useRouter()
const auth   = useAuthStore()
async function handleLogout() { await auth.logout(); router.push({ name: 'home' }) }

/* ---- theming ------------------------------------------------------ */
const themes = ['dusk', 'paper', 'night']
const theme = ref('dusk')
function cycleTheme() {
  theme.value = themes[(themes.indexOf(theme.value) + 1) % themes.length]
}

/* ---- today anchor ------------------------------------------------- */
// All date comparisons use midnight-local Date objects so they stay stable
// for the lifetime of the page regardless of when it was opened.
const todayMidnight = (() => { const d = new Date(); d.setHours(0,0,0,0); return d })()
const todayStr = toDateStr(todayMidnight)

/* ---- calendar navigation ------------------------------------------ */
const viewYear  = ref(todayMidnight.getFullYear())
const viewMonth = ref(todayMidnight.getMonth()) // 0-indexed

const monthLabel = computed(() =>
  new Date(viewYear.value, viewMonth.value, 1)
    .toLocaleDateString([], { month: 'long', year: 'numeric' })
)

function prevMonth() {
  if (viewMonth.value === 0) { viewYear.value--; viewMonth.value = 11 }
  else viewMonth.value--
}
function nextMonth() {
  if (viewMonth.value === 11) { viewYear.value++; viewMonth.value = 0 }
  else viewMonth.value++
}

/* ---- calendar grid ------------------------------------------------ */
const DOW = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']

const calDays = computed(() => {
  const firstDay = new Date(viewYear.value, viewMonth.value, 1)
  const lastDay  = new Date(viewYear.value, viewMonth.value + 1, 0)
  // Monday-first: remap JS's 0=Sun to 0=Mon
  const leadingBlanks = (firstDay.getDay() + 6) % 7
  const days = []
  for (let i = leadingBlanks - 1; i >= 0; i--) {
    days.push({ date: new Date(viewYear.value, viewMonth.value, -i), inMonth: false })
  }
  for (let d = 1; d <= lastDay.getDate(); d++) {
    days.push({ date: new Date(viewYear.value, viewMonth.value, d), inMonth: true })
  }
  while (days.length % 7 !== 0) {
    const prev = days[days.length - 1].date
    const next = new Date(prev); next.setDate(next.getDate() + 1)
    days.push({ date: next, inMonth: false })
  }
  return days
})

/* ---- day selection ------------------------------------------------ */
const selectedStr = ref(todayStr)

const selectedMidnight = computed(() => {
  const [y, m, d] = selectedStr.value.split('-').map(Number)
  return new Date(y, m - 1, d)
})
const isToday  = computed(() => selectedStr.value === todayStr)
const isPast   = computed(() => selectedMidnight.value < todayMidnight)
const isFuture = computed(() => selectedMidnight.value > todayMidnight)

const dayLabel = computed(() => {
  const label = selectedMidnight.value.toLocaleDateString([], {
    weekday: 'long', month: 'long', day: 'numeric',
  })
  return isToday.value ? `today · ${label}` : label
})

function selectDay(str) {
  // Future dates: nothing to show, don't navigate
  const [y, m, d] = str.split('-').map(Number)
  if (new Date(y, m - 1, d) > todayMidnight) return
  selectedStr.value = str
}

/* ---- dots: which days have entries -------------------------------- */
const datesWithEntries = ref({}) // { 'YYYY-MM-DD': count }

async function loadMonthDots() {
  const month = `${viewYear.value}-${String(viewMonth.value + 1).padStart(2, '0')}`
  try {
    const { data } = await api.get('/api/entries/dates', { params: { month } })
    datesWithEntries.value = { ...datesWithEntries.value, ...data }
  } catch {}
}

/* ---- day thread --------------------------------------------------- */
const threadEl   = ref(null)
const dayEntries = ref([])
const dayLoading = ref(false)

async function loadDayEntries() {
  dayLoading.value = true
  try {
    const { data } = await api.get('/api/entries', { params: { date: selectedStr.value } })
    dayEntries.value = [...data.data].reverse()
  } catch {
    dayEntries.value = []
  } finally {
    dayLoading.value = false
    await nextTick()
    scrollToBottom()
  }
}

async function refreshDayEntries() {
  try {
    const { data } = await api.get('/api/entries', { params: { date: selectedStr.value } })
    const fresh = [...data.data].reverse()
    fresh.forEach(e => {
      const idx = dayEntries.value.findIndex(x => x.id === e.id)
      if (idx >= 0) dayEntries.value[idx] = e
      else dayEntries.value.push(e)
    })
  } catch {}
}

function scrollToBottom() {
  if (threadEl.value) threadEl.value.scrollTop = threadEl.value.scrollHeight
}

function formatTime(iso) {
  return new Date(iso).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
}

/* ---- compose (today only) ----------------------------------------- */
const text         = ref('')
const sending      = ref(false)
const sendError    = ref('')
const pendingCoach = ref(null)
const todayIntentId = ref(null)

const POLL_INTERVAL = 3000
const POLL_DEADLINE = 90_000

async function pollCoach(entryId, startedAt = Date.now()) {
  if (Date.now() - startedAt >= POLL_DEADLINE) {
    if (pendingCoach.value === entryId) pendingCoach.value = null
    return
  }
  await new Promise(r => setTimeout(r, POLL_INTERVAL))
  await refreshDayEntries()
  const entry = dayEntries.value.find(e => e.id === entryId)
  if (entry?.coach_message) {
    if (pendingCoach.value === entryId) pendingCoach.value = null
    await nextTick()
    scrollToBottom()
  } else {
    pollCoach(entryId, startedAt)
  }
}

async function send() {
  const body = text.value.trim()
  if (!body || sending.value || !isToday.value) return
  sending.value   = true
  sendError.value = ''
  try {
    const payload = { body }
    if (todayIntentId.value) payload.daily_intent_id = todayIntentId.value
    const { data } = await api.post('/api/entries', payload)
    dayEntries.value.push(data)
    text.value = ''
    await nextTick()
    scrollToBottom()
    datesWithEntries.value[todayStr] = (datesWithEntries.value[todayStr] ?? 0) + 1
    pendingCoach.value = data.id
    pollCoach(data.id)
  } catch {
    sendError.value = 'could not reach the server.'
  } finally {
    sending.value = false
  }
}

function onKeydown(e) {
  if (e.key === 'Enter' && !e.shiftKey) {
    e.preventDefault()
    send()
  }
}

/* ---- day summary -------------------------------------------------- */
const daySummary = computed(() => {
  const withAnalysis = dayEntries.value.filter(e => e.analysis)
  if (!withAnalysis.length) return null

  const emotionCounts   = {}
  const stressorCounts  = {}
  const protectiveCounts = {}
  const needsSet        = new Set()

  for (const entry of withAnalysis) {
    const a = entry.analysis
    // Fixed 2026-08-12: these were reading a '.label' key that doesn't
    // exist on the raw extraction shape (emotions carry '.emotion',
    // protective_factors carry '.factor') — emotionCounts/protectiveCounts
    // were silently always empty, so daySummary.emotions/.protective never
    // showed anything. Caught while updating 'needs' for its own shape
    // change (flat string -> {need, moment} object, hence '.need' below).
    for (const em of (a.emotions ?? []))
      if (em?.emotion) emotionCounts[em.emotion] = (emotionCounts[em.emotion] ?? 0) + 1
    for (const s of (a.stressors ?? []))
      if (s?.category) stressorCounts[s.category] = (stressorCounts[s.category] ?? 0) + 1
    for (const p of (a.protective_factors ?? []))
      if (p?.factor) protectiveCounts[p.factor] = (protectiveCounts[p.factor] ?? 0) + 1
    for (const n of (a.needs ?? []))
      if (n?.need) needsSet.add(n.need)
  }

  const topOf = (obj, n) =>
    Object.entries(obj).sort((a, b) => b[1] - a[1]).slice(0, n).map(([k]) => k.replace(/_/g, ' '))

  return {
    count:      withAnalysis.length,
    emotions:   topOf(emotionCounts, 3),
    stressors:  topOf(stressorCounts, 2),
    protective: topOf(protectiveCounts, 2),
    needs:      [...needsSet].slice(0, 2).map(n => n.replace(/_/g, ' ')),
  }
})

/* ---- helpers ------------------------------------------------------ */
function toDateStr(d) {
  return `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`
}

/* ---- watchers & init ---------------------------------------------- */
watch([viewYear, viewMonth], loadMonthDots)
watch(selectedStr, loadDayEntries)

onMounted(async () => {
  await loadMonthDots()
  await loadDayEntries()
  api.get('/api/intent').then(r => { todayIntentId.value = r.data?.id ?? null }).catch(() => {})
})
</script>

<template>
  <div class="page" :data-theme="theme">

    <header class="bar">
      <img class="wordmark" src="/logo.png" alt="me" />
      <div class="bar-actions">
        <button class="ghost" @click="router.push({ name: 'dashboard' })">dashboard</button>
        <button class="ghost" @click="router.push({ name: 'journal' })">journal</button>
        <button class="ghost current">calendar</button>
        <button class="ghost" @click="router.push({ name: 'memories' })">memories</button>
        <button class="ghost" @click="router.push({ name: 'monthly' })">monthly</button>
        <button class="ghost" @click="router.push({ name: 'my-therapist' })">therapist</button>
        <button class="ghost" @click="handleLogout">log out</button>
      </div>
    </header>

    <div class="layout">

      <!-- ── Calendar panel ──────────────────────────────────────── -->
      <div class="cal-panel">
        <div class="month-nav">
          <button class="ghost-sm" @click="prevMonth">←</button>
          <span class="month-label">{{ monthLabel }}</span>
          <button class="ghost-sm" @click="nextMonth">→</button>
        </div>

        <div class="cal-grid">
          <!-- day-of-week headers -->
          <span v-for="label in DOW" :key="label" class="dow-label">{{ label }}</span>

          <!-- day cells -->
          <button
            v-for="cell in calDays"
            :key="toDateStr(cell.date)"
            class="day-cell"
            :class="{
              'out':       !cell.inMonth,
              'is-today':  toDateStr(cell.date) === todayStr,
              'selected':  toDateStr(cell.date) === selectedStr,
              'has-dot':   !!datesWithEntries[toDateStr(cell.date)],
              'is-future': cell.date > todayMidnight,
            }"
            :disabled="cell.date > todayMidnight"
            @click="selectDay(toDateStr(cell.date))"
          >
            <span class="day-num">{{ cell.date.getDate() }}</span>
            <span v-if="datesWithEntries[toDateStr(cell.date)]" class="dot" />
          </button>
        </div>
      </div>

      <!-- ── Day panel ────────────────────────────────────────────── -->
      <div class="day-panel">

        <div class="day-header">
          <span class="day-label">{{ dayLabel }}</span>
          <span v-if="isPast" class="read-only-badge">read only</span>
        </div>

        <!-- scrollable thread -->
        <div ref="threadEl" class="thread">
          <div class="thread-inner">

            <p v-if="dayLoading" class="state-msg">loading…</p>

            <template v-else>
              <p v-if="isFuture" class="state-msg muted">not there yet.</p>
              <p v-else-if="!dayEntries.length" class="state-msg muted">
                {{ isToday ? 'nothing yet today. write something below.' : 'nothing written this day.' }}
              </p>

              <div v-for="entry in dayEntries" :key="entry.id" class="entry-block">
                <div class="me-bubble">
                  <p class="bubble-body">{{ entry.body }}</p>
                  <span class="bubble-time">{{ formatTime(entry.created_at) }}</span>
                </div>
                <div v-if="entry.coach_message" class="coach-bubble">
                  <p class="bubble-body">{{ entry.coach_message.body }}</p>
                </div>
                <div v-else-if="pendingCoach === entry.id" class="coach-bubble coach-thinking">
                  <p class="bubble-body">thinking…</p>
                </div>
              </div>
              <!-- day snapshot — shown when at least one entry has analysis -->
              <div v-if="daySummary" class="day-snapshot">
                <div class="snapshot-head">day at a glance</div>
                <div class="snapshot-rows">
                  <div v-if="daySummary.emotions.length" class="snapshot-row">
                    <span class="snap-key">felt</span>
                    <span class="snap-val">{{ daySummary.emotions.join(' · ') }}</span>
                  </div>
                  <div v-if="daySummary.stressors.length" class="snapshot-row">
                    <span class="snap-key">on</span>
                    <span class="snap-val">{{ daySummary.stressors.join(' · ') }}</span>
                  </div>
                  <div v-if="daySummary.protective.length" class="snapshot-row">
                    <span class="snap-key">helped</span>
                    <span class="snap-val">{{ daySummary.protective.join(' · ') }}</span>
                  </div>
                  <div v-if="daySummary.needs.length" class="snapshot-row">
                    <span class="snap-key">needed</span>
                    <span class="snap-val">{{ daySummary.needs.join(' · ') }}</span>
                  </div>
                </div>
              </div>

            </template>

          </div>
        </div>

        <!-- compose bar — only on today -->
        <div v-if="isToday" class="compose">
          <div class="compose-inner">
            <textarea
              v-model="text"
              rows="3"
              placeholder="whatever's here."
              @keydown="onKeydown"
            />
            <div class="compose-foot">
              <span v-if="sendError" class="send-error">{{ sendError }}</span>
              <button
                class="primary"
                :disabled="sending || !text.trim()"
                @click="send"
              >{{ sending ? '…' : 'send' }}</button>
            </div>
          </div>
        </div>

      </div>
    </div>
  </div>
</template>

<style scoped>
/* ── theme tokens ────────────────────────────────────────────────── */
.page[data-theme='dusk'] {
  --paper:#F1F2F0; --surface:#FBFBFA; --ink:#26282B; --muted:#6B6F72;
  --accent:#7FA08A; --accent-deep:#57735F; --ochre:#C6A063; --line:#E3E4E1;
}
.page[data-theme='paper'] {
  --paper:#F7F4EE; --surface:#FFFFFF; --ink:#33302A; --muted:#7A736A;
  --accent:#9A8C74; --accent-deep:#6E624E; --ochre:#B98B54; --line:#EAE5DC;
}
.page[data-theme='night'] {
  --paper:#1B1D20; --surface:#232629; --ink:#E9E9E6; --muted:#9BA0A2;
  --accent:#89AE97; --accent-deep:#B6D0BF; --ochre:#D3AE74; --line:#31353A;
}

.page {
  --font-display:'Fraunces', Georgia, serif;
  --font-ui:'Instrument Sans', system-ui, sans-serif;
  background:var(--paper); color:var(--ink);
  font-family:var(--font-ui); line-height:1.5;
  height:100svh; display:flex; flex-direction:column; overflow:hidden;
  transition:background .4s ease, color .4s ease;
}
* { box-sizing:border-box; }

/* ── shell ─────────────────────────────────────────────────────── */
.bar {
  flex-shrink:0;
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

.layout {
  flex:1; min-height:0;
  display:grid;
  grid-template-columns:300px 1fr;
}

/* ── calendar panel ─────────────────────────────────────────────── */
.cal-panel {
  border-right:1px solid var(--line);
  padding:28px 24px;
  display:flex; flex-direction:column; gap:20px;
  overflow-y:auto;
}

.month-nav {
  display:flex; align-items:center; justify-content:space-between;
}
.month-label {
  font-size:15px; font-weight:500; color:var(--ink);
}
.ghost-sm {
  background:none; border:none; color:var(--muted); font:inherit;
  font-size:16px; padding:4px 8px; border-radius:6px; cursor:pointer;
  line-height:1;
}
.ghost-sm:hover { color:var(--ink); background:var(--surface); }

.cal-grid {
  display:grid; grid-template-columns:repeat(7, 1fr); gap:2px;
}

.dow-label {
  font-size:10px; text-transform:uppercase; letter-spacing:.08em;
  color:var(--muted); text-align:center; padding:4px 0 8px;
}

.day-cell {
  position:relative;
  display:flex; flex-direction:column; align-items:center;
  padding:6px 2px 8px;
  border:none; background:none; border-radius:8px; cursor:pointer;
  transition:background .12s ease;
}
.day-cell:hover:not(:disabled) { background:var(--surface); }
.day-cell:disabled { cursor:default; }
.day-cell.out .day-num { color:var(--line); }
.day-cell.is-future .day-num { color:var(--line); }
.day-cell.is-today .day-num {
  background:var(--accent); color:var(--surface);
  border-radius:50%; width:24px; height:24px;
  display:flex; align-items:center; justify-content:center;
}
.day-cell.selected:not(.is-today) {
  background:var(--surface); outline:1px solid var(--accent);
}
.day-num { font-size:13px; color:var(--ink); line-height:1; }

.dot {
  position:absolute; bottom:3px; left:50%; transform:translateX(-50%);
  width:4px; height:4px; border-radius:50%; background:var(--accent);
}
.day-cell.is-today .dot { background:var(--surface); }
.day-cell.selected:not(.is-today) .dot { background:var(--accent-deep); }

/* ── day panel ───────────────────────────────────────────────────── */
.day-panel {
  display:flex; flex-direction:column; min-height:0;
}

.day-header {
  flex-shrink:0;
  display:flex; align-items:center; gap:10px;
  padding:16px 32px; border-bottom:1px solid var(--line);
}
.day-label { font-size:15px; color:var(--ink); }
.read-only-badge {
  font-size:11px; text-transform:uppercase; letter-spacing:.1em;
  color:var(--muted); border:1px solid var(--line);
  padding:2px 8px; border-radius:999px;
}

.thread { flex:1; min-height:0; overflow-y:auto; }
.thread-inner {
  max-width:640px; margin:0 auto;
  padding:28px 32px 20px;
  display:flex; flex-direction:column; gap:20px;
}

.state-msg { color:var(--muted); font-size:15px; text-align:center; padding:40px 0 0; }
.muted { color:var(--muted); }

/* ── bubbles (same as journal) ──────────────────────────────────── */
.entry-block { display:flex; flex-direction:column; gap:8px; }

.me-bubble {
  align-self:flex-end;
  background:var(--accent); color:var(--surface);
  padding:14px 18px; border-radius:18px 18px 4px 18px; max-width:84%;
}
.coach-bubble {
  align-self:flex-start;
  background:var(--surface); color:var(--ink); border:1px solid var(--line);
  padding:14px 18px; border-radius:18px 18px 18px 4px; max-width:84%;
}
.bubble-body {
  margin:0; font-size:15px; line-height:1.65;
  white-space:pre-wrap; word-break:break-word;
}
.bubble-time {
  display:block; font-size:11px; opacity:.65; margin-top:6px; text-align:right;
}
.coach-thinking { opacity:.55; }
.coach-thinking .bubble-body { font-style:italic; }

/* ── compose ─────────────────────────────────────────────────────── */
.compose {
  flex-shrink:0; border-top:1px solid var(--line); background:var(--paper);
}
.compose-inner {
  max-width:640px; margin:0 auto; padding:14px 32px 20px;
}
.compose textarea {
  width:100%; border:1px solid var(--line); border-radius:12px;
  padding:11px 14px; resize:none; background:var(--surface);
  color:var(--ink); font:inherit; font-size:15px; line-height:1.6; outline:none;
  transition:border-color .15s ease;
}
.compose textarea::placeholder { color:var(--muted); opacity:.7; }
.compose textarea:focus { border-color:var(--accent); }
.compose-foot {
  display:flex; align-items:center; justify-content:flex-end;
  gap:12px; margin-top:8px;
}
.send-error { font-size:13px; color:#b3543f; flex:1; }
.primary {
  background:var(--accent-deep); border:none; color:#fff;
  font:inherit; font-size:14px; padding:9px 22px; border-radius:999px; cursor:pointer;
  transition:filter .15s ease;
}
.primary:hover:not(:disabled) { filter:brightness(1.08); }
.primary:disabled { opacity:.45; cursor:default; }

/* ── day snapshot ────────────────────────────────────────────────── */
.day-snapshot {
  margin-top: 4px;
  padding: 16px 20px;
  background: var(--surface);
  border: 1px solid var(--line);
  border-radius: 14px;
}
.snapshot-head {
  font-size: 11px; text-transform: uppercase; letter-spacing: .1em;
  color: var(--muted); margin-bottom: 10px;
}
.snapshot-rows { display: flex; flex-direction: column; gap: 6px; }
.snapshot-row  { display: flex; gap: 10px; align-items: baseline; }
.snap-key {
  font-size: 11px; color: var(--muted); text-transform: uppercase;
  letter-spacing: .08em; min-width: 54px; flex-shrink: 0;
}
.snap-val { font-size: 14px; color: var(--ink); line-height: 1.5; }

/* ── responsive ─────────────────────────────────────────────────── */
@media (max-width: 720px) {
  .layout { grid-template-columns:1fr; grid-template-rows:auto 1fr; }
  .cal-panel { border-right:none; border-bottom:1px solid var(--line); padding:20px 16px; }
  .day-header { padding:12px 16px; }
  .thread-inner { padding:20px 16px 16px; }
  .compose-inner { padding:12px 16px 16px; }
  .bar { padding:16px 20px; }
}
@media (prefers-reduced-motion: reduce) { .page, * { transition:none !important; } }
</style>
