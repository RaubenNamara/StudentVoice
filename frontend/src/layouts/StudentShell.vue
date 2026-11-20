<script setup lang="ts">
import { useRouter } from 'vue-router'
import { ArrowRightOnRectangleIcon } from '@heroicons/vue/24/outline'
import BrandMark from '@/components/BrandMark.vue'
import ThemeToggle from '@/components/ThemeToggle.vue'
import { useSession } from '@/stores/session'

const session = useSession()
const router = useRouter()

async function done() {
  await session.studentLogout()
  router.push({ name: 'vote-login' })
}
</script>

<template>
  <div class="relative min-h-screen overflow-x-hidden bg-paper">
    <!-- soft brand light behind everything -->
    <div class="pointer-events-none absolute inset-x-0 top-0 h-[28rem] bg-[radial-gradient(60rem_22rem_at_50%_-4rem,rgb(79_70_229/0.14),transparent_70%)] dark:bg-[radial-gradient(60rem_22rem_at_50%_-4rem,rgb(99_102_241/0.22),transparent_70%)]" aria-hidden="true" />
    <div class="pointer-events-none absolute -right-24 top-40 h-64 w-64 rounded-full bg-gold-300/20 blur-3xl dark:bg-gold-500/10" aria-hidden="true" />

    <header class="sticky top-0 z-30 border-b border-line/70 bg-surface/80 backdrop-blur-md">
      <div class="mx-auto flex max-w-3xl items-center justify-between gap-3 px-4 py-3">
        <RouterLink :to="{ name: session.student ? 'vote-polls' : 'vote-login' }" class="flex items-center gap-2.5">
          <BrandMark :size="30" />
          <span class="text-sm font-semibold">Student Voice</span>
        </RouterLink>
        <div class="flex items-center gap-1">
          <span v-if="session.student" class="mr-1 hidden rounded-full bg-indigo-50 px-3 py-1 text-xs font-semibold text-indigo-800 sm:inline dark:bg-indigo-950 dark:text-indigo-200">{{ session.student.class }} {{ session.student.stream }}</span>
          <ThemeToggle />
          <button v-if="session.student" class="btn-ghost" @click="done"><ArrowRightOnRectangleIcon class="h-5 w-5" /> Finish</button>
        </div>
      </div>
      <slot name="bar" />
    </header>
    <main class="relative mx-auto max-w-3xl px-4 py-6 sm:py-10">
      <slot />
    </main>
  </div>
</template>
