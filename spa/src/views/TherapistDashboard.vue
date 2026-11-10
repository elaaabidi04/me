<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import api from '../lib/api'
import SpotlightTour from '../components/SpotlightTour.vue'

const router = useRouter()
const auth   = useAuthStore()

// 2026-11-07: guided spotlight tour -- auto-starts once for demo accounts,
// replayable for everyone from the "tour" button in the top bar.
const tour = ref(null)
const tourSteps = [
  { title: 'your practice, hands-on.',
    body: 'five fake clients. click anything, nothing breaks.' },
  { target: '[data-tour="stats"]',
    title: 'at a glance',
    body: 'clients, sessions this week, unreviewed flags.' },
  { target: '.filter-chips', advanceOnClick: true,
    title: 'sort your caseload',
    body: 'who needs you, who has a session soon.',
    try: 'tap a filter' },
  { target: '.roster-list .client-row .row-head', advanceOnClick: true,
    title: 'open a file',
    body: 'everything a client chose to share.',
    try: 'tap a client' },
  // only exists if the previous step's row was actually opened
  { target: '.roster-list .client-row.open .row-tabs', wait: 400,
    title: 'all in one place',
    body: 'crisis chat, briefs, insights, tasks, sessions, trends.' },
  { target: '[data-tour="invite"]',
    title: 'invite code',
    body: 'clients link with it. they choose what you see.' },
  { target: '[data-tour="attention"]',
    title: 'needs attention',
    body: 'unreviewed crisis flags. one click to the chat.' },
  { target: '[data-tour="activity"]',
    title: 'what changed',
    body: 'new shares, finished tasks, new flags.' },
  { target: ['[data-tour="tour-btn"]', '.phone-menu-btn'], doneLabel: "let's go",
    title: 'replay anytime',
    body: 'right here.' },
]

const themes = ['dusk', 'paper', 'night']
const theme  = ref('dusk')
function cycleTheme() { theme.value = themes[(themes.indexOf(theme.value) + 1) % themes.length] }

// 2026-08-19: auth.logout() only clears the store's user, it never
// navigates -- clicking "log out" used to leave this page mounted with
// stale data and no redirect to /login. Routing away forces a real
// unmount, so the next login always gets a fresh mount + fresh fetch.
async function handleLogout() {
  await auth.logout()
  router.push({ name: 'home' })
}

/* ---- load --------------------------------------------------------------
   2026-08-18: split off from the old shared Therapist.vue (user: "they
   shouldn't have the same interface... when u log in as a therapist u
   should make the pages more interesting like in cai" -- Citt.ai's
   practice-management framing, discussed earlier this session). Same
   data as before (invite code, linked clients, sessions), laid out as an
   actual practice-management-style dashboard instead of one stacked
   column: a stat strip, a client roster grid, and a sidebar with a
   this-week mini-schedule + the invite code moved to secondary utility.
   See docs/therapist-bridge.md §11. */
const loading    = ref(true)
const loadError  = ref('')

const inviteCode      = ref('')
const regenerating    = ref(false)
const codeCopied      = ref(false)
const clients         = ref([])
const sessions        = ref([])
// 2026-08-19: "what changed since I last looked" feed -- merges tasks/
// shares/briefs/logged sessions across all currently-linked clients into
// one list server-side (TherapistActivityController::recent()), so this
// page doesn't have to fan out N requests to build it.
const activity         = ref([])
const clientSearch     = ref('')

async function load() {
  loading.value   = true
  loadError.value = ''
  try {
    const [codeRes, linksRes, sessionsRes, activityRes] = await Promise.all([
      api.get('/api/therapist/invite-code'),
      api.get('/api/therapist/link'),
      api.get('/api/sessions').catch(() => ({ data: [] })),
      api.get('/api/therapist/activity').catch(() => ({ data: [] })),
    ])
    inviteCode.value = codeRes.data.invite_code
    clients.value    = linksRes.data
    sessions.value   = sessionsRes.data ?? []
    activity.value   = activityRes.data ?? []
  } catch {
    loadError.value = 'could not load this page — try reloading.'
  } finally {
    loading.value = false
  }
}
onMounted(load)

/* ---- stat strip ---------------------------------------------------------
   Three numbers a therapist actually wants at a glance: caseload size,
   how busy this week is, and how much is unreviewed. All derived from
   data already fetched above -- no new endpoints. */
const clientCount = computed(() => clients.value.length)
const unreviewedTotal = computed(() =>
  clients.value.reduce((sum, c) => sum + (c.unreviewed_flags_count || 0), 0)
)
const sessionsThisWeekCount = computed(() => {
  const now = new Date()
  const weekOut = new Date(now.getTime() + 7 * 24 * 60 * 60 * 1000)
  return sessions.value.filter(s => {
    const at = new Date(s.scheduled_at)
    return !s.is_past && at <= weekOut
  }).length
})

const upcomingByClient = computed(() => {
  const map = {}
  for (const s of sessions.value) {
    if (s.is_past) continue
    const key = s.client_id
    if (!map[key] || new Date(s.scheduled_at) < new Date(map[key].scheduled_at)) {
      map[key] = s
    }
  }
  return map
})

// Sidebar mini-schedule: everything upcoming within 7 days, soonest first.
const thisWeekSessions = computed(() => {
  const now = new Date()
  const weekOut = new Date(now.getTime() + 7 * 24 * 60 * 60 * 1000)
  return sessions.value
    .filter(s => !s.is_past && new Date(s.scheduled_at) <= weekOut)
    .sort((a, b) => new Date(a.scheduled_at) - new Date(b.scheduled_at))
})

/* ---- last activity per client -------------------------------------------
   Proxy for "how quiet is this client", derived from the same 14-day/
   30-item activity feed already fetched for the sidebar (§15 follow-up)
   -- not a full history, but enough to tell active from quiet without a
   new endpoint. A client with nothing in that window just reads as no
   recent activity, not an error. */
