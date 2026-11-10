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

// Same ?client_id=&name= handoff as SharedInsights.vue / Tasks.vue --
// present when a therapist opens a specific client's briefs, absent on
// the client's own view.
const clientId   = computed(() => route.query.client_id ? Number(route.query.client_id) : null)
const clientName = computed(() => route.query.name ?? 'this client')

const briefs    = ref([])
const sessions  = ref([]) // upcoming sessions, for the "attach to session" picker
const loading   = ref(true)
const loadError = ref('')

async function load() {
  loading.value   = true
  loadError.value = ''
  try {
    if (role.value === 'therapist' && clientId.value) {
      const { data } = await api.get(`/api/session-briefs/client/${clientId.value}`)
      briefs.value = data ?? []
    } else {
      const [briefsRes, sessionsRes] = await Promise.all([
        api.get('/api/session-briefs'),
        api.get('/api/sessions').catch(() => ({ data: [] })),
      ])
      briefs.value   = briefsRes.data ?? []
      sessions.value = (sessionsRes.data ?? []).filter(s => !s.is_past)
    }
  } catch (e) {
    loadError.value = e.response?.status === 403
      ? "you don't have access to this."
      : 'could not load — try reloading.'
  } finally {
    loading.value = false
  }
}
onMounted(load)

// The default session in the picker: the soonest upcoming session.
const soonestSessionId = computed(() => {
  if (!sessions.value.length) return ''
  const sorted = [...sessions.value].sort((a, b) => new Date(a.scheduled_at) - new Date(b.scheduled_at))
  return sorted[0].id
})

/* ---- client: write a new brief ----------------------------------------- */
const newContent   = ref('')
const newSessionId = ref('')
const submitting   = ref(false)
const submitError  = ref('')

// Pre-select the soonest upcoming session once sessions load.
function preselectSession() {
  if (newSessionId.value === '' && soonestSessionId.value) {
    newSessionId.value = soonestSessionId.value
  }
}
onMounted(() => { /* sessions load triggers watcher via load() */ })

async function submitBrief() {
  if (submitting.value || !newContent.value.trim()) return
  submitting.value = true
  submitError.value = ''
  try {
    const { data } = await api.post('/api/session-briefs', {
      content:            newContent.value.trim(),
      therapy_session_id: newSessionId.value || null,
    })
    briefs.value.unshift(data.brief)
    newContent.value   = ''
    newSessionId.value = soonestSessionId.value
  } catch (e) {
    submitError.value = e.response?.data?.message || "couldn't send — try again."
  } finally {
    submitting.value = false
  }
}

/* ---- client: revoke a brief -------------------------------------------- */
const revokingId = ref(null)
async function revokeBrief(b) {
  if (revokingId.value) return
  revokingId.value = b.share_id
  try {
    await api.delete(`/api/session-briefs/${b.share_id}`)
    const target = briefs.value.find(x => x.share_id === b.share_id)
    if (target) target.revoked_at = new Date().toISOString()
  } catch {
    // leave it as-is; they can retry
  } finally {
    revokingId.value = null
  }
}

function formatDate(iso) {
  if (!iso) return ''
  return new Date(iso).toLocaleString([], { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })
}
function formatSessionDate(iso) {
  if (!iso) return ''
  return new Date(iso).toLocaleString([], { weekday: 'short', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })
}

