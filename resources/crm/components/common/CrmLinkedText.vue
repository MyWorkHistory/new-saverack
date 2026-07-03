<script setup>
import { computed } from "vue";
import { formatTextWithLinks } from "../../utils/formatTextWithLinks.js";

const props = defineProps({
  text: { type: String, default: "" },
  empty: { type: String, default: "—" },
});

const html = computed(() => {
  const out = formatTextWithLinks(props.text);
  return out || props.empty;
});

const isEmpty = computed(() => !formatTextWithLinks(props.text));
</script>

<template>
  <p v-if="isEmpty" class="mb-0 text-body small">{{ empty }}</p>
  <div v-else class="crm-linked-text small text-body lh-lg mb-0" v-html="html" />
</template>

<style scoped>
.crm-linked-text :deep(.crm-linked-text__link) {
  color: var(--bs-link-color, #0d6efd);
  text-decoration: underline;
  word-break: break-word;
}

.crm-linked-text :deep(.crm-linked-text__link:hover) {
  color: var(--bs-link-hover-color, #0a58ca);
}
</style>
