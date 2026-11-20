<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { Bars3Icon, ChartBarSquareIcon, ChatBubbleLeftRightIcon, KeyIcon, ArrowRightOnRectangleIcon, ShieldCheckIcon, UserGroupIcon, XMarkIcon } from '@heroicons/vue/24/outline'
import BrandMark from '@/components/BrandMark.vue'
import ThemeToggle from '@/components/ThemeToggle.vue'
import { useSession } from '@/stores/session'

const session = useSession()
const route = useRoute()
const router = useRouter()
const open = ref(false)
watch(() => route.fullPath, () => (open.value = false))

const nav = computed(() => [
  { to: { name: 'overview' }, label: 'Overview', icon: ChartBarSquareIcon, match: ['overview'] },
  { to: { name: 'polls' }, label: 'Polls', icon: ChatBubbleLeftRightIcon, match: ['polls', 'poll', 'results'] },
  { to: { name: 'students' }, label: 'Students', icon: UserGroupIcon, match: ['students'] },
  ...(session.isSuper ? [{ to: { name: 'admins' }, label: 'Admins', icon: ShieldCheckIcon, match: ['admins'] }] : []),
])
const active = (match: string[]) => match.includes(String(route.name))

async function logout() {
  await session.adminLogout()
  router.push({ name: 'admin-login' })
}
</script>

<template>
  <div class="min-h-screen bg-paper lg:flex">
    <!-- Sidebar -->
    <aside
      class="fixed inset-y-0 left-0 z-40 flex w-64 flex-col bg-indigo-950 text-indigo-100 transition-transform lg:sticky lg:top-0 lg:h-screen lg:translate-x-0"
      :class="open ? 'translate-x-0' : '-translate-x-full'"
    >
      <div class="flex items-center gap-3 px-5 py-5">
        <BrandMark :size="34" />
        <div class="min-w-0 leading-tight">
          <div class="font-semibold text-white">Student Voice</div>
          <div class="truncate text-xs text-indigo-300">{{ session.school }}</div>
        </div>
        <button class="ml-auto rounded-lg p-1 text-indigo-300 hover:bg-white/10 lg:hidden" aria-label="Close menu" @click="open = false"><XMarkIcon class="h-5 w-5" /></button>
      </div>
      <nav class="flex-1 space-y-1 px-3 py-2">
        <RouterLink
          v-for="item in nav"
          :key="item.label"
          :to="item.to"
          class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition"
          :class="active(item.match) ? 'bg-white/10 text-white shadow-[inset_3px_0_0_#e8b931]' : 'text-indigo-200 hover:bg-white/5 hover:text-white'"
        >
          <component :is="item.icon" class="h-5 w-5" />
          {{ item.label }}
        </RouterLink>
      </nav>
      <div class="border-t border-white/10 p-3">
        <div class="px-3 py-2">
          <div class="truncate text-sm font-semibold text-white">{{ session.admin?.name }}</div>
          <div class="truncate text-xs text-indigo-300">{{ session.isSuper ? 'Super admin' : 'Admin' }} · {{ session.admin?.email }}</div>
        </div>
        <RouterLink :to="{ name: 'change-password' }" class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm text-indigo-200 hover:bg-white/5 hover:text-white">
          <KeyIcon class="h-5 w-5" /> Change password
        </RouterLink>
        <button class="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-sm text-indigo-200 hover:bg-white/5 hover:text-white" @click="logout">
          <ArrowRightOnRectangleIcon class="h-5 w-5" /> Sign out
        </button>
      </div>
    </aside>
    <div v-if="open" class="fixed inset-0 z-30 bg-indigo-950/40 lg:hidden" @click="open = false" />

    <div class="min-w-0 flex-1">
      <header class="sticky top-0 z-20 flex items-center gap-2 border-b border-line bg-surface/90 px-4 py-2.5 backdrop-blur sm:px-6">
        <button class="btn-ghost -ml-2 px-2 lg:hidden" aria-label="Open menu" @click="open = true"><Bars3Icon class="h-6 w-6" /></button>
        <span class="text-sm font-semibold lg:hidden">Student Voice</span>
        <div class="ml-auto"><ThemeToggle /></div>
      </header>
      <main class="mx-auto max-w-6xl px-4 py-6 sm:px-6 lg:py-8">
        <RouterView />
      </main>
    </div>
  </div>
</template>