const lastActivityByClient = computed(() => {
  const map = {}
  for (const e of activity.value) {
    const prev = map[e.client_id]
    if (!prev || new Date(e.created_at) > new Date(prev.created_at)) map[e.client_id] = e
  }
  return map
})

function daysUntil(iso) { return iso ? Math.round((new Date(iso) - new Date()) / 86400000) : null }
function daysSince(iso) { return iso ? Math.round((new Date() - new Date(iso)) / 86400000) : null }

/* ---- dense roster redesign (2026-08-19) ---------------------------------
   User: "make every entite have it's own case... i'dd like u to use
   [a provided mockup -- tiered client list, avatar rows, filter chips,
   expandable action panel, right-rail needs-attention + activity] to
   better distinct the patient." Kept this app's own theme tokens/fonts/
   card language rather than the mockup's literal CSS (same discipline as
   every other mockup-referenced build this session, see §21) -- what's
   reused is the *structure*: three urgency tiers instead of one
   undifferentiated grid, so a caseload of a dozen clients doesn't read as
   a wall of identical cards.

   tier 1 = needs attention (an unreviewed flag, or a session today)
   tier 2 = active & upcoming (a session within 7 days, or activity within
            the last 5 days)
   tier 3 = quiet (neither)
   Same signals sortedClients already used for ordering, just also used
   to group + label, and to power the filter chips below. */
function tierOf(c, nextSession, lastActivity) {
  const nd = nextSession ? daysUntil(nextSession.scheduled_at) : null
  const ld = lastActivity ? daysSince(lastActivity.created_at) : null
  if ((c.unreviewed_flags_count || 0) > 0 || nd === 0) return 1
  if ((nd !== null && nd <= 7) || (ld !== null && ld <= 5)) return 2
  return 3
}
const TIER_LABEL = { 1: 'needs attention', 2: 'active & upcoming', 3: 'quiet' }

const rosterClients = computed(() =>
  clients.value.map(c => {
    const nextSession = upcomingByClient.value[c.client_id] ?? null
    const lastActivity = lastActivityByClient.value[c.client_id] ?? null
    return { ...c, nextSession, lastActivity, tier: tierOf(c, nextSession, lastActivity) }
  })
)

const sortedClients = computed(() => {
  const list = [...rosterClients.value]
  list.sort((a, b) => {
    if (a.tier !== b.tier) return a.tier - b.tier

    const aFlags = a.unreviewed_flags_count || 0
    const bFlags = b.unreviewed_flags_count || 0
    if (aFlags !== bFlags) return bFlags - aFlags

    if (a.nextSession && b.nextSession) {
      const delta = new Date(a.nextSession.scheduled_at) - new Date(b.nextSession.scheduled_at)
      if (delta !== 0) return delta
    } else if (a.nextSession && !b.nextSession) {
      return -1
    } else if (!a.nextSession && b.nextSession) {
      return 1
    }

    return (a.client?.name ?? '').localeCompare(b.client?.name ?? '')
  })
  return list
})

// Filter chips over the same tier/urgency signals -- counts computed off
// the full sorted list (not the search-narrowed one), same "count reflects
// the whole caseload, search just narrows what's visible" the mockup used.
const ROSTER_FILTERS = [
  { id: 'all',    label: 'all',          test: () => true },
  { id: 'review', label: 'needs review', test: c => (c.unreviewed_flags_count || 0) > 0 },
  { id: 'week',   label: 'this week',    test: c => { const nd = c.nextSession ? daysUntil(c.nextSession.scheduled_at) : null; return nd !== null && nd >= 0 && nd <= 7 } },
  { id: 'quiet',  label: 'quiet',        test: c => c.tier === 3 },
]
const activeFilter = ref('all')
const filterCounts = computed(() => {
  const out = {}
  for (const f of ROSTER_FILTERS) out[f.id] = sortedClients.value.filter(f.test).length
  return out
})

const filteredClients = computed(() => {
  const f = ROSTER_FILTERS.find(x => x.id === activeFilter.value) ?? ROSTER_FILTERS[0]
  const q = clientSearch.value.trim().toLowerCase()
  return sortedClients.value.filter(c => f.test(c) && (!q || (c.client?.name ?? '').toLowerCase().includes(q)))
})

// Section headers -- only tiers actually present render one, same
// hide-when-empty rule the sidebar's "needs attention" card already
// followed before this round.
const groupedClients = computed(() => {
  const groups = []
  let current = null
  for (const c of filteredClients.value) {
    if (!current || current.tier !== c.tier) {
      current = { tier: c.tier, label: TIER_LABEL[c.tier], clients: [] }
      groups.push(current)
    }
    current.clients.push(c)
  }
  return groups
})

// Each row expands independently (not an accordion) to reveal the same
// quick-action links the old card grid always showed -- collapsed by
// default is what actually makes a dozen-client list scannable instead
// of a dozen expanded cards' worth of buttons at once.
const openIds = ref(new Set())
function toggleRow(id) {
  const next = new Set(openIds.value)
  if (next.has(id)) next.delete(id); else next.add(id)
  openIds.value = next
}
function relActivity(iso) {
  const d = daysSince(iso)
  if (d === null) return ''
  if (d <= 0) return 'today'
  if (d === 1) return 'yesterday'
  return `${d} days ago`
}

const needsAttention = computed(() =>
  clients.value
    .filter(c => (c.unreviewed_flags_count || 0) > 0)
    .sort((a, b) => (b.unreviewed_flags_count || 0) - (a.unreviewed_flags_count || 0))
)

