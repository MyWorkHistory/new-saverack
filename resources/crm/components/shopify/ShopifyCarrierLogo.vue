<script setup>
import { computed } from "vue";

const props = defineProps({
  carrier: { type: String, default: "" },
  size: { type: Number, default: 22 },
});

/** Brand logo assets under public/images/carriers (UPS = Wikimedia shield; others = Simple Icons). */
const META = {
  UPS: { src: "/images/carriers/ups.svg", pad: 0 },
  USPS: { src: "/images/carriers/usps.svg", pad: 3, bg: "#EEF2FF" },
  FEDEX: { src: "/images/carriers/fedex.svg", pad: 3, bg: "#F5F3FF" },
  DHL: { src: "/images/carriers/dhl.svg", pad: 3, bg: "#D40511" },
};

const key = computed(() => {
  const c = String(props.carrier || "").toUpperCase().replace(/\s+/g, "");
  if (c === "FEDEX" || c === "FEDX") return "FEDEX";
  if (c === "USPS" || c === "ENDICIA") return "USPS";
  if (c === "DHL") return "DHL";
  if (c === "UPS") return "UPS";
  return "";
});

const meta = computed(() => META[key.value] || null);

const boxStyle = computed(() => {
  const m = meta.value;
  const size = props.size;
  if (!m) {
    return { width: `${size}px`, height: `${size}px` };
  }
  return {
    width: `${size}px`,
    height: `${size}px`,
    padding: m.pad ? `${m.pad}px` : "0",
    background: m.bg || "transparent",
  };
});
</script>

<template>
  <span class="so-carrier-logo" :style="boxStyle" aria-hidden="true">
    <img v-if="meta" :src="meta.src" :alt="key" class="so-carrier-logo__img">
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
  align-items: center;
  justify-content: center;
  border-radius: 4px;
  overflow: hidden;
  box-sizing: border-box;
}
.so-carrier-logo__img {
  display: block;
  width: 100%;
  height: 100%;
  object-fit: contain;
}
</style>
