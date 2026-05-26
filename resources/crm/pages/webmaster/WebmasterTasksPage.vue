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
import { useRoute, useRouter } from "vue-router";
import api from "../../services/api";
import ConfirmModal from "../../components/common/ConfirmModal.vue";
import WebmasterTaskDrawer from "../../components/webmaster/WebmasterTaskDrawer.vue";
import WebmasterTaskModal from "../../components/webmaster/WebmasterTaskModal.vue";
import WebmasterTasksBulkEditModal from "../../components/webmaster/WebmasterTasksBulkEditModal.vue";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import { useToast } from "../../composables/useToast";
import { errorMessage } from "../../utils/apiError";
import { crmIsAdmin } from "../../utils/crmUser";
import { DEFAULT_PER_PAGE, PER_PAGE_OPTIONS } from "../../constants/pagination";
import { formatUsdPrice } from "../../utils/formatPrice";
import { formatDateUs } from "../../utils/formatUserDates";
import CrmIconRowActions from "../../components/common/CrmIconRowActions.vue";

const crmUser = inject("crmUser", ref(null));
const toast = useToast();
const route = useRoute();
const router = useRouter();

function userHasPerm(key) {
  const u = crmUser.value;
  if (!u) return false;
  if (crmIsAdmin(u) || u.is_crm_owner) return true;
  return Array.isArray(u.permission_keys) && u.permission_keys.includes(key);
}

const canCreateTasks = computed(() => userHasPerm("webmaster.create"));
const canUpdateTasks = computed(() => userHasPerm("webmaster.update"));
const canDeleteTasks = computed(() => userHasPerm("webmaster.delete"));
const showRowActions = computed(() => canUpdateTasks.value || canDeleteTasks.value);
const showCheckboxColumn = computed(() => canUpdateTasks.value || canDeleteTasks.value);

const TASK_SORT_KEYS = ["title", "status", "priority", "due_date", "created_at"];

const loading = ref(true);
const rows = ref([]);
const pagination = ref({ current_page: 1, last_page: 1, total: 0 });
const users = ref([]);
const meta = ref({ statuses: [], priorities: [] });
const manageOpenId = ref(null);
const manageMenuRect = ref({ top: 0, left: 0 });
const filterMenuOpen = ref(false);
const bulkMenuOpen = ref(false);
const drawerOpen = ref(false);
const taskEditModalOpen = ref(false);
const editingTask = ref(null);
const deleteTarget = ref(null);
const deleteBusy = ref(false);
const deleteError = ref("");
const bulkEditOpen = ref(false);
const bulkEditBusy = ref(false);
const bulkDeleteOpen = ref(false);
const bulkDeleteBusy = ref(false);
const selectedIds = ref([]);

const query = reactive({
  search: "",
  per_page: DEFAULT_PER_PAGE,
  page: 1,
  sort_by: "due_date",
  sort_dir: "asc",
  status: "",
  priority: "",
  assigned_to: "",
  min_price: "",
  max_price: "",
});

let searchDebounce = null;
let searchWatchLock = false;

const MENU_W = 200;
const MENU_H = 160;

const tableColspan = computed(() => {
  let n = 7;
  if (showCheckboxColumn.value) n += 1;
  if (showRowActions.value) n += 1;
  return n;
});

const manageMenuTask = computed(
  () => rows.value.find((t) => t.id === manageOpenId.value) ?? null,
);

const deleteModalOpen = computed(() => deleteTarget.value !== null);
const deleteMessage = computed(() => {
  const t = deleteTarget.value;
  return t ? `Delete task “${t.title}”? This cannot be undone.` : "";
});

const bulkDeleteMessage = computed(() => {
  const n = selectedIds.value.length;
  if (n < 1) return "";
  return `Delete ${n} task${n === 1 ? "" : "s"}? This cannot be undone.`;
});

const isAllPageSelected = computed(
  () =>
    rows.value.length > 0 &&
    rows.value.every((t) => selectedIds.value.includes(t.id)),
);

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

watch(
  () => query.search,
  () => {
    if (searchWatchLock) return;
    clearTimeout(searchDebounce);
    searchDebounce = setTimeout(() => {
      query.page = 1;
      selectedIds.value = [];
      fetchTasks();
    }, 300);
  },
);

watch(
  () => route.query.edit,
  (v) => {
    if (v) openTaskEditFromQuery();
  },
  { immediate: true },
);

