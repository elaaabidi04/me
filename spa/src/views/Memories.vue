<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import api from '../lib/api'

const router = useRouter()
const auth   = useAuthStore()
async function handleLogout() { await auth.logout(); router.push({ name: 'home' }) }

const themes = ['dusk', 'paper', 'night']
const theme  = ref('dusk')
function cycleTheme() { theme.value = themes[(themes.indexOf(theme.value) + 1) % themes.length] }

/* ---- categories (2026-08-14, two-stage memory pipeline) ------------
   Mirrors ExtractionTaxonomy::MEMORY_CATEGORIES on the backend. Icon +
   short label only — this is a light visual cue, not a filter/taxonomy
   UI, matching how EntryAnalysis.vue treats other enum tags as plain
   labels rather than building controls around them. */
const CATEGORY_META = {
  fact:       { icon: '📌', label: 'fact' },
  preference: { icon: '💬', label: 'preference' },
  goal:       { icon: '🎯', label: 'goal' },
  pattern:    { icon: '🔁', label: 'pattern' },
  event:      { icon: '⭐', label: 'event' },
}
function categoryMeta(cat) {
  return CATEGORY_META[cat] ?? { icon: '·', label: cat ?? '' }
}
const CATEGORY_OPTIONS = Object.keys(CATEGORY_META)

/* ---- load ----------------------------------------------------------
   Two lists now: confirmed memories (the original "what me remembers"
   view) plus the full pending inbox (2026-08-14) — every suggestion
   Stage 1 + Stage 2 produced that's still awaiting a yes/no, not just
   the single oldest one the dashboard's gentle note shows at a time.
   Someone who missed or ignored that one-shot notification can still
   find and act on it here. */
const events    = ref([])
const pending   = ref([])
const loading   = ref(true)
const loadError = ref('')

async function load() {
  loading.value   = true
  loadError.value = ''
  try {
    const [confirmedRes, pendingRes] = await Promise.all([
      api.get('/api/canon-events', { params: { status: 'confirmed' } }),
      api.get('/api/canon-events', { params: { status: 'suggested' } }),
    ])
    events.value  = confirmedRes.data
    pending.value = pendingRes.data
  } catch {
    loadError.value = 'could not load your memories — try reloading.'
  } finally {
    loading.value = false
  }
}
onMounted(load)

function formatDate(iso) {
  if (!iso) return ''
  return new Date(iso).toLocaleDateString([], { month: 'short', day: 'numeric', year: 'numeric' })
}
function formatShort(iso) {
  if (!iso) return ''
  return new Date(iso).toLocaleDateString([], { month: 'short', day: 'numeric' })
}

/* ---- busy-state tracking (shared across confirm/dismiss/forget) ---- */
const busy = ref(new Set())
function isBusy(id) { return busy.value.has(id) }

/* ---- pending inbox actions -------------------------------------------
   Optimistic, same non-destructive-with-revert pattern as forget()
   below: remove from the list immediately, put it back on failure. */
async function confirmPending(item) {
  if (isBusy(item.id)) return
  busy.value.add(item.id)

  const previous = pending.value
  pending.value = pending.value.filter(e => e.id !== item.id)

  try {
    const { data } = await api.patch(`/api/canon-events/${item.id}`, { status: 'confirmed' })
    events.value = [data, ...events.value]
  } catch {
    pending.value = previous
    loadError.value = "couldn't confirm that one — try again."
  } finally {
    busy.value.delete(item.id)
  }
}

async function dismissPending(item) {
  if (isBusy(item.id)) return
  busy.value.add(item.id)

  const previous = pending.value
  pending.value = pending.value.filter(e => e.id !== item.id)

  try {
    await api.patch(`/api/canon-events/${item.id}`, { status: 'dismissed' })
  } catch {
    pending.value = previous
    loadError.value = "couldn't dismiss that one — try again."
  } finally {
    busy.value.delete(item.id)
  }
}

/* ---- forget (confirmed memories only) ---------------------------------
   Soft removal (status -> forgotten), not a hard delete — same non-
   destructive-by-default choice already made for corrections/strategies/
   trigger dismissals elsewhere in this app. */
async function forget(event) {
  if (isBusy(event.id)) return
  busy.value.add(event.id)

  const previous = events.value
  events.value = events.value.filter(e => e.id !== event.id)

  try {
    await api.patch(`/api/canon-events/${event.id}`, { status: 'forgotten' })
  } catch {
    events.value = previous
    loadError.value = "couldn't forget that one — try again."
  } finally {
    busy.value.delete(event.id)
  }
}

