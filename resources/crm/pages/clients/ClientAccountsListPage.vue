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
import ClientAccountChannelIcons from "../../components/clients/ClientAccountChannelIcons.vue";
import ClientAccountCreateDrawer from "../../components/clients/ClientAccountCreateDrawer.vue";
import ClientAccountEditModal from "../../components/clients/ClientAccountEditModal.vue";
import ClientAccountsBulkEditModal from "../../components/clients/ClientAccountsBulkEditModal.vue";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import CrmSearchableSelect from "../../components/common/CrmSearchableSelect.vue";
import CrmIconRowActions from "../../components/common/CrmIconRowActions.vue";
import { useToast } from "../../composables/useToast";
import { crmIsAdmin } from "../../utils/crmUser";
import { DEFAULT_PER_PAGE, PER_PAGE_OPTIONS } from "../../constants/pagination";
import { formatDateUs } from "../../utils/formatUserDates";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { getPublicSignupUrl } from "../../utils/publicSignupUrl.js";

const crmUser = inject("crmUser", ref(null));
const toast = useToast();

function userHasPerm(key) {
  const u = crmUser.value;
  if (!u) return false;
  if (crmIsAdmin(u) || u.is_crm_owner) return true;
  return Array.isArray(u.permission_keys) && u.permission_keys.includes(key);
}

const canCreate = computed(() => userHasPerm("clients.create"));

/** Root SPA signup URL (not /tickets-app/); override with VITE_PUBLIC_SIGNUP_URL. */
const publicCreateAccountUrl = computed(() => getPublicSignupUrl());
const canUpdate = computed(() => userHasPerm("clients.update"));
const canDelete = computed(() => userHasPerm("clients.delete"));
const showRowActions = computed(() => canUpdate.value || canDelete.value);
const showCheckboxCol = computed(() => canUpdate.value || canDelete.value);

const tableColspan = computed(() => {
  let n = 8;
  if (!showCheckboxCol.value) n -= 1;
  if (!showRowActions.value) n -= 1;
  return n;
});

const loading = ref(true);
const rows = ref([]);
const pagination = ref({ current_page: 1, last_page: 1, total: 0 });
const accountManagers = ref([]);
const statuses = ref(["pending", "active", "paused", "inactive"]);

const deleteTarget = ref(null);
const deleteBusy = ref(false);
const deleteError = ref("");

const manageOpenId = ref(null);
const manageMenuSubMode = ref("main");
const manageMenuRect = ref({ top: 0, left: 0 });

const manageMenuRow = computed(
  () => rows.value.find((r) => r.id === manageOpenId.value) ?? null,
);

const addDrawerOpen = ref(false);
const bulkEditOpen = ref(false);
const bulkEditBusy = ref(false);
const selectedIds = ref([]);
const editModalOpen = ref(false);
const editAccountId = ref("");

const query = reactive({
  search: "",
  per_page: DEFAULT_PER_PAGE,
  page: 1,
  sort_by: "created_at",
  sort_dir: "desc",
  account_manager_id: "",
});

let searchDebounce = null;
let searchWatchLock = false;

async function copyPublicCreateLink() {
  const url = publicCreateAccountUrl.value;
  if (!url) {
    toast.error("Could not build signup URL.");
    return;
  }
  try {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      await navigator.clipboard.writeText(url);
    } else {
      const ta = document.createElement("textarea");
      ta.value = url;
      ta.setAttribute("readonly", "");
      ta.style.position = "fixed";
      ta.style.left = "-9999px";
      document.body.appendChild(ta);
      ta.select();
      document.execCommand("copy");
      document.body.removeChild(ta);
    }
    toast.success("Public signup link copied.");
  } catch (e) {
    toast.error("Could not copy link.");
  }
}

