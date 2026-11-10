import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '../stores/auth'

const routes = [
  // 2026-08-20: public marketing homepage, lives at the actual root instead
  // of a standalone static site -- so it shares the app's real router,
  // theme system, and auth-aware CTAs. Logged-in visitors are bounced off
  // it below (same pattern as guestOnly), logged-out visitors just see it.
  { path: '/', alias: '/home', name: 'home', component: () => import('../views/Home.vue') },
  // 2026-10-25: owner-only feed of what visitors wrote in the demo accounts.
  { path: '/admin', name: 'admin', component: () => import('../views/Admin.vue'), meta: { requiresAuth: true, requiresAdmin: true } },
  { path: '/login',      name: 'login',      component: () => import('../views/Login.vue'),      meta: { guestOnly: true } },
  { path: '/register',   name: 'register',   component: () => import('../views/Register.vue'),   meta: { guestOnly: true } },
  { path: '/onboarding', name: 'onboarding', component: () => import('../views/Onboarding.vue'), meta: { requiresAuth: true } },
  { path: '/dashboard',   name: 'dashboard',  component: () => import('../views/Dashboard.vue'),  meta: { requiresAuth: true } },
  { path: '/journal',    name: 'journal',    component: () => import('../views/Journal.vue'),    meta: { requiresAuth: true } },
  { path: '/calendar',   name: 'calendar',   component: () => import('../views/Calendar.vue'),   meta: { requiresAuth: true } },
  { path: '/analysis/:id', name: 'entry-analysis', component: () => import('../views/EntryAnalysis.vue'), meta: { requiresAuth: true } },
  { path: '/memories',   name: 'memories',   component: () => import('../views/Memories.vue'),   meta: { requiresAuth: true } },
  { path: '/monthly',    name: 'monthly',    component: () => import('../views/MonthlyDashboard.vue'), meta: { requiresAuth: true } },
  // 2026-08-18: split into two genuinely different pages (was one shared
  // Therapist.vue branching on role -- user: "they shouldn't have the same
  // interface"). 'therapist' is the practice-management dashboard
  // (TherapistDashboard.vue), therapist-only. 'my-therapist' is the
  // client's own linked-therapist page (MyTherapist.vue), client-only.
  // Cross-role guard below sends each role to its own route.
  { path: '/therapist',    name: 'therapist',    component: () => import('../views/TherapistDashboard.vue'), meta: { requiresAuth: true } },
  { path: '/my-therapist', name: 'my-therapist', component: () => import('../views/MyTherapist.vue'),        meta: { requiresAuth: true } },
  // 2026-08-17 redesign: one persistent thread per link, so this no longer
  // takes a safetyEventId -- a client always has at most one, a therapist
  // picks a client via ?client_id= (Therapist.vue links here per row).
  { path: '/therapist/chat', name: 'crisis-thread', component: () => import('../views/CrisisThread.vue'), meta: { requiresAuth: true } },
  // 2026-08-18: share-to-therapist history/inbox -- a client's own share
  // history (?client_id absent) or a therapist's read-only view of one
  // linked client's active shares (?client_id=, ?name= for the header).
  { path: '/therapist/insights', name: 'shared-insights', component: () => import('../views/SharedInsights.vue'), meta: { requiresAuth: true } },
  { path: '/my-insights',        name: 'my-insights',     component: () => import('../views/SharedInsights.vue'), meta: { requiresAuth: true } },
  // 2026-08-18: therapist-assigned tasks -- same ?client_id=(&name=) handoff
  // as shared-insights above.
  { path: '/therapist/tasks', name: 'tasks', component: () => import('../views/Tasks.vue'), meta: { requiresAuth: true } },
  // 2026-08-18: scheduled sessions -- same ?client_id=(&name=) handoff.
  { path: '/therapist/sessions', name: 'sessions', component: () => import('../views/Sessions.vue'), meta: { requiresAuth: true } },
  // 2026-08-19: pre-session briefs -- client writes, therapist reads via ?client_id=(&name=).
  { path: '/therapist/briefs', name: 'session-briefs', component: () => import('../views/SessionBriefs.vue'), meta: { requiresAuth: true } },
  // 2026-08-19: live trend view -- therapist-only, requires live_trends_enabled on the link.
  // Reached from the "trends ●" chip in TherapistDashboard.vue's roster grid.
  { path: '/therapist/trends', name: 'client-trends', component: () => import('../views/ClientTrends.vue'), meta: { requiresAuth: true } },
  // Full activity feed -- all events across caseload for the last 14 days.
  { path: '/therapist/activity', name: 'therapist-activity', component: () => import('../views/TherapistActivity.vue'), meta: { requiresAuth: true } },
]

const router = createRouter({
  history: createWebHistory(),
  routes,
})

