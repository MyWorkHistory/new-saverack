<script setup>
import {
  computed,
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

const loading = ref(true);
const rows = ref([]);
const pagination = ref({ current_page: 1, last_page: 1, total: 0 });
const roles = ref([]);
const currentUser = ref(null);
const deleteTarget = ref(null);
const deleteBusy = ref(false);
const deleteError = ref("");
const manageOpenId = ref(null);
const filterOpen = ref(false);
const addDrawerOpen = ref(false);
const selectedIds = ref([]);

const query = reactive({
  search: "",
  per_page: 15,
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
    return "bg-emerald-50 text-emerald-800 ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/30";
  }
  if (s === "pending") {
    return "bg-amber-50 text-amber-800 ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-500/30";
  }
  if (s === "inactive") {
    return "bg-gray-100 text-gray-700 ring-gray-500/20 dark:bg-gray-800 dark:text-gray-300 dark:ring-gray-500/40";
  }
  return "bg-slate-100 text-slate-700 ring-slate-500/20 dark:bg-slate-800 dark:text-slate-300";
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
  selectedIds.value = [];
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
  fetchUsers();
};

const clearFilters = () => {
  clearTimeout(searchDebounce);
  searchWatchLock = true;
  query.search = "";
  query.role_id = "";
  query.status = "all";
  query.page = 1;
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
  fetchUsers();
};

const canDeleteRow = (user) => {
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
    await fetchUsers();
  } catch (e) {
    const msg =
      e.response?.data?.message ||
      e.response?.data?.error ||
      "Could not delete user.";
    deleteError.value = typeof msg === "string" ? msg : "Could not delete user.";
  } finally {
    deleteBusy.value = false;
  }
};

const toggleManageMenu = (userId, e) => {
  e.stopPropagation();
  manageOpenId.value = manageOpenId.value === userId ? null : userId;
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
  await fetchMe();
  await fetchRoles();
  await fetchUsers();
});

onUnmounted(() => {
  document.removeEventListener("click", onDocClick);
  clearTimeout(searchDebounce);
});
</script>