const deleteModalOpen = computed(() => deleteTarget.value !== null);
const deleteMessage = computed(() => {
  const r = deleteTarget.value;
  return r
    ? `Delete ${r.company_name}? This cannot be undone.`
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
  return Math.min(pagination.value.current_page * query.per_page, t);
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
  const nums = new Set([1, last, cur, cur - 1, cur + 1, cur - 2, cur + 2]);
  const sorted = [...nums].filter((p) => p >= 1 && p <= last).sort((a, b) => a - b);
  const out = [];
  let prev = 0;
  for (const p of sorted) {
    if (prev && p - prev > 1) out.push({ type: "gap" });
    out.push({ type: "page", value: p });
    prev = p;
  }
  return out;
});

const isAllPageSelected = computed(
  () =>
    rows.value.length > 0 &&
    rows.value.every((r) => selectedIds.value.includes(r.id)),
);

watch(
  () => query.search,
  () => {
    if (searchWatchLock) return;
    clearTimeout(searchDebounce);
    searchDebounce = setTimeout(() => {
      query.page = 1;
      selectedIds.value = [];
      fetchRows();
    }, 300);
  },
);

watch(
  () => query.account_manager_id,
  () => {
    query.page = 1;
    selectedIds.value = [];
    fetchRows();
  },
);

const statusBadgeClass = (status) => {
  const s = String(status || "").toLowerCase();
  if (s === "active") {
    return "bg-emerald-50 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300";
  }
  if (s === "pending") {
    return "bg-amber-50 text-amber-800 dark:bg-amber-500/10 dark:text-amber-200";
  }
  if (s === "paused") {
    return "bg-sky-50 text-sky-900 dark:bg-sky-500/15 dark:text-sky-200";
  }
  if (s === "inactive") {
    return "bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300";
  }
  return "bg-slate-100 text-slate-700";
};

const avatarPalettes = [
  "bg-sky-100 text-sky-800 dark:bg-sky-500/20 dark:text-sky-200",
  "bg-violet-100 text-violet-800 dark:bg-violet-500/20 dark:text-violet-200",
  "bg-amber-100 text-amber-900 dark:bg-amber-500/20 dark:text-amber-200",
];

function avatarClassForRow(email) {
  let h = 0;
  const s = email || "";
  for (let i = 0; i < s.length; i++) h = (h + s.charCodeAt(i)) % 997;
  return avatarPalettes[h % avatarPalettes.length];
}

function initialsFromName(name) {
  if (!name || typeof name !== "string") return "?";
  const parts = name.trim().split(/\s+/).slice(0, 2);
  return parts.map((p) => p[0]?.toUpperCase() ?? "").join("") || "?";
}

const TABLE_SORT_COLUMNS = ["status", "company_name", "email", "created_at"];

function toggleSort(column) {
  if (!TABLE_SORT_COLUMNS.includes(column)) return;
  if (query.sort_by !== column) {
    query.sort_by = column;
    query.sort_dir = "asc";
  } else {
    query.sort_dir = query.sort_dir === "asc" ? "desc" : "asc";
  }
  query.page = 1;
  selectedIds.value = [];
  fetchRows();
}

function sortIndicator(column) {
  if (query.sort_by !== column) return "↕";
  return query.sort_dir === "asc" ? "↑" : "↓";
}

function buildParams() {
  const p = {
    search: query.search || undefined,
    per_page: query.per_page,
    page: query.page,
    sort_by: query.sort_by,
    sort_dir: query.sort_dir,
  };
  if (query.account_manager_id) {
    p.account_manager_id = query.account_manager_id;
  }
  return p;
}

function normalizeAccountManagersFromMeta(payload) {
  const raw =
    payload?.account_managers ??
    payload?.accountManagers ??
    (Array.isArray(payload) ? payload : null);
  if (!Array.isArray(raw)) return [];
  return raw.map((row) => ({
    id: Number(row.id),
    name: row.name != null ? String(row.name) : "",
    email: row.email != null ? String(row.email) : "",
  }));
}

async function fetchMeta() {
  try {
    const { data } = await api.get("/client-accounts/meta");
    accountManagers.value = normalizeAccountManagersFromMeta(data);
    if (Array.isArray(data?.statuses) && data.statuses.length) {
      statuses.value = data.statuses;
    }
  } catch (e) {
    accountManagers.value = [];
    toast.errorFrom(e, "Could not load account manager list.");
  }
}

