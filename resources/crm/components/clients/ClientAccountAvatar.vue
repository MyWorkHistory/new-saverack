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
  /** sm = home rows (~36px), md = list (~44px) */
  size: { type: String, default: "md" },
  /** circle = list, rounded = home panel */
  variant: { type: String, default: "circle" },
  /** When true, only brand logo (no primary user avatar fallback). */
  brandOnly: { type: Boolean, default: false },
});

const imageFailed = ref(false);

const brandLogoUrl = computed(() => {
  const url = String(props.account?.brand_logo_url || "").trim();
  return url || null;
});

const avatarUrl = computed(() => {
  if (props.brandOnly) {
    return brandLogoUrl.value;
  }
  return accountRowAvatarUrl(props.account);
});

const isBrandLogo = computed(() => {
  const url = avatarUrl.value;
  if (!url) return false;
  const brand = brandLogoUrl.value;
  return Boolean(brand && url === brand);
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

const rootClass = computed(() => {
  const classes = [
    "client-account-avatar",
    `client-account-avatar--${props.size}`,
  ];

  if (showImage.value && isBrandLogo.value) {
    classes.push("client-account-avatar--brand", "client-account-avatar--rounded");
  } else {
    classes.push(`client-account-avatar--${props.variant}`);
    if (showImage.value) {
      classes.push("client-account-avatar--photo");
    }
  }

  return classes;
});

function onImageError() {
  imageFailed.value = true;
}
</script>

<template>
  <span :class="rootClass" aria-hidden="true">
    <img
      v-if="showImage"
      :src="resolvePublicUrl(avatarUrl)"
      alt=""
      class="client-account-avatar__img"
      @error="onImageError"
    />
    <span v-else class="client-account-avatar__initials" :class="colorClass">
      {{ initials }}
    </span>
  </span>
</template>
