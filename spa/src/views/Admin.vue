<script setup>
// 2026-10-25: owner-only view of what visitors wrote in the public demo
// sandboxes (one per visitor's browser, each deleted after 24h). Backed by GET /api/admin/activity
// (AdminController), which only ever reads the demo accounts.
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import api from '../lib/api'
import { useAuthStore } from '../stores/auth'

const router = useRouter()
const auth = useAuthStore()

const items = ref([])
const sandboxes = ref(0)
const loading = ref(true)
const error = ref(null)
const filter = ref('all')

const types = computed(() => ['all', ...new Set(items.value.map((i) => i.type))])
const shown = computed(() =>
  filter.value === 'all' ? items.value : items.value.filter((i) => i.type === filter.value)
)

async function load() {
  loading.value = true
  error.value = null
  try {
    const { data } = await api.get('/api/admin/activity')
    items.value = data.items
    sandboxes.value = data.sandboxes
  } catch (e) {
    error.value = e.response?.status === 404 ? 'This account is not the admin (check ADMIN_EMAIL).' : 'Could not load activity.'
  } finally {
    loading.value = false
  }
}

async function logout() {
  await auth.logout()
  router.push({ name: 'login' })
}

function fmt(ts) {
  return new Date(ts).toLocaleString(undefined, { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })
}

onMounted(load)
</script>

<template>
  <div class="admin-page">
    <div class="admin-wrap">
      <header class="admin-head">
        <div>
          <span class="kicker">admin</span>
          <h1>what visitors wrote</h1>
          <p class="sub">{{ sandboxes }} active visitor sandbox{{ sandboxes === 1 ? '' : 'es' }} (each deleted after 24h) · seeded data is hidden</p>
        </div>
        <div class="head-actions">
          <button class="ghost" @click="load" :disabled="loading">{{ loading ? 'loading…' : 'refresh' }}</button>
          <button class="ghost" @click="logout">log out</button>
        </div>
      </header>

      <div class="filters" v-if="items.length">
        <button
          v-for="t in types" :key="t"
          class="chip" :class="{ on: filter === t }"
          @click="filter = t"
        >{{ t }}<span v-if="t !== 'all'" class="count">{{ items.filter((i) => i.type === t).length }}</span></button>
      </div>

      <p v-if="error" class="error">{{ error }}</p>
      <p v-else-if="!loading && !items.length" class="empty">No visitor has written anything in their demo yet.</p>

      <ul class="feed">
        <li v-for="(it, i) in shown" :key="i" class="card">
          <div class="card-top">
            <span class="type" :class="it.type.replace(' ', '-')">{{ it.type }}</span>
            <span class="box">{{ it.sandbox }}</span>
            <span class="who">{{ it.author }}<template v-if="it.meta.for"> → {{ it.meta.for }}</template></span>
            <span class="when">{{ fmt(it.at) }}</span>
          </div>
          <p class="body">{{ it.body }}</p>
          <div class="meta" v-if="it.meta.mood || it.meta.flagged || it.meta.rule">
            <span v-if="it.meta.rule" class="flag">blocked · {{ it.meta.rule }}</span>
            <span v-if="it.meta.mood">mood: {{ it.meta.mood }}</span>
            <span v-if="it.meta.flagged" class="flag">{{ it.meta.flagged }}</span>
          </div>
          <p v-if="it.meta.coach_reply" class="reply"><span>coach</span>{{ it.meta.coach_reply }}</p>
        </li>
      </ul>
    </div>
  </div>
</template>

<style scoped>
.admin-page { min-height: 100svh; background: #F1F2F0; padding: 32px 16px 64px; color: #2B2F31; }
.admin-wrap { max-width: 760px; margin: 0 auto; display: flex; flex-direction: column; gap: 18px; }
.admin-head { display: flex; justify-content: space-between; align-items: flex-end; gap: 16px; flex-wrap: wrap; }
.kicker { font-size: 11px; letter-spacing: .12em; text-transform: uppercase; color: #57735F; font-weight: 600; }
h1 { font-size: 24px; margin: 4px 0 6px; }
.sub { margin: 0; font-size: 13px; color: #6B6F72; }
.head-actions { display: flex; gap: 8px; }
button { font: inherit; cursor: pointer; }
.ghost {
  background: transparent; border: 1px solid #D9DBD7; color: #2B2F31;
  padding: 8px 14px; border-radius: 999px; font-size: 13px;
}
.ghost:hover { border-color: #57735F; }
.ghost:disabled { opacity: .6; cursor: default; }
.filters { display: flex; gap: 6px; flex-wrap: wrap; }
.chip {
  background: #FBFBFA; border: 1px solid #E3E4E1; color: #4A4E51;
  padding: 6px 12px; border-radius: 999px; font-size: 12.5px; display: inline-flex; gap: 6px; align-items: center;
}
.chip.on { background: #57735F; border-color: #57735F; color: #fff; }
.count { font-size: 11px; opacity: .75; }
.error { color: #b3543f; font-size: 14px; }
.empty { color: #6B6F72; font-size: 14px; background: #FBFBFA; padding: 24px; border-radius: 14px; text-align: center; }
.feed { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 10px; }
.card { background: #FBFBFA; border-radius: 14px; padding: 16px 18px; display: flex; flex-direction: column; gap: 8px; }
.card-top { display: flex; align-items: center; gap: 10px; font-size: 12.5px; flex-wrap: wrap; }
.type {
  font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .06em;
  padding: 3px 8px; border-radius: 6px; background: #E8EDE9; color: #3F5A47;
}
.type.crisis-chat { background: #F5E6E1; color: #8E3F2C; }
.type.blocked-attempt { background: #2B2F31; color: #F1F2F0; }
.type.task, .type.session-note { background: #E9E7F1; color: #4D4670; }
.who { font-weight: 600; }
.box { font-size: 11.5px; color: #6B6F72; background: #F1F2F0; padding: 2px 7px; border-radius: 6px; font-variant-numeric: tabular-nums; }
.when { margin-left: auto; color: #8A8E91; }
.body { margin: 0; font-size: 14.5px; line-height: 1.55; white-space: pre-wrap; overflow-wrap: anywhere; }
.meta { display: flex; gap: 12px; font-size: 12px; color: #6B6F72; }
.flag { color: #b3543f; font-weight: 600; }
.reply { margin: 0; font-size: 13px; color: #4A4E51; background: #F1F2F0; border-radius: 10px; padding: 10px 12px; line-height: 1.5; }
.reply span { display: block; font-size: 11px; text-transform: uppercase; letter-spacing: .08em; color: #8A8E91; margin-bottom: 3px; }
@media (max-width: 520px) { .when { margin-left: 0; width: 100%; } }
</style>