function formatSessionWhen(iso) {
  return new Date(iso).toLocaleString([], { weekday: 'short', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })
}
function formatSessionShort(iso) {
  return new Date(iso).toLocaleString([], { weekday: 'short', hour: 'numeric', minute: '2-digit' })
}
function formatDate(iso) {
  if (!iso) return ''
  return new Date(iso).toLocaleDateString([], { month: 'short', day: 'numeric', year: 'numeric' })
}
function initials(name) {
  if (!name) return '?'
  const parts = name.trim().split(/\s+/)
  return ((parts[0]?.[0] ?? '') + (parts[1]?.[0] ?? '')).toUpperCase()
}

function formatActivityWhen(iso) {
  return new Date(iso).toLocaleString([], { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })
}
function humanizeAttendance(v) {
  return v === 'no_show' ? 'no-show' : v
}
// Copy per activity type -- the API sends {type, summary}, not prose;
// keeping the wording here means it can change without touching the
// backend, same division of labor as every other display-formatting
// helper in this file.
const ACTIVITY_COPY = {
  task_completed: (e) => `completed "${e.summary}"`,
  insight_shared: (e) => e.summary ? `shared "${e.summary}"` : 'shared an insight',
  brief_shared:   () => 'wrote a session brief',
  session_logged: (e) => `session marked ${humanizeAttendance(e.summary)}`,
}
function activityCopy(e) {
  return (ACTIVITY_COPY[e.type] ?? (() => 'had activity'))(e)
}

/* ---- actions ---- */
async function regenerateCode() {
  if (regenerating.value) return
  regenerating.value = true
  try {
    const { data } = await api.post('/api/therapist/invite-code')
    inviteCode.value = data.invite_code
  } catch {
    loadError.value = "couldn't generate a new code — try again."
  } finally {
    regenerating.value = false
  }
}

async function copyCode() {
  try {
    await navigator.clipboard.writeText(inviteCode.value)
    codeCopied.value = true
    setTimeout(() => { codeCopied.value = false }, 1800)
  } catch {
    // Clipboard permission can be denied — the code is still shown on
    // screen either way, so this isn't a hard failure, just a missed
    // convenience.
  }
}

function openClientChat(client) {
  router.push({ name: 'crisis-thread', query: { client_id: client.client_id } })
}
function openClientInsights(client) {
  router.push({ name: 'shared-insights', query: { client_id: client.client_id, name: client.client?.name ?? '' } })
}
function openClientTasks(client) {
  router.push({ name: 'tasks', query: { client_id: client.client_id, name: client.client?.name ?? '' } })
}
function openClientSessions(client) {
  router.push({ name: 'sessions', query: { client_id: client.client_id, name: client.client?.name ?? '' } })
}
function openClientBriefs(client) {
  router.push({ name: 'session-briefs', query: { client_id: client.client_id, name: client.client?.name ?? '' } })
}
function openClientTrends(client) {
  router.push({ name: 'client-trends', query: { client_id: client.client_id, name: client.client?.name ?? '' } })
}


/* ---- mini calendar ---- */
const calMonth = ref(new Date())
const calDir   = ref(1)  // 1 = forward, -1 = backward
const calKey   = ref(0)
const CAL_DOWS = Array.from({ length: 7 }, (_, i) => {
  const d = new Date(2023, 0, 2 + i) // Jan 2 2023 = Monday
  return d.toLocaleDateString([], { weekday: 'short' })
})
const calMonthLabel = computed(() =>
  calMonth.value.toLocaleString([], { month: 'long', year: 'numeric' })
)
const calDays = computed(() => {
  const year  = calMonth.value.getFullYear()
  const month = calMonth.value.getMonth()
  const first = new Date(year, month, 1)
  const last  = new Date(year, month + 1, 0)
  let startDow = first.getDay()
  startDow = startDow === 0 ? 6 : startDow - 1 // Monday-first
  const days = []
  for (let i = 0; i < startDow; i++) days.push(null)
  for (let d = 1; d <= last.getDate(); d++) days.push(d)
  while (days.length % 7 !== 0) days.push(null)
  return days
})
const sessionDaysInCalMonth = computed(() => {
  const year  = calMonth.value.getFullYear()
  const month = calMonth.value.getMonth()
  const set   = new Set()
  for (const s of sessions.value) {
    const d = new Date(s.scheduled_at)
    if (d.getFullYear() === year && d.getMonth() === month) set.add(d.getDate())
  }
  return set
})
const todayInCal = computed(() => {
  const today = new Date()
  if (today.getFullYear() === calMonth.value.getFullYear() &&
      today.getMonth()    === calMonth.value.getMonth()) return today.getDate()
  return null
})
const calNextSession = computed(() => {
  const year  = calMonth.value.getFullYear()
  const month = calMonth.value.getMonth()
  return sessions.value
    .filter(s => { const d = new Date(s.scheduled_at); return d.getFullYear() === year && d.getMonth() === month && !s.is_past })
    .sort((a, b) => new Date(a.scheduled_at) - new Date(b.scheduled_at))[0] ?? null
})
function calPrev() { calDir.value = -1; const d = new Date(calMonth.value); d.setMonth(d.getMonth() - 1); calMonth.value = d; calKey.value++ }
function calNext() { calDir.value =  1; const d = new Date(calMonth.value); d.setMonth(d.getMonth() + 1); calMonth.value = d; calKey.value++ }
function formatCalFoot(iso) {
  return new Date(iso).toLocaleString([], { weekday: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })
}
</script>

