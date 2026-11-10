<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import api from '../lib/api'

const route  = useRoute()
const router = useRouter()
const auth   = useAuthStore()

const themes = ['dusk', 'paper', 'night']
const theme  = ref('dusk')
function cycleTheme() { theme.value = themes[(themes.indexOf(theme.value) + 1) % themes.length] }
async function handleLogout() { await auth.logout(); router.push({ name: 'home' }) }

const role = computed(() => auth.user?.role ?? 'client')

// Therapist reaches a specific client's thread via ?client_id= (set by
// Therapist.vue's client-list rows); a client always has at most one
// thread, so they never need this.
const clientId = computed(() => route.query.client_id ?? null)

/* ---- load --------------------------------------------------------------
   2026-08-17 redesign, see Therapist.vue's docblock and
   docs/therapist-bridge.md. ONE persistent conversation per therapist ↔
   client link ("we only need one conversation just when the crisis
   happen") — SafetyEvent flags are shown as an inline timeline within
   this single thread rather than as separate rooms. No websockets/polling
   here — reload() runs after sending a message, same "always live, check
   when you check" pattern as the rest of this app. */
const thread     = ref(null)
const loading    = ref(true)
const loadError  = ref('')

function apiParams() {
  return role.value === 'therapist' && clientId.value ? { client_id: clientId.value } : {}
}

async function load() {
  if (role.value === 'therapist' && !clientId.value) {
    loadError.value = 'no client selected.'
    loading.value = false
    return
  }
  loading.value   = true
  loadError.value = ''
  try {
    const { data } = await api.get('/api/crisis-thread', { params: apiParams() })
    thread.value = data
  } catch (e) {
    loadError.value = e.response?.status === 403
      ? "you don't have access to this conversation."
      : e.response?.status === 404
        ? 'no therapist linked yet.'
        : 'could not load this conversation — try reloading.'
  } finally {
    loading.value = false
  }
}
onMounted(load)

const composerText = ref('')
const sending       = ref(false)
const sendError     = ref('')

async function send() {
  const body = composerText.value.trim()
  if (!body || sending.value) return
  sending.value = true
  sendError.value = ''
  try {
    await api.post('/api/crisis-thread/messages', { body, ...apiParams() })
    composerText.value = ''
    await load()
  } catch {
    sendError.value = "couldn't send that — try again."
  } finally {
    sending.value = false
  }
}

const reviewingId = ref(null)
async function reviewFlag(flag) {
  if (reviewingId.value || flag.reviewed_at || role.value !== 'therapist') return
  reviewingId.value = flag.id
  try {
    const { data } = await api.patch(`/api/crisis-thread/flags/${flag.id}`)
    flag.reviewed_at = data.reviewed_at
  } catch {
    // Non-critical acknowledgment flag — silently leave it unreviewed
    // rather than blocking anything.
  } finally {
    reviewingId.value = null
  }
}

function formatTime(iso) {
  return new Date(iso).toLocaleString([], { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })
}
function formatDate(iso) {
  return new Date(iso).toLocaleDateString([], { month: 'short', day: 'numeric' })
}
function isMine(message) {
  return message.sender?.id === auth.user?.id
}

const unreviewedCount = computed(() =>
  (thread.value?.flags ?? []).filter(f => !f.reviewed_at).length
)

