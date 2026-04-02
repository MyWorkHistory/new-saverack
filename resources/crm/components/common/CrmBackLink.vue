<script setup>
import { RouterLink } from "vue-router";

defineProps({
  to: { type: String, required: true },
  label: { type: String, required: true },
});
</script>

<template>
  <RouterLink
    :to="to"
    class="inline-flex items-center gap-1.5 text-sm font-medium text-gray-600 transition hover:text-[#38bdf8] dark:text-gray-400 dark:hover:text-blue-400"
  >
    <svg
      class="h-4 w-4 shrink-0"
      fill="none"
      viewBox="0 0 24 24"
      stroke="currentColor"
      stroke-width="2"
      aria-hidden="true"
    >
      <path
        stroke-linecap="round"
        stroke-linejoin="round"
        d="M15 19l-7-7 7-7"
      />
    </svg>
    {{ label }}
  </RouterLink>
</template>
