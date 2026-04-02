<script setup>
import {
  computed,
  inject,
  onMounted,
  onUnmounted,
  reactive,
  ref,
  watch,
} from "vue";
import { RouterLink } from "vue-router";
import api from "../../services/api";
import ConfirmModal from "../../components/common/ConfirmModal.vue";
import UserCreateDrawer from "../../components/users/UserCreateDrawer.vue";
import UserEditModal from "../../components/users/UserEditModal.vue";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import { useToast } from "../../composables/useToast";
import { crmIsAdmin } from "../../utils/crmUser";
import { DEFAULT_PER_PAGE, PER_PAGE_OPTIONS } from "../../constants/pagination";
import { formatBirthdayUs, formatDateUs } from "../../utils/formatUserDates";
import CrmIconRowActions from "../../components/common/CrmIconRowActions.vue";
import StaffBulkEditModal from "../../components/users/StaffBulkEditModal.vue";
import { resolvePublicUrl } from "../../utils/resolvePublicUrl.js";

const crmUser = inject("crmUser", ref(null));
const toast = useToast();

function userHasPerm(key) {
  const u = crmUser.value;
  if (!u) return false;
  if (crmIsAdmin(u) || u.is_crm_owner) return true;
  return Array.isArray(u.permission_keys) && u.permission_keys.includes(key);
}

const canCreateUsers = computed(() => userHasPerm("users.create"));
const canUpdateUsers = computed(() => userHasPerm("users.update"));
const canDeleteUsers = computed(() => userHasPerm("users.delete"));
const showRowActions = computed(
  () => canUpdateUsers.value || canDeleteUsers.value,
);

const tableColspan = computed(() => {
  let n = 8;
  if (!canDeleteUsers.value) n -= 1;
  if (!showRowActions.value) n -= 1;
  return n;
});

const loading = ref(true);
const rows = ref([]);
const pagination = ref({ current_page: 1, last_page: 1, total: 0 });
const roles = ref([]);
const currentUser = ref(null);
const deleteTarget = ref(null);
const deleteBusy = ref(false);
const deleteError = ref("");
const manageOpenId = ref(null);
/** Viewport-fixed position for teleported row menu (escapes overflow-x-auto). */
const manageMenuRect = ref({ top: 0, left: 0 });

const manageMenuUser = computed(() =>
  rows.value.find((u) => u.id === manageOpenId.value) ?? null,
);
const filterOpen = ref(false);
const addDrawerOpen = ref(false);
const bulkEditOpen = ref(false);
const bulkEditBusy = ref(false);
const selectedIds = ref([]);
const userEditModalOpen = ref(false);
const userEditModalUserId = ref("");

const query = reactive({
  search: "",
  per_page: DEFAULT_PER_PAGE,
  page: 1,
  sort_by: "name",
  sort_dir: "asc",
  role_id: "",
  status: "all",
});

let searchDebounce = null;
let searchWatchLock = false;

const deleteModalOpen = computed(() => deleteTarget.value !== null);

const deleteMessage = computed(() => {
  const u = deleteTarget.value;
  return u
    ? `Are you sure you want to delete ${u.name}? This cannot be undone.`
    : "";
});

const showingFrom = computed(() => {
  const t = pagination.value.total;
  if (t === 0) return 0;
  return (pagination.value.current_page - 1) * query.per_page + 1;
});

const showingTo = computed(() => {
  const t = pagination.value.total;
  if (t === 0) return 0;
  return Math.min(
    pagination.value.current_page * query.per_page,
    t,
  );
});

