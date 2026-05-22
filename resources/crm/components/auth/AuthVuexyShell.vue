<script setup>
import { computed } from "vue";
import { RouterLink } from "vue-router";
import { BRAND_MARK_SRC } from "../../utils/brandAssets.js";
import { loginHeroImageUrl } from "../../utils/loginBackgrounds.js";

const markSrc = computed(() => BRAND_MARK_SRC());
const heroImageSrc = computed(() => loginHeroImageUrl());
</script>

<template>
  <div class="auth-vuexy-shell">
    <div class="auth-vuexy-hero" aria-hidden="true">
      <img
        class="auth-vuexy-hero__image"
        :src="heroImageSrc"
        alt=""
        decoding="async"
      />
    </div>

    <div class="auth-vuexy-form-column">
      <div class="auth-vuexy-form-inner">
        <RouterLink to="/login" class="auth-vuexy-top-brand">
          <img :src="markSrc" alt="" width="40" height="40" />
          <span>Save Rack Fulfillment</span>
        </RouterLink>
        <slot />
      </div>
    </div>
  </div>
</template>
