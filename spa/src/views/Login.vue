<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'

const router = useRouter()
const auth = useAuthStore()

const form = ref({ email: '', password: '' })
const submitting = ref(false)

// 2026-10-15: public portfolio demo -- see api/config/demo.php.
const demoEnabled = import.meta.env.VITE_DEMO_MODE === 'true'
const demoLoading = ref(null)
// 2026-10-25: in demo mode the email/password form is hidden behind a
// small "admin" link -- it's only there for the owner (ADMIN_EMAIL).
const showAdminForm = ref(false)

async function tryDemo(role) {
  demoLoading.value = role
  try {
    const user = await auth.loginAsDemo(role)
    // replace, not push: the browser's back button then leaves the app
    // instead of bouncing off /login straight back into it (2026-11-10)
    router.replace({ name: user.role === 'therapist' ? 'therapist' : 'dashboard' })
  } catch {
    // auth.error is set by the store
  } finally {
    demoLoading.value = null
  }
}

function toggleAdmin() {
  showAdminForm.value = !showAdminForm.value
  auth.error = null
}

async function submit() {
  submitting.value = true
  try {
    await auth.login(form.value)
    if (auth.user?.is_admin) router.replace({ name: 'admin' })
    else router.replace({ name: auth.user?.role === 'therapist' ? 'therapist' : 'dashboard' })
  } catch {
    // auth.error is already set by the store; the template reads it below.
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="auth-page">
    <div class="auth-wrap">
      <router-link :to="{ name: 'home' }" class="back-link">← back</router-link>

      <!-- 2026-10-15: hosted portfolio demo (VITE_DEMO_MODE=true) -- the two
           seeded test accounts, plus a discreet admin login for the owner. -->
      <div v-if="demoEnabled && !showAdminForm" class="auth-card">
        <h1>try me.</h1>
        <p class="demo-intro">pick an account — both are filled with months of fictional data.</p>
        <button type="button" class="demo-choice" :disabled="demoLoading !== null" @click="tryDemo('client')">
          <span class="demo-choice-title">{{ demoLoading === 'client' ? 'preparing your copy…' : 'test user' }}</span>
          <span class="demo-choice-sub">journal, weekly reflection, monthly trends</span>
        </button>
        <button type="button" class="demo-choice" :disabled="demoLoading !== null" @click="tryDemo('therapist')">
          <span class="demo-choice-title">{{ demoLoading === 'therapist' ? 'preparing your copy…' : 'test therapist' }}</span>
          <span class="demo-choice-sub">caseload, crisis threads, client trends</span>
        </button>
        <p v-if="auth.error" class="error">{{ auth.error.email?.[0] }}</p>
        <p class="demo-note">
          you get your own private copy — other visitors can't see what you write. it's deleted after 24h and visible to the developer, so please don't enter real personal details.
        </p>
        <button type="button" class="admin-link" @click="toggleAdmin">.</button>
      </div>

      <form v-else class="auth-card" @submit.prevent="submit">
        <h1>{{ demoEnabled ? 'admin.' : 'welcome back.' }}</h1>
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
        <button v-if="demoEnabled" type="button" class="admin-link" @click="toggleAdmin">← back to test accounts</button>
        <router-link v-else to="/register">need an account?</router-link>
      </form>
    </div>
  </div>
</template>

<style scoped>
.auth-page {
  display: flex; justify-content: center; align-items: center;
  min-height: 100svh;
  background: #F1F2F0;
  padding: 24px 16px;
}
.auth-wrap { width: 100%; max-width: 360px; display: flex; flex-direction: column; gap: 10px; }
.back-link {
  align-self: flex-start;
  font-size: 13.5px; color: #6B6F72; text-decoration: none;
  padding: 6px 2px;
}
.back-link:hover { color: #2B2F31; }
.auth-card {
  display: flex; flex-direction: column; gap: 14px;
  background: #FBFBFA; padding: 28px 24px;
  border-radius: 16px;
  width: 100%;
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
.demo-intro { font-size: 14px; color: #6B6F72; margin: 0 0 4px; line-height: 1.45; }
.demo-choice {
  display: flex; flex-direction: column; align-items: flex-start; gap: 3px;
  padding: 14px 20px; border-radius: 14px; text-align: left;
}
.demo-choice-title { font-size: 16px; font-weight: 600; }
.demo-choice-sub { font-size: 12.5px; opacity: .85; }
.demo-choice:disabled { opacity: .6; cursor: default; }
.demo-note { font-size: 12px; color: #6B6F72; text-align: center; margin: 4px 0 0; line-height: 1.5; }
.admin-link {
  align-self: center;
  background: none; color: #9A9EA1; min-height: 0;
  padding: 4px 8px; font-size: 12px; font-family: inherit;
}
.admin-link:hover { color: #57735F; }
</style>
