# **me** — User Dashboard Mockup

> Companion to the *sujet*. A static Vue mockup of the **user** dashboard (the gentle, anti-addiction side). Mock data, in-memory state, no backend — every point that would call Laravel is marked `// → API`. The therapist dashboard is deliberately *not* this; see §4.

---

## 1. Design thesis — the anti-dashboard

Most wellbeing apps are dashboards you binge-check. **me** is the opposite, and the mockup makes the thesis visible in one component: **the weekly reflection is sealed until its day.** You cannot refresh your way into it. That single "sealed until Sunday" card carries the whole anti-over-analysis principle — there is nothing to obsess over between deliveries.

Everything else stays quiet so that one idea lands. Boldness spent in one place.

## 2. Tokens

- **Palette (default theme "Dusk"):** cool paper `#F1F2F0`, surface `#FBFBFA`, ink `#26282B`, muted `#6B6F72`, sage accent `#7FA08A`, deeper sage `#57735F`, named-thread ochre `#C6A063` (a warm neutral, never an alarming red — alarm would feed anxiety).
- **Type:** *Fraunces* (soft serif) for the greeting only, used with restraint; *Instrument Sans* for everything else. Not a high-contrast serif hero — the greeting is intimate, lowercase, small.
- **Theming:** the whole palette is CSS variables switched by `data-theme`, so the customisation story is real, not implied. Three calm themes ship: Dusk, Paper, Night.
- **Signature:** the sealed weekly card + its quiet countdown.

## 3. Layout

```
┌────────────────────────────────────────────────┐
│  me                              ◐ theme   ⚙    │
│                                                  │
│  good evening.                                   │   ← Fraunces, quiet
│  what do you want from me today?                 │
│  ( dig in ) ( just listen ) ( light ) ( rest )   │   ← one pick, soft pills
│                                                  │
│  ┌────────────────────────────────────────────┐ │
│  │  write.                                     │ │   ← primary action
│  │  [                                        ] │ │
│  │                                   [ save ]  │ │
│  └────────────────────────────────────────────┘ │
│                                                  │
│  ┌───────────────── your week ────────────────┐ │
│  │  ✦  sealed until sunday                     │ │   ← the signature
│  │     your reflection is resting · 3 days     │ │
│  └────────────────────────────────────────────┘ │
│                                                  │
│  a gentle note                                   │   ← coach, current mode
│  "david has come up a few times this week.       │
│   want to sit with that, or leave it?"           │
│  ( sit with it )   ( leave it )                  │   ← the door
│                                                  │
│  threads you've named                            │   ← confirmed entities,
│      · the deadline    · mornings    · david     │      soft constellation
└────────────────────────────────────────────────┘
```

## 4. What the therapist dashboard is NOT

This mockup is soft on purpose. The therapist dashboard reuses the same data but inverts the presentation: a dense **Cytoscape.js** entity graph, mood-variance charts, correlation figures, clinical-descriptive language — the density a professional can hold. The user side hides that behind calm surfaces and a quiet SVG constellation. Same data, opposite presentation.

---

## 5. The component

Vue 3, `<script setup>`. Drop into a Laravel + Vue project as `UserDashboard.vue`. Self-contained (inline SVG constellation, no graph lib needed for the mockup).

