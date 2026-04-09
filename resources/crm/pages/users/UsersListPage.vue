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
import UserCreateDrawer from "../../components/users/UserCreateDrawer.vue";
import UserEditModal from "../../components/users/UserEditModal.vue";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import { useToast } from "../../composables/useToast";
import { crmIsAdmin } from "../../utils/crmUser";
import { DEFAULT_PER_PAGE, PER_PAGE_OPTIONS } from "../../constants/pagination";
import CrmIconRowActions from "../../components/common/CrmIconRowActions.vue";
import StaffRoleIcon from "../../components/users/StaffRoleIcon.vue";
import StaffBulkEditModal from "../../components/users/StaffBulkEditModal.vue";
import { resolvePublicUrl } from "../../utils/resolvePublicUrl.js";
import { formatBirthdayUs, formatDateUs } from "../../utils/formatUserDates";
import { downloadListCsv } from "../../utils/downloadListCsv.js";

const crmUser = inject("crmUser", ref(null));
const toast = useToast();
const router = useRouter();

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

const showCheckboxColumn = computed(
  () => canUpdateUsers.value || canDeleteUsers.value,
);

/** User, Status, Position, Birthday, Hire date, Role (+ optional checkbox & actions). */
const tableColspan = computed(() => {
  let n = 6;
  if (showCheckboxColumn.value) n += 1;
  if (showRowActions.value) n += 1;
  return n;
});

/** Maps to API `sort_by` (User → name). */
const STAFF_SORT_KEYS = [
  "status",
  "name",
  "job_position",
  "birthday",
  "hire_date",
  "role",
];

