<script setup>
import { computed } from "vue";
import { resolvePublicUrl } from "../../utils/resolvePublicUrl.js";
import {
  avatarClassFromSeed,
  initialsFromName,
} from "../../utils/avatarDisplay.js";

const props = defineProps({
  name: { type: String, default: "" },
  email: { type: String, default: "" },
  avatarUrl: { type: String, default: "" },
  size: {
    type: String,
    default: "md",
    validator: (v) => ["sm", "md"].includes(v),
  },
});

const resolvedUrl = computed(() => {
  const raw = String(props.avatarUrl || "").trim();
  if (!raw) return null;
  return resolvePublicUrl(raw) || raw;
});

const initials = computed(() => initialsFromName(props.name || props.email));
const paletteClass = computed(() =>
  avatarClassFromSeed(props.email || props.name),
);
const sizeClass = computed(() =>
  props.size === "sm" ? "crm-note-author-avatar--sm" : "crm-note-author-avatar--md",
);
</script>

<template>
  <img
    v-if="resolvedUrl"
    :src="resolvedUrl"
    alt=""
    class="crm-note-author-avatar rounded-circle flex-shrink-0 object-fit-cover"
    :class="sizeClass"
  />
  <span
    v-else
    class="crm-note-author-avatar crm-note-author-avatar--initials rounded-circle flex-shrink-0 d-inline-flex align-items-center justify-content-center small fw-semibold"
    :class="[paletteClass, sizeClass]"
    aria-hidden="true"
  >
    {{ initials }}
  </span>
</template>

<style scoped>
.crm-note-author-avatar--md {
  width: 2rem;
  height: 2rem;
}

.crm-note-author-avatar--sm {
  width: 1.75rem;
  height: 1.75rem;
  font-size: 0.625rem;
}
</style>
