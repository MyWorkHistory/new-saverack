<script setup>
import { computed } from "vue";
import { useRoute } from "vue-router";
import { useCrmSidebar } from "../../composables/useCrmSidebar";
import CrmHeader from "./CrmHeader.vue";
import CrmUserSidebar from "./CrmUserSidebar.vue";

defineProps({
  user: { type: Object, required: true },
});

defineEmits(["logout", "refresh-user"]);

const route = useRoute();
const { isMobileOpen, toggleMobileSidebar, mainWrapClass } = useCrmSidebar();

const useWideCrmContent = computed(
  () =>
    route.path.startsWith("/users/orders") ||
    route.path === "/users/dashboard" ||
    route.path.startsWith("/users/inventory") ||
    route.path.startsWith("/users/asn") ||
    route.path.startsWith("/users/returns") ||
    route.path === "/users/account-settings" ||
    route.path === "/users/support",
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
    <CrmUserSidebar :user="user" />

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
    </div>
  </div>
</template>
