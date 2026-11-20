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
const campus = import.meta.env.BASE_URL + 'brand/stmark-campus.webp'

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
  <div class="flex min-h-screen flex-col bg-paper">
    <!-- School banner: the campus under St Mark's navy -->
    <section class="relative overflow-hidden bg-indigo-900 pb-28 text-white">
      <img :src="campus" alt="" class="absolute inset-0 h-full w-full object-cover opacity-35" aria-hidden="true" />
      <div class="absolute inset-0 bg-gradient-to-b from-indigo-950/70 via-indigo-900/80 to-indigo-900" aria-hidden="true" />
      <div class="relative flex items-center justify-end px-4 py-3 sm:px-8">
        <ThemeToggle class="text-white hover:bg-white/10" />
      </div>
      <div class="relative mx-auto flex max-w-xl flex-col items-center px-4 text-center">
        <div class="rounded-full bg-white p-3 shadow-xl ring-4 ring-gold-400/70">
          <BrandMark :size="84" />
        </div>
        <p class="mt-4 text-lg font-semibold uppercase tracking-wide sm:text-xl">{{ session.school || "St. Mark's College Namagoma" }}</p>
        <p class="mt-1 text-xs font-semibold uppercase tracking-[0.35em] text-gold-300">To be, not to seem</p>
        <h1 class="mt-6 text-4xl font-semibold tracking-tight sm:text-5xl">Student <span class="text-gold-300">Voice</span></h1>
        <p class="mx-auto mt-3 max-w-sm text-crest-100">Have your say. It takes a few minutes and helps make school better for everyone.</p>
      </div>
    </section>

    <main class="relative -mt-20 flex flex-1 justify-center px-4 pb-12">
      <div class="w-full max-w-md">
        <form class="card p-6 shadow-lift sm:p-8" @submit.prevent="submit">
          <label for="code" class="label">Your voting number</label>
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
          <p class="mt-1.5 text-xs text-gray-500">It's on the slip from your class teacher.</p>
          <p v-if="error" class="field-error" role="alert">{{ error }}</p>
          <button type="submit" class="btn-primary mt-5 w-full py-3.5 text-base" :disabled="busy">
            {{ busy ? 'Checking…' : "Let's go" }}
          </button>
        </form>

        <ul class="mt-6 grid grid-cols-3 gap-2">
          <li v-for="p in promises" :key="p.t" class="rounded-xl border border-line bg-surface p-3 text-center" :title="p.d">
            <component :is="p.icon" class="mx-auto h-5 w-5 text-indigo-700 dark:text-crest-300" />
            <div class="mt-1.5 text-xs font-semibold">{{ p.t }}</div>
            <div class="mt-0.5 hidden text-[0.68rem] leading-snug text-gray-500 sm:block">{{ p.d }}</div>
          </li>
        </ul>
      </div>
    </main>

    <footer class="pb-6 text-center text-xs text-gray-500">
      {{ session.school }} · <RouterLink :to="{ name: 'admin-login' }" class="hover:text-ink">Staff sign in</RouterLink>
    </footer>
  </div>
</template>
