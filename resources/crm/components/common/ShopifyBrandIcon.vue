<script setup>
defineProps({
  size: { type: [Number, String], default: 18 },
  decorative: { type: Boolean, default: true },
});
</script>

<template>
  <svg
    :width="size"
    :height="size"
    viewBox="0 0 24 24"
    xmlns="http://www.w3.org/2000/svg"
    :aria-hidden="decorative ? 'true' : undefined"
    :aria-label="decorative ? undefined : 'Shopify'"
    :role="decorative ? undefined : 'img'"
    class="shopify-brand-icon"
  >
    <path
      fill="#95BF47"
      d="M15.337 23.979c-.35 0-.665-.118-.92-.326l-2.32-1.844-1.89 1.058c-.118.065-.248.097-.389.097-.48 0-.87-.39-.87-.87V5.39c0-.48.39-.87.87-.87h14.64c.48 0 .87.39.87.87v16.714c0 .48-.39.87-.87.87h-1.06l-1.26 2.016a.87.87 0 01-.74.412h-.081z"
    />
  </svg>
</template>

<style scoped>
.shopify-brand-icon {
  display: block;
  flex-shrink: 0;
}
</style>
