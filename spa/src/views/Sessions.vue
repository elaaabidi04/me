<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '../lib/api'
import { useAuthStore } from '../stores/auth'

const route  = useRoute()
const router = useRouter()
const auth   = useAuthStore()

const themes = ['dusk', 'paper', 'night']
const theme  = ref('dusk')
function cycleTheme() { theme.value = themes[(themes.indexOf(theme.value) + 1) % themes.length] }

const role = computed(() => auth.user?.role ?? 'client')

// Same query-param handoff as SharedInsights.vue / Tasks.vue -- present
// when reached from one client's row ("sessions →"). Absent when reached
// from the new navbar "calendar" link (2026-08-18, "yes in the navbar
// and add calendar") -- a therapist opening that sees ALL their clients'
// sessions merged into one calendar, not a dead end asking them to pick
// a client first.
const clientId   = computed(() => route.query.client_id ?? null)
const clientName = computed(() => route.query.name ?? 'this client')
const allClientsMode = computed(() => role.value === 'therapist' && !clientId.value)

/* 2026-08-18 -- user: "should we add calendar for it to check when is
   the session and before a day a notification to check the in between
   progress." See TherapySession's docblock: is_soon (within 24h,
   computed server-side) is the honest in-app version of "a day-before
   notification" -- this app has no email/push/SMS delivery anywhere, so
   the nudge only appears once someone actually opens this page or the
   dashboard, same "check when you check" pattern as everything else. */
const sessions  = ref([])
const loading   = ref(true)
const loadError = ref('')

// Populated only in allClientsMode -- the quick-schedule form needs to
// know WHICH client a new session is for when the calendar isn't
// already scoped to one (see TherapySessionController::store()'s
// client_id requirement).
const linkedClients = ref([])
const scheduleClientId = ref('')

async function load() {
  loading.value = true
  loadError.value = ''
  try {
    if (allClientsMode.value) {
      const [sessionsRes, linksRes] = await Promise.all([
        api.get('/api/sessions'),
        api.get('/api/therapist/link'),
      ])
      sessions.value = sessionsRes.data ?? []
      linkedClients.value = linksRes.data ?? []
    } else {
      const url = role.value === 'therapist'
        ? `/api/sessions/client/${clientId.value}`
        : '/api/sessions'
      const { data } = await api.get(url)
      sessions.value = data ?? []
      if (clientId.value) scheduleClientId.value = clientId.value
    }
  } catch (e) {
    loadError.value = e.response?.status === 403
      ? "you don't have access to this."
      : 'could not load sessions — try reloading.'
  } finally {
    loading.value = false
  }
}
onMounted(load)

/* ---- therapist: schedule a new session ---------------------------------- */
// newWhen is built from the calendar (selected day + this time input)
// rather than typed as one raw datetime-local value -- see
// scheduleOnSelectedDay() below.
const newTime    = ref('09:00')
const newNotes   = ref('')
const scheduling = ref(false)
const scheduleError = ref('')

async function scheduleSession(whenValue) {
  if (scheduling.value || !whenValue) return
  if (allClientsMode.value && !scheduleClientId.value) {
    scheduleError.value = 'pick a client first.'
    return
  }
  scheduling.value = true
  scheduleError.value = ''
  try {
    const { data } = await api.post('/api/sessions', {
      client_id:    scheduleClientId.value || clientId.value,
      scheduled_at: whenValue,
      notes:        newNotes.value.trim() || null,
    })
    sessions.value.push(data)
    newNotes.value = ''
  } catch (e) {
    scheduleError.value = e.response?.data?.message || "couldn't schedule that — try again."
  } finally {
    scheduling.value = false
  }
}
function scheduleOnSelectedDay() {
  if (!selectedDate.value) return
  scheduleSession(`${selectedDate.value}T${newTime.value}`)
}

/* ---- therapist: reschedule (inline edit) --------------------------------- */
const editingId  = ref(null)
const editWhen   = ref('')
const editNotes  = ref('')
const saving     = ref(false)

