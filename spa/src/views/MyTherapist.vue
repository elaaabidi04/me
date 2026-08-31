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

// 2026-08-19: auth.logout() only clears the store's user, it never
// navigates -- clicking "log out" used to leave this page mounted with
// stale data and no redirect to /login. Routing away forces a real
// unmount, so the next login always gets a fresh mount + fresh fetch.
async function handleLogout() {
  await auth.logout()
  router.push({ name: 'home' })
}

/* ---- load --------------------------------------------------------------
   2026-08-18 split: this used to be the client branch of a shared
   Therapist.vue (see docs/therapist-bridge.md §11 for why it's split now
   -- the two roles need genuinely different pages, not one file with an
   if/else). Same data, same behavior, just its own file. See
   TherapistDashboard.vue for the therapist-side counterpart. */
const loading    = ref(true)
const loadError  = ref('')

const therapistLink   = ref(null)
const linkCodeInput   = ref('')
const linking         = ref(false)
const linkError       = ref('')
const unlinking       = ref(false)

const sessions = ref([])

async function load() {
  loading.value   = true
  loadError.value = ''
  try {
    const [linkRes, sessionsRes] = await Promise.all([
      api.get('/api/therapist/link'),
      api.get('/api/sessions').catch(() => ({ data: [] })),
    ])
    therapistLink.value = linkRes.data
    sessions.value       = sessionsRes.data ?? []
  } catch {
    loadError.value = 'could not load this page — try reloading.'
  } finally {
    loading.value = false
  }
}
onMounted(load)

const nextSessionOverall = computed(() => {
  const upcoming = sessions.value.filter(s => !s.is_past)
  if (!upcoming.length) return null
  return upcoming.reduce((soonest, s) => new Date(s.scheduled_at) < new Date(soonest.scheduled_at) ? s : soonest)
})
function formatSessionWhen(iso) {
  return new Date(iso).toLocaleString([], { weekday: 'short', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })
}

/* ---- client actions ------------------------------------------------------
   Submitting a code REPLACES any existing link (see
   TherapistLinkController::link()'s docblock) — the confirm below exists
   so that isn't a silent surprise for someone who forgot they'd already
   linked to someone else. */
async function submitLinkCode() {
  if (linking.value || !linkCodeInput.value.trim()) return
  if (therapistLink.value && !confirm(`This will replace your current link to ${therapistLink.value.therapist.name}. Continue?`)) {
    return
  }
  linking.value = true
  linkError.value = ''
  try {
    const { data } = await api.post('/api/therapist/link', { code: linkCodeInput.value.trim() })
    therapistLink.value = data
    linkCodeInput.value = ''
  } catch (e) {
    linkError.value = e.response?.data?.message ?? "couldn't link with that code."
  } finally {
    linking.value = false
  }
}

/* ---- live trends toggle ------------------------------------------------
   Client opts in (or out) to give their therapist a live view of their
   monthly trend data. Off by default, reversible at any time. Turning it
   off cuts access on the therapist's next request — no caching on the
   server side. See docs/therapist-bridge.md (live trend view) for the
   consent design decision. */
const togglingTrends = ref(false)

async function toggleLiveTrends() {
  if (togglingTrends.value || !therapistLink.value) return
  togglingTrends.value = true
  try {
    const { data } = await api.patch('/api/therapist/link/live-trends')
    therapistLink.value.live_trends_enabled = data.live_trends_enabled
  } catch {
    loadError.value = "couldn't update that — try again."
  } finally {
    togglingTrends.value = false
  }
}

async function unlinkTherapist() {
  if (unlinking.value || !therapistLink.value) return
  if (!confirm('Unlink from your therapist? They will no longer be able to see new crisis check-ins.')) return
  unlinking.value = true
  try {
    await api.delete('/api/therapist/link')
    therapistLink.value = null
  } catch {
    loadError.value = "couldn't unlink — try again."
  } finally {
    unlinking.value = false
  }
}