// Interleave flags and messages into one chronological timeline so a
// crisis moment shows up right where it happened relative to the
// conversation, instead of as a disconnected list up top.
const timeline = computed(() => {
  if (!thread.value) return []
  const flagItems = (thread.value.flags ?? []).map(f => ({ kind: 'flag', at: f.created_at, data: f }))
  const msgItems   = (thread.value.messages ?? []).map(m => ({ kind: 'message', at: m.created_at, data: m }))
  return [...flagItems, ...msgItems].sort((a, b) => new Date(a.at) - new Date(b.at))
})
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
          <button class="ghost current">chat</button>
          <button class="ghost" @click="router.push({ name: 'session-briefs', query: { client_id: clientId, name: thread?.client?.name ?? '' } })">briefs</button>
          <button class="ghost" @click="router.push({ name: 'shared-insights', query: { client_id: clientId, name: thread?.client?.name ?? '' } })">insights</button>
          <button class="ghost" @click="router.push({ name: 'tasks', query: { client_id: clientId, name: thread?.client?.name ?? '' } })">tasks</button>
          <button class="ghost" @click="router.push({ name: 'sessions', query: { client_id: clientId, name: thread?.client?.name ?? '' } })">sessions</button>
          <span class="bar-sep"></span>
          <button class="ghost" @click="router.push({ name: 'sessions' })">calendar</button>
          <button class="ghost" @click="router.push({ name: 'therapist-activity' })">activity</button>
          <button class="ghost" @click="handleLogout">log out</button>
        </template>
        <template v-else>
          <button class="ghost current">chat</button>
          <button class="ghost" @click="router.push({ name: 'my-insights' })">what you've shared</button>
          <button class="ghost" @click="router.push({ name: 'tasks' })">your tasks</button>
          <button class="ghost" @click="router.push({ name: 'session-briefs' })">brief session</button>
          <button class="ghost" @click="router.push({ name: 'sessions' })">sessions</button>
          <button class="ghost" @click="handleLogout">log out</button>
        </template>
      </div>
    </header>

    <div class="wrap">
      <p v-if="loading" class="state-msg">loading…</p>
      <p v-else-if="loadError" class="state-msg">{{ loadError }}</p>

      <template v-else-if="thread">
        <div class="head">
          <span class="eyebrow">ongoing conversation</span>
          <h1 class="stamp">{{ role === 'therapist' ? thread.client?.name : thread.therapist?.name }}</h1>
          <p v-if="unreviewedCount > 0 && role === 'therapist'" class="lede flag-lede">
            {{ unreviewedCount }} unreviewed check-in{{ unreviewedCount === 1 ? '' : 's' }} below
          </p>
        </div>

        <div class="messages">
          <p v-if="!timeline.length" class="state-msg quiet">nothing yet — say something below.</p>

          <template v-for="item in timeline" :key="`${item.kind}-${item.data.id}`">
            <div v-if="item.kind === 'flag'" class="flag-chip" :class="{ unreviewed: !item.data.reviewed_at }">
              <span class="flag-dot"></span>
              <span class="flag-text">
                {{ role === 'therapist' ? 'flagged check-in' : 'a check-in showed the crisis-support message' }}
                — {{ formatDate(item.data.created_at) }}
              </span>
              <button
                v-if="role === 'therapist' && !item.data.reviewed_at"
                class="ghost flag-review" :disabled="reviewingId === item.data.id"
                @click="reviewFlag(item.data)"
              >{{ reviewingId === item.data.id ? '…' : 'mark seen' }}</button>
              <span v-else-if="item.data.reviewed_at" class="flag-reviewed-note">seen</span>
            </div>

            <div v-else class="message" :class="{ mine: isMine(item.data) }">
              <span class="message-sender">{{ isMine(item.data) ? 'you' : (item.data.sender?.name ?? 'them') }}</span>
              <p class="message-body">{{ item.data.body }}</p>
              <span class="message-time">{{ formatTime(item.data.created_at) }}</span>
            </div>
          </template>
        </div>

        <form class="composer" @submit.prevent="send">
          <textarea
            v-model="composerText" rows="2" placeholder="write a message…"
            @keydown.enter.exact.prevent="send"
          ></textarea>
          <button type="submit" class="ghost" :disabled="sending || !composerText.trim()">
            {{ sending ? '…' : 'send' }}
          </button>
        </form>
        <p v-if="sendError" class="error">{{ sendError }}</p>
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
.ghost.current { color:var(--ink); background:var(--surface); font-weight:500; cursor:default; }
.ghost.current:hover { color:var(--ink); background:var(--surface); }
.ghost { background:none; border:none; color:var(--muted); font:inherit; font-size:13px;
  padding:6px 10px; border-radius:8px; cursor:pointer; }
.ghost:hover { color:var(--ink); background:var(--surface); }
.ghost:disabled { opacity:.4; cursor:default; }
.bar-sep { width:1px; height:16px; background:var(--line); flex-shrink:0; margin:0 2px; }

.wrap { max-width:640px; margin:0 auto; padding:40px 40px 60px; }
.state-msg { color:var(--muted); font-size:15px; text-align:center; padding:80px 0 0; }
.state-msg.quiet { padding:20px 0; }

.head { margin-bottom:16px; }
.eyebrow { display:block; font-size:12px; letter-spacing:.14em; text-transform:uppercase; color:var(--muted); margin-bottom:6px; }
.stamp { font-family:var(--font-display); font-weight:400; font-size:26px; margin:0 0 4px; }
.lede { font-size:13px; color:var(--muted); margin:0; }
.lede.flag-lede { color:var(--ochre); }

.messages { display:flex; flex-direction:column; gap:12px; margin-bottom:20px; }

.flag-chip {
  display:flex; align-items:center; gap:8px; align-self:center;
  font-size:12px; color:var(--muted); background:var(--surface);
  border:1px solid var(--line); border-radius:999px; padding:5px 12px 5px 10px;
}
.flag-chip.unreviewed { border-color:var(--ochre); color:var(--ochre); }
.flag-dot { width:6px; height:6px; border-radius:50%; background:currentColor; flex-shrink:0; }
.flag-review { font-size:11px; padding:2px 8px; }
.flag-reviewed-note { font-size:11px; opacity:.7; }

.message {
  background:var(--surface); border:1px solid var(--line); border-radius:12px;
  padding:10px 14px; max-width:80%; align-self:flex-start;
}
.message.mine { align-self:flex-end; background:var(--accent); border-color:var(--accent); }
.message.mine .message-sender, .message.mine .message-time { color:var(--surface); opacity:.8; }
.message.mine .message-body { color:var(--surface); }
.message-sender { display:block; font-size:11px; letter-spacing:.06em; text-transform:uppercase; color:var(--muted); margin-bottom:3px; }
.message-body { margin:0; font-size:14.5px; line-height:1.5; white-space:pre-wrap; word-break:break-word; }
.message-time { display:block; font-size:10.5px; color:var(--muted); margin-top:4px; }

.composer { display:flex; gap:8px; align-items:flex-end; }
.composer textarea {
  flex:1; resize:vertical; padding:10px 12px; border:1px solid var(--line); border-radius:10px;
  font:inherit; font-size:14.5px; background:var(--surface); color:var(--ink);
}
.error { color:var(--ochre); font-size:13px; margin:8px 0 0; }

@media (max-width: 640px) {
  .wrap { padding:32px 20px 48px; }
  .message { max-width:92%; }
}
</style>
