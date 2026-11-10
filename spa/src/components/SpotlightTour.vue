<script setup>
// 2026-11-07: guided spotlight tour (onboarding).
//
//   1. the whole screen dims,
//   2. one element stays lit (the "spotlight"),
//   3. a small card explains what it does,
//   4. the user clicks Next -- or interacts with the lit element itself,
//   5. the spotlight glides to the next element.
//
// Usage:
//   <SpotlightTour ref="tour" tour-id="client" :steps="steps" :user-id="auth.user?.id" auto-start />
//   tour.value.start()                       // e.g. from a "tour" button
//
// A step:
//   { target: '[data-tour="journal"]',   // CSS selector; omit for a centered card with no spotlight
//     title: 'your journal',
//     body:  'what it does…',
//     advanceOnClick: true,              // optional: clicking the lit element moves on, like Next
//     wait: 300,                         // optional: ms to wait for the target (default 1500, for data still loading)
//     try: 'tap any bubble',             // optional: the 👉 action line for hands-on steps
//     interactive: true }                // optional: lit area is clickable but doesn't auto-advance (e.g. an open panel)
//
// 2026-11-07: the lit element is only clickable on steps marked
// advanceOnClick (or interactive: true). On every other step -- e.g. a nav
// link like "monthly" -- a click just nudges the Next button, so the tour
// can't vanish by navigating away mid-way. If the page is left anyway
// (back button, a link outside the tour), the step is remembered for this
// browser tab and the tour picks up there when the page is opened again.
//
// Steps whose target isn't on the page (conditional sections, empty states)
// are skipped automatically. Finished/skipped tours are remembered per user
// in localStorage so auto-start only happens once.
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'

const props = defineProps({
  steps:     { type: Array, required: true },
  tourId:    { type: String, required: true },
  userId:    { type: [Number, String], default: null },
  autoStart: { type: Boolean, default: false },
})
const emit = defineEmits(['finish'])

const PAD = 8              // breathing room around the lit element
const GAP = 14             // space between the lit element and the card
const EDGE = 16            // keep the card this far from the screen edge
const WAIT_MS = 1500       // how long to wait for a target that's still loading

const active  = ref(false)
const index   = ref(0)
const rect    = ref(null)  // lit area, viewport coordinates (null = centered card, no spotlight)
const cardEl  = ref(null)
const nextBtn = ref(null)
const cardPos = ref({ top: 0, left: 0, docked: null })
const vw = ref(window.innerWidth)
const vh = ref(window.innerHeight)

const reducedMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false
const storageKey = computed(() => `me.tour.${props.tourId}.${props.userId ?? 'anon'}`)
const step   = computed(() => props.steps[index.value] ?? {})
const isLast = computed(() => index.value === props.steps.length - 1)
const shownNumber = computed(() => index.value + 1)

let currentTarget = null
let clickHandler  = null
let measureFrame  = 0
let token = 0               // cancels stale async work when the user clicks fast

// ---------------------------------------------------------------- persistence
function seen() {
  try { return localStorage.getItem(storageKey.value) === 'done' } catch { return false }
}
function markSeen() {
  try { localStorage.setItem(storageKey.value, 'done') } catch { /* private mode etc. -- fine */ }
}
const progressKey = computed(() => `${storageKey.value}.at`)
function saveProgress() {
  try { sessionStorage.setItem(progressKey.value, String(index.value)) } catch { /* fine */ }
}
function loadProgress() {
  try {
    const v = sessionStorage.getItem(progressKey.value)
    return v === null ? null : Math.min(Math.max(0, parseInt(v, 10) || 0), props.steps.length - 1)
  } catch { return null }
}
function clearProgress() {
  try { sessionStorage.removeItem(progressKey.value) } catch { /* fine */ }
}

// a click on a lit-but-not-interactive element: point at Next instead
const nudging = ref(false)
function nudge() {
  nudging.value = false
  requestAnimationFrame(() => { nudging.value = true })
  nextBtn.value?.focus({ preventScroll: true })
}
const holeClickable = computed(() => !!(step.value.advanceOnClick || step.value.interactive))

// ---------------------------------------------------------------- public API
function start(from = 0) {
  active.value = true
  saveProgress()
  goTo(from, 1)
}
defineExpose({ start })

