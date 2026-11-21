import { defineStore } from 'pinia'
import { api, setCsrfToken } from '@/api'
import type { Admin, Student } from '@/types'

interface Me {
  school: string
  voting_numbers: boolean
  tusome_url: string
  csrf: string
  admin: Admin | null
  student: Student | null
}

/** Who is signed in: an admin, a student with their voting number, or neither. */
export const useSession = defineStore('session', {
  state: () => ({
    loaded: false,
    school: '',
    /** students may sign in with a voting number (else only from Tusome) */
    votingNumbers: true,
    /** Tusome's address when the two are linked (students can come from their dashboard) */
    tusomeUrl: '',
    admin: null as Admin | null,
    student: null as Student | null,
  }),
  getters: {
    isSuper: (s) => s.admin?.role === 'super_admin',
  },
  actions: {
    async load(force = false) {
      if (this.loaded && !force) return
      const me = await api.get<Me>('/me')
      this.school = me.school
      this.votingNumbers = me.voting_numbers ?? true
      this.tusomeUrl = me.tusome_url ?? ''
      this.admin = me.admin
      this.student = me.student
      setCsrfToken(me.csrf)
      this.loaded = true
    },
    async adminLogin(email: string, password: string) {
      const r = await api.post<{ admin: Admin; csrf: string }>('/admin/login', { email, password })
      this.admin = r.admin
      setCsrfToken(r.csrf)
    },
    async adminLogout() {
      try {
        await api.post('/admin/logout')
      } finally {
        this.admin = null
      }
    },
    async studentLogin(code: string) {
      const r = await api.post<{ student: Student; csrf: string }>('/vote/login', { code })
      this.student = r.student
      setCsrfToken(r.csrf)
    },
    async studentLogout() {
      try {
        await api.post('/vote/logout')
      } finally {
        this.student = null
      }
    },
  },
})
