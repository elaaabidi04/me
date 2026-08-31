<script setup>
import { ref } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useAuthStore } from '../stores/auth'

const router = useRouter()
const route = useRoute()
const auth = useAuthStore()

// role added 2026-08-17 -- see docs/therapist-bridge.md. Defaults to
// 'client' (matches the users table's own default) so this reads as an
// opt-in choice, not a decision every signer-up is forced to make.
// 2026-08-20: Home.vue's "Join as a therapist" CTA links here with
// ?role=therapist so that click pre-selects the toggle below instead of
// making a therapist re-click something they already told us on the
// homepage.
const form = ref({
  name: '', email: '', password: '', password_confirmation: '',
  role: route.query.role === 'therapist' ? 'therapist' : 'client',
})
const submitting = ref(false)

async function submit() {
  submitting.value = true
  try {
    await auth.register(form.value)
    router.push({ name: 'dashboard' })
  } catch {
    // errors surface via auth.error in the template
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="auth-page">
    <form class="auth-card" @submit.prevent="submit">
      <h1>start journaling.</h1>
      <label>
        name
        <input v-model="form.name" type="text" required />
      </label>
      <label>
        email
        <input v-model="form.email" type="email" required autocomplete="email" />
      </label>
      <label>
        password
        <input v-model="form.password" type="password" required autocomplete="new-password" />
      </label>
      <label>
        confirm password
        <input v-model="form.password_confirmation" type="password" required autocomplete="new-password" />
      </label>
      <div class="role-pick">
        <button
          type="button" class="role-btn" :class="{ on: form.role === 'client' }"
          @click="form.role = 'client'"
        >i'm journaling for myself</button>
        <button
          type="button" class="role-btn" :class="{ on: form.role === 'therapist' }"
          @click="form.role = 'therapist'"
        >i'm a therapist</button>
      </div>
      <p v-if="auth.error && Object.keys(auth.error).length" class="error">
        {{ Object.values(auth.error)[0]?.[0] }}
      </p>
      <button type="submit" :disabled="submitting">{{ submitting ? '...' : 'create account' }}</button>
      <router-link to="/login">already have an account?</router-link>
    </form>
  </div>
</template>

<style scoped>
.auth-page {
  display: flex; justify-content: center; align-items: center;
  min-height: 100svh;
  background: #F1F2F0;
  padding: 24px 16px;
}
.auth-card {
  display: flex; flex-direction: column; gap: 14px;
  background: #FBFBFA; padding: 28px 24px;
  border-radius: 16px;
  width: 100%; max-width: 360px;
}
h1 { font-size: 22px; margin: 0 0 4px; }
label { display: flex; flex-direction: column; gap: 5px; font-size: 13px; color: #6B6F72; }
input {
  padding: 10px 12px; border: 1px solid #E3E4E1; border-radius: 8px;
  font: inherit; font-size: 16px;
}
button {
  background: #57735F; color: #fff; border: none;
  padding: 12px; border-radius: 999px; cursor: pointer; font-size: 15px;
  min-height: 44px;
}
.role-pick { display: flex; flex-direction: column; gap: 6px; }
.role-btn {
  background: #FBFBFA; color: #6B6F72; border: 1px solid #E3E4E1;
  padding: 10px 12px; border-radius: 8px; cursor: pointer; font-size: 13.5px;
  text-align: left; min-height: auto; transition: all .15s ease;
}
.role-btn.on { background: #F1F2F0; color: #26282B; border-color: #57735F; }
a { font-size: 13px; color: #6B6F72; text-align: center; }
.error { color: #b3543f; font-size: 13px; margin: 0; }
</style>
