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

/**
 * The voting number box: 6 letters and numbers shown as XXX-XXX. Letters become capitals whatever
 * the keyboard says, the dash puts itself in, and anything else (spaces, dashes, symbols) is
 * refused before it shows. Pasting "k7p 4qx" or "K7P-4QX" ends up the same.
 */
const LENGTH = 6
const clean = (v: string) => v.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, LENGTH)
const withDash = (raw: string) => (raw.length > 3 ? `${raw.slice(0, 3)}-${raw.slice(3)}` : raw)

/** Typed characters that aren't letters or numbers never reach the box (pastes are cleaned after) */
function onBeforeInput(e: InputEvent) {
  if (e.inputType === 'insertText' && e.data && /[^A-Za-z0-9]/.test(e.data)) e.preventDefault()
}

function onInput(e: Event) {
  const el = e.target as HTMLInputElement
  code.value = withDash(clean(el.value))
  // write it back even when nothing changed (Vue wouldn't), so a refused character never lingers
  el.value = code.value
  el.setSelectionRange(el.value.length, el.value.length)
  if (error.value) error.value = ''
}

async function submit() {
  error.value = ''
  if (clean(code.value).length !== LENGTH) {
    error.value = 'Your voting number has 6 letters and numbers, like K7P-4QX. Check your slip.'
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
            class="input py-3.5 text-center font-mono text-3xl tracking-[0.3em] uppercase"
            placeholder="XXX-XXX"
            autocomplete="off"
            autocapitalize="characters"
            autocorrect="off"
            spellcheck="false"
            inputmode="text"
            enterkeyhint="go"
            @beforeinput="onBeforeInput"
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
