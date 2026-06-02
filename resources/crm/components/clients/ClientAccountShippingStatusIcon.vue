<script setup>
import { computed } from "vue";
import { PORTAL_MATERIAL_ICON } from "../../constants/portalMaterialIcons.js";

const props = defineProps({
  status: { type: String, default: "" },
  size: { type: [Number, String], default: 18 },
});

const isActive = computed(() => String(props.status || "").toLowerCase() === "active");

const statusLabel = computed(() => {
  const s = String(props.status || "").trim();
  if (!s) return "Unknown";
  return s.charAt(0).toUpperCase() + s.slice(1);
});

const title = computed(() => {
  if (isActive.value) {
    return "Active — orders visible in client app";
  }
  return `${statusLabel.value} — orders hidden from client app`;
});

const sizePx = computed(() => {
  const n = Number(props.size);
  return Number.isFinite(n) && n > 0 ? n : 18;
});
</script>

<template>
  <span
    class="client-account-shipping-icon d-inline-flex flex-shrink-0"
    :class="isActive ? 'text-success' : 'text-danger'"
    :title="title"
    role="img"
    :aria-label="title"
  >
    <svg
      :width="sizePx"
      :height="sizePx"
      fill="currentColor"
      viewBox="0 0 24 24"
      aria-hidden="true"
    >
      <path :d="PORTAL_MATERIAL_ICON.localShipping" />
    </svg>
  </span>
</template>
