<script setup lang="ts">
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import BrandMark from '@/components/BrandMark.vue'
import { ApiError } from '@/api'
import { useSession } from '@/stores/session'

const session = useSession()
const router = useRouter()
const route = useRoute()
const email = ref('')
const password = ref('')
const error = ref('')
const busy = ref(false)

async function submit() {
  error.value = ''
  busy.value = true
  try {
    await session.adminLogin(email.value, password.value)
    if (session.admin?.must_change_password) router.push({ name: 'change-password' })
    else router.push(typeof route.query.next === 'string' && route.query.next.startsWith('/admin') ? route.query.next : { name: 'overview' })
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Something went wrong.'
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="flex min-h-screen items-center justify-center bg-paper px-4 py-10">
    <div class="w-full max-w-sm">
      <div class="mb-6 flex flex-col items-center text-center">
        <BrandMark :size="76" />
        <p class="mt-4 text-sm font-semibold uppercase tracking-wide text-indigo-800 dark:text-crest-300">{{ session.school }}</p>
        <p class="text-[0.7rem] font-semibold uppercase tracking-[0.3em] text-gold-700">To be, not to seem</p>
        <h1 class="mt-3 text-xl font-semibold">Student Voice · staff</h1>
      </div>
      <form class="card space-y-4 p-6" @submit.prevent="submit">
        <div>
          <label class="label" for="email">Email</label>
          <input id="email" v-model="email" type="email" class="input" autocomplete="username" required />
        </div>
        <div>
          <label class="label" for="password">Password</label>
          <input id="password" v-model="password" type="password" class="input" autocomplete="current-password" required />
        </div>
        <p v-if="error" class="field-error" role="alert">{{ error }}</p>
        <button class="btn-primary w-full" :disabled="busy">{{ busy ? 'Signing in…' : 'Sign in' }}</button>
      </form>
      <p class="mt-6 text-center text-sm text-gray-500">
        A student? <RouterLink :to="{ name: 'vote-login' }" class="font-semibold text-indigo-700 hover:underline dark:text-indigo-300">Vote here</RouterLink>
      </p>
    </div>
  </div>
</template>
