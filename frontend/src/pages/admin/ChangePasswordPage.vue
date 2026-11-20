<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import BrandMark from '@/components/BrandMark.vue'
import { api, ApiError } from '@/api'
import { toast } from '@/feedback'
import { useSession } from '@/stores/session'
import type { Admin } from '@/types'

const session = useSession()
const router = useRouter()
const current = ref('')
const next = ref('')
const again = ref('')
const errors = ref<Record<string, string>>({})
const busy = ref(false)

async function submit() {
  errors.value = {}
  if (next.value !== again.value) {
    errors.value = { again: 'The two new passwords are not the same.' }
    return
  }
  busy.value = true
  try {
    const r = await api.post<{ admin: Admin }>('/admin/password', { current_password: current.value, new_password: next.value })
    session.admin = r.admin
    toast('Password changed.')
    router.push({ name: 'overview' })
  } catch (e) {
    if (e instanceof ApiError) errors.value = { ...e.errors, form: e.message }
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="flex min-h-screen items-center justify-center bg-paper px-4 py-10">
    <div class="w-full max-w-sm">
      <div class="mb-6 flex flex-col items-center text-center">
        <BrandMark :size="44" />
        <h1 class="mt-4 text-xl font-semibold">Choose your password</h1>
        <p class="mt-1 text-sm text-gray-500">
          {{ session.admin?.must_change_password ? 'Replace the temporary password you were given.' : 'Change the password you sign in with.' }}
        </p>
      </div>
      <form class="card space-y-4 p-6" @submit.prevent="submit">
        <div>
          <label class="label" for="cur">{{ session.admin?.must_change_password ? 'Temporary password' : 'Current password' }}</label>
          <input id="cur" v-model="current" type="password" class="input" autocomplete="current-password" required />
        </div>
        <div>
          <label class="label" for="new">New password</label>
          <input id="new" v-model="next" type="password" class="input" autocomplete="new-password" required />
          <p class="mt-1 text-xs text-gray-500">At least 8 characters, with letters and numbers.</p>
        </div>
        <div>
          <label class="label" for="again">New password again</label>
          <input id="again" v-model="again" type="password" class="input" autocomplete="new-password" required />
          <p v-if="errors.again" class="field-error">{{ errors.again }}</p>
        </div>
        <p v-if="errors.form" class="field-error" role="alert">{{ errors.form }}</p>
        <button class="btn-primary w-full" :disabled="busy">{{ busy ? 'Saving…' : 'Save password' }}</button>
        <RouterLink v-if="!session.admin?.must_change_password" :to="{ name: 'overview' }" class="btn-ghost w-full">Cancel</RouterLink>
      </form>
    </div>
  </div>
</template>
