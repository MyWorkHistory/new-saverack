<script setup>
import {
  computed,
  inject,
  nextTick,
  onMounted,
  onUnmounted,
  reactive,
  ref,
  watch,
} from "vue";
import { RouterLink, useRouter } from "vue-router";
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
import { downloadListCsv } from "../../utils/downloadListCsv.js";
import { resolvePublicUrl } from "../../utils/resolvePublicUrl.js";

const crmUser = inject("crmUser", ref(null));
const toast = useToast();
const router = useRouter();
const nf = new Intl.NumberFormat(undefined, { maximumFractionDigits: 0 });

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

const directoryStats = ref({
  total: 0,
  active: 0,
  pending: 0,
  paused: 0,
  inactive: 0,
});

const pausedAndInactiveTotal = computed(
  () => directoryStats.value.paused + directoryStats.value.inactive,
);

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
const filterMenuOpen = ref(false);
const exportOpen = ref(false);
const exportBusy = ref(false);
const bulkEditOpen = ref(false);
const bulkEditBusy = ref(false);
const bulkDeleteOpen = ref(false);
const bulkDeleteBusy = ref(false);
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
  status: "all",
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
    }, 280);
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

watch(
  () => query.status,
  () => {
    query.page = 1;
    selectedIds.value = [];
    fetchRows();
  },
);

const statusBadgeClass = (status) => {
  const s = String(status || "").toLowerCase();
  if (s === "active") {
    return "bg-success-subtle text-success";
  }
  if (s === "pending") {
    return "bg-warning-subtle text-warning-emphasis";
  }
  if (s === "paused") {
    return "bg-info-subtle text-info-emphasis";
  }
  if (s === "inactive") {
    return "bg-secondary-subtle text-secondary";
  }
  return "bg-body-secondary text-body-secondary";
};

const avatarPalettes = [
  "bg-info-subtle text-info-emphasis",
  "bg-primary-subtle text-primary-emphasis",
  "bg-warning-subtle text-warning-emphasis",
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

const TABLE_SORT_COLUMNS = [
  "status",
  "company_name",
  "email",
  "created_at",
];

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
  if (query.sort_by !== column) return "";
  return query.sort_dir === "asc" ? "↑" : "↓";
}

function thAriaSort(column) {
  return query.sort_by === column
    ? query.sort_dir === "asc"
      ? "ascending"
      : "descending"
    : "none";
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
  if (query.status && query.status !== "all") {
    p.status = query.status;
  }
  return p;
}

function buildExportParams() {
  const p = {};
  const s = (query.search || "").trim();
  if (s) p.search = s;
  if (query.account_manager_id) {
    p.account_manager_id = query.account_manager_id;
  }
  if (query.status && query.status !== "all") {
    p.status = query.status;
  }
  return p;
}

