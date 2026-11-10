<script setup>
import { ref, computed, onMounted, onUnmounted, nextTick } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import api from '../lib/api'
import { warm, NEED_WORDS, PROTECTIVE_WORDS, BEHAVIOR_WORDS, THINKING_PATTERN_WORDS } from '../utils/taxonomyDisplay'

const router = useRouter()
const auth   = useAuthStore()
// 2026-11-10: demo copies get a few entries; 0 left = compose is closed
// 2026-11-10: /api/daily-review/pending doesn't always send entry_count --
// fall back to the entries on screen for that day (was rendering "( entries)")
const reviewCount = computed(() => {
  const r = pendingReview.value
  if (!r) return 0
  if (typeof r.entry_count === 'number') return r.entry_count
  return entries.value.filter((e) => (e.created_at || '').slice(0, 10) === r.date).length || (r.date === todayStr ? entries.value.length : 0)
})
const noTriesLeft = computed(() => !!auth.user?.is_demo && auth.user.demo_tries_left === 0)
async function handleLogout() { await auth.logout(); router.push({ name: 'home' }) }

// 2026-08-18, added after a real report of "couldn't save"/"couldn't load"
// with no further detail: every catch block below used to show one fixed
// string regardless of WHY a request failed, which made a real backend bug
// (a UNIQUE constraint violation, found via storage/logs/laravel.log) look
// identical to a network blip -- impossible to tell apart from the UI
// alone. This surfaces the server's actual message when there is one
// (Laravel's default JSON error shape is {"message": "..."}), falling back
// to the generic copy only when there's truly nothing more specific (a
// network-level failure with no response at all).
function apiErrorMessage(err, fallback) {
  return err?.response?.data?.message || fallback
}

const themes = ['dusk', 'paper', 'night']
const theme = ref('dusk')
function cycleTheme() {
  theme.value = themes[(themes.indexOf(theme.value) + 1) % themes.length]
}