function close(completed = false) {
  detachTarget()
  active.value = false
  rect.value = null
  markSeen()
  clearProgress()
  emit('finish', { completed })
}

function next() { isLast.value ? close(true) : goTo(index.value + 1, 1) }
function back() { if (index.value > 0) goTo(index.value - 1, -1) }

// ---------------------------------------------------------------- stepping
function findTarget(selector) {
  const el = document.querySelector(selector)
  if (!el) return null
  const r = el.getBoundingClientRect()
  return r.width > 0 && r.height > 0 ? el : null      // hidden (display:none / collapsed) counts as missing
}

function waitForTarget(selector, myToken, waitMs = WAIT_MS) {
  return new Promise((resolve) => {
    const t0 = performance.now()
    const poll = () => {
      if (myToken !== token) return resolve(null)
      const el = findTarget(selector)
      if (el) return resolve(el)
      if (performance.now() - t0 > waitMs) return resolve(null)
      requestAnimationFrame(poll)
    }
    poll()
  })
}

async function goTo(i, dir) {
  const myToken = ++token
  detachTarget()

  // walk in the current direction until a step with a visible target (or no target) is found
  while (i >= 0 && i < props.steps.length) {
    const s = props.steps[i]
    if (!s.target) { index.value = i; rect.value = null; break }
    const el = await waitForTarget(s.target, myToken, s.wait ?? WAIT_MS)
    if (myToken !== token) return
    if (el) { index.value = i; attachTarget(el, s); break }
    i += dir
  }
  if (i < 0) return goTo(0, 1)
  if (i >= props.steps.length) return close(true)

  await nextTick()
  if (currentTarget) {
    const tall = currentTarget.getBoundingClientRect().height > window.innerHeight * 0.7   // tall panel: show its top (the controls) first
    if (tall) currentTarget.style.scrollMarginTop = '72px'   // clear the sticky demo banner / top bar
    currentTarget.scrollIntoView({ block: tall ? 'start' : 'center', inline: 'nearest', behavior: reducedMotion ? 'auto' : 'smooth' })
    await new Promise((r) => setTimeout(r, reducedMotion ? 0 : 380))
    if (myToken !== token) return
  }
  measure()
  await nextTick()
  nextBtn.value?.focus({ preventScroll: true })
}

// 2026-11-08: panels that open during the tour (thread story, emotion
// detail) grow after they're lit -- re-measure whenever the lit element
// changes size, so the spotlight grows with it.
let resizeObs = null

// 2026-11-10: an open panel can also MOVE without resizing (the bubbles
// above it re-lay out, a drawer slides in) -- follow the lit element's
// position every frame so the clickable hole always sits on top of it.
let trackFrame = 0
let lastBox = ''
function track() {
  cancelAnimationFrame(trackFrame)
  if (!active.value || !currentTarget) return
  const r = currentTarget.getBoundingClientRect()
  const box = `${Math.round(r.top)},${Math.round(r.left)},${Math.round(r.width)},${Math.round(r.height)}`
  if (box !== lastBox) { lastBox = box; measure() }
  trackFrame = requestAnimationFrame(track)
}

function attachTarget(el, s) {
  currentTarget = el
  lastBox = ''
  trackFrame = requestAnimationFrame(track)
  if (window.ResizeObserver) {
    resizeObs = new ResizeObserver(() => onViewportChange())
    resizeObs.observe(el)
  }
  if (s.advanceOnClick) {
    clickHandler = () => setTimeout(next, 120)   // let the element's own click run first
    el.addEventListener('click', clickHandler)
  }
}

function detachTarget() {
  cancelAnimationFrame(trackFrame)
  resizeObs?.disconnect()
  resizeObs = null
  if (currentTarget && clickHandler) currentTarget.removeEventListener('click', clickHandler)
  currentTarget = null
  clickHandler = null
}

