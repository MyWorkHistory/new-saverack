<script setup>
import { computed, ref } from "vue";
import {
  accountRowAvatarUrl,
  accountRowInitials,
  avatarClassFromSeed,
  initialsFromName,
} from "../../utils/avatarDisplay.js";
import { resolvePublicUrl } from "../../utils/resolvePublicUrl.js";

const props = defineProps({
  account: { type: Object, required: true },
  /** sm = home rows (2.25rem), md = list (2.75rem, same as staff list) */
  size: { type: String, default: "md" },
  /** When true, only brand logo (no primary user avatar fallback). */
  brandOnly: { type: Boolean, default: false },
});

const imageFailed = ref(false);

const avatarUrl = computed(() => {
  if (props.brandOnly) {
    const brand = String(props.account?.brand_logo_url || "").trim();
    return brand || null;
  }
  return accountRowAvatarUrl(props.account);
});

const showImage = computed(() => Boolean(avatarUrl.value) && !imageFailed.value);

const initials = computed(() => {
  if (props.brandOnly) {
    return initialsFromName(String(props.account?.company_name || ""));
  }
  return accountRowInitials(props.account);
});

const colorClass = computed(() => {
  const seed = String(props.account?.email || props.account?.company_name || "");
  return avatarClassFromSeed(seed);
});

const sizeStyle = computed(() =>
  props.size === "sm"
    ? { width: "2.25rem", height: "2.25rem" }
    : { width: "2.75rem", height: "2.75rem" },
);

function onImageError() {
  imageFailed.value = true;
}
</script>

<template>
  <span
    class="flex-shrink-0 rounded-circle overflow-hidden bg-body-secondary d-inline-flex"
    :style="sizeStyle"
    aria-hidden="true"
  >
    <img
      v-if="showImage"
      :src="resolvePublicUrl(avatarUrl)"
      alt=""
      class="w-100 h-100 object-fit-cover"
      @error="onImageError"
    />
    <span
      v-else
      class="d-flex w-100 h-100 align-items-center justify-content-center fw-semibold staff-user-cell__meta text-uppercase"
      :class="colorClass"
    >
      {{ initials }}
    </span>
  </span>
</template>
