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

// Same query-param handoff as SharedInsights.vue -- a therapist reaches
// one client's task list via ?client_id= (+ ?name= for the header).
const clientId   = computed(() => route.query.client_id ?? null)
const clientName = computed(() => route.query.name ?? 'this client')

/* 2026-08-18 -- user: "i want to add tasks if he wants to give them to
   his patient and the patient cross them." See AssignedTask's docblock
   and docs/therapist-bridge.md. A therapist assigns a small task
   (title + optional instructions + optional due date) to a currently-
   linked client; the client checks it off, undoable (toggle, not a
   one-way action). */
const tasks     = ref([])
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
      ? `/api/tasks/client/${clientId.value}`
      : '/api/tasks'
    const { data } = await api.get(url)
    tasks.value = data ?? []
  } catch (e) {
    loadError.value = e.response?.status === 403
      ? "you don't have access to this."
      : 'could not load tasks — try reloading.'
  } finally {
    loading.value = false
  }
}
onMounted(load)

/* ---- client: check a task off (toggle, undoable) ---------------------- */
const togglingId = ref(null)
async function toggle(task) {
  if (togglingId.value) return
  togglingId.value = task.id
  const wasComplete = !!task.completed_at
  try {
    const { data } = await api.patch(`/api/tasks/${task.id}/complete`)
    task.completed_at = data.completed_at
  } catch {
    task.completed_at = wasComplete ? task.completed_at : null // leave as-is, non-critical
  } finally {
    togglingId.value = null
  }
}

/* ---- therapist: assign a new task -------------------------------------- */
const newTitle        = ref('')
const newInstructions = ref('')
const newDueDate       = ref('')
const assigning         = ref(false)
const assignError       = ref('')

async function assignTask() {
  if (assigning.value || !newTitle.value.trim()) return
  assigning.value = true
  assignError.value = ''
  try {
    const { data } = await api.post('/api/tasks', {
      client_id:    clientId.value,
      title:        newTitle.value.trim(),
      instructions: newInstructions.value.trim() || null,
      due_date:     newDueDate.value || null,
    })
    tasks.value.unshift(data)
    newTitle.value = ''
    newInstructions.value = ''
    newDueDate.value = ''
  } catch (e) {
    assignError.value = e.response?.data?.message || "couldn't assign that task — try again."
  } finally {
    assigning.value = false
  }
}

const deletingId = ref(null)
async function deleteTask(task) {
  if (deletingId.value) return
  deletingId.value = task.id
  try {
    await api.delete(`/api/tasks/${task.id}`)
    tasks.value = tasks.value.filter(t => t.id !== task.id)
  } catch {
    // non-critical -- leave it in the list, the delete button just stays clickable to retry
  } finally {
    deletingId.value = null
  }
}

function formatDate(iso) {
  if (!iso) return ''
  return new Date(iso).toLocaleDateString([], { month: 'short', day: 'numeric' })
}
function formatDueDate(ymd) {
  if (!ymd) return ''
  // plain 'Y-m-d' string, not a Date-parsed timestamp -- avoids a
  // timezone-shift-by-a-day bug on a bare date-only string.
  const [y, m, d] = ymd.split('-').map(Number)
  return new Date(y, m - 1, d).toLocaleDateString([], { month: 'short', day: 'numeric' })
}
function isOverdue(task) {
  if (!task.due_date || task.completed_at) return false
  return task.due_date < new Date().toISOString().slice(0, 10)
}

