<script setup>
import { computed, onMounted, ref } from "vue";
import { RouterLink, useRoute, useRouter } from "vue-router";
import api from "./services/api";
import CrmAdminShell from "./components/layout/CrmAdminShell.vue";
import {
  clearCrmOwnerCache,
  setTicketNavFromUser,
  setWebmasterNavFromUser,
} from "./router";

const route = useRoute();
const router = useRouter();
const me = ref(null);
const navLoading = ref(true);

const showShell = computed(() => {
  const p = route.path;
  return (
    !p.startsWith("/login") &&
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
  try {
    const { data } = await api.get("/auth/me");
    me.value = data;
    setTicketNavFromUser(data);
    setWebmasterNavFromUser(data);
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

onMounted(loadMe);

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
      class="flex min-h-screen items-center justify-center bg-gray-50 text-gray-500 dark:bg-gray-950 dark:text-gray-400"
    >
      Loading…
    </div>

    <CrmAdminShell
      v-else-if="me"
      :user="me"
      @logout="logout"
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
        You need to sign in to continue.
      </p>
      <RouterLink
        to="/login"
        class="rounded-lg bg-[#206ba4] px-4 py-2 text-sm font-semibold text-white hover:opacity-95"
      >
        Sign in
      </RouterLink>
    </div>
  </div>
</template>
