<script setup>
import { useCrmSidebar } from "../../composables/useCrmSidebar";
import CrmHeader from "./CrmHeader.vue";
import CrmSidebar from "./CrmSidebar.vue";

defineProps({
  user: { type: Object, required: true },
});

defineEmits(["logout", "refresh-user"]);

const { isMobileOpen, toggleMobileSidebar, mainMarginClass } = useCrmSidebar();
</script>

<template>
  <div class="min-h-screen bg-gray-50 dark:bg-gray-950">
    <CrmSidebar :user="user" />

    <div
      v-if="isMobileOpen"
      class="fixed inset-0 z-[90] bg-gray-900/50 lg:hidden"
      aria-hidden="true"
      @click="toggleMobileSidebar"
    />

    <div
      :class="[
        'min-h-screen flex-1 transition-all duration-300 ease-in-out',
        mainMarginClass,
      ]"
    >
      <CrmHeader
        :user="user"
        @logout="$emit('logout')"
        @refresh-user="$emit('refresh-user')"
      />
      <div class="mx-auto max-w-[var(--breakpoint-2xl)] p-4 md:p-6">
        <slot />
      </div>
    </div>
  </div>
</template>