const activeBriefs  = computed(() => briefs.value.filter(b => !b.revoked_at))
const revokedBriefs = computed(() => briefs.value.filter(b => b.revoked_at))
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
          <button class="ghost current">briefs</button>
          <button class="ghost" @click="router.push({ name: 'shared-insights', query: { client_id: clientId, name: clientName } })">insights</button>
          <button class="ghost" @click="router.push({ name: 'tasks', query: { client_id: clientId, name: clientName } })">tasks</button>
          <button class="ghost" @click="router.push({ name: 'sessions', query: { client_id: clientId, name: clientName } })">sessions</button>
          <span class="bar-sep"></span>
        </template>
      </div>
    </header>

    <div class="wrap">
      <div class="head">
        <span class="eyebrow">{{ role === 'therapist' ? 'pre-session briefs' : 'before we meet' }}</span>
        <h1 class="stamp">
          {{ role === 'therapist' ? clientName : "what you've shared" }}
        </h1>
        <p v-if="role === 'client'" class="lede">
          write something you want your therapist to know before your next session — in your own words, at your own pace.
        </p>
      </div>

      <p v-if="loading" class="state-msg">loading…</p>
      <p v-else-if="loadError" class="state-msg">{{ loadError }}</p>

      <template v-else>
        <!-- ---- client: write a new brief ---- -->
        <section v-if="role === 'client'" class="card write-card">
          <span class="card-title">new brief</span>
          <form @submit.prevent="submitBrief" class="write-form">
            <textarea
              v-model="newContent"
              rows="5"
              placeholder="what's been on your mind? what would you want them to know going in?"
              maxlength="8000"
            ></textarea>

            <div class="write-footer">
              <div class="session-picker" v-if="sessions.length">
                <label class="picker-label">attach to session</label>
                <select v-model="newSessionId" @change="() => {}" @vue:mounted="preselectSession">
                  <option value="">no specific session</option>
                  <option v-for="s in sessions" :key="s.id" :value="s.id">
                    {{ formatSessionDate(s.scheduled_at) }}
                  </option>
                </select>
              </div>
              <p v-else class="picker-hint">no upcoming sessions — brief will float free.</p>
              <button type="submit" class="ghost primary" :disabled="submitting || !newContent.trim()">
                {{ submitting ? '…' : 'share with therapist' }}
              </button>
            </div>
          </form>
          <p v-if="submitError" class="error">{{ submitError }}</p>
        </section>

        <!-- ---- therapist: active briefs (read-only) ---- -->
        <template v-if="role === 'therapist'">
          <p v-if="!activeBriefs.length" class="state-msg quiet">{{ clientName }} hasn't shared any briefs yet.</p>
          <div v-else class="brief-list">
            <article class="card brief-card" v-for="b in activeBriefs" :key="b.share_id">
              <div class="brief-meta">
                <span class="brief-date">{{ formatDate(b.granted_at) }}</span>
                <span v-if="b.session_at" class="brief-session">for {{ formatSessionDate(b.session_at) }}</span>
              </div>
              <p class="brief-content">{{ b.content }}</p>
            </article>
          </div>
        </template>

        <!-- ---- client: active briefs ---- -->
        <template v-if="role === 'client'">
          <p v-if="!activeBriefs.length" class="state-msg quiet">nothing shared yet.</p>
          <div v-else class="brief-list">
            <article class="card brief-card" v-for="b in activeBriefs" :key="b.share_id">
              <div class="brief-meta">
                <span class="brief-date">shared {{ formatDate(b.granted_at) }}</span>
                <span v-if="b.session_at" class="brief-session">for {{ formatSessionDate(b.session_at) }}</span>
              </div>
              <p class="brief-content">{{ b.content }}</p>
              <button
                class="ghost danger small"
                :disabled="revokingId === b.share_id"
                @click="revokeBrief(b)"
              >{{ revokingId === b.share_id ? '…' : 'unshare' }}</button>
            </article>
          </div>

          <template v-if="revokedBriefs.length">
            <p class="section-label muted">unshared</p>
            <div class="brief-list faded">
              <article class="card brief-card revoked" v-for="b in revokedBriefs" :key="b.share_id">
                <div class="brief-meta">
                  <span class="brief-date">unshared {{ formatDate(b.revoked_at) }}</span>
                  <span v-if="b.session_at" class="brief-session">for {{ formatSessionDate(b.session_at) }}</span>
                </div>
                <p class="brief-content">{{ b.content }}</p>
              </article>
            </div>
          </template>
        </template>
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
  min-height:100vh;
}
* { box-sizing:border-box; }

