<script setup>
defineProps({
  title: { type: String, required: true },
  subtitle: { type: String, default: "" },
  /** When set, shows "( Results: N )" next to the title (list pages). Omit on non-list pages. */
  resultCount: { type: Number, default: undefined },
});
</script>

<template>
  <div class="mb-6">
    <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1">
      <h1 class="text-2xl font-semibold text-[#0ea5e9] dark:text-blue-400">
        {{ title }}
      </h1>
      <span
        v-if="resultCount !== undefined"
        class="text-sm font-normal text-gray-900 dark:text-gray-200"
      >
        ( Results: {{ resultCount }} )
      </span>
    </div>
    <p v-if="subtitle" class="mt-1 text-sm text-gray-500 dark:text-gray-400">
      {{ subtitle }}
    </p>
  </div>
</template>