const pageItems = computed(() => {
  const last = pagination.value.last_page;
  const cur = pagination.value.current_page;
  if (last < 1) return [];
  if (last <= 7) {
    return Array.from({ length: last }, (_, i) => ({
      type: "page",
      value: i + 1,
    }));
  }
  const nums = new Set([
    1,
    last,
    cur,
    cur - 1,
    cur + 1,
    cur - 2,
    cur + 2,
  ]);
  const sorted = [...nums].filter((p) => p >= 1 && p <= last).sort((a, b) => a - b);
  const out = [];
  let prev = 0;
  for (const p of sorted) {
    if (prev && p - prev > 1) {
      out.push({ type: "gap" });
    }
    out.push({ type: "page", value: p });
    prev = p;
  }
  return out;
});

const isAllPageSelected = computed(
  () =>
    rows.value.length > 0 &&
    rows.value.every((u) => selectedIds.value.includes(u.id)),
);

watch(
  () => query.search,
  () => {
    if (searchWatchLock) return;
    clearTimeout(searchDebounce);
    searchDebounce = setTimeout(() => {
      query.page = 1;
      selectedIds.value = [];
      fetchUsers();
    }, 300);
  },
);

const roleLabels = (user) => {
  const r = user.roles;
  if (!r || !r.length) return "—";
  return r.map((x) => x.label || x.name).join(", ");
};

const statusBadgeClass = (status) => {
  const s = String(status || "").toLowerCase();
  if (s === "active") {
    return "bg-emerald-50 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300";
  }
  if (s === "pending") {
    return "bg-amber-50 text-amber-800 dark:bg-amber-500/10 dark:text-amber-200";
  }
  if (s === "inactive") {
    return "bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300";
  }
  return "bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300";
};

const avatarPalettes = [
  "bg-sky-100 text-sky-800 dark:bg-sky-500/20 dark:text-sky-200",
  "bg-violet-100 text-violet-800 dark:bg-violet-500/20 dark:text-violet-200",
  "bg-amber-100 text-amber-900 dark:bg-amber-500/20 dark:text-amber-200",
  "bg-emerald-100 text-emerald-900 dark:bg-emerald-500/20 dark:text-emerald-200",
  "bg-rose-100 text-rose-900 dark:bg-rose-500/20 dark:text-rose-200",
];

function avatarClassForUser(email) {
  let h = 0;
  const s = email || "";
  for (let i = 0; i < s.length; i++) h = (h + s.charCodeAt(i)) % 997;
  return avatarPalettes[h % avatarPalettes.length];
}

function initials(name) {
  if (!name || typeof name !== "string") return "?";
  const parts = name.trim().split(/\s+/).slice(0, 2);
  return parts.map((p) => p[0]?.toUpperCase() ?? "").join("") || "?";
}

const fetchMe = async () => {
  try {
    const { data } = await api.get("/auth/me");
    currentUser.value = data;
  } catch {
    currentUser.value = null;
  }
};

const fetchRoles = async () => {
  try {
    const { data } = await api.get("/roles");
    roles.value = Array.isArray(data) ? data : [];
  } catch {
    roles.value = [];
  }
};

const buildParams = () => {
  const p = {
    search: query.search || undefined,
    per_page: query.per_page,
    page: query.page,
    sort_by: query.sort_by,
    sort_dir: query.sort_dir,
  };
  if (query.role_id) p.role_id = query.role_id;
  if (query.status && query.status !== "all") p.status = query.status;
  return p;
};

const fetchUsers = async () => {
  loading.value = true;
  deleteError.value = "";
  manageOpenId.value = null;
  try {
    const { data } = await api.get("/users", { params: buildParams() });
    rows.value = data.data;
    pagination.value = {
      current_page: data.current_page,
      last_page: data.last_page,
      total: data.total,
    };
  } finally {
    loading.value = false;
  }
};

const applySearch = () => {
  clearTimeout(searchDebounce);
  query.page = 1;
  selectedIds.value = [];
  fetchUsers();
};

const clearFilters = () => {
  clearTimeout(searchDebounce);
  searchWatchLock = true;
  query.search = "";
  query.role_id = "";
  query.status = "all";
  query.page = 1;
  selectedIds.value = [];
  fetchUsers().finally(() => {
    searchWatchLock = false;
  });
};