<template>
  <div class="space-y-4">
    <UserCreateDrawer v-model:open="addDrawerOpen" @saved="fetchUsers" />

    <p v-if="deleteError" class="text-sm text-red-600 dark:text-red-400">
      {{ deleteError }}
    </p>

    <div
      class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900/40"
    >
      <!-- Toolbar -->
      <div
        class="flex flex-col gap-4 border-b border-gray-100 px-4 py-5 dark:border-gray-800 sm:px-6"
      >
        <div
          class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between"
        >
          <div class="flex items-center gap-3">
            <div>
              <h1 class="text-xl font-bold text-gray-900 dark:text-white">
                Users
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
            <div class="relative min-w-0 flex-1 sm:max-w-xs">
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
                class="h-11 w-full rounded-lg border border-gray-200 bg-gray-50 py-2.5 pl-10 pr-3 text-sm text-gray-900 placeholder:text-gray-400 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20 dark:border-gray-700 dark:bg-gray-800/50 dark:text-white dark:placeholder:text-gray-500"
                @keydown.enter.prevent="applySearch"
              />
            </div>

            <div class="relative flex shrink-0 items-center gap-2" data-filter-root>
              <button
                type="button"
                class="inline-flex h-11 items-center gap-2 rounded-lg border border-gray-200 bg-white px-4 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700"
                :class="{ 'ring-2 ring-blue-500/30': filterOpen }"
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
                        class="flex min-h-10 min-w-0 flex-1 basis-0 items-center justify-center rounded-lg bg-blue-600 px-3 text-xs font-semibold text-white transition hover:bg-blue-700 disabled:opacity-50"
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
              type="button"
              class="inline-flex h-11 shrink-0 items-center justify-center gap-2 rounded-lg bg-blue-600 px-4 text-sm font-semibold text-white shadow-sm hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 dark:focus:ring-offset-gray-900"
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
              Add User
            </button>
          </div>
        </div>
      </div>

      <!-- Table -->
      <div class="overflow-x-auto">
        <table class="min-w-[800px] w-full text-left text-sm">
          <thead>
            <tr
              class="border-b border-gray-100 bg-gray-50 dark:border-gray-800 dark:bg-gray-800/40"
            >
              <th class="w-12 px-4 py-3">
                <input
                  type="checkbox"
                  class="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                  :checked="isAllPageSelected"
                  :disabled="loading || !rows.length"
                  aria-label="Select all on page"
                  @change="toggleSelectAll"
                />
              </th>
              <th
                class="px-4 py-3 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400"
              >
                Status
              </th>
              <th class="px-4 py-3 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                <button
                  type="button"
                  class="inline-flex items-center gap-1 font-semibold text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white"
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
              <th
                class="px-4 py-3 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400"
              >
                Role
              </th>
              <th
                class="w-14 px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400"
              >
                <!-- actions -->
              </th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
            <tr v-if="loading">
              <td colspan="5" class="px-4 py-12 text-center text-gray-500">
                Loading users…
              </td>
            </tr>
            <tr
              v-for="user in rows"
              v-else
              :key="user.id"
              class="bg-white hover:bg-gray-50/80 dark:bg-transparent dark:hover:bg-white/[0.02]"
            >
              <td class="px-4 py-4 align-middle">
                <input
                  type="checkbox"
                  class="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                  :checked="selectedIds.includes(user.id)"
                  :aria-label="`Select ${user.name}`"
                  @change="toggleRowSelect(user.id)"
                />
              </td>
              <td class="px-4 py-4 align-middle">
                <span
                  class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium capitalize ring-1 ring-inset"
                  :class="statusBadgeClass(user.status)"
                >
                  {{ user.status }}
                </span>
              </td>
              <td class="px-4 py-4 align-middle">
                <div class="flex items-center gap-3">
                  <span
                    class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-xs font-semibold"
                    :class="avatarClassForUser(user.email)"
                  >
                    {{ initials(user.name) }}
                  </span>
                  <div class="min-w-0">
                    <RouterLink
                      :to="`/users/${user.id}/edit`"
                      class="block truncate font-semibold text-gray-900 hover:text-blue-600 dark:text-white dark:hover:text-blue-400"
                    >
                      {{ user.name }}
                    </RouterLink>
                    <RouterLink
                      :to="`/users/${user.id}/edit`"
                      class="mt-0.5 block truncate text-xs text-gray-500 hover:text-blue-600 dark:text-gray-400"
                    >
                      {{ user.email }}
                    </RouterLink>
                  </div>
                </div>
              </td>
              <td
                class="px-4 py-4 align-middle text-gray-700 dark:text-gray-300"
              >
                {{ roleLabels(user) }}
              </td>
              <td class="relative px-4 py-4 text-right align-middle">
                <div data-row-actions class="relative inline-flex justify-end">
                  <button
                    type="button"
                    class="rounded-lg p-2 text-gray-500 transition hover:bg-gray-100 hover:text-gray-800 dark:hover:bg-white/10 dark:hover:text-white"
                    :aria-expanded="manageOpenId === user.id"
                    aria-haspopup="true"
                    aria-label="Row actions"
                    @click="toggleManageMenu(user.id, $event)"
                  >
                    <svg
                      class="h-5 w-5"
                      fill="currentColor"
                      viewBox="0 0 24 24"
                      aria-hidden="true"
                    >
                      <path
                        d="M6 10.25a1.75 1.75 0 113.5 0 1.75 1.75 0 01-3.5 0zM10.25 12a1.75 1.75 0 113.5 0 1.75 1.75 0 01-3.5 0zM14.5 10.25a1.75 1.75 0 113.5 0 1.75 1.75 0 01-3.5 0z"
                      />
                    </svg>
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
                      v-if="manageOpenId === user.id"
                      class="absolute right-0 top-full z-20 mt-1 w-40 overflow-hidden rounded-xl border border-gray-200 bg-white py-1 shadow-lg ring-1 ring-black/5 dark:border-gray-700 dark:bg-gray-900 dark:ring-white/10"
                      data-row-actions
                      role="menu"
                      @click.stop
                    >
                      <RouterLink
                        :to="`/users/${user.id}/edit`"
                        class="flex w-full items-center px-4 py-2.5 text-left text-sm font-medium text-gray-800 no-underline transition hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-white/5"
                        role="menuitem"
                        @click="manageOpenId = null"
                      >
                        Edit
                      </RouterLink>
                      <button
                        v-if="canDeleteRow(user)"
                        type="button"
                        class="flex w-full items-center border-t border-gray-100 px-4 py-2.5 text-left text-sm font-medium text-red-600 transition hover:bg-red-50 dark:border-gray-800 dark:text-red-400 dark:hover:bg-red-950/25"
                        role="menuitem"
                        @click="openDeleteModal(user)"
                      >
                        Delete
                      </button>
                    </div>
                  </Transition>
                </div>
              </td>
            </tr>
            <tr v-if="!loading && rows.length === 0">
              <td colspan="5" class="px-4 py-12 text-center text-gray-500">
                No users found.
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <div
        class="flex flex-col gap-4 border-t border-gray-100 px-4 py-4 dark:border-gray-800 sm:flex-row sm:items-center sm:justify-between sm:px-6"
      >
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
                    ? 'bg-blue-600 text-white'
                    : 'text-gray-600 hover:text-blue-600 dark:text-gray-300 dark:hover:text-blue-400',
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
  </div>
</template>