async function fetchRows() {
  loading.value = true;
  manageOpenId.value = null;
  try {
    const { data } = await api.get("/client-accounts", { params: buildParams() });
    rows.value = data.data;
    pagination.value = {
      current_page: data.current_page,
      last_page: data.last_page,
      total: data.total,
    };
  } finally {
    loading.value = false;
  }
}

function goPage(p) {
  if (p < 1 || p > pagination.value.last_page) return;
  query.page = p;
  selectedIds.value = [];
  fetchRows();
}

function onPerPageChange(e) {
  query.per_page = Number(e.target.value);
  query.page = 1;
  selectedIds.value = [];
  fetchRows();
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
    await api.patch("/client-accounts/bulk", {
      client_account_ids: selectedIds.value,
      ...payload,
    });
    toast.success("Accounts updated.");
    bulkEditOpen.value = false;
    selectedIds.value = [];
    await fetchRows();
  } catch (e) {
    toast.errorFrom(e, "Could not update accounts.");
  } finally {
    bulkEditBusy.value = false;
  }
}

function openDeleteModal(row) {
  manageOpenId.value = null;
  deleteError.value = "";
  deleteTarget.value = row;
}

function closeDeleteModal() {
  if (deleteBusy.value) return;
  deleteTarget.value = null;
}

async function confirmDelete() {
  const row = deleteTarget.value;
  if (!row) return;
  deleteBusy.value = true;
  deleteError.value = "";
  try {
    await api.delete(`/client-accounts/${row.id}`);
    deleteTarget.value = null;
    toast.success("Account deleted.");
    await fetchRows();
  } catch (e) {
    deleteError.value = "Could not delete.";
    toast.errorFrom(e, "Could not delete.");
  } finally {
    deleteBusy.value = false;
  }
}

const MENU_W = 200;
const MENU_H_MAIN = 132;
const MENU_H_STATUS = 220;

function placeManageMenu(anchorEl) {
  if (!(anchorEl instanceof HTMLElement)) return;
  const r = anchorEl.getBoundingClientRect();
  const h = manageMenuSubMode.value === "status" ? MENU_H_STATUS : MENU_H_MAIN;
  let top = r.bottom + 4;
  let left = r.right - MENU_W;
  left = Math.max(8, Math.min(left, window.innerWidth - MENU_W - 8));
  if (top + h > window.innerHeight - 8) {
    top = Math.max(8, r.top - h - 4);
  }
  manageMenuRect.value = { top, left };
}

function closeManageMenu() {
  manageOpenId.value = null;
  manageMenuSubMode.value = "main";
}

function toggleManageMenu(rowId, e) {
  e.stopPropagation();
  if (manageOpenId.value === rowId) {
    closeManageMenu();
    return;
  }
  manageMenuSubMode.value = "main";
  manageOpenId.value = rowId;
  const btn = e.currentTarget;
  if (btn instanceof HTMLElement) placeManageMenu(btn);
}

function openStatusSubmenu() {
  manageMenuSubMode.value = "status";
  const trigger = document.querySelector(
    `[aria-row-actions="${manageOpenId.value}"]`,
  );
  if (trigger instanceof HTMLElement) placeManageMenu(trigger);
}

function backToMainMenu() {
  manageMenuSubMode.value = "main";
  const trigger = document.querySelector(
    `[aria-row-actions="${manageOpenId.value}"]`,
  );
  if (trigger instanceof HTMLElement) placeManageMenu(trigger);
}

async function setRowStatus(row, status) {
  if (!canUpdate.value) return;
  try {
    await api.patch(`/client-accounts/${row.id}`, { status });
    toast.success("Status updated.");
    closeManageMenu();
    await fetchRows();
  } catch (e) {
    toast.errorFrom(e, "Could not update status.");
  }
}

function openEditModal(row) {
  editAccountId.value = String(row.id);
  editModalOpen.value = true;
  closeManageMenu();
}