const applyFilterPanel = () => {
  filterOpen.value = false;
  applySearch();
};

const toggleSortName = () => {
  if (query.sort_by !== "name") {
    query.sort_by = "name";
    query.sort_dir = "asc";
  } else {
    query.sort_dir = query.sort_dir === "asc" ? "desc" : "asc";
  }
  query.page = 1;
  fetchUsers();
};

const goPage = (p) => {
  if (p < 1 || p > pagination.value.last_page) return;
  query.page = p;
  selectedIds.value = [];
  fetchUsers();
};

function onPerPageChange(e) {
  query.per_page = Number(e.target.value);
  query.page = 1;
  selectedIds.value = [];
  fetchUsers();
}

function openBulkEdit() {
  if (!selectedIds.value.length) {
    toast.error("Select one or more rows.");
    return;
  }
  bulkEditOpen.value = true;
}

async function onBulkApply(payload) {
  bulkEditBusy.value = true;
  try {
    await api.patch("/users/bulk", {
      user_ids: selectedIds.value,
      ...payload,
    });
    toast.success("Staff updated.");
    bulkEditOpen.value = false;
    selectedIds.value = [];
    await fetchUsers();
  } catch (e) {
    toast.errorFrom(e, "Could not update staff.");
  } finally {
    bulkEditBusy.value = false;
  }
}

const canDeleteRow = (user) => {
  if (!canDeleteUsers.value) return false;
  return !(currentUser.value && user.id === currentUser.value.id);
};

const openDeleteModal = (user) => {
  manageOpenId.value = null;
  deleteError.value = "";
  deleteTarget.value = user;
};

const closeDeleteModal = () => {
  if (deleteBusy.value) return;
  deleteTarget.value = null;
};

const confirmDelete = async () => {
  const user = deleteTarget.value;
  if (!user) return;
  deleteBusy.value = true;
  deleteError.value = "";
  try {
    await api.delete(`/users/${user.id}`);
    deleteTarget.value = null;
    toast.success("User deleted.");
    await fetchUsers();
  } catch (e) {
    const msg =
      e.response?.data?.message ||
      e.response?.data?.error ||
      "Could not delete user.";
    deleteError.value = typeof msg === "string" ? msg : "Could not delete user.";
    toast.errorFrom(e, "Could not delete user.");
  } finally {
    deleteBusy.value = false;
  }
};

const MENU_W = 176;
const MENU_H = 112;

function placeManageMenu(anchorEl) {
  if (!(anchorEl instanceof HTMLElement)) return;
  const r = anchorEl.getBoundingClientRect();
  let top = r.bottom + 4;
  let left = r.right - MENU_W;
  left = Math.max(8, Math.min(left, window.innerWidth - MENU_W - 8));
  if (top + MENU_H > window.innerHeight - 8) {
    top = Math.max(8, r.top - MENU_H - 4);
  }
  manageMenuRect.value = { top, left };
}

function closeManageMenu() {
  manageOpenId.value = null;
}

function openUserEditModal(user) {
  userEditModalUserId.value = String(user.id);
  userEditModalOpen.value = true;
  closeManageMenu();
}

function onWindowScrollOrResize() {
  if (manageOpenId.value !== null) {
    closeManageMenu();
  }
}

const toggleManageMenu = (userId, e) => {
  e.stopPropagation();
  if (manageOpenId.value === userId) {
    closeManageMenu();
    return;
  }
  manageOpenId.value = userId;
  const btn = e.currentTarget;
  if (btn instanceof HTMLElement) {
    placeManageMenu(btn);
  }
};

function toggleSelectAll(ev) {
  if (ev.target.checked) {
    selectedIds.value = rows.value.map((u) => u.id);
  } else {
    selectedIds.value = [];
  }
}