<template>
  <div class="me" :data-theme="theme">
    <header class="bar">
      <img class="wordmark" src="/logo.png" alt="me" />
      <div class="bar-actions">
        <button class="ghost" data-tour="nav-calendar" @click="router.push({ name: 'sessions' })">calendar</button>
        <button class="ghost" @click="router.push({ name: 'therapist-activity' })">activity</button>
        <button class="ghost" data-tour="tour-btn" @click="tour?.start()">tour</button>
        <button class="ghost" @click="handleLogout">log out</button>
      </div>
    </header>

    <div class="wrap">
      <div class="head">
        <div>
          <span class="eyebrow">therapist bridge</span>
          <h1 class="stamp">your practice</h1>
        </div>
      </div>

      <p v-if="loading" class="state-msg">loading…</p>
      <p v-else-if="loadError" class="state-msg">{{ loadError }}</p>

      <template v-else>
        <!-- ---- stat strip ---- -->
        <section class="stat-strip" data-tour="stats" aria-label="Practice summary">
          <div class="stat">
            <span class="stat-num">{{ clientCount }}</span>
            <span class="stat-label">{{ clientCount === 1 ? 'client' : 'clients' }}</span>
          </div>
          <div class="stat">
            <span class="stat-num">{{ sessionsThisWeekCount }}</span>
            <span class="stat-label">sessions this week</span>
          </div>
          <div class="stat" :class="{ 'stat-flag': unreviewedTotal > 0 }">
            <span class="stat-num">{{ unreviewedTotal }}</span>
            <span class="stat-label">unreviewed flags</span>
          </div>
        </section>

        <!-- ---- invite bar ---- -->
        <div class="invite-bar" data-tour="invite">
          <div>
            <span class="invite-bar-label">invite code</span>
            <p class="invite-bar-hint">share this with a client so they can link their account to yours.</p>
          </div>
          <div class="code-row">
            <span class="code">{{ inviteCode }}</span>
            <button class="btn" @click="copyCode">{{ codeCopied ? 'copied ✓' : 'copy' }}</button>
            <button class="btn" :disabled="regenerating" @click="regenerateCode">{{ regenerating ? '…' : 'new code' }}</button>
          </div>
        </div>

        <div class="dash-grid">
          <!-- ---- main: client roster ---- -->
          <section class="roster">
            <div class="roster-head" data-tour="roster-head">
              <span class="section-title">clients</span>
              <div v-if="clients.length" class="filter-chips">
                <button
                  v-for="f in ROSTER_FILTERS" :key="f.id"
                  class="chip" :class="{ on: activeFilter === f.id }"
                  @click="activeFilter = f.id"
                >{{ f.label }}<span class="chip-n">{{ filterCounts[f.id] }}</span></button>
              </div>
            </div>

            <p v-if="!clients.length" class="state-hint">no one has linked to you yet. share your invite code to get started.</p>
            <p v-else-if="!filteredClients.length" class="state-hint">no clients match{{ clientSearch ? ` "${clientSearch}"` : ' this filter' }}.</p>

            <div v-else class="roster-list">
              <div class="roster-search-row">
                <svg class="search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                  <circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
                </svg>
                <input v-model="clientSearch" class="search-input" type="text" placeholder="search clients…" aria-label="Search clients" />
              </div>
              <template v-for="grp in groupedClients" :key="grp.tier">
                <div class="tier-label">{{ grp.label }}</div>
                <div
                  v-for="c in grp.clients" :key="c.id"
                  class="client-row" :class="[`tier-${grp.tier}`, { open: openIds.has(c.id) }]"
                >
                  <button class="row-head" @click="toggleRow(c.id)">
                    <div class="avatar">{{ initials(c.client?.name) }}</div>
                    <div class="row-id">
                      <span class="row-name">{{ c.client?.name }}</span>
                      <span class="row-meta">{{
                        c.lastActivity
                          ? `last active ${relActivity(c.lastActivity.created_at)}`
                          : `linked ${formatDate(c.created_at)}`
                      }}</span>
                    </div>
                    <div class="row-next" :class="{ 'row-next-today': c.nextSession && daysUntil(c.nextSession.scheduled_at) === 0 }">
                      <span class="row-next-label">next session</span>
                      {{ c.nextSession ? formatSessionShort(c.nextSession.scheduled_at) : 'none scheduled' }}
                    </div>
                    <span class="row-flag" :class="{ 'row-flag-none': !c.unreviewed_flags_count }">
                      {{ c.unreviewed_flags_count ? `${c.unreviewed_flags_count} unreviewed` : '' }}
                    </span>
                    <svg class="row-chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="9 6 15 12 9 18"/></svg>
                  </button>

                  <div class="row-panel">
                    <div class="row-panel-in">
                      <p v-if="c.lastActivity" class="row-last">
                        <strong>{{ (c.client?.name ?? '').split(' ')[0] }}</strong> {{ activityCopy(c.lastActivity) }} — {{ relActivity(c.lastActivity.created_at) }}
                      </p>
                      <div class="row-tabs">
                        <button class="tab" @click="openClientChat(c)">conversation</button>
                        <button class="tab" @click="openClientBriefs(c)">session briefs</button>
                        <button class="tab" @click="openClientInsights(c)">insights</button>
                        <button class="tab" @click="openClientTasks(c)">tasks</button>
                        <button class="tab" @click="openClientSessions(c)">sessions</button>
                        <button
                          class="tab" :class="{ 'tab-live': c.live_trends_enabled }"
                          :title="c.live_trends_enabled ? '' : 'client has not enabled live trends'"
                          @click="openClientTrends(c)"
                        >trends{{ c.live_trends_enabled ? ' ●' : '' }}</button>
                      </div>
                    </div>
                  </div>
                </div>
              </template>
            </div>
          </section>

          <!-- ---- sidebar ---- -->
          <aside class="sidebar">
            <section v-if="needsAttention.length" class="card module-alert" data-tour="attention">
              <div class="card-head-row">
                <span class="card-title">needs attention</span>
                <span class="attn-total">{{ needsAttention.length }}</span>
              </div>
              <ul class="attn-list">
                <li v-for="c in needsAttention" :key="c.id">
                  <button class="attn-item" type="button" @click="openClientChat(c)">
                    <span class="attn-avatar">{{ initials(c.client?.name) }}</span>
                    <span class="attn-name">{{ c.client?.name }}</span>
                    <span class="attn-count">{{ c.unreviewed_flags_count }} unreviewed</span>
                  </button>
                </li>
              </ul>
            </section>

            <section class="card activity-card" data-tour="activity">
              <div class="card-head-row">
                <span class="card-title">recent activity</span>
                <button v-if="activity.length" class="see-all-btn" @click="router.push({ name: 'therapist-activity' })">see all →</button>
              </div>
              <p v-if="!activity.length" class="card-hint">nothing new since your caseload last changed.</p>
              <ul v-else class="activity-list">
                <li
                  v-for="e in activity.slice(0, 3)" :key="`${e.type}-${e.client_id}-${e.created_at}`" class="activity-item"
                  @click="openClientChat({ client_id: e.client_id })"
                >
                  <span class="activity-when">{{ formatActivityWhen(e.created_at) }}</span>
                  <span class="activity-text"><strong>{{ e.client_name ?? 'a client' }}</strong> {{ activityCopy(e) }}</span>
                </li>
              </ul>
            </section>

            <div v-if="clients.length" class="cal">
              <div class="cal-head">
                <span class="cal-month">{{ calMonthLabel }}</span>
                <div class="cal-nav">
                  <button class="cal-btn" @click="calPrev" aria-label="Previous month">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
                  </button>
                  <button class="cal-btn" @click="calNext" aria-label="Next month">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
                  </button>
                </div>
              </div>
              <Transition :name="calDir > 0 ? 'cal-next' : 'cal-prev'" mode="out-in">
                <div :key="calKey" class="cal-grid">
                  <span v-for="dow in CAL_DOWS" :key="dow" class="cal-dow">{{ dow }}</span>
                  <template v-for="(day, i) in calDays" :key="i">
                    <button
                      v-if="day !== null && sessionDaysInCalMonth.has(day)"
                      class="cal-day cal-session"
                      :class="{ 'cal-today': day === todayInCal }"
                      @click="router.push({ name: 'sessions' })"
                    >{{ day }}</button>
                    <span
                      v-else
                      class="cal-day"
                      :class="{ 'cal-today': day !== null && day === todayInCal }"
                    >{{ day ?? '' }}</span>
                  </template>
                </div>
              </Transition>
              <div v-if="calNextSession" class="cal-foot" @click="router.push({ name: 'sessions' })">
                <span class="cal-foot-dot"></span>
                <span><b>{{ formatCalFoot(calNextSession.scheduled_at) }}</b><template v-if="calNextSession.client?.name"> · {{ calNextSession.client.name }}</template></span>
              </div>
            </div>

            <section class="card" data-tour="this-week">
              <span class="card-title">this week</span>
              <p v-if="!thisWeekSessions.length" class="card-hint">nothing scheduled in the next 7 days.</p>
              <ul v-else class="week-list">
                <li v-for="s in thisWeekSessions" :key="s.id" class="week-item">
                  <span class="week-when">{{ formatSessionShort(s.scheduled_at) }}</span>
                  <span class="week-who">{{ s.client?.name ?? 'a client' }}</span>
                </li>
              </ul>
            </section>
          </aside>
        </div>
      </template>
    </div>

    <SpotlightTour ref="tour" tour-id="therapist" :steps="tourSteps" :user-id="auth.user?.id" :auto-start="!!auth.user?.is_demo" />
  </div>