function formatDate(iso) {
  if (!iso) return ''
  return new Date(iso).toLocaleDateString([], { month: 'short', day: 'numeric', year: 'numeric' })
}
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
        <button class="ghost" @click="router.push({ name: 'monthly' })">monthly</button>
        <button class="ghost current">therapist</button>
        <button class="ghost" @click="cycleTheme">◐ theme</button>
        <button class="ghost" @click="handleLogout">log out</button>
      </div>
    </header>

    <div class="wrap">
      <div class="head">
        <span class="eyebrow">therapist bridge</span>
        <h1 class="stamp">your therapist</h1>
      </div>

      <p v-if="loading" class="state-msg">loading…</p>
      <p v-else-if="loadError" class="state-msg">{{ loadError }}</p>

      <template v-else>
        <section v-if="nextSessionOverall" class="card next-session-card">
          <span class="card-title">next session</span>
          <p class="next-session-when">{{ formatSessionWhen(nextSessionOverall.scheduled_at) }}</p>
          <p class="card-hint quiet">with {{ nextSessionOverall.therapist?.name ?? 'your therapist' }}</p>
        </section>

        <section class="card">
          <template v-if="therapistLink">
            <span class="card-title">linked to</span>
            <p class="therapist-name">{{ therapistLink.therapist?.name }}</p>
            <p class="card-hint">linked {{ formatDate(therapistLink.created_at) }}. if a crisis check-in happens, they can see and reply to it automatically — no extra step needed each time.</p>

            <!-- live trends consent toggle -->
            <div class="trends-toggle">
              <span class="toggle-label">let them see my live trends</span>
              <button
                class="ghost toggle-btn" :class="{ 'toggle-on': therapistLink.live_trends_enabled }"
                :disabled="togglingTrends"
                @click="toggleLiveTrends"
              >{{ togglingTrends ? '…' : (therapistLink.live_trends_enabled ? 'on' : 'off') }}</button>
              <span class="toggle-hint">{{ therapistLink.live_trends_enabled ? 'your therapist can see your live monthly trend data.' : 'your trend data stays private.' }}</span>
            </div>

            <div class="client-actions">
              <button class="action-btn primary" @click="router.push({ name: 'crisis-thread' })">
                <span>open conversation</span><span class="action-arrow">→</span>
              </button>
              <button class="action-btn" @click="router.push({ name: 'session-briefs' })">
                <span>session briefs</span><span class="action-arrow">→</span>
              </button>
              <button class="action-btn" @click="router.push({ name: 'shared-insights' })">
                <span>what you've shared</span><span class="action-arrow">→</span>
              </button>
              <button class="action-btn" @click="router.push({ name: 'tasks' })">
                <span>your tasks</span><span class="action-arrow">→</span>
              </button>
              <button class="action-btn" @click="router.push({ name: 'sessions' })">
                <span>sessions</span><span class="action-arrow">→</span>
              </button>
              <button class="action-btn danger" :disabled="unlinking" @click="unlinkTherapist">
                {{ unlinking ? '…' : 'unlink' }}
              </button>
            </div>
          </template>
          <template v-else>
            <span class="card-title">link to your therapist</span>
            <p class="card-hint">ask your therapist for their invite code.</p>
            <form class="link-form" @submit.prevent="submitLinkCode">
              <input v-model="linkCodeInput" type="text" placeholder="invite code" autocomplete="off" />
              <button type="submit" class="ghost" :disabled="linking">{{ linking ? '…' : 'link' }}</button>
            </form>
            <p v-if="linkError" class="error">{{ linkError }}</p>
          </template>
        </section>
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
.ghost.danger:hover { color:var(--ochre); }
.ghost.primary { color:var(--accent-deep); font-weight:500; }
.ghost.primary:hover { color:var(--ink); }

.wrap { max-width:720px; margin:0 auto; padding:40px 40px 100px; }
.state-msg { color:var(--muted); font-size:15px; text-align:center; padding:80px 0 0; }

.head { margin-bottom:28px; }
.eyebrow { display:block; font-size:12px; letter-spacing:.14em; text-transform:uppercase; color:var(--muted); margin-bottom:6px; }
.stamp { font-family:var(--font-display); font-weight:400; font-size:30px; margin:0; }

.card {
  background:var(--surface); border:1px solid var(--line); border-radius:16px;
  padding:22px; margin-bottom:20px;
}
.card-title {
  display:block; font-size:12px; letter-spacing:.12em; text-transform:uppercase;
  color:var(--muted); margin-bottom:10px;
}
.card-hint { font-size:13.5px; color:var(--muted); margin:0 0 14px; line-height:1.6; }
.card-hint.quiet { margin:0; }

.next-session-card { border-color:var(--accent); }
.next-session-when { font-family:var(--font-display); font-size:19px; margin:0 0 4px; }

.therapist-name { font-family:var(--font-display); font-size:20px; margin:0 0 8px; }
/* live trends toggle */
.trends-toggle {
  display:flex; align-items:center; gap:10px; flex-wrap:wrap;
  padding:12px 0; border-top:1px solid var(--line); border-bottom:1px solid var(--line);
  margin:6px 0 10px;
}
.toggle-label { font-size:13.5px; flex:0 0 auto; }
.toggle-btn {
  border:1px solid var(--line) !important; background:var(--paper) !important;
  padding:4px 14px !important; border-radius:999px !important;
  font-weight:600 !important; min-width:46px;
}
.toggle-btn.toggle-on { border-color:var(--accent) !important; color:var(--accent-deep) !important; }
.toggle-hint { font-size:12px; color:var(--muted); }
.client-actions {
  display:grid; grid-template-columns:1fr 1fr; gap:8px; margin-top:4px;
}
.action-btn {
  display:flex; align-items:center; justify-content:space-between; gap:8px;
  padding:10px 14px; border-radius:10px;
  border:1px solid var(--line); background:var(--surface);
  color:var(--muted); font:inherit; font-size:13.5px; cursor:pointer;
  transition:border-color .15s, color .15s, background .15s;
  text-align:left;
}
.action-btn:hover { border-color:var(--accent); color:var(--ink); }
.action-btn:disabled { opacity:.45; cursor:default; }
.action-btn.primary {
  border-color:var(--accent); background:var(--accent); color:#fff; font-weight:500;
}
.action-btn.primary:hover { background:var(--accent-deep); border-color:var(--accent-deep); }
.action-btn.primary .action-arrow { color:rgba(255,255,255,.55); }
.action-btn.danger {
  grid-column:span 2; justify-content:center;
  border-color:transparent; color:var(--muted); font-size:12.5px;
}
.action-btn.danger:hover { border-color:var(--ochre); color:var(--ochre); background:var(--surface); }
.action-arrow { font-size:12px; color:var(--line); transition:color .15s; }
.action-btn:not(.primary):hover .action-arrow { color:var(--accent); }

.link-form { display:flex; gap:8px; }
.link-form input {
  flex:1; padding:10px 12px; border:1px solid var(--line); border-radius:8px;
  font:inherit; font-size:15px; background:var(--paper); color:var(--ink);
}
.error { color:var(--ochre); font-size:13px; margin:10px 0 0; }

@media (max-width: 640px) {
  .wrap { padding:32px 20px 80px; }
}
</style>