function toggleRowSelect(userId) {
  const i = selectedIds.value.indexOf(userId);
  if (i === -1) {
    selectedIds.value = [...selectedIds.value, userId];
  } else {
    selectedIds.value = selectedIds.value.filter((id) => id !== userId);
  }
}

function onDocClick(e) {
  if (!e.target.closest("[data-filter-root]")) {
    filterOpen.value = false;
  }
  if (!e.target.closest("[data-row-actions]")) {
    manageOpenId.value = null;
  }
}

onMounted(async () => {
  document.addEventListener("click", onDocClick);
  window.addEventListener("scroll", onWindowScrollOrResize, true);
  window.addEventListener("resize", onWindowScrollOrResize);
  await fetchMe();
  await fetchRoles();
  await fetchUsers();
});

onUnmounted(() => {
  document.removeEventListener("click", onDocClick);
  window.removeEventListener("scroll", onWindowScrollOrResize, true);
  window.removeEventListener("resize", onWindowScrollOrResize);
  clearTimeout(searchDebounce);
});
</script>

<template>
  <div class="space-y-4">
    <UserCreateDrawer
      v-if="canCreateUsers"
      v-model:open="addDrawerOpen"
      @saved="fetchUsers"
    />

    <StaffBulkEditModal
      v-model:open="bulkEditOpen"
      :roles="roles"
      :selected-count="selectedIds.length"
      :busy="bulkEditBusy"
      @apply="onBulkApply"
    />

    <p v-if="deleteError" class="text-sm text-red-600 dark:text-red-400">
      {{ deleteError }}
    </p>

    <!-- TailAdmin pattern: wrapper card + inner table card (see basic-tables) -->
    <div
      class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900"
    >
      <div class="border-b border-gray-100 px-4 py-5 dark:border-gray-800 sm:px-6">
        <div
          class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between"
        >
          <div class="flex items-center gap-3">
            <div>
              <h1 class="text-xl font-bold text-gray-900 dark:text-white">
                Staff
              </h1>
              <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">
                Directory of admin and staff accounts
              </p>
            </div>
            <button
              type="button"
              class="rounded-lg p-2 text-gray-500 transition hover:bg-gray-100 dark:hover:bg-white/10 dark:hover:text-gray-300"
              :disabled="loading"
              title="Refresh"
              aria-label="Refresh list"
              @click="fetchUsers"
            >
              <svg
                class="h-5 w-5"
                fill="none"
                stroke="currentColor"
                viewBox="0 0 24 24"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  stroke-width="2"
                  d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"
                />
              </svg>
            </button>
          </div>

          <div
            class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-end"
          >
            <div class="relative flex shrink-0 items-center gap-2" data-filter-root>
              <button
                type="button"
                class="inline-flex h-11 items-center gap-2 rounded-lg border border-gray-200 bg-white px-4 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700"
                :class="{ 'ring-2 ring-[#2563eb]/30': filterOpen }"
                :aria-expanded="filterOpen"
                @click.stop="filterOpen = !filterOpen"
              >
                <svg
                  class="h-5 w-5 text-gray-500"
                  fill="none"
                  viewBox="0 0 24 24"
                  stroke="currentColor"
                  stroke-width="2"
                >
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"
                  />
                </svg>
                Filter
              </button>

              <Transition
                enter-active-class="transition ease-out duration-100"
                enter-from-class="transform opacity-0 scale-95"
                enter-to-class="transform opacity-100 scale-100"
                leave-active-class="transition ease-in duration-75"
                leave-from-class="transform opacity-100 scale-100"
                leave-to-class="transform opacity-0 scale-95"
              >
                <div
                  v-if="filterOpen"
                  class="absolute right-0 top-full z-30 mt-2 w-72 origin-top-right rounded-xl border border-gray-200 bg-white p-4 shadow-lg dark:border-gray-700 dark:bg-gray-900"
                  @click.stop
                >
                  <div class="space-y-3">
                    <div>
                      <label
                        class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400"
                        >Role</label
                      >
                      <select
                        v-model="query.role_id"
                        class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                      >
                        <option value="">All roles</option>
                        <option
                          v-for="r in roles"
                          :key="r.id"
                          :value="String(r.id)"
                        >
                          {{ r.label || r.name }}
                        </option>
                      </select>
                    </div>
                    <div>
                      <label
                        class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400"
                        >Status</label
                      >
                      <select
                        v-model="query.status"
                        class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                      >
                        <option value="all">All statuses</option>
                        <option value="pending">Pending</option>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                      </select>
                    </div>
                    <div class="flex gap-2 pt-1">
                      <button
                        type="button"
                        class="flex min-h-10 min-w-0 flex-1 basis-0 items-center justify-center rounded-lg bg-[#2563eb] px-3 text-xs font-semibold text-white transition hover:opacity-95 disabled:opacity-50"
                        :disabled="loading"
                        @click="applyFilterPanel"
                      >
                        Apply
                      </button>
                      <button
                        type="button"
                        class="flex min-h-10 min-w-0 flex-1 basis-0 items-center justify-center rounded-lg border border-gray-200 bg-white px-3 text-xs font-medium text-gray-700 transition hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700 disabled:opacity-50"
                        :disabled="loading"
                        @click="
                          clearFilters();
                          filterOpen = false;
                        "
                      >
                        Clear
                      </button>
                    </div>
                  </div>
                </div>
              </Transition>
            </div>

            <button
              v-if="canUpdateUsers"
              type="button"
              class="inline-flex h-11 shrink-0 items-center justify-center gap-2 rounded-lg border border-gray-200 bg-white px-4 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 disabled:opacity-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700"
              :disabled="!selectedIds.length || loading"
              @click="openBulkEdit"
            >
              Bulk edit
            </button>

            <button
              v-if="canCreateUsers"
              type="button"
              class="inline-flex h-11 shrink-0 items-center justify-center gap-2 rounded-lg bg-[#2563eb] px-4 text-sm font-semibold text-white shadow-sm transition hover:opacity-95 focus:outline-none focus:ring-2 focus:ring-[#2563eb]/40 focus:ring-offset-2 dark:focus:ring-offset-gray-900"
              @click="addDrawerOpen = true"
            >
              <svg
                class="h-5 w-5"
                fill="none"
                stroke="currentColor"
                viewBox="0 0 24 24"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  stroke-width="2"
                  d="M12 4v16m8-8H4"
                />
              </svg>
              Add Staff
            </button>
          </div>
        </div>
      </div>

      <div class="px-4 py-4 sm:px-6 sm:pb-6">
        <div
          class="overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]"
        >
          <!-- Search inside table card (TailAdmin basic-tables: white toolbar strip) -->
          <div
            class="border-b border-gray-200 bg-white px-4 py-4 dark:border-gray-700 dark:bg-gray-900 sm:px-6"
          >
            <div class="max-w-md">
              <div class="relative">
                <span
                  class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"
                >
                  <svg
                    class="h-5 w-5"
                    fill="none"
                    viewBox="0 0 20 20"
                    stroke="currentColor"
                    stroke-width="1.5"
                  >
                    <path
                      stroke-linecap="round"
                      d="M3.042 9.374c0-3.497 2.835-6.332 6.333-6.332 3.497 0 6.332 2.835 6.332 6.332 0 3.498-2.835 6.333-6.332 6.333-3.498 0-6.333-2.835-6.333-6.333zM17.208 17.205l-2.82-2.82"
                    />
                  </svg>
                </span>
                <input
                  v-model="query.search"
                  type="search"
                  placeholder="Search…"
                  class="h-11 w-full rounded-lg border border-gray-200 bg-white py-2.5 pl-10 pr-3 text-sm text-gray-900 placeholder:text-gray-400 focus:border-[#2563eb] focus:outline-none focus:ring-2 focus:ring-[#2563eb]/20 dark:border-gray-600 dark:bg-gray-900 dark:text-white dark:placeholder:text-gray-500"
                  @keydown.enter.prevent="applySearch"
                />
              </div>
            </div>
          </div>

          <div class="overflow-x-auto">
            <table class="min-w-[1024px] w-full text-left text-sm">
          <thead>
            <tr
              class="border-b border-gray-200 bg-gray-50 dark:border-gray-700 dark:bg-gray-800/50"
            >
              <th v-if="canDeleteUsers" class="w-12 px-5 py-3 sm:px-6">
                <input
                  type="checkbox"
                  class="h-4 w-4 rounded border-gray-300 text-[#2563eb] focus:ring-[#2563eb]"
                  :checked="isAllPageSelected"
                  :disabled="loading || !rows.length"
                  aria-label="Select all on page"
                  @change="toggleSelectAll"
                />
              </th>
              <th class="px-5 py-3 text-left sm:px-6">
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400">
                  Status
                </p>
              </th>
              <th class="px-5 py-3 text-left sm:px-6">
                <button
                  type="button"
                  class="inline-flex items-center gap-1 text-xs font-medium text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-white"
                  @click="toggleSortName"
                >
                  User
                  <span class="text-gray-400" aria-hidden="true">
                    {{
                      query.sort_by === "name"
                        ? query.sort_dir === "asc"
                          ? "↑"
                          : "↓"
                        : "↕"
                    }}
                  </span>
                </button>
              </th>
              <th class="px-5 py-3 text-left sm:px-6">
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400">
                  Position
                </p>
              </th>
              <th class="px-5 py-3 text-left sm:px-6">
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400">
                  Birthday
                </p>
              </th>
              <th class="px-5 py-3 text-left sm:px-6">
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400">
                  Hire date
                </p>
              </th>
              <th class="px-5 py-3 text-left sm:px-6">
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400">
                  Role
                </p>
              </th>
              <th
                v-if="showRowActions"
                class="w-[4.5rem] min-w-[4.75rem] px-5 py-3 text-right sm:px-6"
              >
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400">
                  Action
                </p>
              </th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
            <tr v-if="loading">
              <td :colspan="tableColspan" class="px-5 py-12 sm:px-6">
                <div class="flex justify-center">
                  <CrmLoadingSpinner message="Loading users…" />
                </div>
              </td>
            </tr>
            <tr
              v-for="user in rows"
              v-else
              :key="user.id"
              class="border-t border-gray-100 bg-white hover:bg-gray-50/80 dark:border-gray-800 dark:bg-transparent dark:hover:bg-white/[0.02]"
            >
              <td v-if="canDeleteUsers" class="px-5 py-4 align-middle sm:px-6">
                <input
                  type="checkbox"
                  class="h-4 w-4 rounded border-gray-300 text-[#2563eb] focus:ring-[#2563eb]"
                  :checked="selectedIds.includes(user.id)"
                  :aria-label="`Select ${user.name}`"
                  @change="toggleRowSelect(user.id)"
                />
              </td>
              <td class="px-5 py-4 align-middle sm:px-6">
                <span
                  class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium capitalize"
                  :class="statusBadgeClass(user.status)"
                >
                  {{ user.status }}
                </span>
              </td>
              <td class="px-5 py-4 align-middle sm:px-6">
                <div class="flex items-center gap-3">
                  <span class="relative h-10 w-10 shrink-0">
                    <img
                      v-if="user.profile?.avatar_url"
                      :src="resolvePublicUrl(user.profile.avatar_url)"
                      alt=""
                      class="h-10 w-10 rounded-full object-cover"
                    />
                    <span
                      v-else
                      class="flex h-10 w-10 items-center justify-center rounded-full text-xs font-semibold"
                      :class="avatarClassForUser(user.email)"
                    >
                      {{ initials(user.name) }}
                    </span>
                  </span>
                  <div class="min-w-0">
                    <RouterLink
                      :to="`/staff/${user.id}`"
                      class="block truncate font-semibold text-gray-900 hover:text-blue-600 dark:text-white dark:hover:text-blue-400"
                    >
                      {{ user.name }}
                    </RouterLink>
                    <RouterLink
                      :to="`/staff/${user.id}`"
                      class="mt-0.5 block truncate text-xs text-gray-500 hover:text-blue-600 dark:text-gray-400"
                    >
                      {{ user.email }}
                    </RouterLink>
                  </div>
                </div>
              </td>
              <td
                class="max-w-[11rem] truncate px-5 py-4 align-middle text-gray-700 sm:px-6 dark:text-gray-300"
                :title="user.profile?.job_position || undefined"
              >
                {{ user.profile?.job_position || "—" }}
              </td>
              <td
                class="whitespace-nowrap px-5 py-4 align-middle text-gray-700 sm:px-6 dark:text-gray-300"
              >
                {{ formatBirthdayUs(user.profile?.birthday) }}
              </td>
              <td
                class="whitespace-nowrap px-5 py-4 align-middle text-gray-700 sm:px-6 dark:text-gray-300"
              >
                {{ formatDateUs(user.profile?.hire_date) }}
              </td>
              <td
                class="px-5 py-4 align-middle text-gray-700 sm:px-6 dark:text-gray-300"
              >
                {{ roleLabels(user) }}
              </td>
              <td
                v-if="showRowActions"
                class="relative px-5 py-4 text-right align-middle sm:px-6"
              >
                <div data-row-actions class="relative inline-flex justify-end">
                  <button
                    type="button"
                    class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg border border-gray-200 bg-white text-gray-600 shadow-sm transition hover:border-gray-300 hover:bg-gray-50 hover:text-gray-900 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-500 dark:hover:bg-white/10 dark:hover:text-white"
                    :aria-expanded="manageOpenId === user.id"
                    aria-haspopup="true"
                    aria-label="Row actions"
                    @click="toggleManageMenu(user.id, $event)"
                  >
                    <CrmIconRowActions />
                  </button>
                </div>
              </td>
            </tr>
            <tr v-if="!loading && rows.length === 0">
              <td :colspan="tableColspan" class="px-5 py-12 text-center text-gray-500 sm:px-6">
                No staff found.
              </td>
            </tr>
          </tbody>
            </table>
          </div>
        </div>

        <!-- Pagination (still inside wrapper card body) -->
        <div
          class="mt-5 flex flex-col gap-4 border-t border-gray-100 pt-5 dark:border-gray-800 lg:flex-row lg:items-center lg:justify-between"
        >
          <div
            class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center sm:gap-6"
          >
            <div class="flex items-center gap-2">
              <label
                for="users-per-page"
                class="whitespace-nowrap text-sm text-gray-600 dark:text-gray-400"
                >Rows per page</label
              >
              <select
                id="users-per-page"
                class="h-9 rounded-lg border border-gray-200 bg-white px-2 pr-8 text-sm text-gray-900 dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                :value="query.per_page"
                :disabled="loading"
                @change="onPerPageChange"
              >
                <option
                  v-for="n in PER_PAGE_OPTIONS"
                  :key="n"
                  :value="n"
                >
                  {{ n }}
                </option>
              </select>
            </div>
            <p class="text-sm text-gray-600 dark:text-gray-400">
              Showing
              <span class="font-semibold text-gray-900 dark:text-white">{{
                showingFrom
              }}</span>
              to
              <span class="font-semibold text-gray-900 dark:text-white">{{
                showingTo
              }}</span>
              of
              <span class="font-semibold text-gray-900 dark:text-white">{{
                pagination.total
              }}</span>
            </p>
          </div>
          <div class="flex flex-wrap items-center gap-2">
          <button
            type="button"
            class="inline-flex h-9 w-9 items-center justify-center rounded-md border border-gray-200 text-gray-600 hover:bg-gray-50 disabled:opacity-40 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-800"
            :disabled="loading || pagination.current_page <= 1"
            aria-label="Previous page"
            @click="goPage(pagination.current_page - 1)"
          >
            <svg
              class="h-4 w-4"
              fill="none"
              viewBox="0 0 24 24"
              stroke="currentColor"
              stroke-width="2"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="M15 19l-7-7 7-7"
              />
            </svg>
          </button>
          <div class="flex items-center gap-1 px-1">
            <template v-for="(item, idx) in pageItems" :key="'pi-' + idx">
              <span
                v-if="item.type === 'gap'"
                class="px-1.5 text-sm text-gray-400"
                >…</span
              >
              <button
                v-else
                type="button"
                :class="[
                  'min-w-[2.25rem] px-2 py-1.5 text-sm font-medium transition rounded-md',
                  item.value === pagination.current_page
                    ? 'bg-[#2563eb] text-white'
                    : 'text-gray-600 hover:text-[#2563eb] dark:text-gray-300 dark:hover:text-blue-400',
                ]"
                :disabled="loading"
                @click="goPage(item.value)"
              >
                {{ item.value }}
              </button>
            </template>
          </div>
          <button
            type="button"
            class="inline-flex h-9 w-9 items-center justify-center rounded-md border border-gray-200 text-gray-600 hover:bg-gray-50 disabled:opacity-40 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-800"
            :disabled="
              loading || pagination.current_page >= pagination.last_page
            "
            aria-label="Next page"
            @click="goPage(pagination.current_page + 1)"
          >
            <svg
              class="h-4 w-4"
              fill="none"
              viewBox="0 0 24 24"
              stroke="currentColor"
              stroke-width="2"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="M9 5l7 7-7 7"
              />
            </svg>
          </button>
        </div>
        </div>
      </div>
    </div>

    <UserEditModal
      v-model:open="userEditModalOpen"
      :user-id="userEditModalUserId"
      @saved="fetchUsers"
    />

    <ConfirmModal
      :open="deleteModalOpen"
      title="Delete user"
      :message="deleteMessage"
      confirm-label="Delete"
      cancel-label="Cancel"
      :busy="deleteBusy"
      @close="closeDeleteModal"
      @confirm="confirmDelete"
    />

    <Teleport to="body">
      <Transition
        enter-active-class="transition ease-out duration-100"
        enter-from-class="transform opacity-0 scale-95"
        enter-to-class="transform opacity-100 scale-100"
        leave-active-class="transition ease-in duration-75"
        leave-from-class="transform opacity-100 scale-100"
        leave-to-class="transform opacity-0 scale-95"
      >
        <div
          v-if="manageMenuUser"
          data-row-actions
          class="fixed z-[300] w-44 overflow-hidden rounded-xl border border-gray-200 bg-white py-1 shadow-lg ring-1 ring-black/5 dark:border-gray-700 dark:bg-gray-900 dark:ring-white/10"
          role="menu"
          :style="{
            top: `${manageMenuRect.top}px`,
            left: `${manageMenuRect.left}px`,
          }"
          @click.stop
        >
          <button
            v-if="canUpdateUsers"
            type="button"
            class="flex w-full items-center px-4 py-2.5 text-left text-sm font-medium text-gray-800 transition hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-white/5"
            role="menuitem"
            @click="openUserEditModal(manageMenuUser)"
          >
            Edit
          </button>
          <button
            v-if="canDeleteRow(manageMenuUser)"
            type="button"
            :class="[
              'flex w-full items-center px-4 py-2.5 text-left text-sm font-medium text-red-600 transition hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-950/25',
              canUpdateUsers
                ? 'border-t border-gray-100 dark:border-gray-800'
                : '',
            ]"
            role="menuitem"
            @click="openDeleteModal(manageMenuUser)"
          >
            Delete
          </button>
        </div>
      </Transition>
    </Teleport>
  </div>
</template>
