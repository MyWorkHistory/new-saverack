<script setup>
import { computed } from "vue";
import { useRoute } from "vue-router";
import { useCrmSidebar } from "../../composables/useCrmSidebar";
import CrmHeader from "./CrmHeader.vue";
import CrmSidebar from "./CrmSidebar.vue";

defineProps({
  user: { type: Object, required: true },
});

defineEmits(["logout", "refresh-user"]);

const route = useRoute();
const { isMobileOpen, toggleMobileSidebar, mainWrapClass } = useCrmSidebar();

/** Full-width main column with demo-like gutters (no 1440px cap). */
const useWideCrmContent = computed(
  () =>
    route.path === "/dashboard" ||
    route.path.startsWith("/staff") ||
    route.path.startsWith("/clients") ||
    route.path.startsWith("/webmaster"),
);

const crmContentClass = computed(() =>
  [
    "crm-content",
    "flex-grow-1",
    useWideCrmContent.value ? "crm-content--staff-wide" : "",
  ].filter(Boolean),
);
</script>

<template>
  <div class="crm-app-shell">
    <CrmSidebar :user="user" />

    <div
      v-if="isMobileOpen"
      class="crm-backdrop-nav d-lg-none"
      aria-hidden="true"
      @click="toggleMobileSidebar"
    />

    <div :class="mainWrapClass">
      <CrmHeader
        :user="user"
        @logout="$emit('logout')"
        @refresh-user="$emit('refresh-user')"
      />
      <div :class="crmContentClass">
        <slot />
      </div>
      <footer class="vx-app-footer flex-shrink-0 mt-auto">
        <div class="vx-app-footer__inner">
          <span>
            © {{ new Date().getFullYear() }}, made with
            <span class="text-danger" aria-hidden="true">♥</span>
            by
            <a href="#" class="text-decoration-none fw-medium">Save Rack</a>
          </span>
          <div class="vx-app-footer__links">
            <a href="mailto:support@saverack.com">Support</a>
            <a href="#">Documentation</a>
          </div>
        </div>
      </footer>
    </div>
  </div>
</template>
