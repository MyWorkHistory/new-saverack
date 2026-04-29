<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from "vue";
import { RouterLink, useRouter } from "vue-router";
import api from "../../services/api";
import { BRAND_MARK_SRC } from "../../utils/brandAssets.js";
import { useCrmSidebar } from "../../composables/useCrmSidebar";
import { setThemeMode, themeMode } from "../../composables/useCrmTheme.js";
import UserEditModal from "../users/UserEditModal.vue";
import { crmIsAdmin } from "../../utils/crmUser";
import { resolvePublicUrl } from "../../utils/resolvePublicUrl.js";

const props = defineProps({
  user: { type: Object, required: true },
});

const emit = defineEmits(["logout", "refresh-user"]);

const router = useRouter();
const { isMobileOpen, toggleSidebar } = useCrmSidebar();
const markSrc = computed(() => BRAND_MARK_SRC());

const menuOpen = ref(false);
const menuRoot = ref(null);
const editProfileModalOpen = ref(false);
const themeMenuOpen = ref(false);
const themeMenuRoot = ref(null);
const headerSearch = ref("");

const buildEnvLabel = computed(() =>
  import.meta.env.MODE === "production" ? "Prod" : "Dev",
);

const buildEnvTitle = computed(
  () => `Build: ${import.meta.env.MODE}`,
);

const canEditOwnProfile = computed(() => {
  const u = props.user;
  if (!u) return false;
  if (crmIsAdmin(u) || u.is_crm_owner) return true;
  return (
    Array.isArray(u.permission_keys) && u.permission_keys.includes("users.update")
  );
});

function openEditProfileModal() {
  editProfileModalOpen.value = true;
  closeMenu();
}

const accountRoot = ref(null);
const accountOpen = ref(false);
const accountFilter = ref("");
const selectedAccountId = ref("workspace");

const clientAccounts = ref([]);
const accountsLoadState = ref("idle");

const canViewClientAccounts = computed(() => {
  const u = props.user;
  if (!u) return false;
  if (crmIsAdmin(u) || u.is_crm_owner) return true;
  return (
    Array.isArray(u.permission_keys) &&
    u.permission_keys.includes("clients.view")
  );
});

const accountOptions = computed(() => {
  const workspace = {
    id: "workspace",
    kind: "workspace",
    title: "Save Rack",
    subtitle: props.user?.email || "Primary workspace",
    accountId: null,
  };
  if (!canViewClientAccounts.value) {
    return [workspace];
  }
  const rows = clientAccounts.value.map((row) => ({
    id: `ca-${row.id}`,
    kind: "client_account",
    accountId: row.id,
    title: row.company_name || `Account #${row.id}`,
    subtitle:
      row.contact_full_name && String(row.contact_full_name).trim()
        ? String(row.contact_full_name).trim()
        : row.email || "",
  }));
  return [workspace, ...rows];
});

const selectedAccount = computed(() => {
  const list = accountOptions.value;
  return (
    list.find((a) => a.id === selectedAccountId.value) ?? list[0] ?? null
  );
});

const filteredAccounts = computed(() => {
  const q = accountFilter.value.trim().toLowerCase();
  const list = accountOptions.value;
  if (!q) return list;
  return list.filter(
    (a) =>
      a.title.toLowerCase().includes(q) ||
      String(a.subtitle ?? "")
        .toLowerCase()
        .includes(q),
  );
});

function toggleAccountPanel() {
  menuOpen.value = false;
  accountOpen.value = !accountOpen.value;
}

function selectAccount(opt) {
  selectedAccountId.value = opt.id;
  accountOpen.value = false;
  accountFilter.value = "";
}

