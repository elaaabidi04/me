import { defineStore } from 'pinia'
import api, { ensureCsrfCookie } from '../lib/api'

export const useAuthStore = defineStore('auth', {
  state: () => ({
    user: null,
    loading: false,
    error: null,
  }),
  getters: {
    isAuthenticated: (state) => state.user !== null,
  },
  actions: {
    async fetchUser() {
      try {
        const { data } = await api.get('/api/user')
        this.user = data
      } catch {
        this.user = null
      }
    },
    async login(credentials) {
      this.loading = true
      this.error = null
      try {
        await ensureCsrfCookie()
        await api.post('/api/login', credentials)
        await this.fetchUser()
      } catch (e) {
        this.error = e.response?.data?.errors ?? { email: ['Login failed'] }
        throw e
      } finally {
        this.loading = false
      }
    },
    async register(payload) {
      this.loading = true
      this.error = null
      try {
        await ensureCsrfCookie()
        const { data } = await api.post('/api/register', payload)
        this.user = data
      } catch (e) {
        this.error = e.response?.data?.errors ?? {}
        throw e
      } finally {
        this.loading = false
      }
    },
    async logout() {
      await api.post('/api/logout')
      this.user = null
    },
  },
})
