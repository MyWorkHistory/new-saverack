<script setup>
import { onMounted, onUnmounted, ref } from "vue";
import {
  LOGIN_BG_INTERVAL_MS,
  loginBackgroundImageUrls,
} from "../../utils/loginBackgrounds.js";

const urls = loginBackgroundImageUrls();
const activeIndex = ref(0);

let timerId = null;

onMounted(() => {
  if (urls.length <= 1) return;
  timerId = window.setInterval(() => {
    activeIndex.value = (activeIndex.value + 1) % urls.length;
  }, LOGIN_BG_INTERVAL_MS);
});

onUnmounted(() => {
  if (timerId != null) {
    clearInterval(timerId);
    timerId = null;
  }
});
</script>

<template>
  <div
    class="relative hidden min-h-[280px] flex-1 overflow-hidden lg:flex lg:min-h-screen"
  >
    <div
      class="absolute inset-0 bg-gradient-to-br from-slate-800 via-slate-900 to-[#0c1929]"
    />

    <img
      v-for="(url, i) in urls"
      :key="url"
      :src="url"
      alt=""
      class="absolute inset-0 h-full w-full object-cover transition-opacity duration-[1000ms] ease-in-out"
      :class="i === activeIndex ? 'opacity-100' : 'opacity-0'"
      loading="eager"
      decoding="async"
    />

    <div
      class="pointer-events-none absolute inset-0 z-[1] bg-gradient-to-t from-black/70 via-black/35 to-black/25"
    />

    <div
      class="relative z-10 flex flex-1 flex-col items-center justify-center px-10 py-16 text-center"
    >
      <slot />
    </div>
  </div>
</template>