function openClientAccountDetailInNewTab(accountId) {
  const resolved = router.resolve({
    name: "client-account-detail",
    params: { id: String(accountId) },
  });
  let href = resolved.href;
  if (typeof href !== "string" || href === "") {
    href = router.resolve(`/clients/accounts/${accountId}`).href;
  }
  const absolute = /^https?:\/\//i.test(href)
    ? href
    : new URL(href, window.location.origin).href;
  window.open(absolute, "_blank", "noopener,noreferrer");
  accountOpen.value = false;
  accountFilter.value = "";
}

function onAccountOptionClick(opt) {
  if (opt.kind === "workspace") {
    selectAccount(opt);
    return;
  }
  if (opt.kind === "client_account" && opt.accountId != null) {
    openClientAccountDetailInNewTab(opt.accountId);
  }
}

async function loadClientAccountsForHeader() {
  if (!canViewClientAccounts.value) {
    clientAccounts.value = [];
    accountsLoadState.value = "idle";
    return;
  }
  accountsLoadState.value = "loading";
  const collected = [];
  try {
    let page = 1;
    let lastPage = 1;
    do {
      const { data } = await api.get("/client-accounts", {
        params: {
          per_page: 500,
          page,
          sort_by: "company_name",
          sort_dir: "asc",
        },
      });
      const chunk = Array.isArray(data.data) ? data.data : [];
      collected.push(...chunk);
      lastPage =
        typeof data.last_page === "number" && data.last_page >= 1
          ? data.last_page
          : 1;
      page += 1;
    } while (page <= lastPage && page <= 25);
    clientAccounts.value = collected;
    accountsLoadState.value = "success";
  } catch {
    clientAccounts.value = [];
    accountsLoadState.value = "error";
  }
}

const firstName = computed(() => {
  const n = props.user?.name;
  if (!n || typeof n !== "string") return "";
  return n.trim().split(/\s+/)[0] ?? "";
});

function initials(name) {
  if (!name || typeof name !== "string") return "?";
  const parts = name.trim().split(/\s+/).slice(0, 2);
  return parts.map((p) => p[0]?.toUpperCase() ?? "").join("") || "?";
}

const avatarPalettes = [
  "bg-info-subtle text-info-emphasis border border-info-subtle",
  "bg-primary-subtle text-primary-emphasis border border-primary-subtle",
  "bg-warning-subtle text-warning-emphasis border border-warning-subtle",
  "bg-success-subtle text-success-emphasis border border-success-subtle",
  "bg-danger-subtle text-danger-emphasis border border-danger-subtle",
];

function avatarClass(email) {
  let h = 0;
  const s = email || "";
  for (let i = 0; i < s.length; i++) h = (h + s.charCodeAt(i)) % 997;
  return avatarPalettes[h % avatarPalettes.length];
}

function closeMenu() {
  menuOpen.value = false;
}

function pickTheme(mode) {
  setThemeMode(mode);
  themeMenuOpen.value = false;
}

function onDocClick(e) {
  if (!menuRoot.value?.contains(e.target)) {
    menuOpen.value = false;
  }
  if (!accountRoot.value?.contains(e.target)) {
    accountOpen.value = false;
  }
  if (!themeMenuRoot.value?.contains(e.target)) {
    themeMenuOpen.value = false;
  }
}

function signOut() {
  menuOpen.value = false;
  emit("logout");
}

watch(
  () => [props.user?.id, canViewClientAccounts.value],
  () => {
    loadClientAccountsForHeader();
  },
  { immediate: true },
);

function onGlobalSearchKey(e) {
  if (!(e instanceof KeyboardEvent)) return;
  if ((e.ctrlKey || e.metaKey) && (e.key === "k" || e.key === "K")) {
    e.preventDefault();
    const el = document.getElementById("crm-global-search");
    if (el instanceof HTMLInputElement) {
      el.focus();
      el.select?.();
    }
  }
}

onMounted(() => {
  document.addEventListener("click", onDocClick);
  document.addEventListener("keydown", onGlobalSearchKey);
});

onUnmounted(() => {
  document.removeEventListener("click", onDocClick);
  document.removeEventListener("keydown", onGlobalSearchKey);
});
</script>

