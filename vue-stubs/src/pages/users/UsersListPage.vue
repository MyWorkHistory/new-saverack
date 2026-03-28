<script setup>
import { computed, onMounted, onUnmounted, reactive, ref } from "vue";
import api from "../../services/api";
import PageHeader from "../../components/common/PageHeader.vue";
import ConfirmModal from "../../components/common/ConfirmModal.vue";

const loading = ref(true);
const rows = ref([]);
const pagination = ref({ current_page: 1, last_page: 1, total: 0 });
const roles = ref([]);
const currentUser = ref(null);
const deleteTarget = ref(null);
const deleteBusy = ref(false);
const deleteError = ref("");
const manageOpenId = ref(null);

const query = reactive({
  search: "",
  per_page: 15,
  page: 1,
  sort_by: "name",
  sort_dir: "asc",
  role_id: "",
  status: "all",
});

const deleteModalOpen = computed(() => deleteTarget.value !== null);

const deleteMessage = computed(() => {
  const u = deleteTarget.value;
  return u
    ? `Are you sure you want to delete ${u.name}? This cannot be undone.`
    : "";
});

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
  query.page = 1;
  fetchUsers();
};

const clearFilters = () => {
  query.search = "";
  query.role_id = "";
  query.status = "all";
  query.page = 1;
  fetchUsers();
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

const closeManageOnOutside = (e) => {
  if (!e.target.closest("[data-manage-root]")) {
    manageOpenId.value = null;
  }
};

onMounted(async () => {
  document.addEventListener("click", closeManageOnOutside);
  await fetchMe();
  await fetchRoles();
  await fetchUsers();
});

onUnmounted(() => {
  document.removeEventListener("click", closeManageOnOutside);
});
</script>

<template>
  <div class="flex flex-col gap-6 lg:flex-row lg:items-start">
    <!-- Sidebar: table management -->
    <aside
      class="w-full shrink-0 space-y-4 lg:sticky lg:top-4 lg:w-64 xl:w-72"
    >
      <div
        class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900/40"
      >
        <h2
          class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400"
        >
          Manage users
        </h2>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
          Create accounts and refresh the directory.
        </p>
        <div class="mt-4 space-y-2">
          <a
            href="/users/new"
            class="flex w-full items-center justify-center gap-2 rounded-xl bg-emerald-600 px-4 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2 dark:focus:ring-offset-gray-900"
          >
            <svg
              class="h-5 w-5"
              fill="none"
              stroke="currentColor"
              viewBox="0 0 24 24"
              aria-hidden="true"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="2"
                d="M12 4v16m8-8H4"
              />
            </svg>
            Add New User
          </a>
          <button
            type="button"
            class="flex w-full items-center justify-center gap-2 rounded-xl border border-gray-200 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 transition hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 dark:hover:bg-gray-800"
            :disabled="loading"
            @click="fetchUsers"
          >
            <svg
              class="h-4 w-4"
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
            Refresh list
          </button>
        </div>
      </div>
    </aside>

    <!-- Main -->
    <div class="min-w-0 flex-1 space-y-4">
      <PageHeader
        title="Users"
        subtitle="Directory of admin and staff accounts"
        :result-count="loading ? undefined : pagination.total"
      />

      <p v-if="deleteError" class="text-sm text-red-600 dark:text-red-400">
        {{ deleteError }}
      </p>

      <!-- Filters -->
      <div
        class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-white/[0.03]"
      >
        <div
          class="flex flex-col gap-3 lg:flex-row lg:flex-wrap lg:items-end"
        >
          <div class="min-w-[180px] flex-1">
            <label
              class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400"
              >Search</label
            >
            <input
              v-model="query.search"
              type="search"
              placeholder="Search by name, email, or phone"
              class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 placeholder:text-gray-400 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20 dark:border-gray-600 dark:bg-gray-900 dark:text-white dark:placeholder:text-gray-500"
              @keyup.enter="applySearch"
            />
          </div>
          <div class="w-full min-w-[140px] sm:w-44">
            <label
              class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400"
              >Role</label
            >
            <select
              v-model="query.role_id"
              class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20 dark:border-gray-600 dark:bg-gray-900 dark:text-white"
            >
              <option value="">All roles</option>
              <option v-for="r in roles" :key="r.id" :value="String(r.id)">
                {{ r.label || r.name }}
              </option>
            </select>
          </div>
          <div class="w-full min-w-[140px] sm:w-44">
            <label
              class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400"
              >Status</label
            >
            <select
              v-model="query.status"
              class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20 dark:border-gray-600 dark:bg-gray-900 dark:text-white"
            >
              <option value="all">All statuses</option>
              <option value="pending">Pending</option>
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
            </select>
          </div>
          <div class="flex flex-wrap gap-2 lg:pb-0.5">
            <button
              type="button"
              class="inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2 dark:focus:ring-offset-gray-900"
              :disabled="loading"
              @click="applySearch"
            >
              <svg
                class="h-4 w-4"
                fill="none"
                stroke="currentColor"
                viewBox="0 0 24 24"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  stroke-width="2"
                  d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"
                />
              </svg>
              Search
            </button>
            <button
              type="button"
              class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200 dark:hover:bg-gray-800"
              :disabled="loading"
              @click="clearFilters"
            >
              Clear
            </button>
          </div>
        </div>
      </div>

      <!-- Table -->
      <div
        class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-white/[0.03]"
      >
        <div class="overflow-x-auto">
          <table class="w-full min-w-[720px] text-left text-sm">
            <thead>
              <tr
                class="bg-slate-800 text-white dark:bg-slate-900 dark:text-white"
              >
                <th class="px-4 py-3 font-semibold">Status</th>
                <th class="px-4 py-3 font-semibold">
                  <button
                    type="button"
                    class="inline-flex items-center gap-1 font-semibold hover:text-white/90"
                    @click="toggleSortName"
                  >
                    Name
                    <span class="text-white/70" aria-hidden="true">
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
                <th class="px-4 py-3 font-semibold">Account</th>
                <th class="px-4 py-3 font-semibold">Role</th>
                <th class="px-4 py-3 text-right font-semibold">Manage</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="loading">
                <td colspan="5" class="px-4 py-10 text-center text-gray-500">
                  Loading users…
                </td>
              </tr>
              <tr
                v-for="(user, idx) in rows"
                v-else
                :key="user.id"
                :class="
                  idx % 2 === 0
                    ? 'bg-white dark:bg-gray-900/20'
                    : 'bg-gray-50/90 dark:bg-gray-900/40'
                "
                class="border-t border-gray-100 dark:border-gray-800"
              >
                <td class="px-4 py-3 align-middle">
                  <span
                    class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset capitalize"
                    :class="statusBadgeClass(user.status)"
                  >
                    {{ user.status }}
                  </span>
                </td>
                <td class="px-4 py-3 align-middle">
                  <a
                    :href="`/users/${user.id}/edit`"
                    class="font-medium text-brand-600 hover:text-brand-700 hover:underline dark:text-brand-400"
                  >
                    {{ user.name }}
                  </a>
                </td>
                <td class="px-4 py-3 align-middle">
                  <a
                    :href="`/users/${user.id}/edit`"
                    class="text-brand-600 hover:text-brand-700 hover:underline dark:text-brand-400"
                  >
                    {{ user.email }}
                  </a>
                </td>
                <td class="px-4 py-3 align-middle text-gray-700 dark:text-gray-300">
                  {{ roleLabels(user) }}
                </td>
                <td class="relative px-4 py-3 text-right align-middle">
                  <div data-manage-root class="inline-block text-left">
                    <button
                      type="button"
                      class="inline-flex items-center gap-1 rounded-lg border border-gray-200 bg-white px-2.5 py-1.5 text-gray-700 shadow-sm hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700"
                      :aria-expanded="manageOpenId === user.id"
                      aria-haspopup="true"
                      @click="toggleManageMenu(user.id, $event)"
                    >
                      <svg
                        class="h-5 w-5 text-brand-600 dark:text-brand-400"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.5"
                        viewBox="0 0 24 24"
                        aria-hidden="true"
                      >
                        <path
                          stroke-linecap="round"
                          stroke-linejoin="round"
                          d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 011.37.49l1.296 2.247a1.125 1.125 0 01-.26 1.431l-1.003.827c-.293.24-.438.613-.431.992a6.723 6.723 0 010 .255c-.007.378.138.75.43.99l1.005.828c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 01-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.57 6.57 0 01-.22.128c-.331.183-.581.495-.644.869l-.213 1.28c-.09.543-.56.941-1.11.941h-2.594c-.55 0-1.02-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 01-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 01-1.369-.49l-1.297-2.247a1.125 1.125 0 01.26-1.431l1.004-.827c.292-.24.437-.613.43-.992a6.932 6.932 0 010-.255c.007-.378-.138-.75-.43-.99l-1.004-.828a1.125 1.125 0 01-.26-1.43l1.297-2.247a1.125 1.125 0 011.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.087.22-.128.332-.183.582-.495.644-.869l.214-1.281z"
                        />
                        <path
                          stroke-linecap="round"
                          stroke-linejoin="round"
                          d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"
                        />
                      </svg>
                      <svg
                        class="h-3 w-3 text-gray-500"
                        fill="currentColor"
                        viewBox="0 0 20 20"
                        aria-hidden="true"
                      >
                        <path
                          fill-rule="evenodd"
                          d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"
                          clip-rule="evenodd"
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
                        class="absolute right-4 z-20 mt-1 w-44 origin-top-right rounded-xl border border-gray-200 bg-white py-1 shadow-lg ring-1 ring-black/5 dark:border-gray-700 dark:bg-gray-900"
                        data-manage-root
                        @click.stop
                      >
                        <a
                          :href="`/users/${user.id}/edit`"
                          class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-800"
                          @click="manageOpenId = null"
                        >
                          Edit user
                        </a>
                        <button
                          v-if="canDeleteRow(user)"
                          type="button"
                          class="block w-full px-4 py-2 text-left text-sm text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-950/30"
                          @click="openDeleteModal(user)"
                        >
                          Delete
                        </button>
                      </div>
                    </Transition>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div
          class="flex flex-col gap-3 border-t border-gray-100 px-4 py-3 text-sm text-gray-600 dark:border-gray-800 dark:text-gray-400 sm:flex-row sm:items-center sm:justify-between"
        >
          <span>
            Page {{ pagination.current_page }} of
            {{ pagination.last_page }}
          </span>
          <div class="flex flex-wrap gap-2">
            <button
              type="button"
              class="rounded-lg border border-gray-200 px-3 py-1.5 text-sm font-medium hover:bg-gray-50 disabled:opacity-40 dark:border-gray-600 dark:hover:bg-gray-800"
              :disabled="loading || pagination.current_page <= 1"
              @click="goPage(pagination.current_page - 1)"
            >
              Previous
            </button>
            <button
              type="button"
              class="rounded-lg border border-gray-200 px-3 py-1.5 text-sm font-medium hover:bg-gray-50 disabled:opacity-40 dark:border-gray-600 dark:hover:bg-gray-800"
              :disabled="
                loading || pagination.current_page >= pagination.last_page
              "
              @click="goPage(pagination.current_page + 1)"
            >
              Next
            </button>
          </div>
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
