<script setup>
import { computed } from "vue";
import { RouterLink } from "vue-router";
import { BRAND_MARK_SRC } from "../../utils/brandAssets.js";

const markSrc = computed(() => BRAND_MARK_SRC());
</script>

<template>
  <div class="auth-vuexy-shell">
    <div class="auth-vuexy-hero" aria-hidden="true">
      <div class="auth-vuexy-hero__blobs">
        <div class="auth-vuexy-hero__blob auth-vuexy-hero__blob--1" />
        <div class="auth-vuexy-hero__blob auth-vuexy-hero__blob--2" />
        <div class="auth-vuexy-hero__ring" />
      </div>
      <div class="auth-vuexy-hero__content">
        <div class="auth-vuexy-hero__figure">
          <div class="auth-vuexy-hero__mock-card auth-vuexy-hero__mock-card--profit">
            <div class="small text-body-secondary mb-0">Profit</div>
            <div class="auth-vuexy-mock-line" />
            <div class="d-flex align-items-baseline justify-content-between mt-1">
              <span class="auth-vuexy-mock-value">624k</span>
              <span class="auth-vuexy-mock-badge">+8.24%</span>
            </div>
          </div>
          <div class="auth-vuexy-hero__mock-card auth-vuexy-hero__mock-card--orders">
            <div class="small text-body-secondary mb-0">Orders</div>
            <div class="auth-vuexy-mock-chart">
              <div class="auth-vuexy-mock-bar" style="height: 35%" />
              <div class="auth-vuexy-mock-bar auth-vuexy-mock-bar--hi" style="height: 100%" />
              <div class="auth-vuexy-mock-bar" style="height: 55%" />
              <div class="auth-vuexy-mock-bar" style="height: 70%" />
            </div>
            <div class="d-flex align-items-baseline justify-content-between mt-1">
              <span class="auth-vuexy-mock-value">124k</span>
              <span class="auth-vuexy-mock-badge">+12.6%</span>
            </div>
          </div>
          <div class="auth-vuexy-character">
            <div class="auth-vuexy-character__leg auth-vuexy-character__leg--l" />
            <div class="auth-vuexy-character__leg auth-vuexy-character__leg--r" />
            <div class="auth-vuexy-character__body" />
            <div class="auth-vuexy-character__arm" />
            <div class="auth-vuexy-character__head" />
          </div>
        </div>
        <p class="auth-vuexy-hero__title">Save Rack</p>
        <p class="auth-vuexy-hero__sub">CRM administration portal</p>
      </div>
    </div>

    <div class="auth-vuexy-form-column">
      <div class="auth-vuexy-form-inner">
        <RouterLink to="/login" class="auth-vuexy-top-brand">
          <img :src="markSrc" alt="" width="40" height="40" />
          <span>Save Rack</span>
        </RouterLink>
        <slot />
      </div>
    </div>
  </div>
</template>
