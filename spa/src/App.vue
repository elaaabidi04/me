<script setup>
import { useAuthStore } from './stores/auth'

// 2026-10-15: shown only while logged into a public demo account
// (user.is_demo comes from the API and is always false unless DEMO_MODE=true).
const auth = useAuthStore()
</script>

<template>
  <div v-if="auth.user?.is_demo" class="demo-banner" role="status">
    <span class="demo-long">Demo — your own private copy with fictional data, deleted after 24 hours. Everything you write is visible to the developer.</span>
    <!-- 2026-11-07: one line on phones instead of two -->
    <span class="demo-short">Demo · deleted after 24h · visible to the developer</span>
  </div>
  <router-view />
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
</style>