<template>
  <header class="crm-navbar">
    <div class="vx-navbar-float">
      <div class="vx-navbar__inner">
        <div
          class="d-flex flex-wrap flex-lg-nowrap align-items-center gap-2 w-100"
        >
        <div class="d-flex align-items-center gap-2 flex-shrink-0">
          <button
            type="button"
            class="btn vx-icon-btn d-lg-none flex-shrink-0"
            aria-label="Toggle sidebar"
            @click="toggleSidebar"
          >
            <svg
              v-if="isMobileOpen"
              width="22"
              height="22"
              fill="none"
              viewBox="0 0 24 24"
              stroke="currentColor"
              stroke-width="2"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="M6 18L18 6M6 6l12 12"
              />
            </svg>
            <svg
              v-else
              width="20"
              height="16"
              viewBox="0 0 16 12"
              fill="none"
              xmlns="http://www.w3.org/2000/svg"
            >
              <path
                fill-rule="evenodd"
                clip-rule="evenodd"
                d="M0.583252 1C0.583252 0.585788 0.919038 0.25 1.33325 0.25H14.6666C15.0808 0.25 15.4166 0.585786 15.4166 1C15.4166 1.41421 15.0808 1.75 14.6666 1.75L1.33325 1.75C0.919038 1.75 0.583252 1.41422 0.583252 1ZM0.583252 11C0.583252 10.5858 0.919038 10.25 1.33325 10.25L14.6666 10.25C15.0808 10.25 15.4166 10.5858 15.4166 11C15.4166 11.4142 15.0808 11.75 14.6666 11.75L1.33325 11.75C0.919038 11.75 0.583252 11.4142 0.583252 11ZM1.33325 5.25C0.919038 5.25 0.583252 5.58579 0.583252 6C0.583252 6.41421 0.919038 6.75 1.33325 6.75L7.99992 6.75C8.41413 6.75 8.74992 6.41421 8.74992 6C8.74992 5.58579 8.41413 5.25 7.99992 5.25L1.33325 5.25Z"
                fill="currentColor"
              />
            </svg>
          </button>

          <div
            ref="accountRoot"
            class="position-relative flex-grow-1 flex-md-grow-0 min-w-0 d-none d-md-block crm-navbar-account"
          >
            <button
              type="button"
              class="btn btn-light border-0 d-flex align-items-center gap-2 w-100 text-start rounded-pill py-2 px-3"
              :aria-expanded="accountOpen"
              aria-haspopup="listbox"
              @click.stop="toggleAccountPanel"
            >
              <span
                class="d-flex align-items-center justify-content-center flex-shrink-0 rounded-3 bg-primary-subtle text-primary"
                style="width: 2rem; height: 2rem"
              >
                <svg
                  width="16"
                  height="16"
                  fill="none"
                  viewBox="0 0 24 24"
                  stroke="currentColor"
                  stroke-width="2"
                  aria-hidden="true"
                >
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"
                  />
                </svg>
              </span>
              <span class="min-w-0 flex-grow-1 text-truncate">
                <span
                  class="d-block text-uppercase fw-semibold text-secondary"
                  style="font-size: 0.6rem; letter-spacing: 0.06em"
                  >Account</span
                >
                <span class="d-block text-truncate fw-semibold text-body small">
                  {{ selectedAccount?.title ?? "Save Rack" }}
                </span>
              </span>
              <svg
                class="flex-shrink-0 text-secondary transition"
                :class="{ 'rotate-180': accountOpen }"
                width="16"
                height="16"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
                stroke-width="2"
                aria-hidden="true"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  d="M19 9l-7 7-7-7"
                />
              </svg>
            </button>

            <div
              v-if="accountOpen"
              class="position-absolute start-0 end-0 mt-2 rounded-4 border bg-body shadow-lg overflow-hidden"
              style="z-index: 1080"
              role="listbox"
            >
              <div class="border-bottom p-2">
                <input
                  v-model="accountFilter"
                  type="search"
                  autocomplete="off"
                  placeholder="Search accounts…"
                  class="form-control form-control-sm"
                  @click.stop
                />
              </div>
              <ul class="list-unstyled mb-0 overflow-auto" style="max-height: 14rem">
                <li
                  v-if="
                    canViewClientAccounts && accountsLoadState === 'loading'
                  "
                  class="px-3 py-4 text-center small text-secondary"
                >
                  Loading accounts…
                </li>
                <li
                  v-else-if="
                    canViewClientAccounts &&
                    accountsLoadState === 'error' &&
                    !clientAccounts.length
                  "
                  class="px-3 py-4 text-center small text-danger"
                >
                  Could not load accounts.
                </li>
                <template v-else>
                  <li v-for="opt in filteredAccounts" :key="opt.id">
                    <button
                      type="button"
                      class="btn btn-link text-start text-decoration-none text-body w-100 rounded-0 py-2 px-3 d-flex flex-column align-items-start"
                      :class="{
                        'bg-body-secondary':
                          opt.kind === 'workspace' &&
                          opt.id === selectedAccountId,
                      }"
                      role="option"
                      :aria-selected="
                        opt.kind === 'workspace' &&
                        opt.id === selectedAccountId
                      "
                      @click.stop="onAccountOptionClick(opt)"
                    >
                      <span
                        class="d-flex w-100 min-w-0 justify-content-between gap-2"
                      >
                        <span class="text-truncate fw-medium">{{
                          opt.title
                        }}</span>
                        <span
                          v-if="opt.kind === 'client_account'"
                          class="flex-shrink-0 text-uppercase text-secondary"
                          style="font-size: 0.65rem"
                          >New tab</span
                        >
                      </span>
                      <span class="small text-secondary">{{
                        opt.subtitle
                      }}</span>
                    </button>
                  </li>
                </template>
              </ul>
            </div>
          </div>

          <RouterLink
            to="/dashboard"
            class="d-none d-sm-flex d-lg-none align-items-center gap-2 text-decoration-none text-body flex-shrink-0"
          >
            <img
              :src="markSrc"
              alt=""
              width="44"
              height="44"
              class="rounded object-fit-contain"
            />
            <span class="fw-bold text-truncate" style="max-width: 5.5rem"
              >Save Rack</span
            >
          </RouterLink>
        </div>

        <div
          class="d-flex align-items-center vx-search-merge flex-grow-1 min-w-0 order-3 order-lg-2 w-100 w-lg-auto mx-lg-1"
        >
          <div class="input-group w-100">
            <span class="input-group-text border-end-0">
              <svg
                width="20"
                height="20"
                class="text-secondary opacity-75 flex-shrink-0"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
                stroke-width="1.5"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"
                />
              </svg>
            </span>
            <input
              id="crm-global-search"
              v-model="headerSearch"
              type="search"
              class="form-control border-start-0"
              placeholder="Search [CTRL + K]"
              autocomplete="off"
              aria-label="Search"
            />
          </div>
        </div>

        <div
          class="d-flex align-items-center gap-1 gap-sm-2 flex-shrink-0 ms-lg-auto order-2 order-lg-3"
        >
          <span
            class="badge rounded-pill vx-navbar-env-badge text-bg-secondary text-uppercase d-none d-sm-inline me-1"
            :title="buildEnvTitle"
            >{{ buildEnvLabel }}</span
          >

          <button
            type="button"
            class="btn vx-icon-btn d-none d-sm-inline-flex"
            aria-label="Language"
            title="Language"
          >
            <svg
              width="20"
              height="20"
              fill="none"
              viewBox="0 0 24 24"
              stroke="currentColor"
              stroke-width="1.5"
              aria-hidden="true"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="m10.5 21 5.25-11.25L21 21m-9-3h7.5M3 5.621a48.474 48.474 0 0 1 6-.371m0 0c1.12 0 2.233.038 3.334.114M9 5.25v13.5m.375-19.5h7.5m-12 0H9m0 0c-.884.724-1.735 1.44-2.548 2.13M9 5.25c.84 1.037 1.763 2.04 2.75 3M15 5.25a48.416 48.416 0 0 0-8 .372m0-.372c-.884.724-1.735 1.44-2.548 2.13"
              />
            </svg>
          </button>

          <div ref="themeMenuRoot" class="position-relative">
            <button
              type="button"
              class="btn vx-icon-btn position-relative"
              aria-label="Theme"
              aria-haspopup="true"
              :aria-expanded="themeMenuOpen"
              @click.stop="themeMenuOpen = !themeMenuOpen"
            >
              <svg
                width="20"
                height="20"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
                stroke-width="1.5"
                aria-hidden="true"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  d="M12 3v2.25m6.364.386-1.591 1.591M21 12h-2.25m-.386 6.364-1.591-1.591M12 18.75V21m-4.773-4.227-1.591 1.591M5.25 12H3m4.773-4.773-1.591-1.591M15.75 12a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0Z"
                />
              </svg>
            </button>
            <div
              v-if="themeMenuOpen"
              class="position-absolute end-0 mt-2 rounded-4 border bg-body shadow py-1"
              style="min-width: 11rem; z-index: 1080"
              role="menu"
            >
              <button
                type="button"
                class="dropdown-item d-flex align-items-center gap-2 py-2 px-3"
                :class="{ active: themeMode === 'light' }"
                @click="pickTheme('light')"
              >
                Light
              </button>
              <button
                type="button"
                class="dropdown-item d-flex align-items-center gap-2 py-2 px-3"
                :class="{ active: themeMode === 'dark' }"
                @click="pickTheme('dark')"
              >
                Dark
              </button>
              <button
                type="button"
                class="dropdown-item d-flex align-items-center gap-2 py-2 px-3"
                :class="{ active: themeMode === 'system' }"
                @click="pickTheme('system')"
              >
                System
              </button>
            </div>
          </div>

          <button
            type="button"
            class="btn vx-icon-btn d-none d-md-inline-flex"
            aria-label="Shortcuts"
            title="Shortcuts"
          >
            <svg
              width="20"
              height="20"
              fill="none"
              viewBox="0 0 24 24"
              stroke="currentColor"
              stroke-width="1.5"
              aria-hidden="true"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="M3.75 3A2.25 2.25 0 0 0 1.5 5.25v2.25A2.25 2.25 0 0 0 3.75 9.75h2.25A2.25 2.25 0 0 0 8.25 7.5V5.25A2.25 2.25 0 0 0 6 3H3.75Zm9 0A2.25 2.25 0 0 0 10.5 5.25v2.25a2.25 2.25 0 0 0 2.25 2.25H15a2.25 2.25 0 0 0 2.25-2.25V5.25A2.25 2.25 0 0 0 15 3h-2.25Zm-9 7.5A2.25 2.25 0 0 0 1.5 12.75v2.25a2.25 2.25 0 0 0 2.25 2.25H6a2.25 2.25 0 0 0 2.25-2.25v-2.25A2.25 2.25 0 0 0 6 10.5H3.75Zm9 0A2.25 2.25 0 0 0 10.5 12.75v2.25a2.25 2.25 0 0 0 2.25 2.25H15a2.25 2.25 0 0 0 2.25-2.25v-2.25A2.25 2.25 0 0 0 15 10.5h-2.25Z"
              />
            </svg>
          </button>

          <button
            type="button"
            class="btn vx-icon-btn position-relative"
            aria-label="Notifications"
          >
            <svg
              width="20"
              height="20"
              fill="none"
              viewBox="0 0 24 24"
              stroke="currentColor"
              stroke-width="1.5"
              aria-hidden="true"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 0 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 0 2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3.75 3.75 0 1 1-5.714 0"
              />
            </svg>
            <span
              class="position-absolute rounded-circle bg-danger border border-white p-0"
              style="
                width: 8px;
                height: 8px;
                top: 6px;
                right: 6px;
              "
              aria-hidden="true"
            />
          </button>

          <div ref="menuRoot" class="position-relative">
            <button
              type="button"
              class="btn btn-link text-decoration-none text-body d-flex align-items-center gap-2 rounded-3 py-1 ps-1 pe-1 pe-sm-2 border-0"
              :aria-expanded="menuOpen"
              aria-haspopup="true"
              @click.stop="
                accountOpen = false;
                menuOpen = !menuOpen;
              "
            >
              <span class="position-relative flex-shrink-0 d-inline-flex">
                <img
                  v-if="user.profile?.avatar_url"
                  :src="resolvePublicUrl(user.profile.avatar_url)"
                  alt=""
                  class="rounded-circle object-fit-cover"
                  width="40"
                  height="40"
                />
                <span
                  v-else
                  class="d-flex align-items-center justify-content-center rounded-circle fw-bold small"
                  style="width: 2.5rem; height: 2.5rem"
                  :class="avatarClass(user.email)"
                >
                  {{ initials(user.name) }}
                </span>
                <span class="vx-avatar-online" aria-hidden="true" />
              </span>
              <span
                class="d-none d-md-inline text-truncate fw-medium d-lg-none"
                style="max-width: 6rem"
              >
                {{ firstName || user.name }}
              </span>
              <svg
                class="d-none d-md-inline flex-shrink-0 text-secondary d-lg-none"
                :class="{ 'rotate-180': menuOpen }"
                width="16"
                height="16"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
                stroke-width="2"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  d="M19 9l-7 7-7-7"
                />
              </svg>
            </button>

            <div
              v-if="menuOpen"
              class="position-absolute end-0 mt-2 rounded-4 border bg-body shadow overflow-hidden"
              style="width: 16rem; z-index: 1080"
              role="menu"
            >
              <div class="border-bottom px-3 pb-3 pt-3">
                <p class="fw-medium mb-0 text-body">{{ user.name }}</p>
                <p class="mb-0 small text-secondary text-truncate">
                  {{ user.email }}
                </p>
              </div>
              <ul class="list-unstyled mb-0 py-1">
                <li v-if="canEditOwnProfile">
                  <button
                    type="button"
                    class="btn btn-link text-start text-decoration-none text-body w-100 py-2 px-3 rounded-0"
                    @click="openEditProfileModal"
                  >
                    Edit profile
                  </button>
                </li>
                <li v-else>
                  <RouterLink
                    :to="`/staff/${user.id}`"
                    class="d-block py-2 px-3 text-body text-decoration-none"
                    @click="closeMenu"
                  >
                    View profile
                  </RouterLink>
                </li>
                <li>
                  <RouterLink
                    to="/dashboard"
                    class="d-block py-2 px-3 text-body text-decoration-none"
                    @click="closeMenu"
                  >
                    Account settings
                  </RouterLink>
                </li>
                <li>
                  <a
                    href="mailto:support@saverack.com"
                    class="d-block py-2 px-3 text-body text-decoration-none"
                    @click="closeMenu"
                  >
                    Support
                  </a>
                </li>
              </ul>
              <div class="border-top" />
              <button
                type="button"
                class="btn btn-link text-start text-danger w-100 py-2 px-3 rounded-0 text-decoration-none"
                role="menuitem"
                @click="signOut"
              >
                Sign out
              </button>
            </div>
          </div>
        </div>
        </div>
      </div>
    </div>
  </header>

  <UserEditModal
    v-if="user?.id"
    v-model:open="editProfileModalOpen"
    :user-id="String(user.id)"
    @saved="$emit('refresh-user')"
  />
</template>

<style scoped>
.rotate-180 {
  transform: rotate(180deg);
}
.btn-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 0;
}
</style>
