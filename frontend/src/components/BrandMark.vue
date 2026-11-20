<script setup lang="ts">
/** The St Mark's College Namagoma crest (transparent background), wherever the app shows its mark */
withDefaults(defineProps<{ size?: number }>(), { size: 36 })
const src = import.meta.env.BASE_URL + 'brand/stmark-crest.webp'
</script>

<template>
  <img :src="src" alt="St. Mark's College Namagoma crest" :width="size" :height="Math.round(size * 0.95)" class="shrink-0 object-contain" draggable="false" />
</template>