// ---------------------------------------------------------------- geometry
function measure() {
  vw.value = window.innerWidth
  vh.value = window.innerHeight
  if (currentTarget) {
    const r = currentTarget.getBoundingClientRect()
    // padded, but never past the screen edge (e.g. the last link in a
    // sideways-scrolling top bar on a phone)
    const left = Math.max(2, r.left - PAD)
    const right = Math.min(vw.value - 2, r.right + PAD)
    const top = Math.max(2, r.top - PAD)
    const bottom = Math.min(vh.value - 2, r.bottom + PAD)
    rect.value = { top, left, width: Math.max(0, right - left), height: Math.max(0, bottom - top) }
  } else {
    rect.value = null
  }
  nextTick(placeCard)
}

function placeCard() {
  const card = cardEl.value
  if (!card) return
  const cw = card.offsetWidth
  const ch = card.offsetHeight
  const r = rect.value

  // no spotlight: centered
  if (!r) {
    cardPos.value = { top: Math.max(EDGE, (vh.value - ch) / 2), left: Math.max(EDGE, (vw.value - cw) / 2), docked: null }
    return
  }

  // phones: dock to whichever screen edge is away from the lit element
  if (vw.value < 600) {
    const litCenter = r.top + r.height / 2
    cardPos.value = { docked: litCenter > vh.value / 2 ? 'top' : 'bottom' }
    return
  }

  const below = r.top + r.height + GAP
  const above = r.top - GAP - ch
  const centeredLeft = Math.min(vw.value - cw - EDGE, Math.max(EDGE, r.left + r.width / 2 - cw / 2))
  const midTop = Math.min(vh.value - ch - EDGE, Math.max(EDGE, r.top + r.height / 2 - ch / 2))

  if (below + ch <= vh.value - EDGE) { cardPos.value = { top: below, left: centeredLeft, docked: null }; return }
  if (above >= EDGE) { cardPos.value = { top: above, left: centeredLeft, docked: null }; return }

  // 2026-11-08: tall target (e.g. an open panel the user should read or
  // scroll) -- put the card BESIDE it rather than on top of its content
  if (r.left + r.width + GAP + cw <= vw.value - EDGE) { cardPos.value = { top: midTop, left: r.left + r.width + GAP, docked: null }; return }
  if (r.left - GAP - cw >= EDGE) { cardPos.value = { top: midTop, left: r.left - GAP - cw, docked: null }; return }

  // no room anywhere: the corner that covers the least of the lit area
  // (2026-11-10: a full-width open panel kept its buttons under the card)
  const overlap = (top, left) => {
    const w = Math.max(0, Math.min(left + cw, r.left + r.width) - Math.max(left, r.left))
    const h = Math.max(0, Math.min(top + ch, r.top + r.height) - Math.max(top, r.top))
    return w * h
  }
  const corners = [
    { top: vh.value - ch - EDGE, left: vw.value - cw - EDGE },
    { top: EDGE, left: vw.value - cw - EDGE },
    { top: vh.value - ch - EDGE, left: EDGE },
    { top: EDGE, left: EDGE },
  ]
  // a tall panel is scrolled so its controls (close, sort) sit at the top --
  // keep the card at the bottom there
  const pool = r.height > vh.value * 0.6 ? corners.filter((c) => c.top > EDGE) : corners
  const best = pool.reduce((a, c) => (overlap(c.top, c.left) < overlap(a.top, a.left) ? c : a))
  cardPos.value = { ...best, docked: null }
}

function onViewportChange() {
  if (!active.value) return
  cancelAnimationFrame(measureFrame)
  measureFrame = requestAnimationFrame(measure)
}

// four click-blocking panels around the lit area, so only the lit element
// (and the card) can be interacted with while the tour is open
const blockers = computed(() => {
  const r = rect.value
  if (!r) return [{ top: 0, left: 0, width: vw.value, height: vh.value }]
  const right = r.left + r.width
  const bottom = r.top + r.height
  return [
    { top: 0, left: 0, width: vw.value, height: Math.max(0, r.top) },
    { top: bottom, left: 0, width: vw.value, height: Math.max(0, vh.value - bottom) },
    { top: r.top, left: 0, width: Math.max(0, r.left), height: r.height },
    { top: r.top, left: right, width: Math.max(0, vw.value - right), height: r.height },
  ]
})

