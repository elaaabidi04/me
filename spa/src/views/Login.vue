<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'

const router = useRouter()
const auth = useAuthStore()

const form = ref({ email: '', password: '' })
const submitting = ref(false)

async function submit() {
  submitting.value = true
  try {
    await auth.login(form.value)
    router.push({ name: 'dashboard' })
  } catch {
    // auth.error is already set by the store; the template reads it below.
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="auth-page">
    <form class="auth-card" @submit.prevent="submit">
      <h1>welcome back.</h1>
      <label>
        email
        <input v-model="form.email" type="email" required autocomplete="email" />
      </label>
      <label>
        password
        <input v-model="form.password" type="password" required autocomplete="current-password" />
      </label>
      <p v-if="auth.error" class="error">{{ auth.error.email?.[0] || 'Login failed.' }}</p>
      <button type="submit" :disabled="submitting">{{ submitting ? '...' : 'log in' }}</button>
      <router-link to="/register">need an account?</router-link>
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
  font: inherit; font-size: 16px; /* 16px prevents iOS auto-zoom on focus */
}
button {
  background: #57735F; color: #fff; border: none;
  padding: 12px; border-radius: 999px; cursor: pointer; font-size: 15px;
  min-height: 44px; /* comfortable touch target */
}
a { font-size: 13px; color: #6B6F72; text-align: center; }
.error { color: #b3543f; font-size: 13px; margin: 0; }
</style>
