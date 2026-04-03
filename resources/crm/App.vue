<script setup>
import { computed, provide, ref, watch } from "vue";
import { RouterLink, useRoute, useRouter } from "vue-router";
import api from "./services/api";
import CrmAdminShell from "./components/layout/CrmAdminShell.vue";
import CrmLoadingSpinner from "./components/common/CrmLoadingSpinner.vue";
import ToastStack from "./components/common/ToastStack.vue";
import {
  clearCrmOwnerCache,
  setClientsNavFromUser,
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

// Login only sets localStorage; `me` is loaded here. Without this, SPA
// navigation from /login to /dashboard left `me` null (stuck on “sign in”).
// Skip refetch when `me` is already set (normal route changes under the shell).
watch(
  () => route.fullPath,
  () => {
    if (!showShell.value) {
      navLoading.value = false;
      return;
    }
    if (!localStorage.getItem("auth_token")) {
      me.value = null;
      navLoading.value = false;
      return;
    }
    if (me.value) {
      return;
    }
    loadMe();
  },
  { immediate: true },
);

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
  <div class="min-h-screen">
    <template v-if="!showShell">
      <router-view
        :key="route.fullPath"
        @refresh-user="loadMe"
      />
    </template>

    <div
      v-else-if="navLoading"
      class="flex min-h-screen items-center justify-center bg-gray-50 dark:bg-gray-950"
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
      class="flex min-h-screen flex-col items-center justify-center gap-2 bg-gray-50 px-4 dark:bg-gray-950"
    >
      <p class="text-sm text-gray-600 dark:text-gray-400">
        You Need To Sign In To Continue.
      </p>
      <RouterLink
        to="/login"
        class="rounded-lg bg-[#2563eb] px-4 py-2 text-sm font-semibold text-white hover:opacity-95"
      >
        Sign In
      </RouterLink>
    </div>

    <ToastStack />
  </div>
</template>
