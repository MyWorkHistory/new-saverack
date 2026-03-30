<script setup>
defineProps({
  title: { type: String, required: true },
  value: { type: [String, Number], required: true },
  subtitle: { type: String, default: "" },
});
</script>

<template>
  <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-white/[0.03]">
    <p class="text-sm text-gray-500 dark:text-gray-400">{{ title }}</p>
    <h3 class="mt-2 text-2xl font-semibold text-gray-800 dark:text-white/90">{{ value }}</h3>
    <p v-if="subtitle" class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ subtitle }}</p>
  </div>
</template>

