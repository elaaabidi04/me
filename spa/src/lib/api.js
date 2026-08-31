import axios from 'axios'

// The API and SPA are separate repos/origins, so auth relies on Sanctum's
// cookie-based SPA flow: fetch a CSRF cookie once, then every request below
// carries it automatically via withCredentials.
const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL || 'http://localhost:8000',
  withCredentials: true,
  withXSRFToken: true,
  headers: { Accept: 'application/json' },
})

export async function ensureCsrfCookie() {
  await api.get('/sanctum/csrf-cookie')
}

export default api
