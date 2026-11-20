<script setup lang="ts">
import Modal from '@/components/Modal.vue'
import { confirmState } from '@/feedback'

function answer(ok: boolean) {
  confirmState.open = false
  confirmState.resolve(ok)
}
</script>

<template>
  <Modal v-if="confirmState.open" :title="confirmState.title" @close="answer(false)">
    <p v-if="confirmState.body" class="text-sm text-gray-600">{{ confirmState.body }}</p>
    <div class="mt-5 flex justify-end gap-2">
      <button class="btn-secondary" @click="answer(false)">Cancel</button>
      <button :class="confirmState.danger ? 'btn bg-red-700 text-white hover:bg-red-800' : 'btn-primary'" @click="answer(true)">{{ confirmState.action }}</button>
    </div>
  </Modal>
</template>