const pendingTasks   = computed(() => tasks.value.filter(t => !t.completed_at))
const completedTasks = computed(() => tasks.value.filter(t => t.completed_at))
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
          <button class="ghost current">tasks</button>
          <button class="ghost" @click="router.push({ name: 'sessions', query: { client_id: clientId, name: clientName } })">sessions</button>
          <span class="bar-sep"></span>
        </template>
        <button class="ghost" @click="cycleTheme">◐ theme</button>
      </div>
    </header>

    <div class="wrap">
      <div class="head">
        <span class="eyebrow">tasks</span>
        <h1 class="stamp">{{ role === 'therapist' ? clientName : 'your tasks' }}</h1>
      </div>

      <p v-if="loading" class="state-msg">loading…</p>
      <p v-else-if="loadError" class="state-msg">{{ loadError }}</p>

      <template v-else>
        <!-- ---- therapist: assign form ---- -->
        <section v-if="role === 'therapist'" class="card">
          <span class="card-title">assign a task</span>
          <form class="assign-form" @submit.prevent="assignTask">
            <input v-model="newTitle" type="text" placeholder="task title" maxlength="255" required />
            <textarea v-model="newInstructions" rows="2" placeholder="instructions (optional)" maxlength="2000"></textarea>
            <div class="assign-row">
              <input v-model="newDueDate" type="date" />
              <button type="submit" class="ghost primary" :disabled="assigning || !newTitle.trim()">
                {{ assigning ? '…' : 'assign' }}
              </button>
            </div>
            <p v-if="assignError" class="error">{{ assignError }}</p>
          </form>
        </section>

        <p v-if="!tasks.length" class="state-msg quiet">
          {{ role === 'therapist' ? 'nothing assigned yet.' : 'nothing assigned to you yet.' }}
        </p>

        <div class="task-list" v-if="pendingTasks.length">
          <div
            class="task-card" v-for="t in pendingTasks" :key="t.id"
            :class="{ overdue: isOverdue(t) }"
          >
            <button
              v-if="role === 'client'"
              class="task-check" :disabled="togglingId === t.id"
              @click="toggle(t)" :aria-label="'mark done: ' + t.title"
            >{{ togglingId === t.id ? '…' : '' }}</button>

            <div class="task-body">
              <span class="task-title">{{ t.title }}</span>
              <span class="task-meta">
                <template v-if="role === 'client'">from {{ t.therapist?.name ?? 'your therapist' }}<template v-if="t.due_date"> · due {{ formatDueDate(t.due_date) }}</template></template>
                <template v-else>assigned {{ formatDate(t.created_at) }}<template v-if="t.due_date"> · due {{ formatDueDate(t.due_date) }}</template></template>
                <span v-if="isOverdue(t)" class="overdue-tag">overdue</span>
              </span>
              <p v-if="t.instructions" class="task-instructions">{{ t.instructions }}</p>
            </div>

            <button
              v-if="role === 'therapist'"
              class="ghost task-delete" :disabled="deletingId === t.id"
              @click="deleteTask(t)"
            >{{ deletingId === t.id ? '…' : 'remove' }}</button>
          </div>
        </div>

        <template v-if="completedTasks.length">
          <p class="done-heading muted">done</p>
          <div class="task-list">
            <div class="task-card done" v-for="t in completedTasks" :key="t.id">
              <button
                v-if="role === 'client'"
                class="task-check checked" :disabled="togglingId === t.id"
                @click="toggle(t)" :aria-label="'mark not done: ' + t.title"
              >{{ togglingId === t.id ? '…' : '✓' }}</button>
              <div class="task-body">
                <span class="task-title">{{ t.title }}</span>
                <span class="task-meta">done {{ formatDate(t.completed_at) }}</span>
              </div>
              <button
                v-if="role === 'therapist'"
                class="ghost task-delete" :disabled="deletingId === t.id"
                @click="deleteTask(t)"
              >{{ deletingId === t.id ? '…' : 'remove' }}</button>
            </div>
          </div>
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

.wrap { max-width:640px; margin:0 auto; padding:40px 40px 60px; }
.state-msg { color:var(--muted); font-size:15px; text-align:center; padding:80px 0 0; }
.state-msg.quiet { padding:20px 0; text-align:left; }

.head { margin-bottom:24px; }
.eyebrow { display:block; font-size:12px; letter-spacing:.14em; text-transform:uppercase; color:var(--muted); margin-bottom:6px; }
.stamp { font-family:var(--font-display); font-weight:400; font-size:26px; margin:0; }

.card {
  background:var(--surface); border:1px solid var(--line); border-radius:14px;
  padding:20px 22px; margin-bottom:22px;
}
.card-title { display:block; font-size:12px; letter-spacing:.12em; text-transform:uppercase; color:var(--muted); margin-bottom:12px; }
.assign-form { display:flex; flex-direction:column; gap:8px; }
.assign-form input[type="text"], .assign-form textarea {
  font:inherit; font-size:14.5px; padding:9px 12px; border:1px solid var(--line);
  border-radius:8px; background:var(--paper); color:var(--ink); resize:vertical;
}
.assign-row { display:flex; align-items:center; justify-content:space-between; gap:10px; }
.assign-row input[type="date"] {
  font:inherit; font-size:13px; padding:7px 10px; border:1px solid var(--line);
  border-radius:8px; background:var(--paper); color:var(--ink);
}
.error { color:var(--ochre); font-size:13px; margin:0; }

.task-list { display:flex; flex-direction:column; gap:10px; }
.task-card {
  display:flex; align-items:flex-start; gap:12px;
  background:var(--surface); border:1px solid var(--line); border-radius:12px; padding:14px 16px;
}
.task-card.overdue { border-color:var(--ochre); }
.task-card.done { opacity:.6; }

.task-check {
  flex:0 0 auto; width:22px; height:22px; margin-top:1px; border-radius:6px;
  border:1.5px solid var(--line); background:var(--paper); cursor:pointer;
  font-size:12px; color:var(--surface); display:flex; align-items:center; justify-content:center;
}
.task-check:hover { border-color:var(--accent); }
.task-check.checked { background:var(--accent); border-color:var(--accent); color:var(--surface); }

.task-body { flex:1; min-width:0; display:flex; flex-direction:column; gap:3px; }
.task-title { font-size:14.5px; font-weight:500; }
.task-meta { font-size:12px; color:var(--muted); display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
.overdue-tag {
  font-size:10.5px; letter-spacing:.06em; text-transform:uppercase;
  color:var(--ochre); border:1px solid var(--ochre); border-radius:999px; padding:1px 7px;
}
.task-instructions { font-size:13px; color:var(--ink); margin:4px 0 0; line-height:1.5; }

.task-delete { flex:0 0 auto; font-size:12px; }

.done-heading { font-size:12px; letter-spacing:.1em; text-transform:uppercase; margin:22px 0 10px; }
.muted { color:var(--muted); }

@media (max-width: 640px) {
  .wrap { padding:32px 20px 48px; }
}
</style>