.bar {
  display:flex; align-items:center; justify-content:space-between;
  padding:18px 40px; border-bottom:1px solid var(--line);
}
.wordmark { height: 80px; width: auto; display: block; }
.bar-left { display:flex; align-items:center; gap:12px; }
.bar-actions { display:flex; align-items:center; gap:6px; }
.bar-sep { width:1px; height:16px; background:var(--line); flex-shrink:0; margin:0 2px; }
.ghost.current { color:var(--ink); background:var(--surface); font-weight:500; cursor:default; }
.ghost.current:hover { color:var(--ink); background:var(--surface); }
.ghost {
  background:none; border:none; color:var(--muted); font:inherit; font-size:13px;
  padding:6px 10px; border-radius:8px; cursor:pointer;
}
.ghost:hover { color:var(--ink); background:var(--surface); }
.ghost:disabled { opacity:.4; cursor:default; }
.ghost.primary { color:var(--accent-deep); font-weight:500; }
.ghost.primary:hover { color:var(--ink); }
.ghost.danger:hover { color:var(--ochre); }
.ghost.small { font-size:12px; padding:4px 8px; }

.wrap { max-width:720px; margin:0 auto; padding:40px 40px 80px; }
.state-msg { color:var(--muted); font-size:15px; text-align:center; padding:60px 0 0; }
.state-msg.quiet { padding:20px 0; text-align:left; }

.head { margin-bottom:28px; }
.eyebrow { display:block; font-size:12px; letter-spacing:.14em; text-transform:uppercase; color:var(--muted); margin-bottom:6px; }
.stamp { font-family:var(--font-display); font-weight:400; font-size:28px; margin:0 0 8px; }
.lede { font-size:14px; color:var(--muted); margin:0; line-height:1.6; max-width:52ch; }

.card {
  background:var(--surface); border:1px solid var(--line); border-radius:14px;
  padding:20px 22px; margin-bottom:18px;
}
.card-title {
  display:block; font-size:12px; letter-spacing:.12em; text-transform:uppercase;
  color:var(--muted); margin-bottom:12px;
}

/* write form */
.write-card { border-color:var(--accent); }
.write-form { display:flex; flex-direction:column; gap:10px; }
.write-form textarea {
  font:inherit; font-size:14.5px; padding:10px 12px; border:1px solid var(--line);
  border-radius:8px; background:var(--paper); color:var(--ink); resize:vertical;
}
.write-footer { display:flex; align-items:flex-end; justify-content:space-between; gap:12px; flex-wrap:wrap; }
.session-picker { display:flex; flex-direction:column; gap:4px; }
.picker-label { font-size:11.5px; color:var(--muted); letter-spacing:.06em; text-transform:uppercase; }
.session-picker select {
  font:inherit; font-size:13.5px; padding:6px 10px; border:1px solid var(--line);
  border-radius:8px; background:var(--paper); color:var(--ink);
}
.picker-hint { font-size:12px; color:var(--muted); margin:0; }
.error { font-size:13px; color:var(--ochre); margin:4px 0 0; }

/* brief cards */
.brief-list { display:flex; flex-direction:column; gap:0; }
.brief-card { display:flex; flex-direction:column; gap:10px; }
.brief-meta { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
.brief-date { font-size:12px; color:var(--muted); }
.brief-session {
  font-size:11.5px; color:var(--accent-deep);
  background:color-mix(in srgb, var(--accent) 12%, var(--surface));
  border:1px solid color-mix(in srgb, var(--accent) 35%, var(--line));
  border-radius:999px; padding:1px 8px;
}
.brief-content { font-size:14.5px; line-height:1.65; margin:0; white-space:pre-wrap; }
.brief-card.revoked { opacity:.55; }

.section-label {
  font-size:12px; letter-spacing:.1em; text-transform:uppercase; margin:18px 0 10px;
}
.muted { color:var(--muted); }
.faded { opacity:.7; }

@media (max-width: 640px) {
  .wrap { padding:32px 20px 60px; }
  .write-footer { flex-direction:column; align-items:flex-start; }
}
</style>