const cardStyle = computed(() => {
  const p = cardPos.value
  if (p.docked === 'top') return { top: `${EDGE}px`, left: `${EDGE}px`, right: `${EDGE}px` }
  if (p.docked === 'bottom') return { bottom: `${EDGE}px`, left: `${EDGE}px`, right: `${EDGE}px` }
  return { top: `${p.top}px`, left: `${p.left}px` }
})

// ---------------------------------------------------------------- keyboard
function onKey(e) {
  if (!active.value) return
  if (e.key === 'Escape') { e.preventDefault(); close(false) }
  else if (e.key === 'ArrowRight') { e.preventDefault(); next() }
  else if (e.key === 'ArrowLeft') { e.preventDefault(); back() }
}

onMounted(() => {
  window.addEventListener('resize', onViewportChange)
  window.addEventListener('scroll', onViewportChange, true)
  window.addEventListener('keydown', onKey)
  const resumeAt = loadProgress()
  if (resumeAt !== null) setTimeout(() => { if (!active.value) start(resumeAt) }, 700)
  else if (props.autoStart && !seen()) setTimeout(() => { if (!active.value) start() }, 700)
})

onBeforeUnmount(() => {
  // left the page mid-tour: pause, don't end -- resumes on the next visit
  if (active.value) saveProgress()
  detachTarget()
  cancelAnimationFrame(measureFrame)
  window.removeEventListener('resize', onViewportChange)
  window.removeEventListener('scroll', onViewportChange, true)
  window.removeEventListener('keydown', onKey)
})

// re-place the card once its content (title/body) has rendered for the new
// step, and remember the step so a reload / navigation mid-tour resumes here
watch(index, () => {
  nextTick(placeCard)
  if (active.value) saveProgress()
})
</script>