function toggleSelectAll(ev) {
  if (ev.target.checked) {
    selectedIds.value = rows.value.map((r) => r.id);
  } else {
    selectedIds.value = [];
  }
}

function toggleRowSelect(id) {
  const i = selectedIds.value.indexOf(id);
  if (i === -1) {
    selectedIds.value = [...selectedIds.value, id];
  } else {
    selectedIds.value = selectedIds.value.filter((x) => x !== id);
  }
}

function onDocClick(e) {
  if (!e.target.closest("[data-row-actions]")) {
    manageOpenId.value = null;
    manageMenuSubMode.value = "main";
  }
}

watch(addDrawerOpen, (o) => {
  if (o) fetchMeta();
});
watch(editModalOpen, (o) => {
  if (o) fetchMeta();
});

onMounted(async () => {
  document.addEventListener("click", onDocClick);
  setCrmPageMeta({
    title: "Save Rack | Client Accounts",
    description: "Client accounts directory.",
  });
  await fetchMeta();
  await fetchRows();
});

onUnmounted(() => {
  document.removeEventListener("click", onDocClick);
  clearTimeout(searchDebounce);
});
</script>

<template>
  <div class="space-y-4">
    <ClientAccountCreateDrawer
      v-if="canCreate"
      v-model:open="addDrawerOpen"
      :account-managers="accountManagers"
      @saved="fetchRows"
    />
    <ClientAccountEditModal
      v-model:open="editModalOpen"
      :account-id="editAccountId"
      :account-managers="accountManagers"
      @saved="fetchRows"
    />
    <ClientAccountsBulkEditModal
      v-model:open="bulkEditOpen"
      :selected-count="selectedIds.length"
      :busy="bulkEditBusy"
      :statuses="statuses"
      @apply="onBulkApply"
    />

    <p v-if="deleteError" class="text-sm text-red-600 dark:text-red-400">
      {{ deleteError }}
    </p>

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
                Client Accounts
              </h1>
              <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">
                Directory of client companies and contacts
              </p>
            </div>
            <button
              type="button"
              class="rounded-lg p-2 text-gray-500 transition hover:bg-gray-100 dark:hover:bg-white/10"
              :disabled="loading"
              title="Refresh"
              aria-label="Refresh list"
              @click="fetchRows"
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
          <div class="flex shrink-0 items-center gap-2">
            <button
              type="button"
              class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-lg border border-gray-300 bg-white text-gray-700 shadow-sm transition hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-white/10"
              title="Copy public signup link"
              aria-label="Copy public signup link"
              @click="copyPublicCreateLink"
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
                  d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"
                />
              </svg>
            </button>
            <button
              v-if="canCreate"
              type="button"
              class="inline-flex h-11 shrink-0 items-center justify-center gap-2 rounded-lg bg-[#2563eb] px-4 text-sm font-semibold text-white shadow-sm transition hover:opacity-95"
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
              Add Account
            </button>
          </div>
        </div>
      </div>

      <div class="px-4 py-4 sm:px-6 sm:pb-6">
        <div
          class="overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]"
        >
          <div
            class="flex flex-col gap-3 border-b border-gray-200 bg-white px-4 py-4 dark:border-gray-700 dark:bg-gray-900 sm:flex-row sm:items-center sm:justify-between sm:px-6"
          >
            <div
              class="flex min-w-0 flex-1 flex-col gap-3 sm:flex-row sm:items-center sm:gap-3"
            >
              <div class="relative min-w-0 flex-1 max-w-md">
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
                  class="h-11 w-full rounded-lg border border-gray-200 bg-white py-2.5 pl-10 pr-3 text-sm text-gray-900 placeholder:text-gray-400 focus:border-[#2563eb] focus:outline-none focus:ring-2 focus:ring-[#2563eb]/20 dark:border-gray-600 dark:bg-gray-900 dark:text-white"
                />
              </div>
              <div class="w-full sm:min-w-[220px] sm:max-w-xs">
                <CrmSearchableSelect
                  v-model="query.account_manager_id"
                  :options="accountManagers"
                  placeholder="All account managers"
                  search-placeholder="Search staff…"
                  empty-label="All account managers"
                  button-id="client-am-filter"
                  aria-label="Filter by account manager"
                />
              </div>
            </div>
            <button
              v-if="canUpdate"
              type="button"
              class="inline-flex h-11 shrink-0 items-center justify-center gap-2 self-start rounded-lg border border-gray-200 bg-white px-4 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 disabled:opacity-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700 sm:self-center"
              :disabled="!selectedIds.length || loading"
              @click="openBulkEdit"
            >
              Bulk Edit
            </button>
          </div>

          <div class="overflow-x-auto">
            <table class="min-w-[1100px] w-full text-left text-sm">
              <thead>
                <tr
                  class="border-b border-gray-200 bg-gray-50 dark:border-gray-700 dark:bg-gray-800/50"
                >
                  <th v-if="showCheckboxCol" class="w-12 px-5 py-3 sm:px-6">
                    <input
                      type="checkbox"
                      class="h-4 w-4 rounded border-gray-300 text-[#2563eb]"
                      :checked="isAllPageSelected"
                      :disabled="loading || !rows.length"
                      @change="toggleSelectAll"
                    />
                  </th>
                  <th class="px-5 py-3 sm:px-6">
                    <button
                      type="button"
                      class="inline-flex items-center gap-1 text-xs font-medium text-gray-500 hover:text-gray-800 dark:text-gray-400"
                      @click="toggleSort('status')"
                    >
                      Status
                      <span class="text-gray-400">{{ sortIndicator("status") }}</span>
                    </button>
                  </th>
                  <th class="px-5 py-3 sm:px-6">
                    <button
                      type="button"
                      class="inline-flex items-center gap-1 text-xs font-medium text-gray-500 hover:text-gray-800 dark:text-gray-400"
                      @click="toggleSort('company_name')"
                    >
                      Account
                      <span class="text-gray-400">{{
                        sortIndicator("company_name")
                      }}</span>
                    </button>
                  </th>
                  <th class="px-5 py-3 sm:px-6">
                    <button
                      type="button"
                      class="inline-flex items-center gap-1 text-xs font-medium text-gray-500 hover:text-gray-800 dark:text-gray-400"
                      @click="toggleSort('email')"
                    >
                      Email
                      <span class="text-gray-400">{{ sortIndicator("email") }}</span>
                    </button>
                  </th>
                  <th class="px-5 py-3 sm:px-6">
                    <span class="text-xs font-medium text-gray-500 dark:text-gray-400"
                      >Channel</span
                    >
                  </th>
                  <th class="px-5 py-3 sm:px-6">
                    <button
                      type="button"
                      class="inline-flex items-center gap-1 text-xs font-medium text-gray-500 hover:text-gray-800 dark:text-gray-400"
                      @click="toggleSort('created_at')"
                    >
                      Create Date
                      <span class="text-gray-400">{{
                        sortIndicator("created_at")
                      }}</span>
                    </button>
                  </th>
                  <th class="px-5 py-3 sm:px-6">
                    <span class="text-xs font-medium text-gray-500 dark:text-gray-400"
                      >Account Manager</span
                    >
                  </th>
                  <th
                    v-if="showRowActions"
                    class="w-[4.5rem] px-5 py-3 text-right sm:px-6"
                  >
                    <span class="text-xs font-medium text-gray-500 dark:text-gray-400"
                      >Action</span
                    >
                  </th>
                </tr>
              </thead>
              <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                <tr v-if="loading">
                  <td :colspan="tableColspan" class="px-5 py-12 sm:px-6">
                    <div class="flex justify-center">
                      <CrmLoadingSpinner message="Loading…" />
                    </div>
                  </td>
                </tr>
                <tr
                  v-for="row in rows"
                  v-else
                  :key="row.id"
                  class="border-t border-gray-100 bg-white hover:bg-gray-50/80 dark:border-gray-800 dark:bg-transparent dark:hover:bg-white/[0.02]"
                >
                  <td v-if="showCheckboxCol" class="px-5 py-4 align-middle sm:px-6">
                    <input
                      type="checkbox"
                      class="h-4 w-4 rounded border-gray-300 text-[#2563eb]"
                      :checked="selectedIds.includes(row.id)"
                      @change="toggleRowSelect(row.id)"
                    />
                  </td>
                  <td class="px-5 py-4 align-middle sm:px-6">
                    <span
                      class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium capitalize"
                      :class="statusBadgeClass(row.status)"
                    >
                      {{ row.status }}
                    </span>
                  </td>
                  <td class="px-5 py-4 align-middle sm:px-6">
                    <RouterLink
                      :to="`/clients/accounts/${row.id}`"
                      class="flex items-center gap-3 rounded-lg outline-none ring-[#2563eb] transition hover:bg-gray-50/80 focus-visible:ring-2 dark:hover:bg-white/[0.04]"
                    >
                      <span
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-xs font-semibold"
                        :class="avatarClassForRow(row.email)"
                      >
                        {{ initialsFromName(row.contact_full_name || row.company_name) }}
                      </span>
                      <div class="min-w-0">
                        <p
                          class="truncate font-semibold text-gray-900 dark:text-white"
                        >
                          {{ row.company_name }}
                        </p>
                        <p class="mt-0.5 truncate text-xs text-gray-500 dark:text-gray-400">
                          {{
                            row.contact_full_name && row.contact_full_name.trim()
                              ? row.contact_full_name
                              : "—"
                          }}
                        </p>
                      </div>
                    </RouterLink>
                  </td>
                  <td
                    class="max-w-[14rem] truncate px-5 py-4 align-middle text-gray-700 sm:px-6 dark:text-gray-300"
                  >
                    {{ row.email }}
                  </td>
                  <td class="px-5 py-4 align-middle sm:px-6">
                    <ClientAccountChannelIcons
                      :notify-email="!!row.notify_email"
                      :telegram-handle="row.telegram_handle || ''"
                      :whatsapp-e164="row.whatsapp_e164 || ''"
                    />
                  </td>
                  <td
                    class="whitespace-nowrap px-5 py-4 align-middle text-gray-700 sm:px-6 dark:text-gray-300"
                  >
                    {{ formatDateUs(row.created_at) }}
                  </td>
                  <td
                    class="max-w-[12rem] truncate px-5 py-4 align-middle text-gray-700 sm:px-6 dark:text-gray-300"
                    :title="row.account_manager?.name"
                  >
                    {{ row.account_manager?.name || "—" }}
                  </td>
                  <td
                    v-if="showRowActions"
                    class="relative px-5 py-4 text-right align-middle sm:px-6"
                  >
                    <div data-row-actions class="relative inline-flex justify-end">
                      <button
                        type="button"
                        class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg border border-gray-200 bg-white text-gray-600 shadow-sm transition hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-900 dark:hover:bg-white/10"
                        :aria-expanded="manageOpenId === row.id"
                        :aria-row-actions="row.id"
                        aria-haspopup="true"
                        aria-label="Row actions"
                        @click="toggleManageMenu(row.id, $event)"
                      >
                        <CrmIconRowActions />
                      </button>
                    </div>
                  </td>
                </tr>
                <tr v-if="!loading && rows.length === 0">
                  <td :colspan="tableColspan" class="px-5 py-12 text-center text-gray-500">
                    No accounts found.
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <div
          class="mt-5 flex flex-col gap-4 border-t border-gray-100 pt-5 dark:border-gray-800 lg:flex-row lg:items-center lg:justify-between"
        >
          <div class="flex flex-wrap items-center gap-6">
            <div class="flex items-center gap-2">
              <label class="text-sm text-gray-600 dark:text-gray-400" for="ca-per-page"
                >Rows per page</label
              >
              <select
                id="ca-per-page"
                class="h-9 rounded-lg border border-gray-200 bg-white px-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                :value="query.per_page"
                :disabled="loading"
                @change="onPerPageChange"
              >
                <option v-for="n in PER_PAGE_OPTIONS" :key="n" :value="n">
                  {{ n }}
                </option>
              </select>
            </div>
            <p class="text-sm text-gray-600 dark:text-gray-400">
              Showing
              <span class="font-semibold text-gray-900 dark:text-white">{{
                showingFrom
              }}</span>
              –
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
              class="inline-flex h-9 w-9 items-center justify-center rounded-md border border-gray-200 text-gray-600 hover:bg-gray-50 disabled:opacity-40 dark:border-gray-600 dark:hover:bg-gray-800"
              :disabled="loading || pagination.current_page <= 1"
              @click="goPage(pagination.current_page - 1)"
            >
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
              </svg>
            </button>
            <div class="flex items-center gap-1 px-1">
              <template v-for="(item, idx) in pageItems" :key="'p-' + idx">
                <span v-if="item.type === 'gap'" class="px-1 text-sm text-gray-400">... </span>
                <button
                  v-else
                  type="button"
                  :class="[
                    'min-w-[2.25rem] rounded-md px-2 py-1.5 text-sm font-medium transition',
                    item.value === pagination.current_page
                      ? 'bg-[#2563eb] text-white'
                      : 'text-gray-600 hover:text-[#2563eb] dark:text-gray-300',
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
              class="inline-flex h-9 w-9 items-center justify-center rounded-md border border-gray-200 text-gray-600 hover:bg-gray-50 disabled:opacity-40 dark:border-gray-600 dark:hover:bg-gray-800"
              :disabled="loading || pagination.current_page >= pagination.last_page"
              @click="goPage(pagination.current_page + 1)"
            >
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
              </svg>
            </button>
          </div>
        </div>
      </div>
    </div>

    <ConfirmModal
      :open="deleteModalOpen"
      title="Delete account"
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
          v-if="manageMenuRow"
          data-row-actions
          class="fixed z-[300] w-[200px] overflow-hidden rounded-xl border border-gray-200 bg-white py-1 shadow-lg dark:border-gray-700 dark:bg-gray-900"
          role="menu"
          :style="{
            top: `${manageMenuRect.top}px`,
            left: `${manageMenuRect.left}px`,
          }"
          @click.stop
        >
          <template v-if="manageMenuSubMode === 'main'">
            <button
              v-if="canUpdate"
              type="button"
              class="flex w-full items-center px-4 py-2.5 text-left text-sm font-medium text-gray-800 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-white/5"
              @click="openStatusSubmenu"
            >
              Status
            </button>
            <button
              v-if="canUpdate"
              type="button"
              class="flex w-full items-center px-4 py-2.5 text-left text-sm font-medium text-gray-800 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-white/5"
              :class="canUpdate ? 'border-t border-gray-100 dark:border-gray-800' : ''"
              @click="openEditModal(manageMenuRow)"
            >
              Edit
            </button>
            <button
              v-if="canDelete"
              type="button"
              class="flex w-full items-center border-t border-gray-100 px-4 py-2.5 text-left text-sm font-medium text-red-600 hover:bg-red-50 dark:border-gray-800 dark:text-red-400 dark:hover:bg-red-950/25"
              @click="openDeleteModal(manageMenuRow)"
            >
              Delete
            </button>
          </template>
          <template v-else>
            <button
              type="button"
              class="flex w-full items-center px-4 py-2 text-left text-xs font-medium text-gray-500 hover:bg-gray-50 dark:text-gray-400 dark:hover:bg-white/5"
              @click="backToMainMenu"
            >
              ← Back
            </button>
            <div class="border-t border-gray-100 dark:border-gray-800" />
            <button
              v-for="s in statuses"
              :key="s"
              type="button"
              class="flex w-full items-center px-4 py-2 text-left text-sm capitalize text-gray-800 hover:bg-gray-50 disabled:opacity-50 dark:text-gray-200 dark:hover:bg-white/5"
              :disabled="manageMenuRow.status === s"
              @click="setRowStatus(manageMenuRow, s)"
            >
              {{ s }}
            </button>
          </template>
        </div>
      </Transition>
    </Teleport>
  </div>
</template>
