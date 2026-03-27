<script setup>
defineProps({
  title: { type: String, required: true },
  subtitle: { type: String, default: "" },
});
</script>

<template>
  <div class="mb-6">
    <h1 class="text-2xl font-semibold text-gray-800 dark:text-white/90">{{ title }}</h1>
    <p v-if="subtitle" class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ subtitle }}</p>
  </div>
</template>

