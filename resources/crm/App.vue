<script setup>
import { computed, onMounted, ref } from "vue";
import { useRoute, useRouter } from "vue-router";
import brandMarkUrl from "@public/images/logo/logo-icon.svg?url";
import api from "./services/api";
import { clearCrmOwnerCache } from "./router";

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
  } catch {
    me.value = null;
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

const go = (path) => {
  router.push(path);
};

const navBtn = (active) =>
  active
    ? "bg-white text-[#206ba4] shadow-sm"
    : "text-white/95 hover:bg-white/15";
</script>

<template>
  <div class="min-h-screen bg-slate-100 dark:bg-gray-950">
    <!-- Brand bar (SaveRack / TailAdmin-style blue) — not the old stub text-only header -->
    <header
      v-if="showShell"
      class="border-b border-[#1a5d8f] bg-[#206ba4] shadow-sm"
    >
      <div
        class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-3 px-4 py-3 lg:px-8"
      >
        <div class="flex flex-wrap items-center gap-5">
          <button
            type="button"
            class="flex items-center gap-2.5 rounded-lg text-left text-white transition hover:opacity-95"
            @click="go('/dashboard')"
          >
            <img
              :src="brandMarkUrl"
              alt=""
              class="h-9 w-9 shrink-0 rounded-lg bg-white/15 p-1 shadow-sm ring-1 ring-white/20"
              width="36"
              height="36"
            />
            <span class="text-lg font-semibold tracking-tight">SaveRack CRM</span>
          </button>
          <nav
            v-if="!navLoading && me"
            class="flex flex-wrap items-center gap-1 text-sm font-medium"
          >
            <button
              type="button"
              class="rounded-lg px-3 py-2 transition"
              :class="navBtn(route.path.startsWith('/dashboard'))"
              @click="go('/dashboard')"
            >
              Dashboard
            </button>
            <button
              type="button"
              class="rounded-lg px-3 py-2 transition"
              :class="navBtn(route.path.startsWith('/users'))"
              @click="go('/users')"
            >
              Users
            </button>
            <template v-if="me.is_crm_owner">
              <button
                type="button"
                class="rounded-lg px-3 py-2 transition"
                :class="
                  navBtn(
                    route.path.startsWith('/tickets') &&
                      !route.path.includes('/board'),
                  )
                "
                @click="go('/tickets')"
              >
                Tickets
              </button>
              <button
                type="button"
                class="rounded-lg px-3 py-2 transition"
                :class="navBtn(route.path.includes('/tickets/board'))"
                @click="go('/tickets/board')"
              >
                Board
              </button>
            </template>
          </nav>
        </div>
        <div
          v-if="me"
          class="flex items-center gap-3 text-sm text-white/95"
        >
          <span class="hidden sm:inline">{{ me.name }}</span>
          <button
            type="button"
            class="rounded-lg border border-white/30 bg-white/10 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-white/20"
            @click="logout"
          >
            Log out
          </button>
        </div>
      </div>
    </header>

    <main
      :class="
        showShell ? 'mx-auto max-w-7xl px-4 py-6 lg:px-8 lg:py-8' : ''
      "
    >
      <router-view @refresh-user="loadMe" />
    </main>
  </div>
</template>
