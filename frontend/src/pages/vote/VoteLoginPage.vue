<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { LockClosedIcon } from '@heroicons/vue/24/outline'
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
</script>

<template>
  <div class="flex min-h-screen flex-col bg-paper">
    <header class="flex items-center justify-between px-4 py-4 sm:px-8">
      <div class="flex items-center gap-3">
        <BrandMark :size="34" />
        <div class="leading-tight">
          <div class="text-sm font-semibold">Student Voice</div>
          <div class="text-xs text-gray-500">{{ session.school }}</div>
        </div>
      </div>
      <ThemeToggle />
    </header>

    <main class="flex flex-1 items-center justify-center px-4 pb-16">
      <div class="w-full max-w-md">
        <div class="mb-8 text-center">
          <p class="eyebrow text-gold-600">Your voice counts</p>
          <h1 class="mt-2 text-3xl font-semibold tracking-tight sm:text-4xl">Have your say</h1>
          <p class="mt-3 text-gray-600">Type the voting number on your slip to see the polls open to your class.</p>
        </div>

        <form class="card p-6 sm:p-8" @submit.prevent="submit">
          <label for="code" class="label">Voting number</label>
          <input
            id="code"
            ref="input"
            :value="code"
            class="input py-3 text-center font-mono text-2xl tracking-[0.3em] uppercase"
            placeholder="XXXX-XXXX"
            autocomplete="off"
            autocapitalize="characters"
            spellcheck="false"
            inputmode="text"
            maxlength="9"
            @input="onInput"
          />
          <p v-if="error" class="field-error" role="alert">{{ error }}</p>
          <button type="submit" class="btn-primary mt-5 w-full py-3 text-base" :disabled="busy">
            {{ busy ? 'Checking…' : 'Continue' }}
          </button>
        </form>

        <div class="mt-6 flex items-start gap-3 rounded-xl bg-indigo-50 p-4 text-sm text-indigo-900 dark:bg-indigo-950/50 dark:text-indigo-200">
          <LockClosedIcon class="mt-0.5 h-5 w-5 shrink-0" />
          <p>Your number only proves you are a student here and stops anyone voting twice. Your answers are saved without your name or number, so nobody can see what you chose.</p>
        </div>
      </div>
    </main>

    <footer class="pb-6 text-center text-xs text-gray-500">
      <RouterLink :to="{ name: 'admin-login' }" class="hover:text-ink">Staff sign in</RouterLink>
    </footer>
  </div>
</template>
