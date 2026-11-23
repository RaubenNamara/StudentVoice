import { createRouter, createWebHistory } from 'vue-router'
import { useSession } from '@/stores/session'

declare module 'vue-router' {
  interface RouteMeta {
    /** 'student': signed in with a voting number; 'admin': a signed-in admin */
    needs?: 'student' | 'admin'
    super?: boolean
    title?: string
  }
}

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  scrollBehavior: (_to, _from, saved) => saved ?? { top: 0 },
  routes: [
    // Students
    { path: '/', name: 'vote-login', component: () => import('@/pages/vote/VoteLoginPage.vue') },
    { path: '/vote', name: 'vote-polls', component: () => import('@/pages/vote/VotePollsPage.vue'), meta: { needs: 'student', title: 'My polls' } },
    { path: '/vote/:id(\\d+)', name: 'vote-poll', component: () => import('@/pages/vote/VotePollPage.vue'), meta: { needs: 'student', title: 'Vote' } },

    // Admins
    { path: '/admin/login', name: 'admin-login', component: () => import('@/pages/admin/AdminLoginPage.vue'), meta: { title: 'Admin sign in' } },
    { path: '/admin/password', name: 'change-password', component: () => import('@/pages/admin/ChangePasswordPage.vue'), meta: { needs: 'admin', title: 'Choose a password' } },
    { path: '/admin/slips', name: 'slips', component: () => import('@/pages/admin/SlipsPage.vue'), meta: { needs: 'admin', title: 'Voting slips' } },
    {
      path: '/admin',
      component: () => import('@/layouts/AdminLayout.vue'),
      meta: { needs: 'admin' },
      children: [
        { path: '', name: 'overview', component: () => import('@/pages/admin/OverviewPage.vue'), meta: { title: 'Overview' } },
        { path: 'polls', name: 'polls', component: () => import('@/pages/admin/PollsPage.vue'), meta: { title: 'Polls' } },
        { path: 'polls/:id(\\d+)', name: 'poll', component: () => import('@/pages/admin/PollEditPage.vue'), meta: { title: 'Poll' } },
        { path: 'polls/:id(\\d+)/results', name: 'results', component: () => import('@/pages/admin/ResultsPage.vue'), meta: { title: 'Results' } },
        { path: 'polls/:id(\\d+)/teachers', name: 'teacher-results', component: () => import('@/pages/admin/TeacherRankingPage.vue'), meta: { title: 'Teacher results' } },
        { path: 'polls/:id(\\d+)/teachers/meeting', name: 'teacher-meeting', component: () => import('@/pages/admin/TeacherMeetingPage.vue'), meta: { title: 'Meeting report' } },
        { path: 'polls/:id(\\d+)/teachers/:teacher(\\d+)/:subject(\\d+)', name: 'teacher-report', component: () => import('@/pages/admin/TeacherReportPage.vue'), meta: { title: 'Teacher report' } },
        { path: 'students', name: 'students', component: () => import('@/pages/admin/StudentsPage.vue'), meta: { title: 'Students' } },
        { path: 'teachers', name: 'teachers', component: () => import('@/pages/admin/TeachersPage.vue'), meta: { title: 'Teachers' } },
        { path: 'admins', name: 'admins', component: () => import('@/pages/admin/AdminsPage.vue'), meta: { title: 'Admins', super: true } },
      ],
    },
    { path: '/:rest(.*)*', redirect: '/' },
  ],
})

router.beforeEach(async (to) => {
  const session = useSession()
  try {
    await session.load()
  } catch {
    // the server is unreachable: let the page show its own error
  }
  if (to.meta.needs === 'student' && !session.student) {
    // students sign in only through Tusome: send them there instead of the voting-number page
    if (session.tusomeUrl && !session.votingNumbers) {
      window.location.href = `${session.tusomeUrl}/login`
      return false
    }
    return { name: 'vote-login' }
  }
  // the start page, Tusome-only: straight to Tusome's sign-in (unless coming back from Tusome
  // with a problem to show, so it can never loop)
  if (to.name === 'vote-login' && !session.student && session.tusomeUrl && !session.votingNumbers && !to.query.tusome) {
    window.location.href = `${session.tusomeUrl}/login`
    return false
  }
  if (to.meta.needs === 'admin') {
    if (!session.admin) return { name: 'admin-login', query: { next: to.fullPath } }
    if (session.admin.must_change_password && to.name !== 'change-password') return { name: 'change-password' }
    if (to.meta.super && !session.isSuper) return { name: 'overview' }
  }
  if (to.name === 'admin-login' && session.admin) return { name: 'overview' }
})

router.afterEach((to) => {
  const session = useSession()
  document.title = [to.meta.title, 'Student Voice', session.school].filter(Boolean).join(' · ')
})

export default router
