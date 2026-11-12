<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from './lib/api'
import { useAuthStore } from './stores/auth'

// 2026-10-15: shown only while logged into a public demo account
// (user.is_demo comes from the API and is always false unless DEMO_MODE=true).
const auth = useAuthStore()

// 2026-11-10: full-screen pages (journal, calendar) are exactly one screen
// tall -- they subtract the banner's live height (--demo-h), or the banner
// pushes their header off the top on phones.
const banner = ref(null)
let obs = null
const root = document.documentElement
function measure() {
  root.style.setProperty('--demo-h', (banner.value?.offsetHeight || 0) + 'px')
}
watch(banner, async (el) => {
  obs?.disconnect()
  await nextTick()
  measure()
  if (el && 'ResizeObserver' in window) { obs = new ResizeObserver(measure); obs.observe(el) }
}, { flush: 'post' })
onBeforeUnmount(() => obs?.disconnect())

// 2026-11-10: on phones the top-bar links (each page's header.bar
// .bar-actions) are hidden behind ONE "menu" button. The sheet lists those
// same buttons and clicking an item clicks the original, so every page's
// own logic (log out, tour, …) keeps working without touching 15 views.
const route = useRoute()
const router = useRouter()

// 2026-11-10: the admin testing the demo (test user ∞ / test therapist ∞)
// gets a "← admin" pill to jump back without logging out
const goingBack = ref(false)
async function backToAdmin() {
  if (auth.user?.is_admin) {   // already the admin account (wandered off /admin)
    menuOpen.value = false
    return router.push({ name: 'admin' })
  }
  goingBack.value = true
  try {
    const { data } = await api.post('/api/admin/back')
    auth.user = data
    menuOpen.value = false
    router.push({ name: 'admin' })
  } catch {
    // session expired: the normal login page handles it
    router.push({ name: 'login' })
  } finally {
    goingBack.value = false
  }
}
const phone = ref(false)
const hasBar = ref(false)
const menuOpen = ref(false)
const menuByTour = ref(false)   // the tour opened it to light an item: keep it under the tour's overlay
const items = ref([])
let mq = null
let domObs = null

function scanBar() {
  hasBar.value = !!document.querySelector('header.bar .bar-actions')
}
function openMenu() {
  const els = [...document.querySelectorAll('header.bar .bar-actions > button, header.bar .bar-actions > a')]
  items.value = els
    .map((el) => ({ el, label: el.textContent.trim(), tour: el.dataset.tour || null, current: el.classList.contains('current') }))
    .filter((it) => it.label)
  menuOpen.value = true
}
function pick(it) {
  menuOpen.value = false
  it.el.click()
}
const onMq = () => { phone.value = mq.matches; if (!mq.matches) menuOpen.value = false }
const onKey = (e) => { if (e.key === 'Escape') menuOpen.value = false }
// the tour opens/closes the menu on phones to show the real links (2026-11-11)
const onTourMenu = (e) => {
  if (!phone.value || !hasBar.value) return
  if (e.detail?.open) { openMenu(); menuByTour.value = true }
  else { menuOpen.value = false; menuByTour.value = false }
}
onMounted(() => {
  mq = window.matchMedia('(max-width: 640px)')
  onMq()
  mq.addEventListener?.('change', onMq)
  // pages render their header after async loads -- watch for it
  domObs = new MutationObserver(scanBar)
  domObs.observe(document.body, { childList: true, subtree: true })
  scanBar()
  window.addEventListener('keydown', onKey)
  window.addEventListener('me:phone-menu', onTourMenu)
})
onBeforeUnmount(() => {
  mq?.removeEventListener?.('change', onMq)
  domObs?.disconnect()
  window.removeEventListener('keydown', onKey)
  window.removeEventListener('me:phone-menu', onTourMenu)
})
watch(() => route.fullPath, () => { menuOpen.value = false; nextTick(scanBar) })
</script>

