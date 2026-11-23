<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { CheckCircleIcon, ExclamationTriangleIcon } from '@heroicons/vue/24/outline'
import { api, ApiError } from '@/api'
import { toast } from '@/feedback'
import { useSession } from '@/stores/session'

/** Super admins: how students sign in - from Tusome (when linked) and/or with voting numbers */
interface SignIn { voting_numbers: boolean; tusome_linked: boolean; tusome_url: string }
const session = useSession()
const s = ref<SignIn | null>(null)
const busy = ref(false)
onMounted(async () => (s.value = await api.get<SignIn>('/admin/sign-in-settings')))

async function setVotingNumbers(on: boolean) {
  if (!s.value) return
  if (!on && !s.value.tusome_linked) {
    toast('Link Tusome first, or students would have no way in.', 'error')
    return
  }
  busy.value = true
  try {
    s.value = await api.post<SignIn>('/admin/sign-in-settings', { voting_numbers: on })
    session.votingNumbers = s.value.voting_numbers
    toast(on ? 'Students can sign in with voting numbers again.' : 'Voting numbers are off: students come in from their Tusome dashboard.')
  } catch (e) {
    toast(e instanceof ApiError ? e.message : 'Could not save.', 'error')
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <section v-if="s" class="card mb-6 p-5">
    <h2 class="font-semibold">How students sign in</h2>
    <div class="mt-3 grid gap-3 sm:grid-cols-2">
      <div class="rounded-xl border border-line p-4">
        <p class="flex items-center gap-2 text-sm font-semibold">
          <CheckCircleIcon v-if="s.tusome_linked" class="h-5 w-5 text-emerald-600" />
          <ExclamationTriangleIcon v-else class="h-5 w-5 text-gold-600" />
          From the Tusome dashboard
        </p>
        <p class="mt-1 text-sm text-gray-600">
          <template v-if="s.tusome_linked">Linked. Students tap <strong>Student Voice</strong> on their Tusome dashboard and arrive signed in, with their usual Tusome account. Switch the button on or off in Tusome: Admin &gt; Settings.</template>
          <template v-else>Not linked yet: put the shared key in backend/.env (TUSOME_API_KEY here, STUDENT_VOICE_API_KEY in Tusome).</template>
        </p>
      </div>
      <div class="rounded-xl border border-line p-4">
        <div class="flex items-start justify-between gap-3">
          <div>
            <p class="text-sm font-semibold">Voting numbers (slips)</p>
            <p class="mt-1 text-sm text-gray-600">{{ s.voting_numbers ? 'On: students can also sign in here with their registration number (or slip number).' : 'Off: slips no longer work; students come in from Tusome only.' }}</p>
          </div>
          <button
            role="switch"
            :aria-checked="s.voting_numbers"
            :disabled="busy"
            class="relative h-7 w-12 shrink-0 rounded-full transition"
            :class="s.voting_numbers ? 'bg-indigo-700' : 'bg-gray-300'"
            :aria-label="s.voting_numbers ? 'Turn voting numbers off' : 'Turn voting numbers on'"
            @click="setVotingNumbers(!s.voting_numbers)"
          >
            <span class="absolute top-1 h-5 w-5 rounded-full bg-white shadow transition-all" :class="s.voting_numbers ? 'left-6' : 'left-1'" />
          </button>
        </div>
      </div>
    </div>
  </section>
</template>
