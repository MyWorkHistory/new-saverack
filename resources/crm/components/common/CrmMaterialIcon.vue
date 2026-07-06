<script setup>
import { computed } from "vue";
import { PORTAL_MATERIAL_ICON } from "../../constants/portalMaterialIcons.js";

const props = defineProps({
  name: { type: String, required: true },
  size: { type: [Number, String], default: 18 },
});

const sizePx = computed(() => {
  const n = Number(props.size);
  return Number.isFinite(n) && n > 0 ? n : 18;
});

const path = computed(() => PORTAL_MATERIAL_ICON[props.name] || PORTAL_MATERIAL_ICON.account);
</script>

<template>
  <svg
    class="crm-material-icon"
    :width="sizePx"
    :height="sizePx"
    fill="currentColor"
    viewBox="0 0 24 24"
    aria-hidden="true"
  >
    <path :d="path" />
  </svg>
</template>

<style scoped>
.crm-material-icon {
  display: block;
  flex-shrink: 0;
}
</style>