async function runAccountsExport() {
  exportOpen.value = false;
  exportBusy.value = true;
  try {
    await downloadListCsv({
      path: "/client-accounts/export-csv",
      params: buildExportParams(),
      filenameBase: "accounts",
      toast,
    });
  } catch {
    /* toast handled in downloadListCsv */
  } finally {
    exportBusy.value = false;
  }
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
    if (data?.directory_stats) {
      const d = data.directory_stats;
      directoryStats.value = {
        total: Number(d.total) || 0,
        active: Number(d.active) || 0,
        pending: Number(d.pending) || 0,
        paused: Number(d.paused) || 0,
        inactive: Number(d.inactive) || 0,
      };
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

function goFirstPage() {
  goPage(1);
}

function goLastPage() {
  goPage(pagination.value.last_page);
}

function applySearch() {
  clearTimeout(searchDebounce);
  query.page = 1;
  selectedIds.value = [];
  fetchRows();
}

function clearFilters() {
  clearTimeout(searchDebounce);
  searchWatchLock = true;
  query.search = "";
  query.account_manager_id = "";
  query.status = "all";
  query.sort_by = "created_at";
  query.sort_dir = "desc";
  query.page = 1;
  selectedIds.value = [];
  fetchRows()
    .finally(() => {
      searchWatchLock = false;
    });
  fetchMeta();
}

async function refreshList() {
  await fetchRows();
  await fetchMeta();
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
    await refreshList();
  } catch (e) {
    toast.errorFrom(e, "Could not update accounts.");
  } finally {
    bulkEditBusy.value = false;
  }
}

function openBulkDelete() {
  if (!selectedIds.value.length) {
    toast.error("Select one or more rows.");
    return;
  }
  bulkDeleteOpen.value = true;
}

function closeBulkDelete() {
  if (!bulkDeleteBusy.value) bulkDeleteOpen.value = false;
}

const bulkDeleteMessage = computed(() => {
  const n = selectedIds.value.length;
  return n
    ? `Delete ${n} account${n === 1 ? "" : "s"}? This cannot be undone.`
    : "";
});

async function confirmBulkDelete() {
  if (!selectedIds.value.length) return;
  bulkDeleteBusy.value = true;
  try {
    await api.delete("/client-accounts/bulk", {
      data: { client_account_ids: selectedIds.value },
    });
    toast.success("Accounts deleted.");
    bulkDeleteOpen.value = false;
    selectedIds.value = [];
    await refreshList();
  } catch (e) {
    toast.errorFrom(e, "Could not delete accounts.");
  } finally {
    bulkDeleteBusy.value = false;
  }
}

function accountStartDate(row) {
  return row.contract_date || row.created_at || null;
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
    await refreshList();
  } catch (e) {
    deleteError.value = "Could not delete.";
    toast.errorFrom(e, "Could not delete.");
  } finally {
    deleteBusy.value = false;
  }
}

const MENU_W = 200;
const MENU_H_MAIN = 180;
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

async function toggleManageMenu(rowId, e) {
  e.stopPropagation();
  if (manageOpenId.value === rowId) {
    closeManageMenu();
    return;
  }
  manageMenuSubMode.value = "main";
  const btn = e.currentTarget;
  manageOpenId.value = rowId;
  await nextTick();
  requestAnimationFrame(() => {
    requestAnimationFrame(() => {
      if (manageOpenId.value !== rowId) return;
      if (btn instanceof HTMLElement) placeManageMenu(btn);
    });
  });
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
    await refreshList();
  } catch (e) {
    toast.errorFrom(e, "Could not update status.");
  }
}

function openEditModal(row) {
  editAccountId.value = String(row.id);
  editModalOpen.value = true;
  closeManageMenu();
}

function goViewAccount(row) {
  closeManageMenu();
  router.push(`/clients/accounts/${row.id}`);
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
  if (!e.target.closest("[data-export-root]")) {
    exportOpen.value = false;
  }
  if (!e.target.closest("[data-toolbar-filter]")) {
    filterMenuOpen.value = false;
  }
  if (!e.target.closest("[data-row-actions]")) {
    manageOpenId.value = null;
    manageMenuSubMode.value = "main";
  }
}

function onWindowScrollOrResize() {
  if (manageOpenId.value !== null) {
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
  window.addEventListener("scroll", onWindowScrollOrResize, true);
  window.addEventListener("resize", onWindowScrollOrResize);
  setCrmPageMeta({
    title: "Save Rack | Accounts",
    description: "Accounts directory.",
  });
  await fetchMeta();
  await fetchRows();
});

onUnmounted(() => {
  document.removeEventListener("click", onDocClick);
  window.removeEventListener("scroll", onWindowScrollOrResize, true);
  window.removeEventListener("resize", onWindowScrollOrResize);
  clearTimeout(searchDebounce);
});
</script>

