<script setup>
defineProps({
  /** `horizontal` = three dots in a row (⋯). `vertical` = kebab (⋮). */
  variant: {
    type: String,
    default: "vertical",
    validator: (v) => v === "vertical" || v === "horizontal",
  },
});
</script>

<template>
  <svg
    class="crm-icon-row-actions"
    width="20"
    height="20"
    fill="currentColor"
    viewBox="0 0 24 24"
    aria-hidden="true"
  >
    <template v-if="variant === 'horizontal'">
      <circle cx="5" cy="12" r="1.75" />
      <circle cx="12" cy="12" r="1.75" />
      <circle cx="19" cy="12" r="1.75" />
    </template>
    <template v-else>
      <circle cx="12" cy="5" r="1.75" />
      <circle cx="12" cy="12" r="1.75" />
      <circle cx="12" cy="19" r="1.75" />
    </template>
  </svg>
</template>

<style scoped>
.crm-icon-row-actions {
  display: block;
}
</style>