function statusLabel(v) {
  const s = meta.value.statuses.find((x) => x.value === v);
  return s ? s.label : v;
}

function priorityLabel(v) {
  const p = meta.value.priorities.find((x) => x.value === v);
  return p ? p.label : v;
}

function statusBadgeClass(status) {
  const x = String(status || "").toLowerCase();
  const map = {
    pending: "text-secondary-emphasis bg-secondary-subtle",
    in_progress: "text-primary-emphasis bg-primary-subtle",
    review: "text-warning-emphasis bg-warning-subtle",
    completed: "text-success-emphasis bg-success-subtle",
  };
  return map[x] || "text-secondary-emphasis bg-secondary-subtle";
}

function priorityBadgeClass(priority) {
  const x = String(priority || "").toLowerCase();
  const map = {
    low: "text-secondary-emphasis bg-secondary-subtle",
    medium: "text-primary-emphasis bg-primary-subtle",
    high: "text-warning-emphasis bg-warning-subtle",
    urgent: "text-danger-emphasis bg-danger-subtle",
  };
  return map[x] || "text-secondary-emphasis bg-secondary-subtle";
}

function toggleSort(column) {
  if (!TASK_SORT_KEYS.includes(column)) return;
  if (query.sort_by === column) {
    query.sort_dir = query.sort_dir === "asc" ? "desc" : "asc";
  } else {
    query.sort_by = column;
    query.sort_dir = column === "title" || column === "due_date" ? "asc" : "desc";
  }
  query.page = 1;
  selectedIds.value = [];
  fetchTasks();
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

async function fetchMeta() {
  try {
    const { data } = await api.get("/webmaster/tasks/meta");
    meta.value = {
      statuses: data.statuses || [],
      priorities: data.priorities || [],
    };
  } catch {
    meta.value = { statuses: [], priorities: [] };
  }
}

async function fetchUsers() {
  try {
    const { data } = await api.get("/users", { params: { per_page: 100, page: 1 } });
    users.value = data.data || [];
  } catch {
    users.value = [];
  }
}

const buildParams = () => {
  const p = {
    search: query.search || undefined,
    per_page: query.per_page,
    page: query.page,
    sort_by: query.sort_by,
    sort_dir: query.sort_dir,
  };
  if (query.status) p.status = query.status;
  if (query.priority) p.priority = query.priority;
  if (query.assigned_to) p.assigned_to = query.assigned_to;
  if (query.min_price !== "" && query.min_price != null) p.min_price = query.min_price;
  if (query.max_price !== "" && query.max_price != null) p.max_price = query.max_price;
  return p;
};

const fetchTasks = async () => {
  loading.value = true;
  deleteError.value = "";
  manageOpenId.value = null;
  try {
    const { data } = await api.get("/webmaster/tasks", { params: buildParams() });
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
  fetchTasks();
};

const clearFilters = () => {
  clearTimeout(searchDebounce);
  searchWatchLock = true;
  query.search = "";
  query.status = "";
  query.priority = "";
  query.assigned_to = "";
  query.min_price = "";
  query.max_price = "";
  query.page = 1;
  selectedIds.value = [];
  fetchTasks().finally(() => {
    searchWatchLock = false;
  });
};

function openAdd() {
  drawerOpen.value = true;
}

function openEdit(row) {
  editingTask.value = { ...row };
  closeManageMenu();
  taskEditModalOpen.value = true;
}

function taskDetailHref(task) {
  if (!task?.id) return "";
  return router.resolve({ path: `/admin/webmaster/tasks/${task.id}` }).href;
}

function openTaskDetailNewTab(task) {
  closeManageMenu();
  const url = taskDetailHref(task);
  if (!url) return;
  window.open(url, "_blank", "noopener,noreferrer");
}

async function openTaskEditFromQuery() {
  const raw = route.query.edit;
  const id =
    typeof raw === "string" && /^\d+$/.test(raw)
      ? raw
      : Array.isArray(raw) && raw[0] && /^\d+$/.test(String(raw[0]))
        ? String(raw[0])
        : null;
  if (!id) return;
  if (!canUpdateTasks.value) {
    router.replace({ path: "/admin/webmaster", query: {} });
    return;
  }
  try {
    const { data } = await api.get(`/webmaster/tasks/${id}`);
    openEdit(data);
  } catch {
    /* modal stays closed */
  } finally {
    router.replace({ path: "/admin/webmaster", query: {} });
  }
}

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

async function toggleManageMenu(taskId, e) {
  e.stopPropagation();
  if (manageOpenId.value === taskId) {
    closeManageMenu();
    return;
  }
  const btn = e.currentTarget;
  manageOpenId.value = taskId;
  await nextTick();
  requestAnimationFrame(() => {
    requestAnimationFrame(() => {
      if (manageOpenId.value !== taskId) return;
      if (btn instanceof HTMLElement) placeManageMenu(btn);
    });
  });
}

function toggleSelectAll(ev) {
  if (ev.target.checked) {
    selectedIds.value = rows.value.map((t) => t.id);
  } else {
    selectedIds.value = [];
  }
}

function toggleRowSelect(taskId) {
  const i = selectedIds.value.indexOf(taskId);
  if (i === -1) {
    selectedIds.value = [...selectedIds.value, taskId];
  } else {
    selectedIds.value = selectedIds.value.filter((id) => id !== taskId);
  }
}

function onDocClick(e) {
  if (!e.target.closest("[data-toolbar-filter]")) filterMenuOpen.value = false;
  if (!e.target.closest("[data-toolbar-bulk]")) bulkMenuOpen.value = false;
  if (!e.target.closest("[data-row-actions]")) closeManageMenu();
}

function onWindowScrollOrResize() {
  if (manageOpenId.value !== null) closeManageMenu();
}

function openBulkEdit() {
  if (!selectedIds.value.length) {
    toast.error("Select one or more tasks.");
    return;
  }
  bulkEditOpen.value = true;
}

async function onBulkApply(payload) {
  bulkEditBusy.value = true;
  try {
    await api.patch("/webmaster/tasks/bulk", {
      task_ids: selectedIds.value,
      ...payload,
    });
    toast.success("Tasks updated.");
    bulkEditOpen.value = false;
    selectedIds.value = [];
    await fetchTasks();
  } catch (e) {
    toast.errorFrom(e, "Could not update tasks.");
  } finally {
    bulkEditBusy.value = false;
  }
}

function openBulkDelete() {
  if (!selectedIds.value.length) {
    toast.error("Select one or more tasks.");
    return;
  }
  bulkDeleteOpen.value = true;
}

function closeBulkDelete() {
  if (bulkDeleteBusy.value) return;
  bulkDeleteOpen.value = false;
}

async function confirmBulkDelete() {
  const ids = selectedIds.value;
  if (!ids.length) return;
  bulkDeleteBusy.value = true;
  try {
    await api.delete("/webmaster/tasks/bulk", { data: { task_ids: ids } });
    toast.success("Tasks deleted.");
    bulkDeleteOpen.value = false;
    selectedIds.value = [];
    await fetchTasks();
  } catch (e) {
    toast.errorFrom(e, "Could not delete tasks.");
  } finally {
    bulkDeleteBusy.value = false;
  }
}

const openDeleteModal = (row) => {
  closeManageMenu();
  deleteTarget.value = row;
};

const closeDeleteModal = () => {
  if (deleteBusy.value) return;
  deleteTarget.value = null;
};

const confirmDelete = async () => {
  const t = deleteTarget.value;
  if (!t) return;
  deleteBusy.value = true;
  deleteError.value = "";
  try {
    await api.delete(`/webmaster/tasks/${t.id}`);
    deleteTarget.value = null;
    toast.success("Task deleted.");
    await fetchTasks();
  } catch (e) {
    deleteError.value = errorMessage(e, "Could not delete task.");
    toast.errorFrom(e, "Could not delete task.");
  } finally {
    deleteBusy.value = false;
  }
};

function onPerPageChange(e) {
  query.per_page = Number(e.target.value);
  query.page = 1;
  selectedIds.value = [];
  fetchTasks();
}

function goPage(p) {
  if (p < 1 || p > pagination.value.last_page) return;
  query.page = p;
  selectedIds.value = [];
  fetchTasks();
}

function goFirstPage() {
  goPage(1);
}

function goLastPage() {
  goPage(pagination.value.last_page);
}

onMounted(async () => {
  document.addEventListener("click", onDocClick);
  window.addEventListener("scroll", onWindowScrollOrResize, true);
  window.addEventListener("resize", onWindowScrollOrResize);
  await fetchMeta();
  await fetchUsers();
  await fetchTasks();
});

onUnmounted(() => {
  document.removeEventListener("click", onDocClick);
  window.removeEventListener("scroll", onWindowScrollOrResize, true);
  window.removeEventListener("resize", onWindowScrollOrResize);
  clearTimeout(searchDebounce);
});
</script>

<template>
  <div class="staff-page staff-page--wide">
    <WebmasterTaskDrawer
      v-model:open="drawerOpen"
      :users="users"
      :statuses="meta.statuses"
      :priorities="meta.priorities"
      @saved="fetchTasks"
    />
    <WebmasterTaskModal
      v-model:open="taskEditModalOpen"
      :task="editingTask"
      :users="users"
      :statuses="meta.statuses"
      :priorities="meta.priorities"
      @saved="fetchTasks"
    />
    <WebmasterTasksBulkEditModal
      v-model:open="bulkEditOpen"
      :statuses="meta.statuses"
      :priorities="meta.priorities"
      :users="users"
      :selected-count="selectedIds.length"
      :busy="bulkEditBusy"
      @apply="onBulkApply"
    />

    <div v-if="deleteError" class="alert alert-danger mb-3 mb-md-4" role="alert">
      {{ deleteError }}
    </div>

    <div
      class="d-flex flex-column flex-md-row align-items-center justify-content-between gap-3 mb-4"
    >
      <div class="min-w-0 flex-grow-1 text-center text-md-start w-100">
        <h1 class="h4 mb-1 fw-semibold text-body staff-page__heading">Webmaster</h1>
        <p class="staff-page__intro mb-0">Site development tasks</p>
      </div>
      <div
        class="d-flex flex-wrap align-items-center justify-content-center justify-content-md-end gap-2 flex-shrink-0"
      >
        <button
          v-if="canCreateTasks"
          type="button"
          class="btn btn-primary staff-page-primary d-inline-flex align-items-center gap-2"
          @click="openAdd"
        >
          <svg width="18" height="18" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z" />
          </svg>
          Add Task
        </button>
        <button
          type="button"
          class="btn btn-outline-secondary btn-sm d-inline-flex align-items-center gap-2"
          :disabled="loading"
          title="Refresh"
          aria-label="Refresh list"
          @click="fetchTasks"
        >
          <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              stroke-width="2"
              d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"
            />
          </svg>
          Refresh
        </button>
      </div>
    </div>

    <div class="staff-table-card staff-datatable-card staff-datatable-card--white">
      <div class="staff-table-toolbar">
        <div class="staff-table-toolbar--row">
          <input
            id="wm-search"
            v-model="query.search"
            type="search"
            class="form-control staff-toolbar-search staff-toolbar-search--inline"
            placeholder="Search tasks"
            autocomplete="off"
            @keydown.enter.prevent="applySearch"
          />
          <div class="position-relative flex-shrink-0" data-toolbar-filter>
            <button
              type="button"
              class="btn btn-outline-secondary staff-toolbar-btn d-inline-flex align-items-center gap-2"
              :aria-expanded="filterMenuOpen"
              aria-haspopup="true"
              aria-controls="wm-filter-panel"
              :disabled="loading"
              @click.stop="
                bulkMenuOpen = false;
                filterMenuOpen = !filterMenuOpen;
              "
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
              <span class="staff-toolbar-filter-text">Filters</span>
            </button>
            <div
              v-if="filterMenuOpen"
              id="wm-filter-panel"
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
                <label class="form-label" for="wm-filter-status">Status</label>
                <select
                  id="wm-filter-status"
                  v-model="query.status"
                  class="form-select staff-datatable-filters__select mb-3"
                  :disabled="loading"
                  @change="applySearch"
                >
                  <option value="">All statuses</option>
                  <option v-for="s in meta.statuses" :key="s.value" :value="s.value">
                    {{ s.label }}
                  </option>
                </select>
                <label class="form-label" for="wm-filter-priority">Priority</label>
                <select
                  id="wm-filter-priority"
                  v-model="query.priority"
                  class="form-select staff-datatable-filters__select mb-3"
                  :disabled="loading"
                  @change="applySearch"
                >
                  <option value="">All priorities</option>
                  <option v-for="p in meta.priorities" :key="p.value" :value="p.value">
                    {{ p.label }}
                  </option>
                </select>
                <label class="form-label" for="wm-filter-assignee">Assignee</label>
                <select
                  id="wm-filter-assignee"
                  v-model="query.assigned_to"
                  class="form-select staff-datatable-filters__select mb-3"
                  :disabled="loading"
                  @change="applySearch"
                >
                  <option value="">Anyone</option>
                  <option v-for="u in users" :key="u.id" :value="String(u.id)">{{ u.name }}</option>
                </select>
                <div class="row g-2">
                  <div class="col-12 col-sm-6">
                    <label class="form-label" for="wm-min-price">Min price</label>
                    <input
                      id="wm-min-price"
                      v-model="query.min_price"
                      type="number"
                      min="0"
                      step="0.01"
                      class="form-control"
                      placeholder="Min"
                      :disabled="loading"
                      @change="applySearch"
                      @keydown.enter.prevent="applySearch"
                    />
                  </div>
                  <div class="col-12 col-sm-6">
                    <label class="form-label" for="wm-max-price">Max price</label>
                    <input
                      id="wm-max-price"
                      v-model="query.max_price"
                      type="number"
                      min="0"
                      step="0.01"
                      class="form-control"
                      placeholder="Max"
                      :disabled="loading"
                      @change="applySearch"
                      @keydown.enter.prevent="applySearch"
                    />
                  </div>
                </div>
              </div>
            </div>
          </div>
          <div
            v-if="canUpdateTasks || canDeleteTasks"
            class="staff-toolbar-row-actions d-flex flex-wrap align-items-center gap-2 gap-md-3 ms-md-auto flex-shrink-0"
          >
            <div class="d-none d-md-flex align-items-center gap-2 flex-shrink-0">
              <button
                v-if="canUpdateTasks"
                type="button"
                class="btn btn-outline-secondary staff-toolbar-btn"
                :disabled="!selectedIds.length || loading"
                @click="openBulkEdit"
              >
                Bulk Edit
              </button>
              <button
                v-if="canDeleteTasks"
                type="button"
                class="btn btn-outline-danger staff-toolbar-btn"
                :disabled="!selectedIds.length || loading"
                @click="openBulkDelete"
              >
                Bulk Delete
              </button>
            </div>
            <div
              v-if="canUpdateTasks && canDeleteTasks"
              class="d-md-none position-relative flex-shrink-0"
              data-toolbar-bulk
            >
              <button
                type="button"
                class="btn btn-outline-secondary staff-toolbar-btn d-inline-flex align-items-center gap-1"
                :aria-expanded="bulkMenuOpen"
                aria-haspopup="true"
                :disabled="loading"
                @click.stop="
                  filterMenuOpen = false;
                  bulkMenuOpen = !bulkMenuOpen;
                "
              >
                Bulk Actions
                <svg width="14" height="14" fill="currentColor" viewBox="0 0 24 24" class="text-secondary" aria-hidden="true">
                  <path d="M7 10l5 5 5-5H7z" />
                </svg>
              </button>
              <div
                v-if="bulkMenuOpen"
                class="dropdown-menu show shadow border px-0 py-1 mt-1 staff-toolbar-bulk-dropdown"
                style="right: 0; left: auto"
                role="menu"
                aria-label="Bulk actions"
                @click.stop
              >
                <button
                  type="button"
                  class="dropdown-item small"
                  role="menuitem"
                  :disabled="!selectedIds.length || loading"
                  @click="
                    bulkMenuOpen = false;
                    openBulkEdit();
                  "
                >
                  Bulk Edit
                </button>
                <button
                  type="button"
                  class="dropdown-item small text-danger"
                  role="menuitem"
                  :disabled="!selectedIds.length || loading"
                  @click="
                    bulkMenuOpen = false;
                    openBulkDelete();
                  "
                >
                  Bulk Delete
                </button>
              </div>
            </div>
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
                :aria-sort="thAriaSort('title')"
              >
                <button type="button" class="staff-sort-btn" :disabled="loading" @click="toggleSort('title')">
                  Task
                  <span v-if="sortIndicator('title')" class="staff-sort-ind">{{ sortIndicator("title") }}</span>
                </button>
              </th>
              <th
                class="staff-table-head__th staff-table-head__th--sort"
                scope="col"
                :aria-sort="thAriaSort('status')"
              >
                <button type="button" class="staff-sort-btn" :disabled="loading" @click="toggleSort('status')">
                  Status
                  <span v-if="sortIndicator('status')" class="staff-sort-ind">{{ sortIndicator("status") }}</span>
                </button>
              </th>
              <th
                class="staff-table-head__th staff-table-head__th--sort"
                scope="col"
                :aria-sort="thAriaSort('priority')"
              >
                <button type="button" class="staff-sort-btn" :disabled="loading" @click="toggleSort('priority')">
                  Priority
                  <span v-if="sortIndicator('priority')" class="staff-sort-ind">{{ sortIndicator("priority") }}</span>
                </button>
              </th>
              <th class="staff-table-head__th" scope="col">Assignee</th>
              <th
                class="staff-table-head__th staff-table-head__th--sort"
                scope="col"
                :aria-sort="thAriaSort('due_date')"
              >
                <button type="button" class="staff-sort-btn" :disabled="loading" @click="toggleSort('due_date')">
                  Due Date
                  <span v-if="sortIndicator('due_date')" class="staff-sort-ind">{{ sortIndicator("due_date") }}</span>
                </button>
              </th>
              <th class="staff-table-head__th" scope="col">Price</th>
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
                  <CrmLoadingSpinner message="Loading tasks…" />
                </div>
              </td>
            </tr>
            <tr v-for="task in rows" v-else :key="task.id" class="align-middle">
              <td v-if="showCheckboxColumn" class="staff-table-cell--tight-check">
                <input
                  type="checkbox"
                  class="form-check-input staff-table-head__check mt-0"
                  :checked="selectedIds.includes(task.id)"
                  :aria-label="`Select ${task.title}`"
                  @change="toggleRowSelect(task.id)"
                />
              </td>
              <td>
                <a
                  :href="taskDetailHref(task)"
                  target="_blank"
                  rel="noopener noreferrer"
                  class="fw-semibold text-body text-decoration-none d-block text-truncate"
                  style="max-width: 16rem"
                  :title="task.title"
                >
                  {{ task.title }}
                </a>
              </td>
              <td>
                <span
                  class="badge rounded-pill text-capitalize fw-medium"
                  :class="statusBadgeClass(task.status)"
                >
                  {{ statusLabel(task.status) }}
                </span>
              </td>
              <td>
                <span
                  class="badge rounded-pill text-capitalize fw-medium"
                  :class="priorityBadgeClass(task.priority)"
                >
                  {{ priorityLabel(task.priority) }}
                </span>
              </td>
              <td class="text-body staff-table-cell__meta text-truncate" style="max-width: 10rem">
                {{ task.assignee?.name || "—" }}
              </td>
              <td class="text-body staff-table-cell__meta text-nowrap">
                {{ task.due_date ? formatDateUs(task.due_date) : "—" }}
              </td>
              <td class="text-body staff-table-cell__meta text-nowrap">
                {{ formatUsdPrice(task.price) || "—" }}
              </td>
              <td v-if="showRowActions" class="staff-actions-cell text-center">
                <div
                  data-row-actions
                  class="staff-actions-inner staff-actions-inner--single justify-content-center"
                >
                  <button
                    type="button"
                    class="staff-action-btn staff-action-btn--more"
                    :class="{ 'is-open': manageOpenId === task.id }"
                    :aria-expanded="manageOpenId === task.id"
                    aria-haspopup="true"
                    aria-label="Row actions"
                    @click="toggleManageMenu(task.id, $event)"
                  >
                    <CrmIconRowActions variant="horizontal" />
                  </button>
                </div>
              </td>
            </tr>
            <tr v-if="!loading && rows.length === 0">
              <td :colspan="tableColspan" class="px-4 py-5 text-center text-secondary">
                No tasks found.
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
          <p class="small text-secondary mb-0 text-center text-sm-start">
            Showing
            <span class="fw-semibold text-body">{{ showingFrom }}</span>
            to
            <span class="fw-semibold text-body">{{ showingTo }}</span>
            of
            <span class="fw-semibold text-body">{{ pagination.total }}</span>
            entries
          </p>
          <div class="d-flex align-items-center gap-2 justify-content-center justify-content-sm-start">
            <label class="small text-secondary text-nowrap mb-0" for="wm-per-page-footer">Rows per page</label>
            <select
              id="wm-per-page-footer"
              class="form-select form-select-sm staff-table-footer-per-page"
              :value="query.per_page"
              :disabled="loading"
              @change="onPerPageChange"
            >
              <option v-for="n in PER_PAGE_OPTIONS" :key="n" :value="n">{{ n }}</option>
            </select>
          </div>
        </div>
        <nav
          v-if="pagination.last_page > 1"
          class="order-1 order-lg-2 d-flex justify-content-center justify-content-lg-end ms-lg-auto flex-shrink-0"
          aria-label="Webmaster task pages"
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
                <svg width="18" height="18" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                  <path d="M5.59 18L7 16.59 2.41 12 7 7.41 5.59 6l-6 6 6 6zm8 0L15 16.59 10.41 12 15 7.41 13.59 6l-6 6 6 6z" />
                </svg>
              </button>
              <button
                type="button"
                class="staff-page-pager-tile staff-page-pager-tile--nav"
                :disabled="loading || pagination.current_page <= 1"
                aria-label="Previous page"
                @click="goPage(pagination.current_page - 1)"
              >
                <svg width="18" height="18" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                  <path d="M15.41 7.41L14 6l-6 6 6 6 1.41-1.41L10.83 12z" />
                </svg>
              </button>
            </div>
            <div class="staff-page-pager__pages">
              <div class="staff-page-pager-inner d-flex align-items-center">
                <template v-for="(item, idx) in pageItems" :key="'wm-pi-' + idx">
                  <span v-if="item.type === 'gap'" class="px-1 small text-secondary user-select-none">…</span>
                  <button
                    v-else
                    type="button"
                    class="staff-page-pager-tile"
                    :class="{ 'staff-page-pager-tile--active': item.value === pagination.current_page }"
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
                :disabled="loading || pagination.current_page >= pagination.last_page"
                aria-label="Next page"
                @click="goPage(pagination.current_page + 1)"
              >
                <svg width="18" height="18" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                  <path d="M8.59 16.59L10 18l6-6-6-6-1.41 1.41L13.17 12z" />
                </svg>
              </button>
              <button
                type="button"
                class="staff-page-pager-tile staff-page-pager-tile--nav"
                :disabled="loading || pagination.current_page >= pagination.last_page"
                aria-label="Last page"
                @click="goLastPage"
              >
                <svg width="18" height="18" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                  <path d="M6.41 6L5 7.41 9.58 12 5 16.59 6.41 18l6-6-6-6zm8 0L13 7.41 17.58 12 13 16.59 14.41 18l6-6-6-6z" />
                </svg>
              </button>
            </div>
          </div>
        </nav>
      </div>
    </div>

    <ConfirmModal
      :open="deleteModalOpen"
      title="Delete Task"
      :message="deleteMessage"
      confirm-label="Delete"
      cancel-label="Cancel"
      :busy="deleteBusy"
      @close="closeDeleteModal"
      @confirm="confirmDelete"
    />

    <ConfirmModal
      :open="bulkDeleteOpen"
      title="Delete Tasks?"
      :message="bulkDeleteMessage"
      confirm-label="Delete"
      cancel-label="Cancel"
      :busy="bulkDeleteBusy"
      danger
      @close="closeBulkDelete"
      @confirm="confirmBulkDelete"
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
          v-if="manageMenuTask"
          data-row-actions
          class="staff-row-menu fixed z-[300] overflow-hidden"
          role="menu"
          :style="{ top: `${manageMenuRect.top}px`, left: `${manageMenuRect.left}px` }"
          @click.stop
        >
          <button type="button" class="staff-row-menu__item" role="menuitem" @click="openTaskDetailNewTab(manageMenuTask)">
            View
          </button>
          <hr v-if="canUpdateTasks || canDeleteTasks" class="staff-row-menu__divider" />
          <button
            v-if="canUpdateTasks"
            type="button"
            class="staff-row-menu__item"
            role="menuitem"
            @click="openEdit(manageMenuTask)"
          >
            Edit
          </button>
          <hr v-if="canUpdateTasks && canDeleteTasks" class="staff-row-menu__divider" />
          <button
            v-if="canDeleteTasks"
            type="button"
            class="staff-row-menu__item staff-row-menu__item--danger"
            role="menuitem"
            @click="openDeleteModal(manageMenuTask)"
          >
            Delete
          </button>
        </div>
      </Transition>
    </Teleport>
  </div>
</template>
