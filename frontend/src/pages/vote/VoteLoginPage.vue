<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { EyeSlashIcon, HandRaisedIcon, LockClosedIcon } from '@heroicons/vue/24/outline'
import BrandMark from '@/components/BrandMark.vue'
import ThemeToggle from '@/components/ThemeToggle.vue'
import { ApiError } from '@/api'
import { useSession } from '@/stores/session'

const session = useSession()
const router = useRouter()
const route = useRoute()
const code = ref('')
const error = ref(route.query.expired ? 'You were signed out after a while without activity. Enter your voting number again.' : '')
const busy = ref(false)
const input = ref<HTMLInputElement>()

onMounted(() => {
  if (session.student) router.replace({ name: 'vote-polls' })
  input.value?.focus()
})

/** Shown as XXXX-XXXX while typing, whatever the student types between */
function onInput(e: Event) {
  const raw = (e.target as HTMLInputElement).value.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 8)
  code.value = raw.length > 4 ? `${raw.slice(0, 4)}-${raw.slice(4)}` : raw
}

async function submit() {
  error.value = ''
  if (code.value.replace('-', '').length !== 8) {
    error.value = 'Your voting number has 8 letters and numbers. Check your slip.'
    return
  }
  busy.value = true
  try {
    await session.studentLogin(code.value)
    router.push({ name: 'vote-polls' })
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Something went wrong.'
  } finally {
    busy.value = false
  }
}

const promises = [
  { icon: EyeSlashIcon, t: 'No names', d: 'Answers are saved without your name or number.' },
  { icon: HandRaisedIcon, t: 'Once each', d: 'Your number stops anyone voting twice.' },
  { icon: LockClosedIcon, t: 'Safe to be honest', d: 'Teachers never see who said what.' },
]
</script>

<template>
  <div class="relative flex min-h-screen flex-col overflow-hidden bg-paper">
    <!-- brand light -->
    <div class="pointer-events-none absolute inset-0 bg-[radial-gradient(50rem_30rem_at_50%_-8rem,rgb(79_70_229/0.18),transparent_70%)] dark:bg-[radial-gradient(50rem_30rem_at_50%_-8rem,rgb(99_102_241/0.28),transparent_70%)]" aria-hidden="true" />
    <div class="pointer-events-none absolute -left-20 bottom-10 h-72 w-72 rounded-full bg-gold-300/25 blur-3xl dark:bg-gold-500/10" aria-hidden="true" />
    <!-- speech bubbles drifting in the background -->
    <svg class="pointer-events-none absolute right-[6%] top-28 hidden h-24 w-24 text-indigo-200 sm:block dark:text-indigo-900" viewBox="0 0 64 64" fill="currentColor" aria-hidden="true"><path d="M10 14a8 8 0 0 1 8-8h28a8 8 0 0 1 8 8v20a8 8 0 0 1-8 8H30l-10 9v-9h-2a8 8 0 0 1-8-8z" /></svg>
    <svg class="pointer-events-none absolute left-[7%] top-52 hidden h-14 w-14 text-gold-200 sm:block dark:text-gold-700/40" viewBox="0 0 64 64" fill="currentColor" aria-hidden="true"><path d="M10 14a8 8 0 0 1 8-8h28a8 8 0 0 1 8 8v20a8 8 0 0 1-8 8H30l-10 9v-9h-2a8 8 0 0 1-8-8z" /></svg>

    <header class="relative flex items-center justify-between px-4 py-4 sm:px-8">
      <div class="flex items-center gap-3">
        <BrandMark :size="34" />
        <div class="leading-tight">
          <div class="text-sm font-semibold">Student Voice</div>
          <div class="text-xs text-gray-500">{{ session.school }}</div>
        </div>
      </div>
      <ThemeToggle />
    </header>

    <main class="relative flex flex-1 items-center justify-center px-4 pb-14">
      <div class="w-full max-w-md">
        <div class="mb-8 text-center">
          <span class="inline-flex items-center gap-2 rounded-full border border-gold-300/60 bg-gold-50 px-3 py-1 text-xs font-semibold text-gold-700 dark:border-gold-700/50 dark:bg-gold-700/20 dark:text-gold-300">
            <span class="h-1.5 w-1.5 animate-pulse rounded-full bg-gold-500" /> Your voice counts
          </span>
          <h1 class="mt-4 text-4xl font-semibold tracking-tight sm:text-5xl">Have your <span class="bg-gradient-to-r from-indigo-700 to-indigo-500 bg-clip-text text-transparent dark:from-indigo-300 dark:to-indigo-400">say</span></h1>
          <p class="mx-auto mt-3 max-w-sm text-gray-600">Type the voting number on your slip. It takes a few minutes and makes school better.</p>
        </div>

        <form class="card p-6 shadow-lift sm:p-8" @submit.prevent="submit">
          <label for="code" class="label">Voting number</label>
          <input
            id="code"
            ref="input"
            :value="code"
            class="input py-3.5 text-center font-mono text-2xl tracking-[0.3em] uppercase"
            placeholder="XXXX-XXXX"
            autocomplete="off"
            autocapitalize="characters"
            spellcheck="false"
            maxlength="9"
            @input="onInput"
          />
          <p v-if="error" class="field-error" role="alert">{{ error }}</p>
          <button type="submit" class="btn-primary mt-5 w-full bg-gradient-to-r from-indigo-700 to-indigo-600 py-3.5 text-base" :disabled="busy">
            {{ busy ? 'Checking…' : "Let's go" }}
          </button>
        </form>

        <ul class="mt-6 grid grid-cols-3 gap-2">
          <li v-for="p in promises" :key="p.t" class="rounded-xl bg-surface/70 p-3 text-center backdrop-blur" :title="p.d">
            <component :is="p.icon" class="mx-auto h-5 w-5 text-indigo-700 dark:text-indigo-300" />
            <div class="mt-1.5 text-xs font-semibold">{{ p.t }}</div>
            <div class="mt-0.5 hidden text-[0.68rem] leading-snug text-gray-500 sm:block">{{ p.d }}</div>
          </li>
        </ul>
      </div>
    </main>

    <footer class="relative pb-6 text-center text-xs text-gray-500">
      <RouterLink :to="{ name: 'admin-login' }" class="hover:text-ink">Staff sign in</RouterLink>
    </footer>
  </div>
</template>