<template>
  <div class="staff-page staff-page--wide client-accounts-directory">
    <ClientAccountCreateDrawer
      v-if="canCreate"
      v-model:open="addDrawerOpen"
      @saved="refreshList"
    />
    <ClientAccountEditModal
      v-model:open="editModalOpen"
      :account-id="editAccountId"
      :account-managers="accountManagers"
      @saved="refreshList"
    />
    <ClientAccountsBulkEditModal
      v-model:open="bulkEditOpen"
      :selected-count="selectedIds.length"
      :busy="bulkEditBusy"
      :statuses="statuses"
      @apply="onBulkApply"
    />

    <ConfirmModal
      :open="bulkDeleteOpen"
      title="Delete accounts?"
      :message="bulkDeleteMessage"
      confirm-label="Delete"
      :busy="bulkDeleteBusy"
      danger
      @close="closeBulkDelete"
      @confirm="confirmBulkDelete"
    />

    <div
      v-if="deleteError"
      class="alert alert-danger mb-3 mb-md-4"
      role="alert"
    >
      {{ deleteError }}
    </div>

    <div
      class="d-flex flex-column flex-md-row align-items-start align-items-md-center gap-3 mb-4"
    >
      <div class="min-w-0 flex-grow-1">
        <h1 class="h4 mb-1 fw-semibold text-body">Accounts</h1>
        <p class="text-secondary small mb-0">
          Directory of client companies and contacts
        </p>
      </div>
      <div
        class="d-flex flex-wrap align-items-center gap-2 ms-md-auto flex-shrink-0"
      >
        <button
          v-if="canCreate"
          type="button"
          class="btn btn-primary staff-page-primary d-inline-flex align-items-center gap-2"
          @click="addDrawerOpen = true"
        >
          <svg
            width="18"
            height="18"
            fill="currentColor"
            viewBox="0 0 24 24"
            aria-hidden="true"
          >
            <path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z" />
          </svg>
          Add Account
        </button>
      </div>
    </div>

    <div class="row g-4 mb-2">
      <div class="col-12 col-sm-6 col-xl-3">
        <div class="staff-stat-card h-100">
          <p class="staff-stat-card__label">Total accounts</p>
          <p class="staff-stat-card__value">
            {{ nf.format(directoryStats.total) }}
          </p>
          <p class="staff-stat-card__sub">All companies in the directory</p>
          <div
            class="staff-stat-card__icon text-white"
            style="background: #2563eb"
            aria-hidden="true"
          >
            <svg width="22" height="22" fill="currentColor" viewBox="0 0 24 24">
              <path
                d="M12 7V3H2v18h20V7H12zM6 19H4v-2h2v2zm0-4H4v-2h2v2zm0-4H4V9h2v2zm0-4H4V5h2v2zm4 12H8v-2h2v2zm0-4H8v-2h2v2zm0-4H8V9h2v2zm0-4H8V5h2v2zm10 12h-8v-2h2v-2h-2v-2h2v-2h-2V9h8v10zm-2-8h-2v2h2v-2zm0 4h-2v2h2v-2z"
              />
            </svg>
          </div>
        </div>
      </div>
      <div class="col-12 col-sm-6 col-xl-3">
        <div class="staff-stat-card h-100">
          <p class="staff-stat-card__label">Active</p>
          <p class="staff-stat-card__value">
            {{ nf.format(directoryStats.active) }}
          </p>
          <p class="staff-stat-card__sub">Accounts marked active</p>
          <div
            class="staff-stat-card__icon bg-success-subtle text-success"
            aria-hidden="true"
          >
            <svg width="22" height="22" fill="currentColor" viewBox="0 0 24 24">
              <path
                d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"
              />
            </svg>
          </div>
        </div>
      </div>
      <div class="col-12 col-sm-6 col-xl-3">
        <div class="staff-stat-card h-100">
          <p class="staff-stat-card__label">Pending</p>
          <p class="staff-stat-card__value">
            {{ nf.format(directoryStats.pending) }}
          </p>
          <p class="staff-stat-card__sub">Awaiting activation</p>
          <div
            class="staff-stat-card__icon bg-warning-subtle text-warning-emphasis"
            aria-hidden="true"
          >
            <svg width="22" height="22" fill="currentColor" viewBox="0 0 24 24">
              <path
                d="M15.5 14h-.79l-.28-.27A6.471 6.471 0 0016 9.5 6.5 6.5 0 109.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 9.5 5 7.49 5 5c0-2.59 2.01-4.5 4.5-4.5S14 2.41 14 5c0 2.49-2.01 4.5-4.5 4.5z"
              />
            </svg>
          </div>
        </div>
      </div>
      <div class="col-12 col-sm-6 col-xl-3">
        <div class="staff-stat-card h-100">
          <p class="staff-stat-card__label">Paused &amp; inactive</p>
          <p class="staff-stat-card__value">
            {{ nf.format(pausedAndInactiveTotal) }}
          </p>
          <p class="staff-stat-card__sub">Not in active onboarding</p>
          <div
            class="staff-stat-card__icon bg-secondary-subtle text-secondary"
            aria-hidden="true"
          >
            <svg width="22" height="22" fill="currentColor" viewBox="0 0 24 24">
              <path
                d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"
              />
            </svg>
          </div>
        </div>
      </div>
    </div>

    <div class="staff-table-card staff-datatable-card staff-datatable-card--white">
      <div class="staff-table-toolbar">
        <div class="staff-table-toolbar--row">
          <input
            id="ca-search"
            v-model="query.search"
            type="search"
            class="form-control staff-toolbar-search staff-toolbar-search--inline"
            placeholder="Search accounts"
            autocomplete="off"
            @keydown.enter.prevent="applySearch"
          />
          <div class="position-relative flex-shrink-0" data-toolbar-filter>
            <button
              type="button"
              class="btn btn-outline-secondary staff-toolbar-btn d-inline-flex align-items-center gap-2"
              :aria-expanded="filterMenuOpen"
              aria-haspopup="true"
              aria-controls="ca-filter-panel"
              :disabled="loading"
              @click.stop="filterMenuOpen = !filterMenuOpen"
            >
              <svg
                width="18"
                height="18"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
                viewBox="0 0 24 24"
                aria-hidden="true"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"
                />
              </svg>
              <span class="d-none d-sm-inline">Filters</span>
            </button>
            <div
              v-if="filterMenuOpen"
              id="ca-filter-panel"
              class="dropdown-menu dropdown-menu-end show shadow border p-0 staff-toolbar-filter-dropdown"
              role="dialog"
              aria-label="Table filters"
              @click.stop
            >
              <div class="staff-toolbar-filter-dropdown__head">
                <span>Filters</span>
                <button
                  type="button"
                  class="btn btn-link btn-sm text-secondary text-decoration-none p-0"
                  :disabled="loading"
                  @click="
                    clearFilters();
                    filterMenuOpen = false;
                  "
                >
                  Reset
                </button>
              </div>
              <div class="staff-toolbar-filter-dropdown__body">
                <label class="form-label" for="ca-filter-status">Status</label>
                <select
                  id="ca-filter-status"
                  v-model="query.status"
                  class="form-select staff-datatable-filters__select mb-3"
                  :disabled="loading"
                >
                  <option value="all">All statuses</option>
                  <option
                    v-for="st in statuses"
                    :key="st"
                    :value="st"
                  >
                    {{ st.charAt(0).toUpperCase() + st.slice(1) }}
                  </option>
                </select>
                <label class="form-label" for="client-am-filter">Account manager</label>
                <CrmSearchableSelect
                  v-model="query.account_manager_id"
                  appearance="staff"
                  :options="accountManagers"
                  placeholder="All account managers"
                  search-placeholder="Search staff…"
                  empty-label="All account managers"
                  button-id="client-am-filter"
                  aria-label="Filter by account manager"
                />
              </div>
            </div>
          </div>
          <div
            class="d-flex flex-wrap align-items-center gap-2 gap-md-3 ms-md-auto flex-shrink-0"
          >
            <div class="position-relative" data-export-root>
              <button
                type="button"
                class="btn btn-outline-secondary staff-toolbar-btn d-inline-flex align-items-center gap-2"
                :aria-expanded="exportOpen"
                :disabled="loading || exportBusy"
                @click.stop="exportOpen = !exportOpen"
              >
                <svg
                  width="18"
                  height="18"
                  fill="currentColor"
                  viewBox="0 0 24 24"
                  aria-hidden="true"
                >
                  <path
                    d="M9 16h6v-6h4l-7-7-7 7h4v6zm-4 2h14v2H5v-2z"
                  />
                </svg>
                Export
                <svg
                  width="14"
                  height="14"
                  fill="currentColor"
                  viewBox="0 0 24 24"
                  class="text-secondary"
                  aria-hidden="true"
                >
                  <path d="M7 10l5 5 5-5H7z" />
                </svg>
              </button>
              <div
                v-if="exportOpen"
                class="dropdown-menu show shadow border px-0 py-1 mt-1 staff-toolbar-export-dropdown"
                style="min-width: 11rem; right: 0; left: auto"
                @click.stop
              >
                <button
                  type="button"
                  class="dropdown-item small"
                  :disabled="exportBusy"
                  @click="runAccountsExport"
                >
                  Download CSV
                </button>
              </div>
            </div>
            <button
              type="button"
              class="btn btn-outline-secondary staff-toolbar-btn d-inline-flex align-items-center justify-content-center"
              title="Copy public signup link"
              aria-label="Copy public signup link"
              @click="copyPublicCreateLink"
            >
              <svg
                width="18"
                height="18"
                fill="none"
                stroke="currentColor"
                viewBox="0 0 24 24"
                aria-hidden="true"
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
              v-if="canUpdate"
              type="button"
              class="btn btn-outline-secondary staff-toolbar-btn"
              :disabled="!selectedIds.length || loading"
              @click="openBulkEdit"
            >
              Bulk Edit
            </button>
            <button
              v-if="canDelete"
              type="button"
              class="btn btn-outline-danger staff-toolbar-btn"
              :disabled="!selectedIds.length || loading"
              @click="openBulkDelete"
            >
              Bulk Delete
            </button>
          </div>
        </div>
      </div>

      <div class="table-responsive staff-table-wrap">
        <table class="table table-hover align-middle mb-0 staff-data-table">
          <thead class="table-light staff-table-head">
            <tr>
              <th
                v-if="showCheckboxCol"
                class="staff-table-head__th staff-table-head__th--select"
                scope="col"
              >
                <input
                  type="checkbox"
                  class="form-check-input staff-table-head__check mt-0"
                  :checked="isAllPageSelected"
                  :disabled="loading || !rows.length"
                  aria-label="Select all on page"
                  @change="toggleSelectAll"
                />
              </th>
              <th
                class="staff-table-head__th staff-table-head__th--sort"
                scope="col"
                :aria-sort="thAriaSort('company_name')"
              >
                <button
                  type="button"
                  class="staff-sort-btn"
                  :disabled="loading"
                  @click="toggleSort('company_name')"
                >
                  Account
                  <span
                    v-if="sortIndicator('company_name')"
                    class="staff-sort-ind"
                    >{{ sortIndicator("company_name") }}</span
                  >
                </button>
              </th>
              <th
                class="staff-table-head__th staff-table-head__th--sort"
                scope="col"
                :aria-sort="thAriaSort('status')"
              >
                <button
                  type="button"
                  class="staff-sort-btn"
                  :disabled="loading"
                  @click="toggleSort('status')"
                >
                  Status
                  <span v-if="sortIndicator('status')" class="staff-sort-ind">{{
                    sortIndicator("status")
                  }}</span>
                </button>
              </th>
              <th
                class="staff-table-head__th staff-table-head__th--sort"
                scope="col"
                :aria-sort="thAriaSort('email')"
              >
                <button
                  type="button"
                  class="staff-sort-btn"
                  :disabled="loading"
                  @click="toggleSort('email')"
                >
                  Email
                  <span v-if="sortIndicator('email')" class="staff-sort-ind">{{
                    sortIndicator("email")
                  }}</span>
                </button>
              </th>
              <th
                class="staff-table-head__th text-center staff-table-head__th--channel"
                scope="col"
                aria-sort="none"
              >
                Channel
              </th>
              <th
                class="staff-table-head__th staff-table-head__th--sort"
                scope="col"
                :aria-sort="thAriaSort('created_at')"
              >
                <button
                  type="button"
                  class="staff-sort-btn"
                  :disabled="loading"
                  @click="toggleSort('created_at')"
                >
                  Start date
                  <span
                    v-if="sortIndicator('created_at')"
                    class="staff-sort-ind"
                    >{{ sortIndicator("created_at") }}</span
                  >
                </button>
              </th>
              <th class="staff-table-head__th" scope="col" aria-sort="none">
                Account manager
              </th>
              <th
                v-if="showRowActions"
                class="staff-table-head__th staff-actions-col text-center client-accounts-actions-col"
                scope="col"
                aria-sort="none"
              >
                Actions
              </th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="loading">
              <td :colspan="tableColspan" class="py-5">
                <div class="d-flex justify-content-center py-3">
                  <CrmLoadingSpinner message="Loading…" />
                </div>
              </td>
            </tr>
            <tr
              v-for="row in rows"
              v-else
              :key="row.id"
              class="align-middle"
            >
              <td v-if="showCheckboxCol" class="staff-table-cell--tight-check">
                <input
                  type="checkbox"
                  class="form-check-input staff-table-head__check mt-0"
                  :checked="selectedIds.includes(row.id)"
                  :aria-label="`Select ${row.company_name}`"
                  @change="toggleRowSelect(row.id)"
                />
              </td>
              <td>
                <RouterLink
                  :to="`/clients/accounts/${row.id}`"
                  class="d-flex align-items-center gap-3 min-w-0 text-decoration-none rounded px-1 py-1"
                >
                  <span
                    class="flex-shrink-0 rounded-circle overflow-hidden bg-body-secondary d-inline-flex"
                    style="width: 2.75rem; height: 2.75rem"
                  >
                    <img
                      v-if="row.primary_avatar_url"
                      :src="resolvePublicUrl(row.primary_avatar_url)"
                      alt=""
                      class="w-100 h-100 object-fit-cover"
                    />
                    <span
                      v-else
                      class="d-flex w-100 h-100 align-items-center justify-content-center small fw-semibold"
                      :class="avatarClassForRow(row.email)"
                    >
                      {{
                        initialsFromName(
                          row.contact_full_name || row.company_name,
                        )
                      }}
                    </span>
                  </span>
                  <div class="min-w-0">
                    <span class="d-block text-truncate fw-semibold text-body">{{
                      row.company_name
                    }}</span>
                    <span
                      class="d-block text-truncate text-body staff-user-cell__meta"
                    >
                      {{
                        row.contact_full_name &&
                        String(row.contact_full_name).trim()
                          ? row.contact_full_name
                          : "—"
                      }}
                    </span>
                  </div>
                </RouterLink>
              </td>
              <td>
                <span
                  class="badge rounded-pill text-capitalize fw-medium"
                  :class="statusBadgeClass(row.status)"
                >
                  {{ row.status }}
                </span>
              </td>
              <td
                class="text-body staff-table-cell__meta text-truncate"
                style="max-width: 14rem"
              >
                {{ row.email }}
              </td>
              <td class="staff-table-cell--channel text-center">
                <div class="staff-table-cell--channel-inner">
                  <ClientAccountChannelIcons
                    :notify-email="!!row.notify_email"
                    :telegram-handle="row.telegram_handle || ''"
                    :whatsapp-e164="row.whatsapp_e164 || ''"
                    :slack-channel="row.slack_channel || ''"
                    :in-house-slack="row.in_house_slack || ''"
                  />
                </div>
              </td>
              <td class="text-body staff-table-cell__meta text-nowrap">
                {{ formatDateUs(accountStartDate(row)) }}
              </td>
              <td
                class="text-body staff-table-cell__meta text-truncate"
                style="max-width: 12rem"
                :title="row.account_manager?.name"
              >
                {{ row.account_manager?.name || "—" }}
              </td>
              <td v-if="showRowActions" class="staff-actions-cell text-center client-accounts-actions-cell">
                <div
                  data-row-actions
                  class="staff-actions-inner staff-actions-inner--single"
                >
                  <button
                    type="button"
                    class="staff-action-btn staff-action-btn--more"
                    :class="{ 'is-open': manageOpenId === row.id }"
                    :aria-expanded="manageOpenId === row.id"
                    :aria-row-actions="row.id"
                    aria-haspopup="true"
                    aria-label="Row actions"
                    @click="toggleManageMenu(row.id, $event)"
                  >
                    <CrmIconRowActions variant="horizontal" />
                  </button>
                </div>
              </td>
            </tr>
            <tr v-if="!loading && rows.length === 0">
              <td
                :colspan="tableColspan"
                class="px-4 py-5 text-center text-secondary"
              >
                No accounts found.
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <p class="staff-table-mobile-scroll-cue d-md-none" aria-hidden="true">
        Scroll sideways or swipe to see all columns.
      </p>

      <div
        class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-lg-between gap-3 border-top staff-table-footer"
      >
        <div
          class="d-flex flex-column flex-sm-row align-items-sm-center gap-2 gap-sm-4 flex-wrap order-2 order-lg-1 justify-content-center justify-content-lg-start"
        >
          <p
            class="small text-secondary mb-0 text-center text-sm-start"
          >
            Showing
            <span class="fw-semibold text-body">{{ showingFrom }}</span>
            to
            <span class="fw-semibold text-body">{{ showingTo }}</span>
            of
            <span class="fw-semibold text-body">{{ pagination.total }}</span>
            entries
          </p>
          <div
            class="d-flex align-items-center gap-2 justify-content-center justify-content-sm-start"
          >
            <label
              class="small text-secondary text-nowrap mb-0"
              for="ca-per-page-footer"
              >Rows per page</label
            >
            <select
              id="ca-per-page-footer"
              class="form-select form-select-sm staff-table-footer-per-page"
              :value="query.per_page"
              :disabled="loading"
              @change="onPerPageChange"
            >
              <option v-for="n in PER_PAGE_OPTIONS" :key="n" :value="n">
                {{ n }}
              </option>
            </select>
          </div>
        </div>
        <nav
          class="order-1 order-lg-2 d-flex justify-content-center justify-content-lg-end ms-lg-auto flex-shrink-0"
          aria-label="Account list pages"
        >
          <div class="staff-page-pager staff-page-pager--cluster">
            <div class="staff-page-pager__start">
              <button
                type="button"
                class="staff-page-pager-tile staff-page-pager-tile--nav"
                :disabled="loading || pagination.current_page <= 1"
                aria-label="First page"
                @click="goFirstPage"
              >
                <svg
                  width="18"
                  height="18"
                  fill="currentColor"
                  viewBox="0 0 24 24"
                  aria-hidden="true"
                >
                  <path
                    d="M5.59 18L7 16.59 2.41 12 7 7.41 5.59 6l-6 6 6 6zm8 0L15 16.59 10.41 12 15 7.41 13.59 6l-6 6 6 6z"
                  />
                </svg>
              </button>
              <button
                type="button"
                class="staff-page-pager-tile staff-page-pager-tile--nav"
                :disabled="loading || pagination.current_page <= 1"
                aria-label="Previous page"
                @click="goPage(pagination.current_page - 1)"
              >
                <svg
                  width="18"
                  height="18"
                  fill="currentColor"
                  viewBox="0 0 24 24"
                  aria-hidden="true"
                >
                  <path d="M15.41 7.41L14 6l-6 6 6 6 1.41-1.41L10.83 12z" />
                </svg>
              </button>
            </div>
            <div class="staff-page-pager__pages">
              <div class="staff-page-pager-inner d-flex align-items-center">
                <template v-for="(item, idx) in pageItems" :key="'ca-pi-' + idx">
                  <span
                    v-if="item.type === 'gap'"
                    class="px-1 small text-secondary user-select-none"
                    >…</span
                  >
                  <button
                    v-else
                    type="button"
                    class="staff-page-pager-tile"
                    :class="{
                      'staff-page-pager-tile--active':
                        item.value === pagination.current_page,
                    }"
                    :disabled="loading"
                    @click="goPage(item.value)"
                  >
                    {{ item.value }}
                  </button>
                </template>
              </div>
            </div>
            <div class="staff-page-pager__end">
              <button
                type="button"
                class="staff-page-pager-tile staff-page-pager-tile--nav"
                :disabled="
                  loading || pagination.current_page >= pagination.last_page
                "
                aria-label="Next page"
                @click="goPage(pagination.current_page + 1)"
              >
                <svg
                  width="18"
                  height="18"
                  fill="currentColor"
                  viewBox="0 0 24 24"
                  aria-hidden="true"
                >
                  <path d="M8.59 16.59L10 18l6-6-6-6-1.41 1.41L13.17 12z" />
                </svg>
              </button>
              <button
                type="button"
                class="staff-page-pager-tile staff-page-pager-tile--nav"
                :disabled="
                  loading || pagination.current_page >= pagination.last_page
                "
                aria-label="Last page"
                @click="goLastPage"
              >
                <svg
                  width="18"
                  height="18"
                  fill="currentColor"
                  viewBox="0 0 24 24"
                  aria-hidden="true"
                >
                  <path
                    d="M6.41 6L5 7.41 9.58 12 5 16.59 6.41 18l6-6-6-6zm8 0L13 7.41 17.58 12 13 16.59 14.41 18l6-6-6-6z"
                  />
                </svg>
              </button>
            </div>
          </div>
        </nav>
      </div>
    </div>

    <ConfirmModal
      :open="deleteModalOpen"
      title="Delete account"
      subtitle="This action is permanent and may remove related CRM data."
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
        enter-from-class="opacity-0"
        enter-to-class="opacity-100"
        leave-active-class="transition ease-in duration-75"
        leave-from-class="opacity-100"
        leave-to-class="opacity-0"
      >
        <div
          v-if="manageMenuRow"
          data-row-actions
          class="staff-row-menu fixed z-[300]"
          role="menu"
          :style="{
            top: `${manageMenuRect.top}px`,
            left: `${manageMenuRect.left}px`,
            minWidth: '200px',
          }"
          @click.stop
        >
          <template v-if="manageMenuSubMode === 'main'">
            <button
              type="button"
              class="staff-row-menu__item"
              role="menuitem"
              @click="goViewAccount(manageMenuRow)"
            >
              View
            </button>
            <hr
              v-if="canUpdate || canDelete"
              class="staff-row-menu__divider"
            />
            <button
              v-if="canUpdate"
              type="button"
              class="staff-row-menu__item"
              role="menuitem"
              @click="openStatusSubmenu"
            >
              Status
            </button>
            <hr
              v-if="canUpdate"
              class="staff-row-menu__divider"
            />
            <button
              v-if="canUpdate"
              type="button"
              class="staff-row-menu__item"
              role="menuitem"
              @click="openEditModal(manageMenuRow)"
            >
              Edit
            </button>
            <hr
              v-if="canUpdate && canDelete"
              class="staff-row-menu__divider"
            />
            <button
              v-if="canDelete"
              type="button"
              class="staff-row-menu__item staff-row-menu__item--danger"
              role="menuitem"
              @click="openDeleteModal(manageMenuRow)"
            >
              Delete
            </button>
          </template>
          <template v-else>
            <button
              type="button"
              class="staff-row-menu__item"
              role="menuitem"
              @click="backToMainMenu"
            >
              ← Back
            </button>
            <hr class="staff-row-menu__divider" />
            <button
              v-for="s in statuses"
              :key="s"
              type="button"
              class="staff-row-menu__item text-capitalize"
              role="menuitem"
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
