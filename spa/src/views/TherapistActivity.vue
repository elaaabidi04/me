<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import api from '../lib/api'

const router = useRouter()
const auth   = useAuthStore()

const themes = ['dusk', 'paper', 'night']
const theme  = ref('dusk')
function cycleTheme() { theme.value = themes[(themes.indexOf(theme.value) + 1) % themes.length] }
async function handleLogout() { await auth.logout(); router.push({ name: 'home' }) }

const loading   = ref(true)
const loadError = ref('')
const activity  = ref([])

async function load() {
  loading.value   = true
  loadError.value = ''
  try {
    const { data } = await api.get('/api/therapist/activity')
    activity.value = data ?? []
  } catch {
    loadError.value = 'could not load activity — try reloading.'
  } finally {
    loading.value = false
  }
}
onMounted(load)

// Group items by calendar date for the timeline heading rows.
const grouped = computed(() => {
  const map = {}
  for (const e of activity.value) {
    const key = new Date(e.created_at).toLocaleDateString([], { weekday: 'long', month: 'long', day: 'numeric' })
    if (!map[key]) map[key] = []
    map[key].push(e)
  }
  return Object.entries(map).map(([date, events]) => ({ date, events }))
})

function formatTime(iso) {
  return new Date(iso).toLocaleString([], { hour: 'numeric', minute: '2-digit' })
}
function humanizeAttendance(v) { return v === 'no_show' ? 'no-show' : v }
const ACTIVITY_COPY = {
  task_completed: (e) => `completed "${e.summary}"`,
  insight_shared: (e) => e.summary ? `shared "${e.summary}"` : 'shared an insight',
  brief_shared:   () => 'wrote a session brief',
  session_logged: (e) => `session marked ${humanizeAttendance(e.summary)}`,
}
function activityCopy(e) { return (ACTIVITY_COPY[e.type] ?? (() => 'had activity'))(e) }
function openChat(clientId) { router.push({ name: 'crisis-thread', query: { client_id: clientId } }) }
</script>

<template>
  <div class="me" :data-theme="theme">
    <header class="bar">
      <img class="wordmark" src="/logo.png" alt="me" />
      <div class="bar-actions">
        <button class="ghost" @click="router.push({ name: 'therapist' })">← your practice</button>
        <button class="ghost" @click="handleLogout">log out</button>
      </div>
    </header>

    <div class="wrap">
      <div class="head">
        <span class="eyebrow">therapist bridge</span>
        <h1 class="stamp">recent activity</h1>
        <p class="lede">everything that happened across your caseload in the last 14 days.</p>
      </div>

      <p v-if="loading" class="state-msg">loading…</p>
      <p v-else-if="loadError" class="state-msg">{{ loadError }}</p>
      <p v-else-if="!activity.length" class="state-msg">no activity in the last 14 days.</p>

      <div v-else class="timeline">
        <div v-for="group in grouped" :key="group.date" class="day-group">
          <div class="day-label">{{ group.date }}</div>
          <ul class="feed">
            <li
              v-for="e in group.events"
              :key="`${e.type}-${e.client_id}-${e.created_at}`"
              class="event"
              @click="openChat(e.client_id)"
            >
              <div class="event-dot"></div>
              <div class="event-body">
                <div class="event-time">{{ formatTime(e.created_at) }}</div>
                <div class="event-text">
                  <strong>{{ e.client_name ?? 'a client' }}</strong> {{ activityCopy(e) }}
                </div>
              </div>
            </li>
          </ul>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.me[data-theme='dusk'] {
  --paper:#ECEAE5; --surface:#FCFBF9; --surface-2:#F0EDE8;
  --ink:#2C352F; --ink-soft:#5B635C; --muted:#8E938D;
  --accent:#5F7462; --accent-soft:#E7ECE5; --accent-deep:#3F5342;
  --line:#E2DED7; --line-soft:#EDEAE4;
  --shadow: 0 1px 2px rgba(44,53,47,.04), 0 8px 24px -16px rgba(44,53,47,.16);
}
.me[data-theme='paper'] {
  --paper:#F7F4EE; --surface:#FFFFFF; --surface-2:#F2EFE9;
  --ink:#33302A; --ink-soft:#635D54; --muted:#7A736A;
  --accent:#9A8C74; --accent-soft:#EDE8DE; --accent-deep:#6E624E;
  --line:#EAE5DC; --line-soft:#EEE8DF;
  --shadow: 0 1px 2px rgba(51,48,42,.04), 0 8px 24px -16px rgba(51,48,42,.14);
}
.me[data-theme='night'] {
  --paper:#1B1D20; --surface:#232629; --surface-2:#1E2124;
  --ink:#E9E9E6; --ink-soft:#B8BDB9; --muted:#9BA0A2;
  --accent:#89AE97; --accent-soft:#1C2D22; --accent-deep:#B6D0BF;
  --line:#31353A; --line-soft:#292D32;
  --shadow: 0 1px 2px rgba(0,0,0,.20), 0 8px 24px -16px rgba(0,0,0,.40);
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
  padding:6px 10px; border-radius:8px; cursor:pointer; transition:color .15s, background .15s;
}
.ghost:hover { color:var(--ink); background:var(--surface); }

.wrap { max-width:680px; margin:0 auto; padding:40px 40px 100px; }
.state-msg { color:var(--muted); font-size:15px; text-align:center; padding:80px 0 0; }

.head { margin-bottom:36px; }
.eyebrow {
  display:block; font-size:11px; font-weight:600; letter-spacing:.16em;
  text-transform:uppercase; color:var(--accent); margin-bottom:6px;
}
.stamp { font-family:var(--font-display); font-weight:340; font-size:38px; letter-spacing:-.01em; margin:0 0 8px; }
.lede { font-size:14px; color:var(--muted); margin:0; }

/* ---- timeline ---- */
.timeline { display:flex; flex-direction:column; gap:32px; }

.day-label {
  font-size:11px; font-weight:600; letter-spacing:.12em; text-transform:uppercase;
  color:var(--muted); margin-bottom:14px;
  padding-bottom:10px; border-bottom:1px solid var(--line-soft);
}

.feed { list-style:none; margin:0; padding:0; display:flex; flex-direction:column; }

.event {
  position:relative; display:flex; gap:14px;
  padding:0 0 18px 0; cursor:pointer;
}
.event:last-child { padding-bottom:0; }

.event-dot {
  flex:0 0 auto; width:8px; height:8px; border-radius:50%;
  background:var(--accent); box-shadow:0 0 0 3px var(--accent-soft);
  margin-top:5px;
}
/* vertical connector between dots */
.event::after {
  content:""; position:absolute; left:3.5px; top:16px; bottom:0;
  width:1px; background:var(--line);
}
.event:last-child::after { display:none; }

.event-body { display:flex; flex-direction:column; gap:2px; flex:1; }
.event-time { font-size:11.5px; color:var(--muted); }
.event-text { font-size:14px; color:var(--ink-soft); line-height:1.4; }
.event-text strong { color:var(--ink); font-weight:600; }
.event:hover .event-text { color:var(--ink); }

@media (max-width:640px) {
  .wrap { padding:32px 20px 80px; }
  .bar { padding:16px 20px; }
}
</style>
