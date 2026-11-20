<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { PrinterIcon, ScissorsIcon } from '@heroicons/vue/24/outline'
import BrandMark from '@/components/BrandMark.vue'
import PrintHeader from '@/components/PrintHeader.vue'
import { api } from '@/api'
import { useSession } from '@/stores/session'
import type { ClassGroup } from '@/types'

interface Slip {
  reg_no: string
  name: string
  class: string
  stream: string
  voter_code: string
}

const session = useSession()
const classes = ref<ClassGroup[]>([])
const pick = ref({ class: 'S.1', stream: '' })
const slips = ref<Slip[]>([])
const loading = ref(false)
const layout = ref<'slips' | 'list'>('slips')
const voteUrl = computed(() => window.location.origin + import.meta.env.BASE_URL)
const streams = computed(() => classes.value.find((c) => c.class === pick.value.class)?.streams ?? [])

onMounted(async () => {
  classes.value = await api.get<ClassGroup[]>('/admin/students/classes')
  if (classes.value.length) pick.value.class = classes.value[0].class
  await load()
})
watch(() => pick.value.class, () => (pick.value.stream = ''))
watch(pick, load, { deep: true })

async function load() {
  loading.value = true
  try {
    slips.value = (await api.get<{ students: Slip[] }>('/admin/students/slips', pick.value)).students
  } finally {
    loading.value = false
  }
}
const print = () => window.print()
</script>

<template>
  <div class="force-light min-h-screen bg-white text-[rgb(24,24,38)]">
    <div class="no-print sticky top-0 z-10 border-b border-gray-200 bg-white/95 backdrop-blur">
      <div class="mx-auto flex max-w-5xl flex-wrap items-end gap-3 px-4 py-3">
        <RouterLink :to="{ name: 'students' }" class="btn-ghost mr-auto">← Students</RouterLink>
        <div>
          <label class="mb-1 block text-xs font-semibold text-gray-500" for="c">Class</label>
          <select id="c" v-model="pick.class" class="input w-28"><option v-for="c in classes" :key="c.class">{{ c.class }}</option></select>
        </div>
        <div>
          <label class="mb-1 block text-xs font-semibold text-gray-500" for="s">Stream</label>
          <select id="s" v-model="pick.stream" class="input w-36">
            <option value="">All streams</option>
            <option v-for="s in streams" :key="s.stream" :value="s.stream">{{ s.stream }} ({{ s.students }})</option>
          </select>
        </div>
        <div>
          <label class="mb-1 block text-xs font-semibold text-gray-500" for="l">Print as</label>
          <select id="l" v-model="layout" class="input w-44">
            <option value="slips">Slips to cut out</option>
            <option value="list">Class list</option>
          </select>
        </div>
        <button class="btn-primary" :disabled="!slips.length" @click="print"><PrinterIcon class="h-4 w-4" /> Print {{ slips.length }}</button>
      </div>
      <p class="mx-auto max-w-5xl px-4 pb-3 text-xs text-gray-500">
        Keep printed slips safe: anyone holding a slip can vote as that student. Hand each one to its student. If a slip is lost or seen by others, give that student a new number on the Students page.
      </p>
    </div>

    <p v-if="loading" class="py-16 text-center text-gray-500">Loading…</p>
    <p v-else-if="!slips.length" class="py-16 text-center text-gray-500">No students in this class.</p>

    <!-- Slips: 10 to an A4 page -->
    <div v-else-if="layout === 'slips'" class="mx-auto grid max-w-5xl grid-cols-2 gap-0 p-4 print:p-0">
      <div v-for="s in slips" :key="s.reg_no" class="slip break-inside-avoid border border-dashed border-gray-300 p-4">
        <div class="flex items-center gap-2">
          <BrandMark :size="28" />
          <div class="min-w-0 leading-tight">
            <div class="truncate text-[10px] font-semibold uppercase tracking-wide text-indigo-900">{{ session.school }}</div>
            <div class="text-[9px] text-gray-500">Student Voice · voting slip</div>
          </div>
          <ScissorsIcon class="ml-auto h-3.5 w-3.5 text-gray-300" />
        </div>
        <div class="mt-2 truncate text-sm font-semibold">{{ s.name }}</div>
        <div class="text-[11px] text-gray-500">{{ s.class }} {{ s.stream }} · {{ s.reg_no }}</div>
        <div class="mt-2 rounded-md bg-gray-100 py-1.5 text-center font-mono text-xl font-semibold tracking-[0.25em]">{{ s.voter_code }}</div>
        <div class="mt-1.5 text-[9px] leading-snug text-gray-500">Go to <b>{{ voteUrl }}</b> and type this voting number. Keep it secret. Your answers never show your name.</div>
      </div>
    </div>

    <!-- Class list -->
    <div v-else class="mx-auto max-w-5xl p-4">
      <PrintHeader always :title="`Voting numbers · ${pick.class} ${pick.stream}`" :subtitle="`vote at ${voteUrl}`" />
      <table class="w-full text-sm">
        <thead class="border-b-2 border-gray-300 text-left text-xs uppercase text-gray-500">
          <tr><th class="py-1.5 pr-2">#</th><th class="py-1.5 pr-2">Name</th><th class="py-1.5 pr-2">Reg. no</th><th class="py-1.5 pr-2">Stream</th><th class="py-1.5">Voting number</th></tr>
        </thead>
        <tbody>
          <tr v-for="(s, i) in slips" :key="s.reg_no" class="border-b border-gray-200">
            <td class="py-1.5 pr-2 text-gray-400">{{ i + 1 }}</td>
            <td class="py-1.5 pr-2">{{ s.name }}</td>
            <td class="py-1.5 pr-2">{{ s.reg_no }}</td>
            <td class="py-1.5 pr-2">{{ s.stream }}</td>
            <td class="py-1.5 font-mono tracking-wider">{{ s.voter_code }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<style scoped>
@media print {
  @page { size: A4; margin: 8mm; }
  /* 2 x 5 slips per page */
  .slip { height: 55mm; }
}
</style>