function toggleSort(column) {
  if (!STAFF_SORT_KEYS.includes(column)) return;
  if (query.sort_by === column) {
    query.sort_dir = query.sort_dir === "asc" ? "desc" : "asc";
  } else {
    query.sort_by = column;
    query.sort_dir = "asc";
  }
  query.page = 1;
  selectedIds.value = [];
  fetchUsers();
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

const nf = new Intl.NumberFormat(undefined, { maximumFractionDigits: 0 });

const stats = ref({
  total: 0,
  active: 0,
  pending: 0,
  inactive: 0,
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
const exportOpen = ref(false);
const exportBusy = ref(false);
const filterMenuOpen = ref(false);
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
  status: "all",
});

let searchDebounce = null;
let searchWatchLock = false;

const deleteModalOpen = computed(() => deleteTarget.value !== null);

const deleteMessage = computed(() => {
  const u = deleteTarget.value;
  return u
    ? `Are You Sure You Want To Delete ${u.name}? This Cannot Be Undone.`
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

const statusBadgeClass = (status) => {
  const s = String(status || "").toLowerCase();
  if (s === "active") {
    return "bg-success-subtle text-success";
  }
  if (s === "pending") {
    return "bg-warning-subtle text-warning-emphasis";
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
  "bg-success-subtle text-success",
  "bg-danger-subtle text-danger-emphasis",
];

function primaryRoleLabel(user) {
  const r = user.roles;
  if (!r || !r.length) return "—";
  return r[0].label || r[0].name || "—";
}

function roleIconMeta(user) {
  const label = primaryRoleLabel(user);
  if (label === "—") {
    return { wrap: "bg-body-secondary text-body-secondary", kind: "user" };
  }
  let h = 0;
  for (let i = 0; i < label.length; i++) {
    h = (h + label.charCodeAt(i)) % 997;
  }
  const variants = [
    { wrap: "bg-info-subtle text-info", kind: "chart" },
    { wrap: "bg-warning-subtle text-warning-emphasis", kind: "pencil" },
    { wrap: "bg-primary-subtle text-primary", kind: "crown" },
    { wrap: "bg-danger-subtle text-danger", kind: "monitor" },
    { wrap: "bg-success-subtle text-success", kind: "user" },
  ];
  return variants[h % variants.length];
}

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
  if (query.status && query.status !== "all") p.status = query.status;
  return p;
};

function buildExportParams() {
  const p = {};
  const s = (query.search || "").trim();
  if (s) p.search = s;
  if (query.status && query.status !== "all") p.status = query.status;
  return p;
}

async function runStaffExport() {
  exportOpen.value = false;
  exportBusy.value = true;
  try {
    await downloadListCsv({
      path: "/users/export-csv",
      params: buildExportParams(),
      filenameBase: "staff",
      toast,
    });
  } catch {
    /* toast handled in downloadListCsv */
  } finally {
    exportBusy.value = false;
  }
}

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

async function fetchStats() {
  try {
    const base = { per_page: 1, page: 1 };
    const [all, active, pending, inactive] = await Promise.all([
      api.get("/users", { params: { ...base } }),
      api.get("/users", { params: { ...base, status: "active" } }),
      api.get("/users", { params: { ...base, status: "pending" } }),
      api.get("/users", { params: { ...base, status: "inactive" } }),
    ]);
    stats.value = {
      total: all.data?.total ?? 0,
      active: active.data?.total ?? 0,
      pending: pending.data?.total ?? 0,
      inactive: inactive.data?.total ?? 0,
    };
  } catch {
    /* ignore */
  }
}

async function refreshList() {
  await fetchUsers();
  await fetchStats();
}

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
  query.status = "all";
  query.sort_by = "name";
  query.sort_dir = "asc";
  query.page = 1;
  selectedIds.value = [];
  fetchUsers().finally(() => {
    searchWatchLock = false;
  });
};

const goPage = (p) => {
  if (p < 1 || p > pagination.value.last_page) return;
  query.page = p;
  selectedIds.value = [];
  fetchUsers();
};

function goFirstPage() {
  goPage(1);
}

function goLastPage() {
  goPage(pagination.value.last_page);
}

function onPerPageChange(e) {
  query.per_page = Number(e.target.value);
  query.page = 1;
  selectedIds.value = [];
  fetchUsers();
}

function openBulkEdit() {
  if (!selectedIds.value.length) {
    toast.error("Select One Or More Rows.");
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
    toast.success("Staff Updated.");
    bulkEditOpen.value = false;
    selectedIds.value = [];
    await refreshList();
  } catch (e) {
    toast.errorFrom(e, "Could Not Update Staff.");
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
    toast.success("User Deleted.");
    await refreshList();
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

const MENU_W = 200;
const MENU_H = 200;

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

function goViewStaff(user) {
  closeManageMenu();
  router.push(`/staff/${user.id}`);
}

function onWindowScrollOrResize() {
  if (manageOpenId.value !== null) {
    closeManageMenu();
  }
}

async function toggleManageMenu(userId, e) {
  e.stopPropagation();
  if (manageOpenId.value === userId) {
    closeManageMenu();
    return;
  }
  const btn = e.currentTarget;
  manageOpenId.value = userId;
  await nextTick();
  requestAnimationFrame(() => {
    requestAnimationFrame(() => {
      if (manageOpenId.value !== userId) return;
      if (btn instanceof HTMLElement) placeManageMenu(btn);
    });
  });
}

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
  if (!e.target.closest("[data-export-root]")) {
    exportOpen.value = false;
  }
  if (!e.target.closest("[data-toolbar-filter]")) {
    filterMenuOpen.value = false;
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
  await refreshList();
});

onUnmounted(() => {
  document.removeEventListener("click", onDocClick);
  window.removeEventListener("scroll", onWindowScrollOrResize, true);
  window.removeEventListener("resize", onWindowScrollOrResize);
  clearTimeout(searchDebounce);
});
</script>

<template>
  <div class="staff-page staff-page--wide staff-directory-page">
    <UserCreateDrawer
      v-if="canCreateUsers"
      v-model:open="addDrawerOpen"
      @saved="refreshList"
    />

    <StaffBulkEditModal
      v-model:open="bulkEditOpen"
      :roles="roles"
      :selected-count="selectedIds.length"
      :busy="bulkEditBusy"
      @apply="onBulkApply"
    />

    <div
      v-if="deleteError"
      class="alert alert-danger mb-3 mb-md-4"
      role="alert"
    >
      {{ deleteError }}
    </div>

    <div
      class="d-flex flex-column flex-md-row align-items-center justify-content-between gap-3 mb-4"
    >
      <div class="min-w-0 flex-grow-1 text-center text-md-start w-100">
        <h1 class="h4 mb-1 fw-semibold text-body staff-page__heading">Staff</h1>
        <p class="staff-page__intro mb-0">
          Directory of admin and staff accounts
        </p>
      </div>
      <div
        class="d-flex flex-wrap align-items-center justify-content-center justify-content-md-end gap-2 flex-shrink-0"
      >
        <button
          v-if="canCreateUsers"
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
          Add Staff
        </button>
      </div>
    </div>

    <div class="row g-4 mb-2">
      <div class="col-12 col-sm-6 col-xl-3">
        <div class="staff-stat-card h-100">
          <p class="staff-stat-card__label">Total users</p>
          <p class="staff-stat-card__value">{{ nf.format(stats.total) }}</p>
          <p class="staff-stat-card__sub">All accounts in the directory</p>
          <div
            class="staff-stat-card__icon text-white"
            style="background: #2563eb"
            aria-hidden="true"
          >
            <svg width="22" height="22" fill="currentColor" viewBox="0 0 24 24">
              <path
                d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"
              />
            </svg>
          </div>
        </div>
      </div>
      <div class="col-12 col-sm-6 col-xl-3">
        <div class="staff-stat-card h-100">
          <p class="staff-stat-card__label">Active users</p>
          <p class="staff-stat-card__value">{{ nf.format(stats.active) }}</p>
          <p class="staff-stat-card__sub">Accounts marked active</p>
          <div class="staff-stat-card__icon bg-success-subtle text-success" aria-hidden="true">
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
          <p class="staff-stat-card__label">Pending users</p>
          <p class="staff-stat-card__value">{{ nf.format(stats.pending) }}</p>
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
          <p class="staff-stat-card__label">Inactive users</p>
          <p class="staff-stat-card__value">{{ nf.format(stats.inactive) }}</p>
          <p class="staff-stat-card__sub">Disabled or suspended</p>
          <div class="staff-stat-card__icon bg-secondary-subtle text-secondary" aria-hidden="true">
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
            id="staff-search"
            v-model="query.search"
            type="search"
            class="form-control staff-toolbar-search staff-toolbar-search--inline"
            placeholder="Search user"
            autocomplete="off"
            @keydown.enter.prevent="applySearch"
          />
          <div class="position-relative flex-shrink-0" data-toolbar-filter>
            <button
              type="button"
              class="btn btn-outline-secondary staff-toolbar-btn d-inline-flex align-items-center gap-2"
              :aria-expanded="filterMenuOpen"
              aria-haspopup="true"
              aria-controls="staff-filter-panel"
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
              id="staff-filter-panel"
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
                <label class="form-label" for="staff-filter-status">Status</label>
                <select
                  id="staff-filter-status"
                  v-model="query.status"
                  class="form-select staff-datatable-filters__select"
                  :disabled="loading"
                  @change="applySearch"
                >
                  <option value="all">All statuses</option>
                  <option value="pending">Pending</option>
                  <option value="active">Active</option>
                  <option value="inactive">Inactive</option>
                </select>
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
                class="dropdown-menu show shadow border px-0 py-1 mt-1"
                style="min-width: 11rem; right: 0; left: auto"
                @click.stop
              >
                <button
                  type="button"
                  class="dropdown-item small"
                  :disabled="exportBusy"
                  @click="runStaffExport"
                >
                  Download CSV
                </button>
              </div>
            </div>
            <button
              v-if="canUpdateUsers"
              type="button"
              class="btn btn-outline-secondary staff-toolbar-btn"
              :disabled="!selectedIds.length || loading"
              @click="openBulkEdit"
            >
              Bulk edit
            </button>
          </div>
        </div>
      </div>

      <div class="table-responsive staff-table-wrap">
        <table class="table table-hover align-middle mb-0 staff-data-table">
          <thead class="table-light staff-table-head">
            <tr>
              <th
                v-if="showCheckboxColumn"
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
                :aria-sort="thAriaSort('name')"
              >
                <button
                  type="button"
                  class="staff-sort-btn"
                  :disabled="loading"
                  @click="toggleSort('name')"
                >
                  User
                  <span v-if="sortIndicator('name')" class="staff-sort-ind">{{
                    sortIndicator("name")
                  }}</span>
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
                :aria-sort="thAriaSort('job_position')"
              >
                <button
                  type="button"
                  class="staff-sort-btn"
                  :disabled="loading"
                  @click="toggleSort('job_position')"
                >
                  Position
                  <span
                    v-if="sortIndicator('job_position')"
                    class="staff-sort-ind"
                    >{{ sortIndicator("job_position") }}</span
                  >
                </button>
              </th>
              <th
                class="staff-table-head__th staff-table-head__th--sort"
                scope="col"
                :aria-sort="thAriaSort('birthday')"
              >
                <button
                  type="button"
                  class="staff-sort-btn"
                  :disabled="loading"
                  @click="toggleSort('birthday')"
                >
                  Birthday
                  <span v-if="sortIndicator('birthday')" class="staff-sort-ind">{{
                    sortIndicator("birthday")
                  }}</span>
                </button>
              </th>
              <th
                class="staff-table-head__th staff-table-head__th--sort"
                scope="col"
                :aria-sort="thAriaSort('hire_date')"
              >
                <button
                  type="button"
                  class="staff-sort-btn"
                  :disabled="loading"
                  @click="toggleSort('hire_date')"
                >
                  Hire Date
                  <span
                    v-if="sortIndicator('hire_date')"
                    class="staff-sort-ind"
                    >{{ sortIndicator("hire_date") }}</span
                  >
                </button>
              </th>
              <th
                class="staff-table-head__th staff-table-head__th--sort"
                scope="col"
                :aria-sort="thAriaSort('role')"
              >
                <button
                  type="button"
                  class="staff-sort-btn"
                  :disabled="loading"
                  @click="toggleSort('role')"
                >
                  Role
                  <span v-if="sortIndicator('role')" class="staff-sort-ind">{{
                    sortIndicator("role")
                  }}</span>
                </button>
              </th>
              <th
                v-if="showRowActions"
                class="staff-table-head__th staff-actions-col"
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
                  <CrmLoadingSpinner message="Loading users…" />
                </div>
              </td>
            </tr>
            <tr
              v-for="user in rows"
              v-else
              :key="user.id"
              class="align-middle"
            >
              <td v-if="showCheckboxColumn" class="staff-table-cell--tight-check">
                <input
                  type="checkbox"
                  class="form-check-input staff-table-head__check mt-0"
                  :checked="selectedIds.includes(user.id)"
                  :aria-label="`Select ${user.name}`"
                  @change="toggleRowSelect(user.id)"
                />
              </td>
              <td>
                <div class="d-flex align-items-center gap-3 min-w-0">
                  <span
                    class="flex-shrink-0 rounded-circle overflow-hidden bg-body-secondary d-inline-flex"
                    style="width: 2.75rem; height: 2.75rem"
                  >
                    <img
                      v-if="user.profile?.avatar_url"
                      :src="resolvePublicUrl(user.profile.avatar_url)"
                      alt=""
                      class="w-100 h-100 object-fit-cover"
                    />
                    <span
                      v-else
                      class="d-flex w-100 h-100 align-items-center justify-content-center fw-semibold staff-user-cell__meta"
                      :class="avatarClassForUser(user.email)"
                    >
                      {{ initials(user.name) }}
                    </span>
                  </span>
                  <div class="min-w-0">
                    <RouterLink
                      :to="`/staff/${user.id}`"
                      class="d-block text-truncate fw-semibold text-body text-decoration-none"
                    >
                      {{ user.name }}
                    </RouterLink>
                    <RouterLink
                      :to="`/staff/${user.id}`"
                      class="d-block text-truncate text-body text-decoration-none staff-user-cell__meta"
                    >
                      {{ user.email }}
                    </RouterLink>
                  </div>
                </div>
              </td>
              <td>
                <span
                  class="badge rounded-pill text-capitalize fw-medium"
                  :class="statusBadgeClass(user.status)"
                >
                  {{ user.status }}
                </span>
              </td>
              <td
                class="text-body staff-table-cell__meta text-truncate"
                style="max-width: 10rem"
                :title="user.profile?.job_position || undefined"
              >
                {{ user.profile?.job_position || "—" }}
              </td>
              <td class="text-body staff-table-cell__meta text-nowrap">
                {{ formatBirthdayUs(user.profile?.birthday) }}
              </td>
              <td class="text-body staff-table-cell__meta text-nowrap">
                {{ formatDateUs(user.profile?.hire_date) }}
              </td>
              <td>
                <div class="d-flex align-items-center gap-2 min-w-0">
                  <StaffRoleIcon :roles="user.roles" />
                  <span class="text-body text-truncate staff-table-cell__meta">{{
                    primaryRoleLabel(user)
                  }}</span>
                </div>
              </td>
              <td v-if="showRowActions" class="staff-actions-cell text-center">
                <div
                  data-row-actions
                  class="staff-actions-inner staff-actions-inner--single justify-content-center"
                >
                  <button
                    type="button"
                    class="staff-action-btn staff-action-btn--more"
                    :class="{ 'is-open': manageOpenId === user.id }"
                    :aria-expanded="manageOpenId === user.id"
                    aria-haspopup="true"
                    aria-label="Row actions"
                    @click="toggleManageMenu(user.id, $event)"
                  >
                    <CrmIconRowActions variant="vertical" />
                  </button>
                </div>
              </td>
            </tr>
            <tr v-if="!loading && rows.length === 0">
              <td :colspan="tableColspan" class="px-4 py-5 text-center text-secondary">
                No staff found.
              </td>
            </tr>
          </tbody>
        </table>
      </div>

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
              for="users-per-page-footer"
              >Rows per page</label
            >
            <select
              id="users-per-page-footer"
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
          aria-label="Staff list pages"
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
                <template v-for="(item, idx) in pageItems" :key="'pi-' + idx">
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

    <UserEditModal
      v-model:open="userEditModalOpen"
      :user-id="userEditModalUserId"
      @saved="refreshList"
    />

    <ConfirmModal
      :open="deleteModalOpen"
      title="Delete User"
      subtitle="This action is permanent and may be audited."
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
          v-if="manageMenuUser"
          data-row-actions
          class="staff-row-menu fixed z-[300] overflow-hidden"
          role="menu"
          :style="{
            top: `${manageMenuRect.top}px`,
            left: `${manageMenuRect.left}px`,
          }"
          @click.stop
        >
          <button
            type="button"
            class="staff-row-menu__item"
            role="menuitem"
            @click="goViewStaff(manageMenuUser)"
          >
            View
          </button>
          <hr
            v-if="canUpdateUsers || canDeleteRow(manageMenuUser)"
            class="staff-row-menu__divider"
          />
          <button
            v-if="canUpdateUsers"
            type="button"
            class="staff-row-menu__item"
            role="menuitem"
            @click="openUserEditModal(manageMenuUser)"
          >
            Edit
          </button>
          <hr
            v-if="canUpdateUsers && canDeleteRow(manageMenuUser)"
            class="staff-row-menu__divider"
          />
          <button
            v-if="canDeleteRow(manageMenuUser)"
            type="button"
            class="staff-row-menu__item staff-row-menu__item--danger"
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
