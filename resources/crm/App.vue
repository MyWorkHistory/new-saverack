<script setup>
import { computed, onMounted, provide, ref, watch } from "vue";
import { initCrmTheme } from "./composables/useCrmTheme.js";
import { RouterLink, useRoute, useRouter } from "vue-router";
import api from "./services/api";
import CrmAdminShell from "./components/layout/CrmAdminShell.vue";
import CrmLoadingSpinner from "./components/common/CrmLoadingSpinner.vue";
import ToastStack from "./components/common/ToastStack.vue";
import {
  clearCrmOwnerCache,
  setBillingNavFromUser,
  setClientsNavFromUser,
  setInventoryNavFromUser,
  setUsersNavFromUser,
  setWebmasterNavFromUser,
} from "./router";

const route = useRoute();
const router = useRouter();
const me = ref(null);
const navLoading = ref(false);

/** Same user as shell/sidebar; pages use inject("crmUser") for permission checks. */
provide("crmUser", me);

const showShell = computed(() => {
  const p = route.path;
  return (
    !p.startsWith("/login") &&
    !p.startsWith("/create") &&
    !p.startsWith("/forgot-password") &&
    !p.startsWith("/reset-password")
  );
});

const loadMe = async () => {
  if (!localStorage.getItem("auth_token")) {
    me.value = null;
    navLoading.value = false;
    return;
  }
  navLoading.value = true;
  try {
    const { data } = await api.get("/auth/me");
    me.value = data;
    setWebmasterNavFromUser(data);
    setUsersNavFromUser(data);
    setClientsNavFromUser(data);
    setBillingNavFromUser(data);
    setInventoryNavFromUser(data);
  } catch (e) {
    me.value = null;
    if (e.response?.status === 401) {
      localStorage.removeItem("auth_token");
      clearCrmOwnerCache();
      router.replace({ name: "login", query: { redirect: route.fullPath } });
    }
  } finally {
    navLoading.value = false;
  }
};

// Load /auth/me when entering the authenticated shell, or when `me` is still empty.
// Important: do not skip load when `me` is already set but the user just signed in
// as a different account (token replaced on /login while stale `me` stayed in memory),
// which hid Staff/Webmaster for admins and broke permission UI.
watch(
  showShell,
  (shell, prevShell) => {
    if (!shell) {
      navLoading.value = false;
      return;
    }
    if (!localStorage.getItem("auth_token")) {
      me.value = null;
      navLoading.value = false;
      return;
    }
    const enteredShellFromPublic = prevShell === false;
    if (me.value === null || enteredShellFromPublic) {
      loadMe();
    }
  },
  { immediate: true },
);

onMounted(() => {
  initCrmTheme();
});

const logout = async () => {
  try {
    await api.post("/auth/logout");
  } catch {
    /* ignore */
  }
  localStorage.removeItem("auth_token");
  clearCrmOwnerCache();
  me.value = null;
  router.push("/login");
};
</script>

<template>
  <div class="min-vh-100 d-flex flex-column">
    <template v-if="!showShell">
      <router-view
        :key="route.fullPath"
        @refresh-user="loadMe"
      />
    </template>

    <div
      v-else-if="navLoading"
      class="min-vh-100 d-flex align-items-center justify-content-center"
    >
      <CrmLoadingSpinner message="Loading…" :center="true" />
    </div>

    <CrmAdminShell
      v-else-if="me"
      :user="me"
      @logout="logout"
      @refresh-user="loadMe"
    >
      <router-view
        :key="route.fullPath"
        @refresh-user="loadMe"
      />
    </CrmAdminShell>

    <div
      v-else
      class="min-vh-100 d-flex flex-column align-items-center justify-content-center gap-2 px-3"
    >
      <p class="small text-secondary mb-0">
        You need to sign in to continue.
      </p>
      <RouterLink to="/login" class="btn btn-primary fw-semibold">
        Sign in
      </RouterLink>
    </div>

    <ToastStack />
  </div>
</template>
