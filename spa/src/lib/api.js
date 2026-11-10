import axios from 'axios'

// The API and SPA are separate repos/origins, so auth relies on Sanctum's
// cookie-based SPA flow: fetch a CSRF cookie once, then every request below
// carries it automatically via withCredentials.
const api = axios.create({
  // 2026-11-10: production builds call the API on the SAME address as the
  // page -- Vercel forwards /api and /sanctum to Render (spa/vercel.json),
  // or Laravel serves the SPA itself (Dockerfile). Set VITE_API_URL to
  // override; local dev defaults to the Laravel server.
  baseURL: import.meta.env.VITE_API_URL ?? (import.meta.env.PROD ? '' : 'http://localhost:8000'),
  withCredentials: true,
  withXSRFToken: true,
  headers: { Accept: 'application/json' },
})

export async function ensureCsrfCookie() {
  await api.get('/sanctum/csrf-cookie')
}

export default api