<template>
  <div v-if="active" class="tour" :class="{ 'no-motion': reducedMotion }">
    <!-- click blockers (transparent) -->
    <div v-for="(b, i) in blockers" :key="i" class="tour-blocker"
      :style="{ top: b.top + 'px', left: b.left + 'px', width: b.width + 'px', height: b.height + 'px' }" />

    <!-- the spotlight: a transparent hole whose giant shadow dims everything else -->
    <div v-if="rect" class="tour-hole"
      :style="{ top: rect.top + 'px', left: rect.left + 'px', width: rect.width + 'px', height: rect.height + 'px' }" />
    <div v-else class="tour-dim" />
    <!-- lit but not meant to be clicked on this step: catch the click and point at Next -->
    <div v-if="rect && !holeClickable" class="tour-blocker tour-hole-guard"
      :style="{ top: rect.top + 'px', left: rect.left + 'px', width: rect.width + 'px', height: rect.height + 'px' }"
      @click="nudge" />

    <div
      ref="cardEl" class="tour-card" :class="{ docked: cardPos.docked }" :style="cardStyle"
      role="dialog" aria-modal="true" :aria-labelledby="`tour-title-${tourId}`" :aria-describedby="`tour-body-${tourId}`"
    >
      <div class="tour-top">
        <span class="tour-count">{{ shownNumber }} / {{ steps.length }}</span>
        <button class="tour-skip" type="button" @click="close(false)">skip tour</button>
      </div>
      <h2 :id="`tour-title-${tourId}`" class="tour-title">{{ step.title }}</h2>
      <p :id="`tour-body-${tourId}`" class="tour-body" aria-live="polite">{{ step.body }}</p>
      <p v-if="step.try" class="tour-try">👉 {{ step.try }}</p>
      <p v-else-if="step.advanceOnClick" class="tour-try">👉 tap it</p>
      <div class="tour-dots" aria-hidden="true">
        <span v-for="(s, i) in steps" :key="i" class="tour-dot" :class="{ on: i === index, past: i < index }" />
      </div>
      <div class="tour-actions">
        <button v-if="index > 0" class="tour-btn ghost" type="button" @click="back">back</button>
        <button ref="nextBtn" class="tour-btn" :class="{ nudge: nudging }" type="button" @click="next" @animationend="nudging = false">{{ isLast ? (step.doneLabel ?? 'got it') : 'next' }}</button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.tour {
  --t-paper: var(--surface, #FBFBFA);
  --t-ink: var(--ink, #26282B);
  --t-muted: var(--muted, #6B6F72);
  --t-accent: var(--accent-deep, #57735F);
  --t-line: var(--line, #E3E4E1);
  position: fixed; inset: 0; z-index: 2000;
  font-family: var(--font-ui, system-ui, sans-serif);
  /* the wrapper itself must let clicks through, or the lit element could
     never be interacted with -- only the blockers and the card catch them */
  pointer-events: none;
}
.tour-blocker { position: fixed; background: transparent; pointer-events: auto; }
.tour-dim { pointer-events: auto; }
.tour-dim { position: fixed; inset: 0; background: rgba(16, 20, 18, .62); }
.tour-hole {
  position: fixed; border-radius: 14px; pointer-events: none;
  box-shadow: 0 0 0 9999px rgba(16, 20, 18, .62), 0 0 0 2px rgba(255, 255, 255, .55) inset;
  transition: top .42s cubic-bezier(.4, 0, .2, 1), left .42s cubic-bezier(.4, 0, .2, 1),
              width .42s cubic-bezier(.4, 0, .2, 1), height .42s cubic-bezier(.4, 0, .2, 1);
}
.tour-card {
  position: fixed; width: min(340px, calc(100vw - 32px)); pointer-events: auto;
  background: var(--t-paper); color: var(--t-ink);
  border-radius: 16px; padding: 18px 20px 16px;
  box-shadow: 0 18px 50px rgba(0, 0, 0, .28), 0 2px 6px rgba(0, 0, 0, .08);
  transition: top .42s cubic-bezier(.4, 0, .2, 1), left .42s cubic-bezier(.4, 0, .2, 1), opacity .2s;
  animation: tour-in .28s ease-out;
}
.tour-card.docked { width: auto; }
@keyframes tour-in { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: none; } }
.no-motion .tour-hole, .no-motion .tour-card { transition: none; animation: none; }

.tour-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px; }
.tour-count { font-size: 11.5px; letter-spacing: .08em; color: var(--t-muted); font-variant-numeric: tabular-nums; }
.tour-skip {
  background: none; border: none; padding: 4px 0; cursor: pointer;
  font: inherit; font-size: 12px; color: var(--t-muted);
}
.tour-skip:hover { color: var(--t-ink); }
.tour-title {
  font-family: var(--font-display, Georgia, serif); font-weight: 400;
  font-size: 21px; line-height: 1.25; margin: 0 0 6px;
}
.tour-body { font-size: 14.5px; line-height: 1.5; margin: 0; color: var(--t-ink); }
.tour-try {
  font-size: 13px; color: var(--t-accent); margin: 10px 0 0; font-weight: 600;
  background: color-mix(in srgb, var(--t-accent) 10%, transparent);
  border-radius: 8px; padding: 6px 10px; display: inline-block;
}
.tour-dots { display: flex; gap: 5px; margin: 14px 0 12px; flex-wrap: wrap; }
.tour-dot { width: 6px; height: 6px; border-radius: 999px; background: var(--t-line); transition: background .2s, width .2s; }
.tour-dot.past { background: color-mix(in srgb, var(--t-accent) 45%, var(--t-line)); }
.tour-dot.on { background: var(--t-accent); width: 16px; }
.tour-actions { display: flex; justify-content: flex-end; gap: 8px; }
.tour-btn {
  font: inherit; font-size: 14px; cursor: pointer;
  background: var(--t-accent); color: var(--paper, #fff); border: 1px solid var(--t-accent);
  border-radius: 999px; padding: 8px 18px; min-height: 38px;
}
.tour-hole-guard { cursor: default; border-radius: 14px; }
.tour-btn.nudge { animation: tour-nudge .45s ease; }
@keyframes tour-nudge {
  0%, 100% { transform: none; }
  25% { transform: translateX(-4px); }
  50% { transform: translateX(4px); }
  75% { transform: translateX(-2px); }
}
.no-motion .tour-btn.nudge { animation: none; outline: 2px solid var(--t-accent); outline-offset: 2px; }
.tour-btn.ghost { background: transparent; color: var(--t-ink); border-color: var(--t-line); }
.tour-btn:focus-visible, .tour-skip:focus-visible { outline: 2px solid var(--t-accent); outline-offset: 2px; }
</style>