<template>
  <div v-if="auth.user?.is_demo" ref="banner" class="demo-banner" role="status">
    <span class="demo-long">Demo — your own private copy with fictional data, deleted after 24 hours. Everything you write is visible to the developer.</span>
    <!-- 2026-11-07: one line on phones instead of two -->
    <span class="demo-short">Demo · deleted after 24h · visible to the developer</span>
  </div>
  <router-view />

  <button v-if="auth.user?.demo_unlimited || (auth.user?.is_admin && route.name !== 'admin')" class="admin-back" type="button" :disabled="goingBack" @click="backToAdmin">
    {{ goingBack ? '…' : '← admin' }}
  </button>

  <!-- phone menu (2026-11-10) -->
  <button
    v-if="phone && hasBar"
    class="phone-menu-btn" type="button"
    :aria-expanded="menuOpen" aria-label="menu"
    @click="menuOpen ? (menuOpen = false) : (openMenu(), menuByTour = false)"
  ><span class="pm-lines" aria-hidden="true"></span>menu</button>
  <div v-if="phone && menuOpen" class="pm-scrim" :class="{ 'pm-above': !menuByTour }" @click="menuOpen = false"></div>
  <nav v-if="phone && menuOpen" class="pm-sheet" :class="{ 'pm-above': !menuByTour }" aria-label="pages">
    <button
      v-for="(it, i) in items" :key="i" type="button"
      class="pm-item" :class="{ current: it.current }" :data-tour-item="it.tour"
      @click="pick(it)"
    >{{ it.label }}</button>
  </nav>
</template>

<style>
.demo-banner {
  position: sticky; top: 0; z-index: 1000;
  padding: 6px 16px;
  background: #57735F; color: #fff;
  font-size: 12.5px; text-align: center; line-height: 1.4;
}
.demo-short { display: none; }
@media (max-width: 640px) {
  .demo-banner { font-size: 12px; padding: 5px 12px; }
  .demo-long { display: none; }
  .demo-short { display: inline; }
}

/* ---- admin test session: back to /admin (2026-11-10) ---- */
.admin-back {
  position: fixed; z-index: 100000; left: 14px; bottom: 16px;   /* above the tour overlay */
  font: inherit; font-size: 13px; font-weight: 600; color: #fff;
  background: #2B2F31; border: none; border-radius: 999px; padding: 9px 15px;
  box-shadow: 0 6px 18px rgba(0,0,0,.18); cursor: pointer;
}
.admin-back:disabled { opacity: .7; }

/* ---- phone menu (2026-11-10) ---- */
.phone-menu-btn {
  position: fixed; z-index: 1001;
  top: calc(var(--demo-h, 0px) + 12px); right: 14px;
  display: inline-flex; align-items: center; gap: 7px;
  font: inherit; font-size: 13px; font-weight: 500; color: #26282B;
  background: #FBFBFA; border: 1px solid #E3E4E1; border-radius: 999px;
  padding: 7px 13px; box-shadow: 0 2px 10px rgba(0,0,0,.06); cursor: pointer;
}
.pm-lines, .pm-lines::before, .pm-lines::after {
  display: block; width: 13px; height: 1.5px; background: currentColor; border-radius: 2px; position: relative;
}
.pm-lines::before, .pm-lines::after { content: ''; position: absolute; left: 0; }
.pm-lines::before { top: -4px; } .pm-lines::after { top: 4px; }
.pm-scrim { position: fixed; inset: 0; z-index: 1001; background: rgba(20,22,24,.18); }
/* 2026-11-12: the tour's overlay (z 2000) used to swallow taps on the menu,
   and the tour auto-starts for every visitor -- so on phones the menu was
   unreachable until the tour ended. The button always sits above the tour;
   the sheet too, unless the tour itself opened it to light an item. */
.phone-menu-btn { z-index: 2100 !important; }
.pm-scrim.pm-above { z-index: 2101; }
.pm-sheet.pm-above { z-index: 2102 !important; }
.pm-sheet {
  position: fixed; z-index: 1002;
  top: calc(var(--demo-h, 0px) + 54px); right: 14px; left: auto;
  min-width: 200px; max-width: calc(100vw - 28px); max-height: calc(100svh - var(--demo-h, 0px) - 70px); overflow-y: auto;
  background: #FBFBFA; border: 1px solid #E3E4E1; border-radius: 16px;
  box-shadow: 0 12px 32px rgba(0,0,0,.14); padding: 6px;
  display: flex; flex-direction: column;
}
.pm-item {
  font: inherit; font-size: 15px; text-align: left; color: #4A4E51;
  background: none; border: none; border-radius: 10px; padding: 11px 14px; cursor: pointer;
}
.pm-item:hover, .pm-item:active { background: #F1F2F0; color: #26282B; }
.pm-item.current { color: #26282B; font-weight: 600; background: #EEF2EE; }
</style>
