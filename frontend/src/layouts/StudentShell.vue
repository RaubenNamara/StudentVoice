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
  <div class="min-h-screen bg-paper">
    <header class="sticky top-0 z-30 border-b border-line bg-surface/90 backdrop-blur">
      <div class="mx-auto flex max-w-3xl items-center justify-between gap-3 px-4 py-3">
        <RouterLink :to="{ name: 'vote-polls' }" class="flex items-center gap-2.5">
          <BrandMark :size="30" />
          <span class="text-sm font-semibold">Student Voice</span>
        </RouterLink>
        <div class="flex items-center gap-1">
          <span v-if="session.student" class="mr-2 hidden text-sm text-gray-600 sm:inline">{{ session.student.class }} {{ session.student.stream }}</span>
          <ThemeToggle />
          <button class="btn-ghost" @click="done"><ArrowRightOnRectangleIcon class="h-5 w-5" /> Finish</button>
        </div>
      </div>
      <slot name="bar" />
    </header>
    <main class="mx-auto max-w-3xl px-4 py-6 sm:py-10">
      <slot />
    </main>
  </div>
</template>