function toLocalInputValue(iso) {
  const d = new Date(iso)
  const pad = n => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`
}
function startEdit(s) {
  editingId.value = s.id
  editWhen.value = toLocalInputValue(s.scheduled_at)
  editNotes.value = s.notes ?? ''
}
function cancelEdit() {
  editingId.value = null
}
async function saveEdit(s) {
  if (saving.value) return
  saving.value = true
  try {
    const { data } = await api.patch(`/api/sessions/${s.id}`, {
      scheduled_at: editWhen.value,
      notes: editNotes.value.trim() || null,
    })
    Object.assign(s, data)
    editingId.value = null
  } catch {
    // leave the edit form open so they can retry
  } finally {
    saving.value = false
  }
}

/* ---- therapist: log a past session (attendance + outcome note) -----------
   Only meaningful for sessions where is_past is true and attendance is not
   yet set. The affordance is inline on the session card; once logged the
   card shows a compact summary instead. */
const loggingId    = ref(null)
const logAttendance = ref('')
const logOutcomeNote = ref('')
const logSaving    = ref(false)
const logError     = ref('')

const ATTENDANCE_OPTS = [
  { value: 'attended',   label: 'attended' },
  { value: 'no_show',    label: 'no-show' },
  { value: 'cancelled',  label: 'cancelled' },
]

function startLog(s) {
  loggingId.value = s.id
  logAttendance.value = ''
  logOutcomeNote.value = ''
  logError.value = ''
}
function cancelLog() {
  loggingId.value = null
}
async function saveLog(s) {
  if (logSaving.value || !logAttendance.value) return
  logSaving.value = true
  logError.value = ''
  try {
    const { data } = await api.patch(`/api/sessions/${s.id}/log`, {
      attendance:   logAttendance.value,
      outcome_note: logOutcomeNote.value.trim() || null,
    })
    Object.assign(s, data)
    loggingId.value = null
  } catch (e) {
    logError.value = e.response?.data?.message || "couldn't save — try again."
  } finally {
    logSaving.value = false
  }
}

function attendanceLabel(val) {
  return ATTENDANCE_OPTS.find(o => o.value === val)?.label ?? val
}

const cancelingId = ref(null)
async function cancelSession(s) {
  if (cancelingId.value) return
  cancelingId.value = s.id
  try {
    await api.delete(`/api/sessions/${s.id}`)
    sessions.value = sessions.value.filter(x => x.id !== s.id)
  } catch {
    // non-critical -- stays in the list, button stays clickable to retry
  } finally {
    cancelingId.value = null
  }
}

function formatWhen(iso) {
  return new Date(iso).toLocaleString([], { weekday: 'short', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })
}
function formatTime(iso) {
  return new Date(iso).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })
}
function hoursUntil(iso) {
  return Math.max(0, Math.round((new Date(iso) - new Date()) / 3_600_000))
}

const upcoming = computed(() => sessions.value.filter(s => !s.is_past).sort((a, b) => new Date(a.scheduled_at) - new Date(b.scheduled_at)))
const soonSession = computed(() => upcoming.value.find(s => s.is_soon) ?? null)

/* ---- month calendar grid ------------------------------------------------
   "yes [add calendar]" -- the flat upcoming/past lists this page had
   before are now a compact 5-item preview under a real month grid,
   which is the primary view. Monday-start, matching this app's
   convention everywhere else a week boundary matters (daysUntilSunday()
   in Dashboard.vue, DailyReview's 24h window, etc.). */
const today = new Date()
const viewYear  = ref(today.getFullYear())
const viewMonth = ref(today.getMonth()) // 0-11
const selectedDate = ref(null) // 'YYYY-MM-DD'

function pad2(n) { return String(n).padStart(2, '0') }
function dateKey(d) { return `${d.getFullYear()}-${pad2(d.getMonth() + 1)}-${pad2(d.getDate())}` }
const todayKey = dateKey(today)

const monthLabel = computed(() =>
  new Date(viewYear.value, viewMonth.value, 1).toLocaleDateString([], { month: 'long', year: 'numeric' })
)

function goPrevMonth() {
  if (viewMonth.value === 0) { viewMonth.value = 11; viewYear.value-- } else { viewMonth.value-- }
}
function goNextMonth() {
  if (viewMonth.value === 11) { viewMonth.value = 0; viewYear.value++ } else { viewMonth.value++ }
}
function goToday() {
  viewYear.value = today.getFullYear()
  viewMonth.value = today.getMonth()
  selectedDate.value = todayKey
}

const sessionsByDate = computed(() => {
  const map = {}
  for (const s of sessions.value) {
    const key = dateKey(new Date(s.scheduled_at))
    ;(map[key] ??= []).push(s)
  }
  for (const key in map) map[key].sort((a, b) => new Date(a.scheduled_at) - new Date(b.scheduled_at))
  return map
})

const WEEKDAY_LABELS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']

const calendarDays = computed(() => {
  const first = new Date(viewYear.value, viewMonth.value, 1)
  const firstWeekday = (first.getDay() + 6) % 7 // 0=mon..6=sun
  const gridStart = new Date(first)
  gridStart.setDate(first.getDate() - firstWeekday)

  const days = []
  for (let i = 0; i < 42; i++) {
    const d = new Date(gridStart)
    d.setDate(gridStart.getDate() + i)
    const key = dateKey(d)
    days.push({
      key,
      day: d.getDate(),
      inMonth: d.getMonth() === viewMonth.value,
      isToday: key === todayKey,
      isPast: d < new Date(today.getFullYear(), today.getMonth(), today.getDate()),
      sessions: sessionsByDate.value[key] ?? [],
    })
  }
  // Trim a trailing row that's entirely next-month padding -- most
  // months only need 5 rows, not a fixed 6.
  if (days.length > 35 && days.slice(-7).every(d => !d.inMonth)) {
    days.splice(-7, 7)
  }
  return days
})

function selectDay(day) {
  selectedDate.value = selectedDate.value === day.key ? null : day.key
}
function jumpToDate(iso) {
  const d = new Date(iso)
  viewYear.value = d.getFullYear()
  viewMonth.value = d.getMonth()
  selectedDate.value = dateKey(d)
}
const selectedDaySessions = computed(() => selectedDate.value ? (sessionsByDate.value[selectedDate.value] ?? []) : [])
function formatSelectedDate(key) {
  const [y, m, d] = key.split('-').map(Number)
  return new Date(y, m - 1, d).toLocaleDateString([], { weekday: 'long', month: 'long', day: 'numeric' })
}
</script>

<template>
  <div class="me" :data-theme="theme">
    <header class="bar">
      <div class="bar-left">
        <button class="ghost" @click="router.push({ name: role === 'therapist' ? 'therapist' : 'my-therapist' })">← back</button>
        <img class="wordmark" src="/logo.png" alt="me" />
      </div>
      <div class="bar-actions">
        <template v-if="role === 'therapist'">
          <button class="ghost" @click="router.push({ name: 'crisis-thread', query: { client_id: clientId } })">chat</button>
          <button class="ghost" @click="router.push({ name: 'session-briefs', query: { client_id: clientId, name: clientName } })">briefs</button>
          <button class="ghost" @click="router.push({ name: 'shared-insights', query: { client_id: clientId, name: clientName } })">insights</button>
          <button class="ghost" @click="router.push({ name: 'tasks', query: { client_id: clientId, name: clientName } })">tasks</button>
          <button class="ghost current">sessions</button>
          <span class="bar-sep"></span>
        </template>
        <button class="ghost" @click="cycleTheme">◐ theme</button>
      </div>
    </header>

    <div class="wrap">
      <div class="head">
        <span class="eyebrow">calendar</span>
        <h1 class="stamp">{{ allClientsMode ? 'all clients' : (role === 'therapist' ? clientName : 'your sessions') }}</h1>
      </div>

      <p v-if="loading" class="state-msg">loading…</p>
      <p v-else-if="loadError" class="state-msg">{{ loadError }}</p>

      <template v-else>
        <!-- in-app "day before" nudge -- see the script docblock for why
             this is what "notification" means here. -->
        <div v-if="soonSession" class="soon-card">
          <span class="soon-title">{{ formatWhen(soonSession.scheduled_at) }} — in about {{ hoursUntil(soonSession.scheduled_at) }}h</span>
          <p v-if="role === 'therapist'" class="soon-hint">
            worth a look before you meet — what's changed since last time.
          </p>
          <p v-else class="soon-hint">your next session is coming up.</p>
          <div class="soon-actions" v-if="role === 'therapist'">
            <button class="ghost" @click="router.push({ name: 'shared-insights', query: { client_id: soonSession.client_id ?? clientId, name: soonSession.client?.name ?? clientName } })">shared insights →</button>
            <button class="ghost" @click="router.push({ name: 'tasks', query: { client_id: soonSession.client_id ?? clientId, name: soonSession.client?.name ?? clientName } })">tasks →</button>
          </div>
          <div class="soon-actions" v-else>
            <button class="ghost" @click="router.push('/')">anything to share before you go? →</button>
          </div>
        </div>

        <!-- ---- month calendar ---- -->
        <section class="card calendar-card">
          <div class="calendar-nav">
            <button class="ghost" @click="goPrevMonth">← prev</button>
            <span class="month-label">{{ monthLabel }}</span>
            <button class="ghost" @click="goNextMonth">next →</button>
            <button class="ghost today-btn" @click="goToday">today</button>
          </div>

          <div class="calendar-grid">
            <span class="weekday-label" v-for="w in WEEKDAY_LABELS" :key="w">{{ w }}</span>
            <button
              v-for="d in calendarDays" :key="d.key"
              class="day-cell"
              :class="{ 'out-month': !d.inMonth, today: d.isToday, past: d.isPast, selected: selectedDate === d.key, 'has-soon': d.sessions.some(s => s.is_soon) }"
              @click="selectDay(d)"
            >
              <span class="day-num">{{ d.day }}</span>
              <span class="day-dots" v-if="d.sessions.length">
                <span v-for="s in d.sessions.slice(0, 3)" :key="s.id" class="day-dot"></span>
              </span>
            </button>
          </div>
        </section>

        <!-- ---- selected day detail ---- -->
        <section v-if="selectedDate" class="card day-detail">
          <span class="card-title">{{ formatSelectedDate(selectedDate) }}</span>

          <p v-if="!selectedDaySessions.length" class="card-hint quiet">nothing scheduled this day.</p>

          <div class="session-list" v-if="selectedDaySessions.length">
            <div class="session-card" v-for="s in selectedDaySessions" :key="s.id" :class="{ soon: s.is_soon }">
              <template v-if="editingId === s.id">
                <div class="edit-form">
                  <input v-model="editWhen" type="datetime-local" />
                  <textarea v-model="editNotes" rows="2" placeholder="notes (optional)" maxlength="2000"></textarea>
                  <div class="edit-actions">
                    <button class="ghost" @click="cancelEdit" :disabled="saving">cancel</button>
                    <button class="ghost primary" @click="saveEdit(s)" :disabled="saving">{{ saving ? '…' : 'save' }}</button>
                  </div>
                </div>
              </template>
              <!-- log form: therapist, past, not yet logged -->
              <template v-else-if="role === 'therapist' && s.is_past && !s.attendance && loggingId === s.id">
                <div class="log-form">
                  <span class="log-prompt">what happened?</span>
                  <div class="attendance-btns">
                    <button
                      v-for="opt in ATTENDANCE_OPTS" :key="opt.value"
                      class="ghost" :class="{ 'att-on': logAttendance === opt.value }"
                      @click="logAttendance = opt.value"
                    >{{ opt.label }}</button>
                  </div>
                  <textarea v-model="logOutcomeNote" rows="2" placeholder="outcome notes (optional)" maxlength="4000"></textarea>
                  <p v-if="logError" class="error">{{ logError }}</p>
                  <div class="log-actions">
                    <button class="ghost" @click="cancelLog" :disabled="logSaving">cancel</button>
                    <button class="ghost primary" :disabled="logSaving || !logAttendance" @click="saveLog(s)">
                      {{ logSaving ? '…' : 'save' }}
                    </button>
                  </div>
                </div>
              </template>

              <template v-else>
                <div class="session-body">
                  <span class="session-when">{{ formatTime(s.scheduled_at) }}</span>
                  <span class="session-meta">
                    <template v-if="role === 'client'">with {{ s.therapist?.name ?? 'your therapist' }}</template>
                    <template v-else-if="allClientsMode">{{ s.client?.name ?? 'a client' }}</template>
                    <span v-if="s.is_soon" class="soon-tag">soon</span>
                    <span v-if="s.attendance" class="att-tag" :class="'att-' + s.attendance">{{ attendanceLabel(s.attendance) }}</span>
                  </span>
                  <p v-if="s.notes" class="session-notes">{{ s.notes }}</p>
                  <p v-if="s.outcome_note" class="session-notes outcome">{{ s.outcome_note }}</p>
                </div>
                <div v-if="role === 'therapist'" class="session-actions">
                  <!-- log affordance: only for past sessions without attendance -->
                  <button v-if="s.is_past && !s.attendance" class="ghost primary" @click="startLog(s)">log session</button>
                  <button v-if="!s.is_past" class="ghost" @click="startEdit(s)">reschedule</button>
                  <button v-if="!s.is_past" class="ghost danger" :disabled="cancelingId === s.id" @click="cancelSession(s)">
                    {{ cancelingId === s.id ? '…' : 'cancel' }}
                  </button>
                </div>
              </template>
            </div>
          </div>

          <!-- therapist: add a session directly on this day -->
          <form v-if="role === 'therapist'" class="quick-schedule" @submit.prevent="scheduleOnSelectedDay">
            <select v-if="allClientsMode" v-model="scheduleClientId" required>
              <option value="" disabled>which client?</option>
              <option v-for="c in linkedClients" :key="c.client_id" :value="c.client_id">{{ c.client?.name }}</option>
            </select>
            <input v-model="newTime" type="time" required />
            <input v-model="newNotes" type="text" placeholder="notes (optional)" maxlength="2000" />
            <button type="submit" class="ghost primary" :disabled="scheduling">{{ scheduling ? '…' : 'add session' }}</button>
          </form>
          <p v-if="scheduleError" class="error">{{ scheduleError }}</p>
        </section>

        <!-- ---- compact upcoming preview ---- -->
        <p class="upcoming-heading muted">coming up</p>
        <p v-if="!upcoming.length" class="state-msg quiet">nothing scheduled yet.</p>
        <div class="session-list" v-else>
          <button
            class="session-card preview" v-for="s in upcoming.slice(0, 5)" :key="s.id"
            :class="{ soon: s.is_soon }" @click="jumpToDate(s.scheduled_at)"
          >
            <div class="session-body">
              <span class="session-when">{{ formatWhen(s.scheduled_at) }}</span>
              <span class="session-meta">
                <template v-if="role === 'client'">with {{ s.therapist?.name ?? 'your therapist' }}</template>
                <template v-else-if="allClientsMode">{{ s.client?.name ?? 'a client' }}</template>
                <span v-if="s.is_soon" class="soon-tag">soon</span>
              </span>
            </div>
          </button>
        </div>
      </template>
    </div>
  </div>
</template>

<style scoped>
.me[data-theme='dusk'] {
  --paper:#F1F2F0; --surface:#FBFBFA; --ink:#26282B; --muted:#6B6F72;
  --accent:#7FA08A; --accent-deep:#57735F; --ochre:#C6A063; --line:#E3E4E1;
}
.me[data-theme='paper'] {
  --paper:#F7F4EE; --surface:#FFFFFF; --ink:#33302A; --muted:#7A736A;
  --accent:#9A8C74; --accent-deep:#6E624E; --ochre:#B98B54; --line:#EAE5DC;
}
.me[data-theme='night'] {
  --paper:#1B1D20; --surface:#232629; --ink:#E9E9E6; --muted:#9BA0A2;
  --accent:#89AE97; --accent-deep:#B6D0BF; --ochre:#D3AE74; --line:#31353A;
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
.bar-left { display:flex; align-items:center; gap:12px; }
.bar-actions { display:flex; align-items:center; gap:6px; }
.bar-sep { width:1px; height:16px; background:var(--line); flex-shrink:0; margin:0 2px; }
.ghost.current { color:var(--ink); background:var(--surface); font-weight:500; cursor:default; }
.ghost.current:hover { color:var(--ink); background:var(--surface); }
.ghost { background:none; border:none; color:var(--muted); font:inherit; font-size:13px;
  padding:6px 10px; border-radius:8px; cursor:pointer; }
.ghost:hover { color:var(--ink); background:var(--surface); }
.ghost:disabled { opacity:.4; cursor:default; }
.ghost.primary { color:var(--accent-deep); font-weight:500; }
.ghost.primary:hover { color:var(--ink); }
.ghost.danger:hover { color:var(--ochre); }

.wrap { max-width:720px; margin:0 auto; padding:40px 40px 60px; }
.state-msg { color:var(--muted); font-size:15px; text-align:center; padding:80px 0 0; }
.state-msg.quiet { padding:20px 0; text-align:left; }

.head { margin-bottom:24px; }
.eyebrow { display:block; font-size:12px; letter-spacing:.14em; text-transform:uppercase; color:var(--muted); margin-bottom:6px; }
.stamp { font-family:var(--font-display); font-weight:400; font-size:26px; margin:0; }

.soon-card {
  background:color-mix(in srgb, var(--accent) 12%, var(--surface));
  border:1px solid var(--accent); border-radius:14px; padding:16px 20px; margin-bottom:22px;
}
.soon-title { display:block; font-family:var(--font-display); font-size:17px; margin-bottom:4px; }
.soon-hint { font-size:13px; color:var(--muted); margin:0 0 10px; }
.soon-actions { display:flex; gap:8px; flex-wrap:wrap; }
.soon-actions .ghost { border:1px solid var(--line); background:var(--surface); }

.card {
  background:var(--surface); border:1px solid var(--line); border-radius:14px;
  padding:20px 22px; margin-bottom:22px;
}
.card-title { display:block; font-size:12px; letter-spacing:.12em; text-transform:uppercase; color:var(--muted); margin-bottom:12px; }
.card-hint { font-size:13px; color:var(--muted); margin:0; }
.card-hint.quiet { margin:0 0 4px; }
.schedule-form, .edit-form { display:flex; flex-direction:column; gap:8px; }
.schedule-form input[type="datetime-local"], .edit-form input[type="datetime-local"] {
  font:inherit; font-size:14px; padding:8px 10px; border:1px solid var(--line);
  border-radius:8px; background:var(--paper); color:var(--ink);
}
.schedule-form textarea, .edit-form textarea {
  font:inherit; font-size:14.5px; padding:9px 12px; border:1px solid var(--line);
  border-radius:8px; background:var(--paper); color:var(--ink); resize:vertical;
}
.schedule-row, .edit-actions { display:flex; justify-content:flex-end; gap:8px; }
.error { color:var(--ochre); font-size:13px; margin:0; }

.session-list { display:flex; flex-direction:column; gap:10px; }
.session-card {
  display:flex; align-items:flex-start; justify-content:space-between; gap:12px;
  background:var(--surface); border:1px solid var(--line); border-radius:12px; padding:14px 16px;
}
.session-card.soon { border-color:var(--accent); }
.session-card.past { opacity:.6; }
.session-card.preview {
  width:100%; text-align:left; font:inherit; cursor:pointer; color:inherit;
}
.session-card.preview:hover { border-color:var(--accent); }

.session-body { flex:1; min-width:0; display:flex; flex-direction:column; gap:3px; }
.session-when { font-size:14.5px; font-weight:500; }
.session-meta { font-size:12px; color:var(--muted); display:flex; align-items:center; gap:8px; }
.soon-tag {
  font-size:10.5px; letter-spacing:.06em; text-transform:uppercase;
  color:var(--accent-deep); border:1px solid var(--accent); border-radius:999px; padding:1px 7px;
}
.session-notes { font-size:13px; color:var(--ink); margin:4px 0 0; line-height:1.5; }
.session-actions { flex:0 0 auto; display:flex; gap:4px; }

.upcoming-heading, .past-heading { font-size:12px; letter-spacing:.1em; text-transform:uppercase; margin:22px 0 10px; }
.muted { color:var(--muted); }

/* month calendar grid -- "yes [add calendar]" */
.calendar-card { padding:18px 20px; }
.calendar-nav { display:flex; align-items:center; gap:6px; margin-bottom:14px; }
.month-label { font-family:var(--font-display); font-size:16px; flex:1; text-align:center; }
.today-btn { margin-left:4px; }

.calendar-grid { display:grid; grid-template-columns:repeat(7, 1fr); gap:4px; }
.weekday-label {
  font-size:10.5px; letter-spacing:.06em; text-transform:uppercase; color:var(--muted);
  text-align:center; padding:4px 0;
}
.day-cell {
  aspect-ratio:1; display:flex; flex-direction:column; align-items:center; justify-content:center;
  gap:3px; border:1px solid transparent; border-radius:10px; background:none; cursor:pointer;
  font:inherit; color:var(--ink); padding:4px;
}
.day-cell:hover { background:var(--paper); }
.day-cell.out-month { color:var(--muted); opacity:.4; }
.day-cell.past { opacity:.55; }
.day-cell.today { border-color:var(--accent); font-weight:600; }
.day-cell.selected { background:var(--accent); color:var(--surface); }
.day-cell.selected .day-dot { background:var(--surface); }
.day-cell.has-soon:not(.selected) { border-color:var(--ochre); }
.day-num { font-size:13px; }
.day-dots { display:flex; gap:2px; }
.day-dot { width:4px; height:4px; border-radius:50%; background:var(--accent); }

.day-detail { border-color:var(--accent); }
/* session log affordance */
.log-form { display:flex; flex-direction:column; gap:8px; width:100%; }
.log-prompt { font-size:12px; letter-spacing:.08em; text-transform:uppercase; color:var(--muted); }
.attendance-btns { display:flex; gap:6px; flex-wrap:wrap; }
.ghost.att-on { color:var(--accent-deep); background:color-mix(in srgb, var(--accent) 12%, var(--surface)); }
.log-form textarea {
  font:inherit; font-size:13.5px; padding:8px 10px; border:1px solid var(--line);
  border-radius:8px; background:var(--paper); color:var(--ink); resize:vertical;
}
.log-actions { display:flex; justify-content:flex-end; gap:8px; }
.att-tag {
  font-size:10.5px; letter-spacing:.06em; text-transform:uppercase;
  border-radius:999px; padding:1px 7px; border:1px solid var(--line);
}
.att-tag.att-attended { color:var(--accent-deep); border-color:var(--accent); }
.att-tag.att-no_show  { color:var(--ochre); border-color:var(--ochre); }
.att-tag.att-cancelled { color:var(--muted); }
.session-notes.outcome { font-style:italic; color:var(--muted); }

.quick-schedule { display:flex; gap:8px; margin-top:14px; flex-wrap:wrap; }
.quick-schedule input[type="time"] {
  font:inherit; font-size:13.5px; padding:7px 10px; border:1px solid var(--line);
  border-radius:8px; background:var(--paper); color:var(--ink);
}
.quick-schedule input[type="text"] {
  flex:1; min-width:140px; font:inherit; font-size:13.5px; padding:7px 10px;
  border:1px solid var(--line); border-radius:8px; background:var(--paper); color:var(--ink);
}
.quick-schedule select {
  font:inherit; font-size:13.5px; padding:7px 10px; border:1px solid var(--line);
  border-radius:8px; background:var(--paper); color:var(--ink);
}

@media (max-width: 560px) {
  .day-num { font-size:11px; }
  .month-label { font-size:14px; }
}

@media (max-width: 640px) {
  .wrap { padding:32px 20px 48px; }
}
</style>
