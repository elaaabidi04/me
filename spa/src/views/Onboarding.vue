<script setup>
import { ref, computed } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import api from '../lib/api'

const router = useRouter()
const auth = useAuthStore()

const step = ref(0)
const saving = ref(false)

const answers = ref({
  tone: null,
  nudge_level: null,
  language: null,
  product_mode: null,
  top_values: [],
})

const questions = [
  {
    key: 'tone',
    prompt: 'how would you like me to be with you?',
    options: [
      { value: 'gentle', label: 'gentle', detail: 'soft, open-ended, no pushing' },
      { value: 'direct', label: 'direct', detail: 'clear, honest, even if it stings a little' },
    ],
  },
  {
    key: 'nudge_level',
    prompt: 'how much should i nudge you to go deeper?',
    options: [
      { value: 1, label: 'rarely', detail: 'let me come to things on my own' },
      { value: 2, label: 'sometimes', detail: 'a gentle prompt when it feels right' },
      { value: 3, label: 'often',  detail: 'keep helping me look closer' },
    ],
  },
  {
    key: 'language',
    prompt: 'which language feels most like you?',
    options: [
      { value: 'en',     label: 'english' },
      { value: 'fr',     label: 'français' },
      { value: 'darija', label: 'darija' },
      { value: 'ar',     label: 'عربية' },
    ],
  },
  {
    key: 'product_mode',
    prompt: 'are you using this on your own, or alongside a therapist?',
    options: [
      { value: 'solo',   label: 'on my own' },
      { value: 'bridge', label: 'alongside a therapist' },
    ],
  },
  {
    key: 'top_values',
    prompt: 'pick up to 3 things that matter most to you right now.',
    multi: true,
    options: [
      'family', 'learning', 'freedom', 'creativity',
      'health', 'achievement', 'love', 'connection', 'growth', 'security',
    ],
  },
]

const current = computed(() => questions[step.value])
const isLast = computed(() => step.value === questions.length - 1)

function pick(value) {
  const q = current.value
  if (q.multi) {
    const arr = answers.value.top_values
    const idx = arr.indexOf(value)
    if (idx >= 0) arr.splice(idx, 1)
    else if (arr.length < 3) arr.push(value)
  } else {
    answers.value[q.key] = value
  }
}

function isSelected(value) {
  const q = current.value
  if (q.multi) return answers.value.top_values.includes(value)
  return answers.value[q.key] === value
}

function next() {
  if (!isLast.value) { step.value++; return }
  submit()
}

async function submit(skip = false) {
  saving.value = true
  try {
    const payload = skip ? { skip: true } : { ...answers.value }
    await api.post('/api/onboarding', payload)
    await auth.fetchUser()
    router.push('/')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="onboard">
    <div class="card">
      <div class="progress">
        <span
          v-for="(_, i) in questions" :key="i"
          class="dot" :class="{ active: i === step, done: i < step }"
        />
      </div>

      <p class="q">{{ current.prompt }}</p>

      <!-- single-select -->
      <div v-if="!current.multi" class="opts">
        <button
          v-for="opt in current.options" :key="opt.value"
          class="opt" :class="{ on: isSelected(opt.value) }"
          @click="pick(opt.value)"
        >
          <span class="opt-label">{{ opt.label }}</span>
          <span v-if="opt.detail" class="opt-detail">{{ opt.detail }}</span>
        </button>
      </div>

      <!-- multi-select (top_values) -->
      <div v-else class="opts multi">
        <button
          v-for="v in current.options" :key="v"
          class="opt chip" :class="{ on: isSelected(v) }"
          @click="pick(v)"
        >{{ v }}</button>
      </div>

      <div class="foot">
        <button class="ghost" @click="submit(true)">skip all</button>
        <button class="primary" :disabled="saving" @click="next">
          {{ saving ? '…' : isLast ? 'done' : 'next' }}
        </button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.onboard {
  min-height: 100svh;
  display: flex; align-items: center; justify-content: center;
  background: #F1F2F0; font-family: 'Instrument Sans', system-ui, sans-serif;
  padding: 24px;
}
.card {
  background: #FBFBFA; border: 1px solid #E3E4E1; border-radius: 20px;
  padding: 36px 32px 28px; width: 100%; max-width: 480px;
}
.progress { display: flex; gap: 6px; margin-bottom: 28px; }
.dot {
  width: 6px; height: 6px; border-radius: 50%;
  background: #E3E4E1; transition: background .2s;
}
.dot.done  { background: #7FA08A; }
.dot.active { background: #57735F; }

.q { font-size: 18px; color: #26282B; margin: 0 0 22px; line-height: 1.4; }

.opts { display: flex; flex-direction: column; gap: 10px; }
.opts.multi { flex-direction: row; flex-wrap: wrap; gap: 8px; }

.opt {
  background: #F1F2F0; border: 1px solid #E3E4E1; border-radius: 12px;
  padding: 12px 16px; cursor: pointer; text-align: left;
  transition: all .15s ease; color: #26282B;
  font-family: inherit;
}
.opt:hover { border-color: #7FA08A; }
.opt.on    { background: #7FA08A; border-color: #7FA08A; color: #FBFBFA; }
.opt.chip  { border-radius: 999px; padding: 8px 14px; font-size: 14px; }

.opt-label { display: block; font-size: 15px; }
.opt-detail { display: block; font-size: 12px; opacity: .65; margin-top: 2px; }
.opt.on .opt-detail { opacity: .8; }

.foot { display: flex; justify-content: space-between; align-items: center; margin-top: 28px; }
.ghost {
  background: none; border: none; color: #6B6F72; font-family: inherit;
  font-size: 13px; padding: 8px 4px; cursor: pointer;
}
.ghost:hover { color: #26282B; }
.primary {
  background: #57735F; border: none; color: #fff;
  font-family: inherit; font-size: 14px;
  padding: 10px 24px; border-radius: 999px; cursor: pointer;
}
.primary:disabled { opacity: .45; cursor: default; }
.primary:hover:not(:disabled) { filter: brightness(1.08); }
</style>