/* ---- edit (2026-08-14) -------------------------------------------------
   Inline, one item at a time. Works on both lists — the caller passes
   which ref to patch back into so a confirmed-memory edit and a pending-
   candidate edit share the same code path. Editing a confirmed memory's
   description re-embeds server-side (see CanonEventController::update());
   editing a still-pending one doesn't, since nothing is retrievable yet. */
const editingId    = ref(null)
const editText     = ref('')
const editCategory = ref('event')

function startEdit(item) {
  editingId.value    = item.id
  editText.value     = item.description
  editCategory.value = item.category
}
function cancelEdit() {
  editingId.value = null
}
async function saveEdit(item, list) {
  const text = editText.value.trim()
  if (!text) return

  const payload = {}
  if (text !== item.description) payload.description = text
  if (editCategory.value !== item.category) payload.category = editCategory.value

  if (Object.keys(payload).length === 0) {
    editingId.value = null
    return
  }

  try {
    const { data } = await api.patch(`/api/canon-events/${item.id}`, payload)
    const idx = list.value.findIndex(e => e.id === item.id)
    if (idx !== -1) list.value[idx] = data
  } catch {
    loadError.value = "couldn't save that edit — try again."
  } finally {
    editingId.value = null
  }
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
        <button class="ghost current">memories</button>
        <button class="ghost" @click="router.push({ name: 'monthly' })">monthly</button>
        <button class="ghost" @click="router.push({ name: 'my-therapist' })">therapist</button>
        <button class="ghost" @click="handleLogout">log out</button>
      </div>
    </header>

    <div class="wrap">
      <div class="head">
        <span class="eyebrow">what me remembers</span>
        <h1 class="stamp">memories</h1>
        <p class="lede">
          things you've confirmed are worth remembering across days — never anything guessed or assumed.
          the coach may reference one of these if it's genuinely relevant, but most replies won't.
        </p>
      </div>

      <p v-if="loading" class="state-msg">loading…</p>
      <p v-else-if="loadError" class="state-msg">{{ loadError }}</p>

      <template v-else>
        <!-- ---- pending inbox ------------------------------------- -->
        <section v-if="pending.length" class="pending-block">
          <h2 class="section-title">waiting for your yes ({{ pending.length }})</h2>
          <section class="card event-card pending-card" v-for="p in pending" :key="p.id">
            <template v-if="editingId === p.id">
              <textarea class="edit-text" v-model="editText" rows="2"></textarea>
              <select class="edit-category" v-model="editCategory">
                <option v-for="c in CATEGORY_OPTIONS" :key="c" :value="c">{{ categoryMeta(c).icon }} {{ categoryMeta(c).label }}</option>
              </select>
              <div class="event-meta">
                <button class="ghost" @click="saveEdit(p, pending)">save</button>
                <button class="ghost" @click="cancelEdit">cancel</button>
              </div>
            </template>
            <template v-else>
              <p class="event-text">{{ p.description }}</p>
              <!-- Source quote (2026-08-15) — shown for 'pattern' suggestions
                   specifically, since that category is the one most likely to
                   carry an emotional/relational characterization the person
                   should confirm against their own words, not just the
                   model's paraphrase. "pattern" is a proxy for "sensitive,"
                   not a definition of it — a sensitive claim mis-categorized
                   as fact/goal/etc. by the evaluator won't get this treatment;
                   see docs/memory.md. Purely additive: p.description above
                   always renders regardless of whether a quote exists, so a
                   row created before this feature (or one where the quote
                   embed/parse failed) still shows a complete suggestion, just
                   without the extra line — never a blank confirmation. -->
              <p v-if="p.category === 'pattern' && p.quote" class="event-quote">"{{ p.quote }}"</p>
              <div class="event-meta">
                <span class="event-category">{{ categoryMeta(p.category).icon }} {{ categoryMeta(p.category).label }}</span>
                <span v-if="p.entity" class="event-entity">{{ p.entity.name }}</span>
                <span class="event-date">suggested {{ formatDate(p.created_at) }}</span>
                <button v-if="p.source_entry" class="ghost source-link" @click="router.push('/analysis/' + p.source_entry.id)">from {{ formatShort(p.source_entry.created_at) }} →</button>
                <div class="pending-actions">
                  <button class="ghost" @click="startEdit(p)" :disabled="isBusy(p.id)">edit</button>
                  <button class="ghost" @click="dismissPending(p)" :disabled="isBusy(p.id)">no</button>
                  <button class="ghost confirm-btn" @click="confirmPending(p)" :disabled="isBusy(p.id)">yes, remember it</button>
                </div>
              </div>
            </template>
          </section>
        </section>

        <!-- ---- confirmed memories --------------------------------- -->
        <section class="card empty-card" v-if="!events.length && !pending.length">
          <p class="empty-note">nothing remembered yet — when you confirm a suggested memory from the dashboard (or here), it'll show up below.</p>
        </section>

        <template v-if="events.length">
          <h2 v-if="pending.length" class="section-title">remembered</h2>
          <section class="card event-card" v-for="e in events" :key="e.id">
            <template v-if="editingId === e.id">
              <textarea class="edit-text" v-model="editText" rows="2"></textarea>
              <select class="edit-category" v-model="editCategory">
                <option v-for="c in CATEGORY_OPTIONS" :key="c" :value="c">{{ categoryMeta(c).icon }} {{ categoryMeta(c).label }}</option>
              </select>
              <div class="event-meta">
                <button class="ghost" @click="saveEdit(e, events)">save</button>
                <button class="ghost" @click="cancelEdit">cancel</button>
              </div>
            </template>
            <template v-else>
              <p class="event-text">{{ e.description }}</p>
              <div class="event-meta">
                <span class="event-category">{{ categoryMeta(e.category).icon }} {{ categoryMeta(e.category).label }}</span>
                <span v-if="e.entity" class="event-entity">{{ e.entity.name }}</span>
                <span class="event-date">remembered {{ formatDate(e.confirmed_at) }}</span>
                <button v-if="e.source_entry" class="ghost source-link" @click="router.push('/analysis/' + e.source_entry.id)">from {{ formatShort(e.source_entry.created_at) }} →</button>
                <button class="ghost" @click="startEdit(e)" :disabled="isBusy(e.id)">edit</button>
                <button class="ghost forget-btn" @click="forget(e)" :disabled="isBusy(e.id)">forget this</button>
              </div>
            </template>
          </section>
        </template>
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
  min-height:100vh;
  transition:background .4s ease, color .4s ease;
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
.ghost:disabled { opacity:.5; cursor:default; }

.wrap { max-width:720px; margin:0 auto; padding:40px 40px 100px; }
.state-msg { color:var(--muted); font-size:15px; text-align:center; padding:80px 0 0; }

.head { margin-bottom:28px; }
.eyebrow { display:block; font-size:12px; letter-spacing:.14em; text-transform:uppercase; color:var(--muted); margin-bottom:6px; }
.stamp { font-family:var(--font-display); font-weight:400; font-size:30px; margin:0 0 10px; }
.lede { font-size:14.5px; color:var(--muted); margin:0; max-width:56ch; line-height:1.6; }

.section-title {
  font-family:var(--font-display); font-weight:400; font-size:16px;
  color:var(--muted); margin:0 0 12px; letter-spacing:.02em;
}
.pending-block { margin-bottom:28px; }

.card {
  background:var(--surface); border:1px solid var(--line); border-radius:16px;
  padding:22px 24px; margin-bottom:14px;
}
.pending-card { border-color:color-mix(in srgb, var(--accent) 40%, var(--line)); }
.empty-note { color:var(--muted); font-size:15px; margin:0; }

.event-text { font-size:16px; line-height:1.6; margin:0 0 14px; }
.event-quote {
  font-size:13.5px; line-height:1.5; font-style:italic; color:var(--muted);
  margin:-8px 0 14px; padding-left:12px; border-left:2px solid var(--line);
}
.event-meta { display:flex; align-items:center; gap:12px; flex-wrap:wrap; }
.event-category {
  font-size:11.5px; letter-spacing:.02em;
  padding:3px 10px; border-radius:999px;
  background:color-mix(in srgb, var(--ochre) 16%, transparent); color:var(--ink);
}
.event-entity {
  font-size:11.5px; letter-spacing:.04em; text-transform:uppercase;
  padding:3px 10px; border-radius:999px;
  background:color-mix(in srgb, var(--accent) 16%, transparent); color:var(--accent-deep);
}
.event-date { font-size:12.5px; color:var(--muted); }
.forget-btn { margin-left:auto; }
.source-link {
  font-size:12px; color:var(--accent-deep);
  text-decoration:underline; text-underline-offset:2px;
  padding:2px 4px;
}

.pending-actions { display:flex; gap:6px; margin-left:auto; }
.confirm-btn { color:var(--accent-deep); font-weight:600; }

.edit-text {
  width:100%; font:inherit; font-size:15px; color:var(--ink);
  background:var(--paper); border:1px solid var(--line); border-radius:10px;
  padding:10px 12px; resize:vertical; margin-bottom:10px;
}
.edit-category {
  font:inherit; font-size:13px; color:var(--ink);
  background:var(--paper); border:1px solid var(--line); border-radius:8px;
  padding:6px 10px; margin-bottom:10px;
}
</style>
