<script setup>
import { computed } from "vue";

const props = defineProps({
  carrier: { type: String, default: "" },
  size: { type: Number, default: 22 },
});

const key = computed(() => {
  const c = String(props.carrier || "").toUpperCase().replace(/\s+/g, "");
  if (c === "FEDEX" || c === "FEDX") return "FEDEX";
  if (c === "USPS" || c === "ENDICIA") return "USPS";
  if (c === "DHL") return "DHL";
  if (c === "UPS") return "UPS";
  return "";
});
</script>

<template>
  <span class="so-carrier-logo" :style="{ width: size + 'px', height: size + 'px' }" aria-hidden="true">
    <!-- UPS -->
    <svg v-if="key === 'UPS'" viewBox="0 0 32 32" width="100%" height="100%">
      <rect width="32" height="32" rx="4" fill="#351C15" />
      <path fill="#FFB500" d="M8 22V10h5.2c2.4 0 3.9 1.3 3.9 3.3 0 1.4-.8 2.5-2.1 2.9L18.2 22h-2.6l-2.9-5.2H10.4V22H8zm2.4-7.2h2.5c1.1 0 1.7-.5 1.7-1.4s-.6-1.4-1.7-1.4h-2.5v2.8z" />
    </svg>
    <!-- USPS -->
    <svg v-else-if="key === 'USPS'" viewBox="0 0 32 32" width="100%" height="100%">
      <rect width="32" height="32" rx="4" fill="#004B87" />
      <path fill="#fff" d="M6 11h20v2.2H6V11zm0 4.2h20V17H6v-1.8zm0 4.2h14V21H6v-1.6z" />
      <circle cx="24" cy="20.2" r="2.2" fill="#E31837" />
    </svg>
    <!-- FedEx -->
    <svg v-else-if="key === 'FEDEX'" viewBox="0 0 32 32" width="100%" height="100%">
      <rect width="32" height="32" rx="4" fill="#4D148C" />
      <text x="4" y="21" fill="#FF6600" font-size="9" font-weight="800" font-family="Arial,sans-serif">Fx</text>
    </svg>
    <!-- DHL -->
    <svg v-else-if="key === 'DHL'" viewBox="0 0 32 32" width="100%" height="100%">
      <rect width="32" height="32" rx="4" fill="#FFCC00" />
      <text x="3.5" y="21" fill="#D40511" font-size="10" font-weight="900" font-family="Arial,sans-serif">DHL</text>
    </svg>
    <svg v-else viewBox="0 0 32 32" width="100%" height="100%">
      <rect width="32" height="32" rx="4" fill="#E5E7EB" />
      <path fill="#6B7280" d="M8 16h16v2H8zm0-4h12v2H8zm0 8h10v2H8z" />
    </svg>
  </span>
</template>

<style scoped>
.so-carrier-logo {
  display: inline-flex;
  flex-shrink: 0;
  vertical-align: middle;
}
.so-carrier-logo svg {
  display: block;
  border-radius: 4px;
}
</style>