</template>

<style scoped>
/* ---- theme tokens ---- */
.me[data-theme='dusk'] {
  --paper:#ECEAE5; --surface:#FCFBF9; --surface-2:#F0EDE8;
  --ink:#2C352F; --ink-soft:#5B635C; --muted:#8E938D;
  --accent:#5F7462; --accent-soft:#E7ECE5; --accent-deep:#3F5342;
  --ochre:#A07230; --line:#E2DED7; --line-soft:#EDEAE4;
  --shadow: 0 1px 2px rgba(44,53,47,.04), 0 8px 24px -16px rgba(44,53,47,.16);
  --shadow-hover: 0 2px 4px rgba(44,53,47,.06), 0 14px 32px -18px rgba(44,53,47,.24);
}
.me[data-theme='paper'] {
  --paper:#F7F4EE; --surface:#FFFFFF; --surface-2:#F2EFE9;
  --ink:#33302A; --ink-soft:#635D54; --muted:#7A736A;
  --accent:#9A8C74; --accent-soft:#EDE8DE; --accent-deep:#6E624E;
  --ochre:#B98B54; --line:#EAE5DC; --line-soft:#EEE8DF;
  --shadow: 0 1px 2px rgba(51,48,42,.04), 0 8px 24px -16px rgba(51,48,42,.14);
  --shadow-hover: 0 2px 4px rgba(51,48,42,.06), 0 14px 32px -18px rgba(51,48,42,.22);
}
.me[data-theme='night'] {
  --paper:#1B1D20; --surface:#232629; --surface-2:#1E2124;
  --ink:#E9E9E6; --ink-soft:#B8BDB9; --muted:#9BA0A2;
  --accent:#89AE97; --accent-soft:#1C2D22; --accent-deep:#B6D0BF;
  --ochre:#D3AE74; --line:#31353A; --line-soft:#292D32;
  --shadow: 0 1px 2px rgba(0,0,0,.20), 0 8px 24px -16px rgba(0,0,0,.40);
  --shadow-hover: 0 2px 4px rgba(0,0,0,.28), 0 14px 32px -18px rgba(0,0,0,.52);
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

/* ---- nav bar ---- */
.bar {
  display:flex; align-items:center; justify-content:space-between;
  padding:18px 40px; border-bottom:1px solid var(--line);
}
.wordmark { height: 80px; width: auto; display: block; }
.bar-actions { display:flex; gap:6px; }
.ghost {
  background:none; border:none; color:var(--muted); font:inherit; font-size:13px;
  padding:6px 10px; border-radius:8px; cursor:pointer;
  transition:color .15s, background .15s;
}
.ghost:hover { color:var(--ink); background:var(--surface); }

/* ---- page header ---- */
.wrap { max-width:1160px; margin:0 auto; padding:40px 40px 100px; }
.state-msg { color:var(--muted); font-size:15px; text-align:center; padding:80px 0 0; }
.head {
  display:flex; align-items:flex-end; justify-content:space-between;
  gap:20px; flex-wrap:wrap; margin-bottom:28px;
}
/* calendar + activity side by side */
.eyebrow {
  display:block; font-size:11px; font-weight:600; letter-spacing:.16em;
  text-transform:uppercase; color:var(--accent); margin-bottom:6px;
}
.stamp { font-family:var(--font-display); font-weight:340; font-size:42px; letter-spacing:-.01em; margin:0; }

/* ---- stat strip ---- */
.stat-strip {
  display:grid; grid-template-columns:repeat(3, 1fr); gap:16px;
  margin-bottom:20px;
}
.stat {
  background:var(--surface); border:1px solid var(--line); border-radius:14px;
  box-shadow:var(--shadow); padding:20px 22px 18px;
  display:flex; flex-direction:column; gap:6px;
  transition:box-shadow .18s, transform .18s, border-color .18s;
}
.stat:hover { box-shadow:var(--shadow-hover); transform:translateY(-2px); border-color:var(--line-soft); }
.stat-num {
  font-family:var(--font-display); font-weight:340; font-size:38px; line-height:1;
  color:var(--ink);
}
.stat-label {
  font-size:11px; font-weight:600; letter-spacing:.11em;
  text-transform:uppercase; color:var(--muted);
}
.stat.stat-flag .stat-num { color:var(--accent); }

/* ---- invite bar ---- */
.invite-bar {
  display:flex; align-items:center; justify-content:space-between;
  gap:20px; flex-wrap:wrap;
  background:var(--surface); border:1px solid var(--line); border-radius:14px;
  padding:16px 20px; box-shadow:var(--shadow); margin-bottom:24px;
}
.invite-bar-label {
  display:block; font-size:11px; font-weight:600; letter-spacing:.14em;
  text-transform:uppercase; color:var(--muted); margin-bottom:4px;
}
.invite-bar-hint { font-size:13px; color:var(--ink-soft); margin:0; max-width:46ch; }
.code-row { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
.code {
  font-family:monospace; font-size:14px; font-weight:700; letter-spacing:.14em;
  color:var(--accent); background:var(--accent-soft);
  border-radius:10px; padding:8px 16px; text-align:center; min-width:120px;
}
.btn {
  font:inherit; font-size:12.5px; font-weight:500; color:var(--ink-soft);
  background:var(--surface); border:1px solid var(--line); border-radius:10px;
  padding:8px 12px; cursor:pointer;
  transition:background .15s, border-color .15s, color .15s;
}
.btn:hover { background:var(--surface-2); border-color:var(--muted); color:var(--ink); }
.btn:disabled { opacity:.45; cursor:default; }
.btn:disabled:hover { background:var(--surface); border-color:var(--line); color:var(--ink-soft); }

/* ---- two-column layout ---- */
.dash-grid { display:grid; grid-template-columns:minmax(0,1fr) 340px; gap:24px; align-items:start; }

/* ---- roster ---- */
.roster-head { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:16px; flex-wrap:wrap; }
.section-title {
  font-size:11px; font-weight:600; letter-spacing:.14em;
  text-transform:uppercase; color:var(--muted);
}
.state-hint { font-size:13.5px; color:var(--muted); line-height:1.6; }

/* ---- filter chips ---- */
.filter-chips { display:flex; flex-wrap:wrap; gap:7px; }
.chip {
  font:inherit; font-size:12.5px; font-weight:500; cursor:pointer;
  padding:6px 12px; border-radius:999px; border:1px solid var(--line);
  background:var(--surface); color:var(--ink-soft);
  transition:background .15s, border-color .15s, color .15s;
}
.chip:hover { border-color:var(--accent); color:var(--ink); }
.chip.on { background:var(--accent-deep); border-color:var(--accent-deep); color:#fff; }
.chip-n { opacity:.7; margin-left:5px; font-variant-numeric:tabular-nums; }

/* search (below cal+activity row) */
.search-wrap { position:relative; width:220px; max-width:100%; }
.search-icon {
  position:absolute; left:11px; top:50%; transform:translateY(-50%);
  width:14px; height:14px; color:var(--muted); pointer-events:none;
}
.search-input {
  font:inherit; font-size:13px; padding:7px 12px 7px 32px;
  border:1px solid var(--line); border-radius:999px;
  background:var(--surface); color:var(--ink); width:100%;
  transition:border-color .15s, box-shadow .15s;
}
.search-input::placeholder { color:var(--muted); }
.search-input:focus {
  outline:none; border-color:var(--accent);
  box-shadow:0 0 0 3px var(--accent-soft);
}

.avatar {
  flex:0 0 auto; width:38px; height:38px; border-radius:50%;
  background:var(--accent-soft); color:var(--accent);
  display:grid; place-items:center;
  font-size:12.5px; font-weight:600; letter-spacing:.02em;
}

/* ---- tiered roster list ----------------------------------------------
   2026-08-19: replaces the auto-fill card grid. User asked to give each
   client "their own case" and "better distinct the patient" -- rows
   grouped into three urgency tiers (needs attention / active & upcoming
   / quiet), collapsed by default, each with a colored left border per
   tier so the grouping is visible even without reading the section
   label. Structure borrowed from a mockup the user provided; the theme
   tokens, fonts, and shadow language are this app's own, not copied
   verbatim (same discipline as §21's diagram-mockup adaptation). */
.roster-search-row {
  position:relative; margin-bottom:4px;
}
.roster-search-row .search-input { width:100%; }
.tier-label {
  font-size:11px; font-weight:600; letter-spacing:.13em; text-transform:uppercase;
  color:var(--muted); margin:22px 2px 8px;
}
.tier-label:first-of-type { margin-top:4px; }
.roster-list { display:flex; flex-direction:column; gap:6px; }

.client-row {
  background:var(--surface); border:1px solid var(--line); border-left-width:3px;
  border-radius:12px; overflow:hidden;
  transition:box-shadow .15s, border-color .15s;
}
.client-row:hover { box-shadow:var(--shadow-hover); }
.client-row.tier-1 { border-left-color:var(--ochre); }
.client-row.tier-2 { border-left-color:var(--accent); }
.client-row.tier-3 { border-left-color:var(--line); }

.row-head {
  width:100%; display:flex; align-items:center; gap:13px;
  padding:11px 14px; border:none; background:none; cursor:pointer;
  font:inherit; text-align:left;
}
.row-id { display:flex; flex-direction:column; min-width:0; flex:1 1 auto; }
.row-name { font-weight:600; font-size:14.5px; color:var(--ink); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.row-meta { font-size:12px; color:var(--muted); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.row-next {
  font-size:12.5px; color:var(--ink-soft); white-space:nowrap; text-align:right; min-width:110px; flex:0 0 auto;
}
.row-next.row-next-today { color:var(--ochre); font-weight:600; }
.row-next-label {
  display:block; font-size:10px; letter-spacing:.07em; text-transform:uppercase;
  color:var(--muted); font-weight:600;
}
.row-next.row-next-today .row-next-label { color:var(--ochre); }
.row-flag {
  flex:0 0 auto; font-size:11.5px; font-weight:600; color:var(--ochre);
  background:var(--accent-soft); border:1px solid var(--line-soft); border-radius:999px;
  padding:3px 10px; min-width:0;
}
.row-flag:empty, .row-flag.row-flag-none { visibility:hidden; padding:3px 0; border:none; background:none; }
.row-chev { flex:0 0 auto; width:15px; height:15px; color:var(--muted); transition:transform .18s; }
.client-row.open .row-chev { transform:rotate(90deg); }

/* expanded action panel */
.row-panel { max-height:0; overflow:hidden; transition:max-height .22s ease; }
.client-row.open .row-panel { max-height:180px; }
.row-panel-in { padding:0 14px 14px 65px; border-top:1px solid var(--line-soft); margin-top:2px; padding-top:12px; }
.row-last { font-size:12.5px; color:var(--ink-soft); margin:0 0 10px; }
.row-last strong { color:var(--ink); font-weight:600; }
.row-tabs { display:flex; flex-wrap:wrap; gap:7px; }
.tab {
  font:inherit; font-size:12.5px; font-weight:500; color:var(--accent-deep);
  padding:6px 12px; border:1px solid var(--line-soft); border-radius:8px;
  background:var(--surface-2); cursor:pointer; transition:background .15s, color .15s;
}
.tab:hover { background:var(--accent-soft); }
.tab.tab-live { color:var(--accent); border-color:var(--accent-soft); }

/* ---- sidebar ---- */
.sidebar { display:flex; flex-direction:column; gap:16px; }
.card {
  background:var(--surface); border:1px solid var(--line); border-radius:14px;
  box-shadow:var(--shadow); padding:20px;
  transition:box-shadow .18s, transform .18s;
}
.card:hover { box-shadow:var(--shadow-hover); transform:translateY(-1px); }
.card.module-alert { border-left:3px solid var(--ochre); }
.card-head-row {
  display:flex; align-items:center; justify-content:space-between; margin-bottom:12px;
}
.card-title {
  display:block; font-size:11px; font-weight:600; letter-spacing:.14em;
  text-transform:uppercase; color:var(--muted); margin-bottom:0;
}
.see-all-btn {
  font:inherit; font-size:12px; color:var(--accent); background:none; border:none;
  cursor:pointer; padding:0; transition:color .15s;
}
.see-all-btn:hover { color:var(--accent-deep); }
.card-hint { font-size:13px; color:var(--muted); margin:0; line-height:1.6; }

/* activity feed — timeline (2026-11-07: sidebar card, last 3 only) */
.activity-list { list-style:none; margin:0; padding:0; display:flex; flex-direction:column; }
.activity-item {
  position:relative; padding:0 0 14px 22px; cursor:pointer;
}
.activity-item:last-child { padding-bottom:0; }
.activity-item::before {
  content:""; position:absolute; left:3px; top:5px;
  width:7px; height:7px; border-radius:50%;
  background:var(--accent); box-shadow:0 0 0 3px var(--accent-soft);
}
.activity-item::after {
  content:""; position:absolute; left:6px; top:14px; bottom:2px;
  width:1px; background:var(--line);
}
.activity-item:last-child::after { display:none; }
.activity-when { display:block; font-size:11px; color:var(--muted); margin-bottom:2px; }
.activity-text {
  display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden;
  font-size:13px; color:var(--ink-soft); line-height:1.45;
}
.activity-text strong { color:var(--ink); font-weight:600; }
.activity-item:hover .activity-text { color:var(--ink); }

/* this week */
.week-list { list-style:none; margin:6px 0 0; padding:0; display:flex; flex-direction:column; }
.week-item {
  display:flex; align-items:center; justify-content:space-between; gap:8px;
  font-size:13.5px; padding:8px 0; border-bottom:1px solid var(--line-soft);
}
.week-item:last-child { border-bottom:none; padding-bottom:0; }
.week-when { color:var(--ink); font-weight:600; flex:0 0 auto; }
.week-who { color:var(--muted); text-align:right; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }

/* needs attention (2026-11-07: same avatar + filled badge language as the client rows) */
.card.module-alert { padding:18px 14px 12px; }
.card.module-alert .card-head-row { padding:0 6px; margin-bottom:8px; }
.attn-total {
  font-size:11px; font-weight:700; color:var(--ochre);
  min-width:20px; height:20px; padding:0 6px; border-radius:999px;
  display:grid; place-items:center; background:color-mix(in srgb, var(--ochre) 14%, transparent);
}
.attn-list { list-style:none; margin:0; padding:0; display:flex; flex-direction:column; gap:2px; }
.attn-item {
  width:100%; display:flex; align-items:center; gap:10px;
  font:inherit; font-size:13.5px; text-align:left; color:inherit;
  padding:8px 6px; border:none; background:none; border-radius:10px; cursor:pointer;
  transition:background .15s;
}
.attn-item:hover { background:var(--surface-2); }
.attn-item:focus-visible { outline:2px solid var(--accent); outline-offset:1px; }
.attn-avatar {
  flex:0 0 auto; width:28px; height:28px; border-radius:50%;
  background:var(--accent-soft); color:var(--accent);
  display:grid; place-items:center; font-size:11px; font-weight:600;
}
.attn-name { flex:1; min-width:0; font-weight:600; color:var(--ink); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.attn-count {
  flex:0 0 auto; font-size:11px; font-weight:600; color:var(--ochre);
  background:var(--accent-soft); border:1px solid var(--line-soft); border-radius:999px; padding:2px 9px;
}

/* ---- calendar ----
   2026-08-19: used to be one 260px cell inside the old card grid; now
   that the roster below it is a full-width list, it's a standalone card
   of its own, capped so the day cells don't stretch into oversized
   squares. */
.cal {
  background:var(--surface); border:1px solid var(--line); border-radius:14px;
  box-shadow:var(--shadow); padding:18px;
  display:flex; flex-direction:column;
}
.cal-head {
  display:flex; align-items:center; justify-content:space-between; margin-bottom:14px;
}
.cal-month { font-size:13.5px; font-weight:600; color:var(--ink); text-transform:capitalize; }
.cal-nav { display:flex; gap:6px; }
.cal-btn {
  width:26px; height:26px; display:grid; place-items:center;
  border:1px solid var(--line); background:var(--surface);
  border-radius:8px; cursor:pointer; color:var(--ink-soft);
  transition:background .15s, color .15s, border-color .15s;
}
.cal-btn:hover { background:var(--surface-2); color:var(--ink); }
.cal-btn svg { width:14px; height:14px; }
.cal-grid { display:grid; grid-template-columns:repeat(7,1fr); gap:2px; }
.cal-dow {
  font-size:10px; font-weight:600; letter-spacing:.03em;
  text-transform:uppercase; color:var(--muted);
  text-align:center; padding-bottom:6px;
}
.cal-day {
  height:32px; display:grid; place-items:center;
  font-size:12.5px; color:var(--ink-soft); border-radius:8px;
}
.cal-day.cal-today {
  box-shadow:inset 0 0 0 1.5px var(--accent);
  color:var(--ink); font-weight:600;
}
.cal-day.cal-session {
  background:var(--accent); color:#fff; font-weight:600;
  cursor:pointer; border:none; font-family:inherit; font-size:12.5px;
  transition:background .15s;
}
.cal-day.cal-session:hover { background:var(--accent-deep); }
.cal-day.cal-today.cal-session { background:var(--accent-deep); box-shadow:none; }
.cal-foot {
  margin-top:14px; padding-top:14px; border-top:1px solid var(--line-soft);
  display:flex; align-items:center; gap:8px;
  font-size:12.5px; color:var(--ink-soft);
  cursor:pointer; border-radius:8px; transition:color .15s;
}
.cal-foot:hover { color:var(--ink); }
.cal-foot-dot { width:6px; height:6px; border-radius:50%; background:var(--accent); flex-shrink:0; }
.cal-foot b { color:var(--ink); font-weight:600; }

/* ---- calendar slide animation ---- */
.cal-next-enter-active,
.cal-prev-enter-active  { transition: opacity .18s ease, transform .18s ease; }
.cal-next-leave-active,
.cal-prev-leave-active  { transition: opacity .12s ease; }
.cal-next-enter-from    { opacity: 0; transform: translateX(14px); }
.cal-next-leave-to      { opacity: 0; }
.cal-prev-enter-from    { opacity: 0; transform: translateX(-14px); }
.cal-prev-leave-to      { opacity: 0; }

/* ---- responsive ---- */
@media (max-width:900px) {
  /* minmax(0,1fr), not 1fr: a plain 1fr track grows to its widest row
     (the client rows / filter chips) and pushed the page past the screen */
  .dash-grid { grid-template-columns:minmax(0,1fr); }
  /* sidebar drops under the roster: two cards per row instead of four full-width slabs */
  .sidebar { display:grid; grid-template-columns:repeat(2, minmax(0,1fr)); align-items:start; }
}
@media (max-width:640px) {
  .wrap { padding:32px 20px 80px; }
  .bar { padding:16px 20px; }
  .stamp { font-size:34px; }
  .head { flex-direction:column; align-items:flex-start; }
  .head-search { width:100%; }
  .stat-strip { grid-template-columns:1fr; gap:10px; }
  .invite-bar { flex-direction:column; align-items:flex-start; }
  .sidebar { grid-template-columns:1fr; }
  /* client rows on phones (2026-11-07): drop the "next session" label and
     let the date column shrink so name + date + flag + chevron fit */
  .row-head { gap:10px; padding:10px 12px; }
  .row-next { min-width:0; font-size:12px; }
  .row-next-label { display:none; }
  .row-flag { font-size:11px; padding:2px 8px; }
  .row-panel-in { padding:12px 12px 12px; }
}

@media (prefers-reduced-motion:reduce) {
  * { transition:none !important; }
}
</style>
