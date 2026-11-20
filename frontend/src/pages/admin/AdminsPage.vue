<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { PlusIcon } from '@heroicons/vue/24/outline'
import PageHeader from '@/components/PageHeader.vue'
import Modal from '@/components/Modal.vue'
import { api, ApiError } from '@/api'
import { confirm, formatDate, toast } from '@/feedback'
import { useSession } from '@/stores/session'

interface Row {
  id: number
  name: string
  email: string
  role: 'super_admin' | 'admin'
  status: 'active' | 'disabled'
  must_change_password: number
  last_login_at: string | null
}

const session = useSession()
const admins = ref<Row[]>([])
const adding = ref(false)
const form = ref({ name: '', email: '', role: 'admin' })
const error = ref('')
const temp = ref<{ name: string; password: string } | null>(null)

const load = async () => (admins.value = await api.get<Row[]>('/admin/admins'))
onMounted(load)

async function add() {
  error.value = ''
  try {
    const r = await api.post<{ temporary_password: string }>('/admin/admins', form.value)
    temp.value = { name: form.value.name, password: r.temporary_password }
    adding.value = false
    form.value = { name: '', email: '', role: 'admin' }
    await load()
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Could not add.'
  }
}

async function save(a: Row, change: Partial<Row>) {
  try {
    await api.patch(`/admin/admins/${a.id}`, { role: a.role, status: a.status, ...change })
    toast('Saved.')
  } catch (e) {
    toast(e instanceof ApiError ? e.message : 'Could not save.', 'error')
  }
  await load()
}

async function reset(a: Row) {
  if (!(await confirm(`Reset ${a.name}'s password?`, 'They get a temporary password and must choose a new one when they sign in.', 'Reset password'))) return
  const r = await api.post<{ temporary_password: string }>(`/admin/admins/${a.id}/reset-password`)
  temp.value = { name: a.name, password: r.temporary_password }
  await load()
}
</script>

<template>
  <PageHeader title="Admins" subtitle="Who can run polls, manage students and see results. Super admins also manage this list.">
    <button class="btn-primary" @click="adding = true"><PlusIcon class="h-4 w-4" /> Add an admin</button>
  </PageHeader>

  <div class="card overflow-x-auto">
    <table class="w-full text-sm">
      <thead class="table-head">
        <tr><th class="px-4 py-2.5">Name</th><th class="px-4 py-2.5">Role</th><th class="px-4 py-2.5">Last sign in</th><th class="px-4 py-2.5" /></tr>
      </thead>
      <tbody class="divide-y divide-line">
        <tr v-for="a in admins" :key="a.id" :class="a.status === 'disabled' ? 'opacity-60' : ''">
          <td class="px-4 py-3">
            <div class="font-medium">{{ a.name }} <span v-if="a.id === session.admin?.id" class="text-xs font-normal text-gray-500">(you)</span></div>
            <div class="text-xs text-gray-500">{{ a.email }}<template v-if="a.status === 'disabled'"> · switched off</template></div>
          </td>
          <td class="px-4 py-3">
            <select class="input w-36 py-1.5" :value="a.role" :disabled="a.id === session.admin?.id" @change="save(a, { role: ($event.target as HTMLSelectElement).value as Row['role'] })">
              <option value="admin">Admin</option>
              <option value="super_admin">Super admin</option>
            </select>
          </td>
          <td class="whitespace-nowrap px-4 py-3 text-gray-600">{{ a.last_login_at ? formatDate(a.last_login_at) : a.must_change_password ? 'Not yet' : '' }}</td>
          <td class="whitespace-nowrap px-4 py-3 text-right">
            <template v-if="a.id !== session.admin?.id">
              <button class="btn-ghost px-2 py-1 text-xs" @click="reset(a)">Reset password</button>
              <button class="btn-ghost px-2 py-1 text-xs" @click="save(a, { status: a.status === 'active' ? 'disabled' : 'active' })">{{ a.status === 'active' ? 'Switch off' : 'Switch on' }}</button>
            </template>
          </td>
        </tr>
      </tbody>
    </table>
  </div>

  <Modal v-if="adding" title="Add an admin" @close="adding = false">
    <form class="space-y-4" @submit.prevent="add">
      <div><label class="label" for="n">Name</label><input id="n" v-model="form.name" class="input" required /></div>
      <div><label class="label" for="e">Email</label><input id="e" v-model="form.email" type="email" class="input" required /></div>
      <div>
        <label class="label" for="r">Role</label>
        <select id="r" v-model="form.role" class="input"><option value="admin">Admin</option><option value="super_admin">Super admin</option></select>
      </div>
      <p v-if="error" class="field-error">{{ error }}</p>
      <div class="flex justify-end gap-2"><button type="button" class="btn-secondary" @click="adding = false">Cancel</button><button class="btn-primary">Add admin</button></div>
    </form>
  </Modal>

  <Modal v-if="temp" title="Temporary password" @close="temp = null">
    <p class="text-sm text-gray-600">Give this to {{ temp.name }}. It is shown only once; they choose their own password when they sign in.</p>
    <p class="mt-4 rounded-lg bg-gray-100 py-3 text-center font-mono text-xl tracking-wider">{{ temp.password }}</p>
    <div class="mt-5 flex justify-end"><button class="btn-primary" @click="temp = null">Done</button></div>
  </Modal>
</template>