router.beforeEach(async (to) => {
  const auth = useAuthStore()

  if (auth.user === null && !auth._checked) {
    await auth.fetchUser()
    auth._checked = true
  }

  // The homepage is public, but a logged-in visitor landing on '/' means
  // they followed an old bookmark/link -- send them straight into the app
  // instead of showing marketing copy to someone who already has an
  // account. Role-appropriate landing (therapist vs client) is resolved
  // below, same as the guestOnly redirect just does for 'dashboard'.
  // 2026-11-10: the admin account has no journal of its own -- its home is
  // /admin, wherever a link or a redirect would otherwise drop it
  if (auth.user?.is_admin && ['home', 'dashboard', 'onboarding', 'login', 'register'].includes(to.name)) {
    return { name: 'admin' }
  }

  // 2026-11-10: not for demo visitors -- for them "back" from the app is
  // the landing page they came from; redirecting trapped the back button.
  if (to.name === 'home' && auth.isAuthenticated && !auth.user?.is_demo) {
    return { name: auth.user?.role === 'therapist' ? 'therapist' : 'dashboard' }
  }

  // 2026-10-15: hosted portfolio demo (VITE_DEMO_MODE=true) -- there is
  // no sign-up; every "Start journaling" / "Join as a therapist" link ends
  // up on the login page, which in demo mode is just the two test-account
  // buttons (see Login.vue).
  if (import.meta.env.VITE_DEMO_MODE === 'true' && to.name === 'register' && !auth.isAuthenticated) {
    return { name: 'login' }
  }

  if (to.meta.requiresAuth && !auth.isAuthenticated) {
    return { name: 'login' }
  }
  if (to.meta.requiresAdmin && !auth.user?.is_admin) {
    return { name: 'home' }
  }
  // 2026-11-10: a demo visitor going back to /login sees the two test
  // buttons again (switch user <-> therapist) instead of being bounced
  if (to.meta.guestOnly && auth.isAuthenticated && !(auth.user?.is_demo && to.name === 'login')) {
    return { name: 'dashboard' }
  }

  // Redirect new users to onboarding before they reach any authenticated page,
  // unless they're already heading there. Therapist accounts have no
  // preferences row at all (onboarding is a journaling-only concept — see
  // AuthController::register()), so optional chaining here already reads
  // as "nothing set" and quietly skips this redirect for them; the role
  // check below is still explicit rather than relying on that alone.
  if (
    auth.isAuthenticated &&
    auth.user?.role !== 'therapist' &&
    to.name !== 'onboarding' &&
    auth.user?.preferences?.onboarding_completed === false
  ) {
    return { name: 'onboarding' }
  }

  // A therapist's home is the therapist portal, not the journaling
  // dashboard — send them there instead of the empty/irrelevant journaling
  // UI (2026-08-17, see docs/therapist-bridge.md).
  if (
    auth.isAuthenticated &&
    auth.user?.role === 'therapist' &&
    ['dashboard', 'journal', 'calendar', 'memories', 'monthly', 'onboarding', 'entry-analysis'].includes(to.name)
  ) {
    return { name: 'therapist' }
  }

  // 2026-08-18: 'therapist' and 'my-therapist' are now two different
  // pages, not one shared file -- keep each role on its own side. A
  // client hitting /therapist (e.g. a stale bookmark) lands on their own
  // page instead of a therapist dashboard that isn't theirs, and vice
  // versa for a therapist hitting /my-therapist.
  if (auth.isAuthenticated && to.name === 'therapist' && auth.user?.role !== 'therapist') {
    return { name: 'my-therapist' }
  }
  if (auth.isAuthenticated && to.name === 'my-therapist' && auth.user?.role === 'therapist') {
    return { name: 'therapist' }
  }

  // Clients hitting the therapist-scoped insights URL land on their own
  // insights page instead; therapists always supply ?client_id= so they pass.
  if (auth.isAuthenticated && to.name === 'shared-insights' && auth.user?.role !== 'therapist' && !to.query.client_id) {
    return { name: 'my-insights' }
  }
})

// 2026-11-10: after a redeploy, a tab that was already open still asks for
// the OLD build's page files (their names change every build), the server
// rightly 404s them, and the navigation died silently -> blank page. Reload
// once at the target URL so the browser picks up the new build.
const CHUNK_ERROR = /dynamically imported module|Couldn.t resolve component|Importing a module script failed|Unable to preload|Failed to fetch|error loading dynamically|Loading (CSS )?chunk|ChunkLoadError/i
function reloadOnce(url) {
  let last = 0
  try { last = Number(sessionStorage.getItem('chunk-reload-at') || 0) } catch {}
  if (Date.now() - last < 10000) return false   // already reloaded just now: don't loop
  try { sessionStorage.setItem('chunk-reload-at', String(Date.now())) } catch {}
  window.location.assign(url)
  return true
}
router.onError((err, to) => {
  if (CHUNK_ERROR.test(String(err?.message || err))) reloadOnce(to?.fullPath || window.location.href)
})
// Vite reports a stale CSS/JS file here ("Unable to preload CSS for …").
// Give the router a moment to report it with the target page; otherwise
// reload where we are.
window.addEventListener('vite:preloadError', (event) => {
  event.preventDefault()
  setTimeout(() => reloadOnce(window.location.href), 300)
})

export default router
