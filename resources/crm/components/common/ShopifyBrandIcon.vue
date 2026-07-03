<script setup>
import shopifyBagLogoUrl from "@public/images/shopify-bag-logo.svg";

defineProps({
  size: { type: [Number, String], default: 18 },
  decorative: { type: Boolean, default: true },
});
</script>

<template>
  <img
    :src="shopifyBagLogoUrl"
    :alt="decorative ? '' : 'Shopify'"
    :width="size"
    :height="size"
    class="shopify-brand-icon"
    :aria-hidden="decorative ? 'true' : undefined"
    decoding="async"
    draggable="false"
  />
</template>

<style scoped>
.shopify-brand-icon {
  display: block;
  flex-shrink: 0;
  object-fit: contain;
}
</style>
