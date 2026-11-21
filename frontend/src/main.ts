import { createApp } from 'vue'
import { createPinia } from 'pinia'
import '@fontsource-variable/inter'
import './style.css'
import App from './App.vue'
import router from './router'
import { handleAuthProblems } from './api'
import { useSession } from './stores/session'

const app = createApp(App)
app.use(createPinia())
app.use(router)

// The server ended a session, or wants the temporary password changed: go to the right page
handleAuthProblems((status, code, url) => {
  const session = useSession()
  if (code === 'must_change_password') {
    router.push({ name: 'change-password' })
  } else if (code === 'student_signed_out' || (status === 401 && url.startsWith('/vote'))) {
    session.student = null
    // linked to Tusome: back there to sign in again, not to the voting-number page
    if (session.exitUrl) window.location.href = session.exitUrl
    else router.push({ name: 'vote-login', query: { expired: '1' } })
  } else if (status === 401 || code === 'session_expired') {
    session.admin = null
    session.loaded = false
    router.push({ name: 'admin-login' })
  }
})

app.mount('#app')