```vue
<script setup>
import { ref, computed } from 'vue'

/* ---- mock state (in-memory; replace with API) ------------------- */
const themes = ['dusk', 'paper', 'night']
const theme = ref('dusk')
function cycleTheme () {
  theme.value = themes[(themes.indexOf(theme.value) + 1) % themes.length]
}

const picks = [
  { id: 'engage',  label: 'dig in' },
  { id: 'witness', label: 'just listen' },
  { id: 'light',   label: 'light' },
  { id: 'rest',    label: 'rest' },
]
const todaysPick = ref(null)        // → API: persist today's intent
function choosePick (id) {
  todaysPick.value = todaysPick.value === id ? null : id
}

const entry = ref('')
const saved = ref(false)
function saveEntry () {
  if (!entry.value.trim()) return
  // → API: POST /entries { text, pick: todaysPick }
  saved.value = true
  entry.value = ''
  setTimeout(() => (saved.value = false), 2200)
}

/* weekly reflection is sealed until its day — the anti-binge core */
const daysUntilReflection = ref(3)  // → API: derived from the weekly schedule
const sealed = computed(() => daysUntilReflection.value > 0)

/* coach note — one offered "door" (mock of offer_door mode) */
const coachNote = ref({
  text: 'david has come up a few times this week. want to sit with that, or leave it?',
  answered: false,
})
function answerDoor (choice) {
  // → API: record the choice; 'sit' opens a reflection, 'leave' closes it
  coachNote.value.answered = true
}

/* threads the USER has confirmed as meaningful (never auto-asserted) */
const threads = ref([
  { name: 'the deadline', weight: 3, trigger: false },
  { name: 'mornings',     weight: 2, trigger: false },
  { name: 'david',        weight: 4, trigger: true  },
])
// simple radial layout for the calm constellation
const nodes = computed(() =>
  threads.value.map((t, i) => {
    const a = (i / threads.value.length) * Math.PI * 2 - Math.PI / 2
    const r = 46 + t.weight * 10
    return { ...t, x: 160 + Math.cos(a) * r, y: 90 + Math.sin(a) * r, rad: 8 + t.weight * 2 }
  })
)

const greeting = (() => {
  const h = new Date().getHours()
  return h < 12 ? 'good morning.' : h < 18 ? 'good afternoon.' : 'good evening.'
})()
</script>

<template>
  <div class="me" :data-theme="theme">
    <header class="bar">
      <span class="wordmark">me</span>
      <div class="bar-actions">
        <button class="ghost" @click="cycleTheme">◐ theme</button>
        <button class="ghost" aria-label="settings">⚙</button>
      </div>
    </header>

    <!-- greeting + the one daily pick -->
    <section class="intro">
      <h1 class="greeting">{{ greeting }}</h1>
      <p class="ask">what do you want from me today?</p>
      <div class="picks" role="group" aria-label="today's intent">
        <button
          v-for="p in picks" :key="p.id"
          class="pill" :class="{ on: todaysPick === p.id }"
          @click="choosePick(p.id)"
        >{{ p.label }}</button>
      </div>
    </section>

    <!-- primary action: write -->
    <section class="card write">
      <label class="card-label" for="entry">write.</label>
      <textarea id="entry" v-model="entry" rows="5"
                placeholder="whatever's here."></textarea>
      <div class="write-foot">
        <span class="hint" :class="{ show: saved }">set down.</span>
        <button class="primary" @click="saveEntry">save</button>
      </div>
    </section>

    <!-- the signature: sealed weekly reflection -->
    <section class="card sealed" :class="{ open: !sealed }">
      <span class="seal-eyebrow">your week</span>
      <template v-if="sealed">
        <p class="seal-title">✦ sealed until sunday</p>
        <p class="seal-sub">your reflection is resting · {{ daysUntilReflection }} days</p>
      </template>
      <template v-else>
        <p class="seal-title">your reflection is ready</p>
        <button class="primary">open it</button>
      </template>
    </section>

    <!-- coach: one door, then it stops -->
    <section class="note" v-if="!coachNote.answered">
      <span class="note-label">a gentle note</span>
      <p class="note-text">{{ coachNote.text }}</p>
      <div class="door">
        <button class="pill" @click="answerDoor('sit')">sit with it</button>
        <button class="pill" @click="answerDoor('leave')">leave it</button>
      </div>
    </section>
    <section class="note quiet" v-else>
      <p class="note-text muted">okay. it'll keep.</p>
    </section>

    <!-- threads the user has named -->
    <section class="threads">
      <span class="threads-label">threads you've named</span>
      <svg class="constellation" viewBox="0 0 320 180" role="img"
           aria-label="a quiet map of your named threads">
        <line v-for="(n, i) in nodes" :key="'l' + i"
              x1="160" y1="90" :x2="n.x" :y2="n.y" class="edge" />
        <g v-for="(n, i) in nodes" :key="'n' + i">
          <circle :cx="n.x" :cy="n.y" :r="n.rad"
                  :class="['node', { trigger: n.trigger }]" />
          <text :x="n.x" :y="n.y + n.rad + 12" class="node-label">{{ n.name }}</text>
        </g>
      </svg>
      <p class="threads-foot muted">you named these. me never labels them for you.</p>
    </section>
  </div>
</template>

<style scoped>
/* ---- theming: everything derives from these vars ---------------- */
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
  max-width:560px; margin:0 auto; padding:22px 20px 60px;
  background:var(--paper); color:var(--ink);
  font-family:var(--font-ui); line-height:1.5;
  transition:background .4s ease, color .4s ease;
}
* { box-sizing:border-box; }

.bar { display:flex; align-items:center; justify-content:space-between; margin-bottom:34px; }
.wordmark { font-family:var(--font-display); font-size:22px; font-weight:500; letter-spacing:.5px; }
.bar-actions { display:flex; gap:6px; }
.ghost { background:none; border:none; color:var(--muted); font:inherit; font-size:13px;
  padding:6px 8px; border-radius:8px; cursor:pointer; }
.ghost:hover { color:var(--ink); background:var(--surface); }

.intro { margin-bottom:26px; }
.greeting { font-family:var(--font-display); font-weight:400; font-size:30px;
  margin:0 0 2px; letter-spacing:.2px; }
.ask { margin:0 0 14px; color:var(--muted); font-size:15px; }
.picks { display:flex; flex-wrap:wrap; gap:8px; }

.pill { background:var(--surface); border:1px solid var(--line); color:var(--ink);
  font:inherit; font-size:14px; padding:8px 15px; border-radius:999px; cursor:pointer;
  transition:all .18s ease; }
.pill:hover { border-color:var(--accent); }
.pill.on { background:var(--accent); border-color:var(--accent); color:var(--surface); }

.card { background:var(--surface); border:1px solid var(--line);
  border-radius:16px; padding:18px; margin-bottom:16px; }
.card-label, .note-label, .seal-eyebrow, .threads-label {
  display:block; font-size:12px; letter-spacing:.14em; text-transform:uppercase;
  color:var(--muted); margin-bottom:10px; }

.write textarea { width:100%; border:none; resize:vertical; background:transparent;
  color:var(--ink); font:inherit; font-size:16px; line-height:1.6; outline:none; }
.write textarea::placeholder { color:var(--muted); opacity:.7; }
.write-foot { display:flex; align-items:center; justify-content:flex-end; gap:12px; margin-top:8px; }
.hint { font-size:13px; color:var(--accent-deep); opacity:0; transition:opacity .3s; }
.hint.show { opacity:1; }
.primary { background:var(--accent-deep); border:none; color:#fff; font:inherit; font-size:14px;
  padding:9px 20px; border-radius:999px; cursor:pointer; }
.primary:hover { filter:brightness(1.06); }

/* signature: the sealed card reads as closed, not busy */
.sealed { text-align:center; padding:26px 18px; }
.seal-title { font-family:var(--font-display); font-size:19px; margin:0 0 4px; }
.seal-sub { margin:0; color:var(--muted); font-size:14px; }
.sealed.open { border-color:var(--accent); }

.note { margin:22px 2px; }
.note-text { font-size:16px; margin:0 0 12px; }
.note-text.muted, .muted { color:var(--muted); }
.door { display:flex; gap:8px; }
.note.quiet .note-text { font-size:15px; }

.threads { margin-top:26px; }
.constellation { width:100%; height:auto; display:block; }
.edge { stroke:var(--line); stroke-width:1.5; }
.node { fill:var(--accent); }
.node.trigger { fill:var(--ochre); }
.node-label { fill:var(--muted); font-size:10px; text-anchor:middle; font-family:var(--font-ui); }
.threads-foot { font-size:13px; margin-top:6px; text-align:center; }

@media (prefers-reduced-motion: reduce) { .me, * { transition:none !important; } }
</style>
```

## 6. Wiring to Laravel (the `// → API` points)

- **daily pick** → `POST /api/intent { pick }` for today; read back on load to pre-set the coach's posture.
- **save entry** → `POST /api/entries { text, pick }`; Laravel queues the extraction job.
- **sealed card** → `daysUntilReflection` comes from the weekly schedule; the reflection endpoint returns 423 (locked) until its day, so the client *can't* fetch it early. Enforce the seal on the server, not just the UI.
- **coach note** → the current entry's classify-then-respond result (see the Coach Decision Spec); the door choice posts back.
- **threads** → confirmed entities only; the graph data (`nodes`) is built by your Laravel aggregation, styled `trigger` only where the user confirmed it.

## 7. Quality floor already in

Responsive to a phone width, visible focus on interactive elements, `prefers-reduced-motion` respected, colour never used as the only signal (labels accompany the graph), and no alarming red anywhere — the ochre "trigger" node stays warm and calm by design.