// Computed once at page load — all API requests use this to scope to today only.
// Past days are in the Calendar view.
const todayStr = (() => {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`
})()

const threadEl = ref(null)
const entries  = ref([])
const loading  = ref(true)
const hasMore  = ref(false)
const loadingMore  = ref(false)
const currentPage  = ref(1)
const todayIntentId = ref(null)
const todaysPick    = ref(null)

// Collapsed from 4 picks to 2 (2026-08-12) — 'light'/'rest' retired, see
// CoachService::PICK_TO_MODE in the API repo for why.
const picks = [
  { id: 'engage',  label: 'dig in' },
  { id: 'witness', label: 'just listen' },
]

async function choosePick(id) {
  todaysPick.value = id
  try {
    const { data } = await api.post('/api/intent', { pick: id })
    todayIntentId.value = data.id
  } catch {
    // leave todaysPick set locally even if the request fails — the next
    // send() attempt will just retry sending daily_intent_id as null.
  }
}

const text         = ref('')
const sending      = ref(false)
const saved        = ref(false)
const sendError    = ref('')
const pendingCoach = ref(null) // id of the entry currently waiting for a coach reply
const hasTherapistLink = ref(false)

/* ---- crisis banner + auto-navigate (2026-08-17) ---------------------
   User: "the brows to /therapist will be automatic" + "withe a gentle
   notication to encourage him to talk to his therapist". Fires only for
   the reply to an entry just sent in THIS session (from pollCoach below,
   never from load()) — otherwise reopening the journal on a day with an
   old crisis-mode reply already in it would re-trigger the redirect every
   time, which would be more alarming than helpful. Gentle + cancelable:
   a visible countdown with a "stay here" escape hatch, not a silent
   redirect out from under someone mid-thought. */
const CRISIS_REDIRECT_SECONDS = 8
const crisisBanner = ref(null) // { secondsLeft } | null
let crisisTimer = null

function startCrisisRedirect() {
  if (crisisBanner.value) return // already counting down
  crisisBanner.value = { secondsLeft: CRISIS_REDIRECT_SECONDS }
  crisisTimer = setInterval(() => {
    if (!crisisBanner.value) return
    crisisBanner.value.secondsLeft -= 1
    if (crisisBanner.value.secondsLeft <= 0) {
      goToTherapistNow()
    }
  }, 1000)
}

function cancelCrisisRedirect() {
  if (crisisTimer) { clearInterval(crisisTimer); crisisTimer = null }
  crisisBanner.value = null
}

function goToTherapistNow() {
  if (crisisTimer) { clearInterval(crisisTimer); crisisTimer = null }
  crisisBanner.value = null
  router.push({ name: 'my-therapist' })
}

function formatStamp(iso) {
  const d   = new Date(iso)
  const now = new Date()
  const hm  = d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
  if (d.toDateString() === now.toDateString()) return hm
  return d.toLocaleDateString([], { month: 'short', day: 'numeric' }) + ' · ' + hm
}

function scrollToBottom() {
  if (threadEl.value) threadEl.value.scrollTop = threadEl.value.scrollHeight
}

async function load() {
  try {
    const [pageRes, intentRes, linkRes] = await Promise.all([
      api.get('/api/entries', { params: { date: todayStr } }),
      api.get('/api/intent').catch(() => ({ data: null })),
      // Only used to phrase the crisis banner below — non-critical, so a
      // failure here just falls back to the unlinked copy.
      api.get('/api/therapist/link').catch(() => ({ data: null })),
    ])
    // Paginator returns newest-first; reverse so the thread reads chronologically.
    entries.value       = [...pageRes.data.data].reverse()
    currentPage.value   = pageRes.data.current_page
    hasMore.value       = pageRes.data.current_page < pageRes.data.last_page
    todayIntentId.value = intentRes.data?.id ?? null
    todaysPick.value    = intentRes.data?.pick ?? null
    hasTherapistLink.value = !!linkRes.data
  } finally {
    loading.value = false
    await nextTick()
    scrollToBottom()
  }
}

// Fetches the next (older) page and prepends it — previously `hasMore` was
// tracked but never acted on, so once someone passed 20 entries, anything
// older than that silently had no way to be seen again from this view (it
// was never deleted, just permanently off-screen). Scroll position is
// preserved across the prepend so the thread doesn't jump.
async function loadMore() {
  if (!hasMore.value || loadingMore.value) return
  loadingMore.value = true
  const el = threadEl.value
  const prevHeight = el ? el.scrollHeight : 0
  const prevTop    = el ? el.scrollTop : 0
  try {
    const { data: page } = await api.get('/api/entries', { params: { date: todayStr, page: currentPage.value + 1 } })
    const older = [...page.data].reverse()
    entries.value      = [...older, ...entries.value]
    currentPage.value  = page.current_page
    hasMore.value      = page.current_page < page.last_page
    await nextTick()
    if (el) el.scrollTop = el.scrollHeight - prevHeight + prevTop
  } catch {
    // leave hasMore as-is so the button stays put and can be retried
  } finally {
    loadingMore.value = false
  }
}

async function refreshEntries() {
  try {
    const { data: page } = await api.get('/api/entries', { params: { date: todayStr } })
    const fresh = [...page.data].reverse()
    // Merge in place so existing bubbles don't flash/disappear during the update.
    fresh.forEach(newEntry => {
      const idx = entries.value.findIndex(e => e.id === newEntry.id)
      if (idx >= 0) entries.value[idx] = newEntry
      else entries.value.push(newEntry)
    })
  } catch {
    // Silent — background refresh; user can reload manually if needed.
  }
}

// Poll every 3 s until the coach reply lands or the job's own timeout (90 s)
// elapses — whichever comes first. Requires `php artisan queue:work` in api/.
const POLL_INTERVAL = 3000
const POLL_DEADLINE = 90_000

async function pollCoach(entryId, startedAt = Date.now()) {
  if (Date.now() - startedAt >= POLL_DEADLINE) {
    if (pendingCoach.value === entryId) pendingCoach.value = null
    return
  }
  await new Promise(r => setTimeout(r, POLL_INTERVAL))
  await refreshEntries()
  const entry = entries.value.find(e => e.id === entryId)
  if (entry?.coach_message) {
    if (pendingCoach.value === entryId) pendingCoach.value = null
    await nextTick()
    scrollToBottom()
    if (entry.coach_message.mode === 'crisis') startCrisisRedirect()
  } else {
    pollCoach(entryId, startedAt)
  }
}

async function send() {
  const body = text.value.trim()
  if (!body || sending.value) return
  sending.value   = true
  sendError.value = ''
  try {
    const payload = { body }
    if (todayIntentId.value) payload.daily_intent_id = todayIntentId.value
    const { data } = await api.post('/api/entries', payload)
    entries.value.push(data)
    // 2026-11-10: demo visitors get a few entries per demo copy
    if (auth.user?.is_demo && typeof auth.user.demo_tries_left === 'number') {
      auth.user.demo_tries_left = Math.max(0, auth.user.demo_tries_left - 1)
    }
    text.value  = ''
    // 2026-11-07: a blocked prompt-injection attempt comes back with its
    // fixed reply already attached -- nothing to wait for
    saved.value = true
    setTimeout(() => (saved.value = false), 2200)
    await nextTick()
    scrollToBottom()

    if (!data.coach_message) {
      pendingCoach.value = data.id
      pollCoach(data.id)
    }
  } catch (err) {
    if (err.response?.status === 429) {
      sendError.value = err.response.data?.message || 'demo limit reached.'
      if (auth.user?.is_demo && err.response.data?.tries_left === 0) auth.user.demo_tries_left = 0
    } else {
      sendError.value = 'could not reach the server — check that `php artisan serve` is running in api/.'
    }
  } finally {
    sending.value = false
  }
  tryDailyReset() // re-check after compose clears (user may have finished just inside the window)
}

function onKeydown(e) {
  if (e.key === 'Enter' && !e.shiftKey) {
    e.preventDefault()
    send()
  }
}

function formatCoach(body) {
  if (!body) return ''
  return body
    .split(/\n\n+/)
    .map(para => {
      const html = para
        .replace(/\n/g, '<br>')
        .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
      return `<p class="coach-para">${html}</p>`
    })
    .join('')
}

/* ---- daily breakdown review (2026-08-18) --------------------------
   User: "id like to make the breakdown that the user will unteract with
   after the daylie discussion like a button save the journal then a
   notification would u like to see the breakdown and then he sees and
   choose to correct if the discussion gonna end after 24 hour and the
   user didn't click save the ai will use the breakdown without the user
   input automatically." Four scope decisions confirmed before building
   this (see docs/dashboard-logic.md): whole-day, non-gating (this is
   purely a look-and-optionally-correct UI — nothing downstream waits on
   it), an in-app banner (no push/email system exists), and a 24h-from-
   last-activity auto-confirm window computed server-side, lazily, with
   no client involvement at all -- if the user never opens this page
   again, the day still silently becomes "auto_confirmed" the next time
   ANY daily-review endpoint happens to touch that row; nothing here needs
   to run for that to happen. */
const pendingReview    = ref(null) // { date, saved_at, entry_count } | null -- GET /api/daily-review/pending
const savingJournal     = ref(false)
const saveJournalError  = ref('')
const reviewDrawerOpen  = ref(false)
const reviewLoading     = ref(false)
const reviewError       = ref('')
const reviewData        = ref(null) // full GET /api/daily-review/{date} response
const reviewDrill       = ref(null) // { column, value, loading, error, entries } | null

// column -> display config. Matches DailyReviewController::FIELD_SPEC's
// *_breakdown keys exactly so this can read the response with no reshaping.
const REVIEW_FIELDS = [
  { key: 'emotion_breakdown',           column: 'emotions',           title: 'feelings',           dict: {} },
  { key: 'stressor_breakdown',          column: 'stressors',          title: 'what weighed on you', dict: {} },
  { key: 'protective_factor_breakdown', column: 'protective_factors', title: 'what helped',         dict: PROTECTIVE_WORDS },
  { key: 'behavior_breakdown',          column: 'behaviors',          title: 'what you did',        dict: BEHAVIOR_WORDS },
  { key: 'need_breakdown',              column: 'needs',              title: 'what you needed',     dict: NEED_WORDS },
  { key: 'thinking_pattern_breakdown',  column: 'thinking_patterns',  title: 'thinking patterns',   dict: THINKING_PATTERN_WORDS },
]

// width from `share` (weighted dominance), same reasoning as Dashboard.vue's
// breakdownRows() -- falls back to `rate` for safety, though this endpoint
// always computes fresh so share should always be present here.
function reviewRows(breakdownKey, dict) {
  const list = reviewData.value?.[breakdownKey]
  if (!list?.length) return []
  return list.map(i => ({
    label: warm(dict, i.label),
    rawLabel: i.label,
    width: Math.round((i.share ?? i.rate) * 100),
  }))
}

const visibleReviewFields = computed(() => {
  if (!reviewData.value) return []
  return REVIEW_FIELDS
    .map(f => ({ ...f, rows: reviewRows(f.key, f.dict) }))
    .filter(f => f.rows.length)
})

async function checkPendingReview() {
  try {
    const { data } = await api.get('/api/daily-review/pending')
    pendingReview.value = data
  } catch {
    // non-critical background check -- same posture as the therapist-link
    // lookup in load() above, silently skip on failure
  }
}

async function saveJournal() {
  if (savingJournal.value || !entries.value.length) return
  savingJournal.value = true
  saveJournalError.value = ''
  try {
    await api.post(`/api/daily-review/${todayStr}/save`)
    // "then a notification" -- shows the banner immediately, same session,
    // rather than waiting for a reload to re-fetch pending().
    pendingReview.value = { date: todayStr, saved_at: new Date().toISOString(), entry_count: entries.value.length }
  } catch (err) {
    saveJournalError.value = apiErrorMessage(err, "couldn't save — try again in a moment.")
  } finally {
    savingJournal.value = false
  }
}

function dismissReviewBanner() {
  // Local only, no server call -- "not now" isn't a durable decision, the
  // banner is allowed to reappear next visit since saved_at stays set and
  // reviewed_at stays null server-side. Only actually opening it (below)
  // or the 24h auto-confirm resolves it for good.
  pendingReview.value = null
}

async function openReviewDrawer() {
  if (!pendingReview.value) return
  const date = pendingReview.value.date
  reviewDrawerOpen.value = true
  reviewLoading.value = true
  reviewError.value = ''
  reviewDrill.value = null
  try {
    const { data } = await api.get(`/api/daily-review/${date}`)
    reviewData.value = data
    // "then he sees" -- viewing the breakdown IS the review; correcting
    // individual tags below is optional on top of that. Best-effort, not
    // awaited -- a failure here shouldn't block the drawer from showing.
    api.post(`/api/daily-review/${date}/mark-reviewed`).catch(() => {})
    pendingReview.value = null
  } catch (err) {
    reviewError.value = apiErrorMessage(err, "couldn't load that day's breakdown — try again in a moment.")
  } finally {
    reviewLoading.value = false
  }
}

function closeReviewDrawer() {
  reviewDrawerOpen.value = false
  reviewData.value  = null
  reviewDrill.value = null
}

async function openReviewDrill(column, rawLabel) {
  reviewDrill.value = { column, value: rawLabel, loading: true, error: '', entries: [] }
  try {
    const { data } = await api.get(
      `/api/daily-review/${reviewData.value.date}/${column}/${encodeURIComponent(rawLabel)}`
    )
    reviewDrill.value = { column, value: rawLabel, loading: false, error: '', entries: data.entries }
  } catch (err) {
    reviewDrill.value = { column, value: rawLabel, loading: false, error: apiErrorMessage(err, "couldn't load those entries."), entries: [] }
  }
}

// Reuses the exact same POST /api/analysis-corrections endpoint the
// EntryAnalysis.vue inspector already uses -- no new correction mechanism,
// just a quick "not quite" (action: 'removed') from inside the daily
// drawer. A full "correct to X" re-label still goes through the existing
// per-entry inspector (linked below), not duplicated here.
async function markTagWrong(entryAnalysisId, originalValue) {
  try {
    await api.post('/api/analysis-corrections', {
      entry_analysis_id: entryAnalysisId,
      field:             reviewDrill.value.column,
      original_value:    originalValue,
      action:            'removed',
    })
    const item = reviewDrill.value.entries.find(e => e.entry_analysis_id === entryAnalysisId)
    if (item) item.already_corrected = true
  } catch {
    // leave the row as-is on failure -- no misleading optimistic update
  }
}

/* ---- daily reset (23:30 – 00:15) ---------------------------------- */
const dailyResetDone = ref(false)

function isInResetWindow() {
  const now = new Date()
  const h = now.getHours(), m = now.getMinutes()
  return (h === 23 && m >= 30) || (h === 0 && m <= 14)
}

function tryDailyReset() {
  if (dailyResetDone.value) return          // already cleared this session
  if (!isInResetWindow()) return            // outside the nightly window
  if (text.value.trim() !== '' || sending.value) return // mid-compose — wait
  entries.value    = []
  pendingCoach.value = null
  dailyResetDone.value = true
}

let _resetTimer = null

onMounted(() => { load(); checkPendingReview(); _resetTimer = setInterval(tryDailyReset, 30_000) })
onUnmounted(() => { clearInterval(_resetTimer); if (crisisTimer) clearInterval(crisisTimer) })
</script>

<template>
  <div class="me" :data-theme="theme">

    <header class="bar">
      <img class="wordmark" src="/logo.png" alt="me" />
      <div class="bar-actions">
        <button class="ghost" @click="router.push({ name: 'dashboard' })">dashboard</button>
        <button class="ghost current">journal</button>
        <button class="ghost" @click="router.push({ name: 'calendar' })">calendar</button>
        <button class="ghost" @click="router.push({ name: 'memories' })">memories</button>
        <button class="ghost" @click="router.push({ name: 'monthly' })">monthly</button>
        <button class="ghost" @click="router.push({ name: 'my-therapist' })">therapist</button>
        <button class="ghost" @click="handleLogout">log out</button>
      </div>
    </header>

    <!-- gentle crisis notice + auto-navigate (2026-08-17) — see docblock
         on crisisBanner above. Deliberately calm styling (accent tones,
         not red/alarm colors) and always cancelable. -->
    <div v-if="crisisBanner" class="crisis-banner">
      <p class="crisis-text">
        {{ hasTherapistLink
          ? "That sounds like a lot to carry right now. We're opening a space to talk with your therapist."
          : "That sounds like a lot to carry right now. You deserve support — here's a place to connect with a therapist." }}
      </p>
      <div class="crisis-actions">
        <button class="ghost" @click="cancelCrisisRedirect">stay here</button>
        <button class="ghost crisis-go" @click="goToTherapistNow">go now ({{ crisisBanner.secondsLeft }})</button>
      </div>
    </div>

    <!-- today's intent — sets the coach's engagement ceiling for every
         message sent from here today (see CoachService::resolveMode) -->
    <div class="intent-bar">
      <span class="intent-label">today:</span>
      <button
        v-for="p in picks" :key="p.id"
        class="pill" :class="{ on: todaysPick === p.id }"
        @click="choosePick(p.id)"
      >{{ p.label }}</button>
      <!-- "save the journal" (2026-08-18) -- only meaningful once there's
           something to review; see saveJournal() above. -->
      <button
        v-if="entries.length"
        class="ghost save-journal-btn"
        :disabled="savingJournal"
        @click="saveJournal"
      >{{ savingJournal ? 'saving…' : '✓ save' }}<span class="save-long"> the journal</span></button>
    </div>
    <p v-if="saveJournalError" class="save-journal-error">{{ saveJournalError }}</p>

    <!-- daily breakdown review banner (2026-08-18) -- gated on saveJournal()
         having been clicked (pendingReview set); see docblock above. -->
    <div v-if="pendingReview" class="review-banner">
      <p class="review-text">
        <span class="rv-long">saved. want to see {{ pendingReview.date === todayStr ? "today's" : "that day's" }} breakdown{{ reviewCount ? ` (${reviewCount} ${reviewCount === 1 ? 'entry' : 'entries'})` : '' }}?</span>
        <span class="rv-short">saved ✓</span>
      </p>
      <div class="review-actions">
        <button class="ghost review-go" @click="openReviewDrawer"><span class="rv-long">see it →</span><span class="rv-short">breakdown →</span></button>
        <button class="ghost review-x" @click="dismissReviewBanner" aria-label="not now"><span class="rv-long">not now</span><span class="rv-short">✕</span></button>
      </div>
    </div>

    <!-- scrollable thread -->
    <div ref="threadEl" class="thread">
      <div class="thread-inner">

        <p v-if="loading" class="state-msg">loading…</p>

        <template v-else>
          <button v-if="hasMore" class="more-note load-more" :disabled="loadingMore" @click="loadMore">
            {{ loadingMore ? 'loading…' : 'load older entries' }}
          </button>
          <div v-if="!entries.length" class="coach-bubble">
            <div class="bubble-body">
              <p class="coach-para">hey, how are you today?</p>
            </div>
          </div>

          <div v-for="entry in entries" :key="entry.id" class="entry-block">
            <!-- user's entry — right-aligned -->
            <div class="me-bubble">
              <p class="bubble-body">{{ entry.body }}</p>
              <span class="bubble-time">{{ formatStamp(entry.created_at) }}</span>
            </div>
            <!-- coach reply — left-aligned when present -->
            <div v-if="entry.coach_message" class="coach-bubble">
              <div class="bubble-body" v-html="formatCoach(entry.coach_message.body)"></div>
            </div>
            <!-- thinking indicator only for the entry just sent, while the job runs -->
            <div v-else-if="pendingCoach === entry.id" class="coach-bubble coach-thinking">
              <p class="bubble-body">thinking…</p>
            </div>
            <!-- link to the "verify accuracy" inspector, once a reading exists -->
            <button v-if="entry.analysis" class="inspect-link" @click="router.push('/analysis/' + entry.id)">see the breakdown →</button>
          </div>
        </template>

      </div>
    </div>

    <!-- daily breakdown review drawer (2026-08-18) -->
    <transition name="drawer">
      <div v-if="reviewDrawerOpen" class="review-overlay" @click.self="closeReviewDrawer">
        <div class="review-panel">
          <div class="review-panel-head">
            <span class="review-panel-title">
              {{ reviewData ? (reviewData.date === todayStr ? "today's breakdown" : reviewData.date + "'s breakdown") : 'breakdown' }}
            </span>
            <button class="drawer-close" @click="closeReviewDrawer">✕</button>
          </div>

          <p v-if="reviewLoading" class="state-msg">loading…</p>
          <p v-else-if="reviewError" class="state-msg">{{ reviewError }}</p>

          <template v-else-if="reviewData">
            <p class="review-panel-sub">
              {{ reviewData.entry_count }} {{ reviewData.entry_count === 1 ? 'entry' : 'entries' }}
            </p>

            <p v-if="!visibleReviewFields.length" class="state-msg">nothing tagged yet for this day.</p>

            <div v-for="f in visibleReviewFields" :key="f.key" class="review-field">
              <span class="review-field-title">{{ f.title }}</span>
              <button
                v-for="r in f.rows" :key="r.rawLabel"
                class="review-row"
                @click="openReviewDrill(f.column, r.rawLabel)"
              >
                <span class="review-row-label">{{ r.label }}</span>
                <div class="review-row-track"><div class="review-row-fill" :style="{ width: r.width + '%' }"></div></div>
              </button>
            </div>

            <!-- drill-through: the specific entries behind a clicked tag -->
            <div v-if="reviewDrill" class="review-drill">
              <div class="review-drill-head">
                <span class="review-drill-title">{{ warm({}, reviewDrill.value) }}</span>
                <button class="drawer-close" @click="reviewDrill = null">✕</button>
              </div>
              <p v-if="reviewDrill.loading" class="state-msg">loading…</p>
              <p v-else-if="reviewDrill.error" class="state-msg">{{ reviewDrill.error }}</p>
              <template v-else>
                <div v-for="e in reviewDrill.entries" :key="e.entry_analysis_id + ':' + e.original_value" class="review-drill-entry">
                  <p class="review-drill-body">{{ e.body }}</p>
                  <div class="review-drill-actions">
                    <span v-if="e.already_corrected" class="review-drill-done">✓ noted</span>
                    <button v-else class="ghost" @click="markTagWrong(e.entry_analysis_id, e.original_value)">not quite</button>
                    <button class="ghost" :disabled="auth.user?.is_demo" :title="auth.user?.is_demo ? 'off in the demo' : null" @click="router.push('/analysis/' + e.entry_id)">full breakdown →</button>
                  </div>
                </div>
              </template>
            </div>
          </template>
        </div>
      </div>
    </transition>

    <!-- compose bar — pinned to bottom -->
    <div class="compose">
      <div class="compose-inner">
        <textarea
          v-model="text"
          rows="3"
          :placeholder="noTriesLeft ? 'that was your last demo entry — go explore the dashboard.' : 'whatever\'s here.'"
          :disabled="noTriesLeft"
          @keydown="onKeydown"
        ></textarea>
        <div class="compose-foot">
          <!-- 2026-11-07: demo visitors are reminded the owner can read this -->
          <span v-if="auth.user?.demo_unlimited" class="demo-visible">admin test · unlimited</span>
          <span v-else-if="auth.user?.is_demo" class="demo-visible">demo · visible to the developer<template v-if="typeof auth.user.demo_tries_left === 'number'"> · <b>{{ auth.user.demo_tries_left }}</b> {{ auth.user.demo_tries_left === 1 ? 'try' : 'tries' }} left</template></span>
          <span class="hint" :class="{ show: saved }">set down.</span>
          <span v-if="sendError" class="send-error">{{ sendError }}</span>
          <button class="primary" :disabled="sending || !text.trim() || noTriesLeft" @click="send">
            {{ sending ? '…' : 'send' }}
          </button>
        </div>
      </div>
    </div>

  </div>
</template>

<style scoped>
/* ---- theme tokens -------------------------------------------------- */
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

/* ---- shell: fixed header + scrolling thread + pinned compose ------ */
.me {
  --font-display:'Fraunces', Georgia, serif;
  --font-ui:'Instrument Sans', system-ui, sans-serif;
  background:var(--paper); color:var(--ink);
  font-family:var(--font-ui); line-height:1.5;
  height:calc(100svh - var(--demo-h, 0px)); display:flex; flex-direction:column; overflow:hidden;
  transition:background .4s ease, color .4s ease;
}
* { box-sizing:border-box; }

.bar {
  flex-shrink:0;
  display:flex; align-items:center; justify-content:space-between;
  padding:18px 40px; border-bottom:1px solid var(--line);
}
.intent-bar {
  flex-shrink:0;
  display:flex; align-items:center; gap:8px; flex-wrap:wrap;
  padding:12px 40px; border-bottom:1px solid var(--line);
}
.crisis-banner {
  flex-shrink:0;
  display:flex; align-items:center; justify-content:space-between; gap:16px; flex-wrap:wrap;
  padding:14px 40px; background:var(--accent); border-bottom:1px solid var(--accent-deep);
}
.crisis-text { margin:0; font-size:13.5px; line-height:1.5; color:var(--surface); max-width:520px; }
.crisis-actions { display:flex; gap:6px; flex-shrink:0; }
.crisis-actions .ghost { color:var(--surface); opacity:.85; }
.review-drill-actions .ghost:disabled { opacity:.4; cursor:not-allowed; } /* 2026-11-10: off in the demo */
.compose textarea:disabled { opacity:.6; cursor:not-allowed; }
.crisis-actions .ghost:hover { opacity:1; background:rgba(255,255,255,.15); color:var(--surface); }
.crisis-go { font-weight:500; opacity:1; }
.intent-label { font-size:12px; color:var(--muted); text-transform:uppercase; letter-spacing:.08em; }
.pill {
  background:var(--surface); border:1px solid var(--line); color:var(--ink);
  font:inherit; font-size:13px; padding:6px 13px; border-radius:999px; cursor:pointer;
  transition:all .18s ease;
}
.pill:hover { border-color:var(--accent); }
.pill.on { background:var(--accent); border-color:var(--accent); color:var(--surface); }
.wordmark { height: 80px; width: auto; display: block; }
.bar-actions { display:flex; gap:6px; }
.ghost {
  background:none; border:none; color:var(--muted); font:inherit; font-size:13px;
  padding:6px 10px; border-radius:8px; cursor:pointer;
}
.ghost:hover { color:var(--ink); background:var(--surface); }

/* ---- daily breakdown review (2026-08-18) --------------------------- */
.save-journal-btn { margin-left:auto; border:1px solid var(--line); }
.save-journal-btn:disabled { opacity:.6; cursor:default; }
.save-journal-error { margin:0; padding:6px 40px 0; font-size:12.5px; color:#b3543f; }

.review-banner {
  flex-shrink:0;
  display:flex; align-items:center; justify-content:space-between; gap:16px; flex-wrap:wrap;
  padding:12px 40px; background:var(--surface); border-bottom:1px solid var(--line);
}
.review-text { margin:0; font-size:13.5px; line-height:1.5; color:var(--ink); }
.rv-short { display:none; }
.review-actions .review-go { order:2; } .review-actions .review-x { order:1; }
.review-actions { display:flex; gap:6px; flex-shrink:0; }
.review-go { color:var(--accent-deep); font-weight:500; }

.review-overlay {
  position:fixed; inset:0; z-index:40;
  background:rgba(0,0,0,.28);
  display:flex; justify-content:flex-end;
}
.review-panel {
  width:min(440px, 100%); height:100%;
  background:var(--paper); border-left:1px solid var(--line);
  padding:24px 26px 40px; overflow-y:auto;
}
.review-panel-head {
  display:flex; align-items:center; justify-content:space-between;
  margin-bottom:4px;
}
.review-panel-title {
  font-family:var(--font-display); font-size:19px; font-weight:500;
}
.review-panel-sub { margin:0 0 20px; font-size:12.5px; color:var(--muted); }

.review-field { margin-bottom:22px; display:flex; flex-direction:column; gap:8px; }
.review-field-title {
  font-size:11px; color:var(--muted); text-transform:uppercase; letter-spacing:.08em;
}
.review-row {
  display:flex; flex-direction:column; gap:5px;
  background:none; border:none; text-align:left; cursor:pointer;
  padding:6px 8px; border-radius:8px; font:inherit;
  transition:background .15s ease;
}
.review-row:hover { background:var(--surface); }
.review-row-label { font-size:14px; color:var(--ink); }
.review-row-track { height:5px; border-radius:999px; background:var(--line); overflow:hidden; }
.review-row-fill { height:100%; background:var(--accent); border-radius:999px; }

.review-drill {
  margin-top:8px; padding-top:16px; border-top:1px solid var(--line);
}
.review-drill-head {
  display:flex; align-items:center; justify-content:space-between;
  margin-bottom:10px;
}
.review-drill-title { font-size:13px; color:var(--muted); text-transform:uppercase; letter-spacing:.06em; }
.review-drill-entry {
  padding:10px 0; border-bottom:1px solid var(--line);
}
.review-drill-entry:last-child { border-bottom:none; }
.review-drill-body { margin:0 0 8px; font-size:14px; line-height:1.6; color:var(--ink); }
.review-drill-actions { display:flex; align-items:center; gap:8px; }
.review-drill-done { font-size:12.5px; color:var(--accent-deep); }

.drawer-close {
  background:none; border:none; color:var(--muted); font-size:14px;
  cursor:pointer; padding:2px 6px; border-radius:6px;
}
.drawer-close:hover { color:var(--ink); background:var(--surface); }

.drawer-enter-active, .drawer-leave-active { transition:opacity .2s ease; }
.drawer-enter-active .review-panel, .drawer-leave-active .review-panel { transition:transform .25s ease; }
.drawer-enter-from, .drawer-leave-to { opacity:0; }
.drawer-enter-from .review-panel, .drawer-leave-to .review-panel { transform:translateX(24px); }

/* ---- thread ------------------------------------------------------- */
/* min-height:0 is essential: without it, a flex child won't shrink below
   its natural content height, so overflow-y:auto never actually scrolls. */
.thread { flex:1; min-height:0; overflow-y:auto; }
.thread-inner {
  max-width:680px; margin:0 auto;
  padding:36px 40px 24px;
  display:flex; flex-direction:column; gap:22px;
}

.state-msg { color:var(--muted); font-size:15px; text-align:center; padding:60px 0 0; }
.more-note {
  font-size:12px; color:var(--muted); text-align:center;
  letter-spacing:.06em; padding:0 0 4px;
}
.load-more {
  display:block; width:100%; background:none; border:none; font:inherit;
  cursor:pointer; text-decoration:underline; text-underline-offset:2px;
}
.load-more:hover:not(:disabled) { color:var(--ink); }
.load-more:disabled { cursor:default; opacity:.7; }

/* ---- entry block: me bubble + optional coach bubble --------------- */
.entry-block { display:flex; flex-direction:column; gap:8px; }

.me-bubble {
  align-self:flex-end;
  background:var(--accent); color:var(--surface);
  padding:14px 18px; border-radius:18px 18px 4px 18px;
  max-width:84%;
}
/* In night mode --surface is dark, giving dark text on the medium-toned
   accent — better contrast than forcing #fff on a light accent color.  */

.coach-bubble {
  align-self:flex-start;
  background:var(--surface); color:var(--ink);
  border:1px solid var(--line);
  padding:18px 22px; border-radius:18px 18px 18px 4px;
  max-width:84%;
}

.bubble-body {
  margin:0; font-size:15px; line-height:1.7;
  word-break:break-word;
}
.coach-para {
  margin:0 0 12px; line-height:1.75;
}
/* 2026-11-10: the last paragraph of a coach reply (rendered via v-html)
   left an empty strip at the bottom of the bubble */
.bubble-body :deep(p:last-child), .bubble-body :deep(.coach-para:last-child) { margin-bottom:0; }
.bubble-body :deep(p:first-child) { margin-top:0; }
.coach-para:last-child { margin-bottom:0; }
.coach-para strong { font-weight:640; color:var(--ink); }
.bubble-time {
  display:block; font-size:11px; opacity:.65;
  margin-top:6px; text-align:right;
}
.inspect-link {
  align-self:flex-end;
  background:none; border:none; color:var(--muted); font:inherit; font-size:12px;
  cursor:pointer; padding:2px 2px; text-decoration:underline; text-underline-offset:2px;
}
.inspect-link:hover { color:var(--accent-deep); }

/* ---- compose bar -------------------------------------------------- */
.compose {
  flex-shrink:0;
  border-top:1px solid var(--line);
  background:var(--paper);
}
.compose-inner {
  max-width:680px; margin:0 auto;
  padding:14px 40px 20px;
}
.compose textarea {
  width:100%; border:1px solid var(--line); border-radius:12px;
  padding:11px 14px; resize:none; background:var(--surface);
  color:var(--ink); font:inherit; font-size:15px; line-height:1.6; outline:none;
  transition:border-color .15s ease;
}
.compose textarea::placeholder { color:var(--muted); opacity:.7; }
.compose textarea:focus { border-color:var(--accent); }

.demo-visible { font-size: 11.5px; color: var(--muted); margin-right: auto; }
.compose-foot {
  display:flex; align-items:center; justify-content:flex-end;
  gap:12px; margin-top:8px;
}
.hint { font-size:13px; color:var(--accent-deep); opacity:0; transition:opacity .3s; }
.hint.show { opacity:1; }
.send-error { font-size:13px; color:#b3543f; flex:1; }
.coach-thinking { opacity:.55; }
.coach-thinking .bubble-body { font-style:italic; }
.primary {
  background:var(--accent-deep); border:none; color:#fff;
  font:inherit; font-size:14px;
  padding:9px 22px; border-radius:999px; cursor:pointer;
  transition:filter .15s ease;
}
.primary:hover:not(:disabled) { filter:brightness(1.08); }
.primary:disabled { opacity:.45; cursor:default; }

/* ---- mobile ------------------------------------------------------- */
@media (max-width: 680px) {
  .bar { padding:16px 20px; }
  .intent-bar { padding:8px 14px; gap:6px; flex-wrap:nowrap; }
  .intent-label { display:none; }
  .intent-bar .pill { font-size:12.5px; padding:5px 11px; white-space:nowrap; }
  .save-journal-btn { font-size:12.5px; padding:5px 10px; white-space:nowrap; }
  .save-long { display:none; }
  .thread-inner { padding:16px 14px 12px; gap:16px; }
  .compose-inner { padding:8px 14px 10px; }
  .compose textarea { font-size:14.5px; padding:9px 12px; height:64px; }
  .compose-foot { margin-top:6px; gap:8px; }
  .demo-visible { font-size:11px; line-height:1.35; }
  .primary { padding:8px 18px; font-size:13.5px; }
  /* one slim line: "saved ✓ · breakdown → · ✕" */
  .review-banner { padding:5px 8px 5px 14px; gap:6px; flex-wrap:nowrap; }
  .review-text { font-size:12.5px; line-height:1.3; color:var(--muted); }
  .rv-long { display:none; }
  .rv-short { display:inline; }
  .review-actions { gap:0; }
  .review-actions .review-go { order:1; } .review-actions .review-x { order:2; }
  .review-actions .ghost { padding:4px 8px; font-size:12.5px; }
  .review-x { color:var(--muted); }
  .save-journal-error { padding:6px 14px 0; }
  .review-panel { width:100%; padding:20px 20px 32px; }
  .me-bubble { padding:11px 14px; max-width:90%; }
  .coach-bubble { padding:13px 16px; max-width:92%; }
  .bubble-body { font-size:14.5px; line-height:1.6; }
}

@media (prefers-reduced-motion: reduce) { .me, * { transition:none !important; } }
</style>
