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
import draggable from "vuedraggable";
import api from "../../services/api";
import ConfirmModal from "../../components/common/ConfirmModal.vue";
import WebmasterTaskDrawer from "../../components/webmaster/WebmasterTaskDrawer.vue";
import WebmasterTaskModal from "../../components/webmaster/WebmasterTaskModal.vue";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import { useToast } from "../../composables/useToast";
import { errorMessage } from "../../utils/apiError";
import { useRoute, useRouter } from "vue-router";
import { crmIsAdmin } from "../../utils/crmUser";
import { DEFAULT_PER_PAGE, PER_PAGE_OPTIONS } from "../../constants/pagination";
import { formatUsdPrice } from "../../utils/formatPrice";
import { formatDateUs } from "../../utils/formatUserDates";
import CrmIconRowActions from "../../components/common/CrmIconRowActions.vue";

const crmUser = inject("crmUser", ref(null));
const toast = useToast();
const route = useRoute();
const router = useRouter();

const loading = ref(true);
const rows = ref([]);
const pagination = ref({ current_page: 1, last_page: 1, total: 0 });
const users = ref([]);
const meta = ref({ statuses: [], priorities: [] });
const boardColumns = ref([]);
const manageOpenId = ref(null);
const drawerOpen = ref(false);
const taskEditModalOpen = ref(false);
const editingTask = ref(null);
const deleteTarget = ref(null);
const deleteBusy = ref(false);
const deleteError = ref("");
const statusUpdateError = ref("");

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

const canMutateWebmasterTasks = computed(() => {
  const u = crmUser?.value;
  if (!u) return false;
  return !!u.is_crm_owner || crmIsAdmin(u);
});

let searchDebounce = null;
let searchWatchLock = false;

const deleteModalOpen = computed(() => deleteTarget.value !== null);
const deleteMessage = computed(() => {
  const t = deleteTarget.value;
  return t ? `Delete Task “${t.title}”? This Cannot Be Undone.` : "";
});

const hasMorePages = computed(
  () => pagination.value.last_page > 1,
);

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

watch(
  () => query.search,
  () => {
    if (searchWatchLock) return;
    clearTimeout(searchDebounce);
    searchDebounce = setTimeout(() => {
      query.page = 1;
      fetchTasks();
    }, 300);
  },
);

watch(
  () => route.query.edit,
  (v) => {
    if (v) {
      openTaskEditFromQuery();
    }
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

const priorityClass = (p) => {
  const x = String(p || "").toLowerCase();
  const map = {
    low: "bg-slate-100 text-slate-800 dark:bg-slate-800 dark:text-slate-200",
    medium:
      "bg-blue-50 text-blue-800 dark:bg-blue-500/10 dark:text-blue-200",
    high: "bg-amber-50 text-amber-900 dark:bg-amber-500/10 dark:text-amber-200",
    urgent: "bg-red-50 text-red-800 dark:bg-red-500/10 dark:text-red-200",
  };
  return (
    map[x] ||
    "bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300"
  );
};

const avatarPalettes = [
  "bg-sky-100 text-sky-800 dark:bg-sky-500/20 dark:text-sky-200",
  "bg-violet-100 text-violet-800 dark:bg-violet-500/20 dark:text-violet-200",
  "bg-amber-100 text-amber-900 dark:bg-amber-500/20 dark:text-amber-200",
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
  if (query.min_price !== "" && query.min_price != null) {
    p.min_price = query.min_price;
  }
  if (query.max_price !== "" && query.max_price != null) {
    p.max_price = query.max_price;
  }
  return p;
};

function syncBoardFromRows() {
  const statuses = meta.value.statuses;
  if (!statuses.length) {
    boardColumns.value = [];
    return;
  }
  boardColumns.value = statuses.map((s) => ({
    value: s.value,
    label: s.label,
    tasks: rows.value.filter((t) => t.status === s.value),
  }));
}

const fetchTasks = async () => {
  loading.value = true;
  deleteError.value = "";
  statusUpdateError.value = "";
  manageOpenId.value = null;
  try {
    const { data } = await api.get("/webmaster/tasks", { params: buildParams() });
    rows.value = data.data;
    pagination.value = {
      current_page: data.current_page,
      last_page: data.last_page,
      total: data.total,
    };
    await nextTick();
    syncBoardFromRows();
  } finally {
    loading.value = false;
  }
};

const applySearch = () => {
  clearTimeout(searchDebounce);
  query.page = 1;
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
  fetchTasks().finally(() => {
    searchWatchLock = false;
  });
};

function openAdd() {
  drawerOpen.value = true;
}

function openEdit(row) {
  editingTask.value = { ...row };
  manageOpenId.value = null;
  taskEditModalOpen.value = true;
}

function goTaskDetail(task) {
  manageOpenId.value = null;
  router.push(`/webmaster/tasks/${task.id}`);
}

function onKanbanCardClick(task, e) {
  if (e.target instanceof Element && e.target.closest("[data-kanban-card-actions]")) {
    return;
  }
  goTaskDetail(task);
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
  if (!canMutateWebmasterTasks.value) {
    router.replace({ path: "/webmaster", query: {} });
    return;
  }
  try {
    const { data } = await api.get(`/webmaster/tasks/${id}`);
    openEdit(data);
  } catch {
    /* drawer stays closed */
  } finally {
    router.replace({ path: "/webmaster", query: {} });
  }
}

const toggleManageMenu = (id, e) => {
  e.stopPropagation();
  manageOpenId.value = manageOpenId.value === id ? null : id;
};

function onDocClick(e) {
  if (!e.target.closest("[data-kanban-card-actions]")) {
    manageOpenId.value = null;
  }
}

const openDeleteModal = (row) => {
  manageOpenId.value = null;
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
    toast.success("Task Deleted.");
    await fetchTasks();
  } catch (e) {
    deleteError.value = errorMessage(e, "Could Not Delete Task.");
    toast.errorFrom(e, "Could Not Delete Task.");
  } finally {
    deleteBusy.value = false;
  }
};

function descSnippet(text) {
  if (!text) return "";
  const s = String(text).trim();
  return s.length > 72 ? `${s.slice(0, 72)}…` : s;
}

function onPerPageChange(e) {
  query.per_page = Number(e.target.value);
  query.page = 1;
  fetchTasks();
}

function goPage(p) {
  if (p < 1 || p > pagination.value.last_page) return;
  query.page = p;
  fetchTasks();
}

function goFirstPage() {
  goPage(1);
}

function goLastPage() {
  goPage(pagination.value.last_page);
}

function dueBadgeLabel(dueDate) {
  if (!dueDate) return null;
  const d = new Date(dueDate + "T12:00:00");
  if (Number.isNaN(d.getTime())) return dueDate;
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  const day = new Date(d);
  day.setHours(0, 0, 0, 0);
  const diff = Math.round((day - today) / 86400000);
  if (diff === 0) return "Today";
  if (diff === 1) return "Tomorrow";
  if (diff === -1) return "Yesterday";
  return formatDateUs(dueDate);
}

async function onColumnChange(columnStatus, evt) {
  if (!evt.added) return;
  const task = evt.added.element;
  if (!task || task.status === columnStatus) return;
  const prev = task.status;
  task.status = columnStatus;
  statusUpdateError.value = "";
  try {
    await api.put(`/webmaster/tasks/${task.id}`, { status: columnStatus });
  } catch (e) {
    task.status = prev;
    statusUpdateError.value = errorMessage(
      e,
      "Could not update task status.",
    );
    toast.errorFrom(e, "Could not update task status.");
    await fetchTasks();
  }
}

onMounted(async () => {
  document.addEventListener("click", onDocClick);
  await fetchMeta();
  await fetchUsers();
  await fetchTasks();
});

onUnmounted(() => {
  document.removeEventListener("click", onDocClick);
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

    <div
      v-if="deleteError"
      class="alert alert-danger mb-3 mb-md-4"
      role="alert"
    >
      {{ deleteError }}
    </div>
    <div
      v-if="statusUpdateError"
      class="alert alert-danger mb-3 mb-md-4"
      role="alert"
    >
      {{ statusUpdateError }}
    </div>

    <div
      class="d-flex flex-column flex-md-row align-items-start align-items-md-center gap-2 gap-md-3 mb-4"
    >
      <div class="min-w-0">
        <h1 class="h4 mb-1 fw-semibold text-body">Webmaster</h1>
        <p class="text-secondary small mb-0">
          Site development tasks — drag cards between columns to change status
        </p>
      </div>
      <button
        type="button"
        class="btn btn-outline-secondary btn-sm ms-md-auto d-inline-flex align-items-center gap-2"
        :disabled="loading"
        title="Refresh"
        aria-label="Refresh list"
        @click="fetchTasks"
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
            d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"
          />
        </svg>
        Refresh
      </button>
    </div>

    <div class="staff-table-card staff-datatable-card">
      <div class="staff-datatable-filters">
        <div
          class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3"
        >
          <h2 class="staff-datatable-filters__title mb-0">Filters</h2>
          <button
            type="button"
            class="btn btn-link btn-sm text-secondary text-decoration-none px-0"
            :disabled="loading"
            @click="clearFilters"
          >
            Reset
          </button>
        </div>
        <div class="row g-3 g-md-4">
          <div class="col-12 col-sm-6 col-lg-3">
            <label class="visually-hidden" for="wm-filter-status">Status</label>
            <select
              id="wm-filter-status"
              v-model="query.status"
              class="form-select staff-datatable-filters__select"
              :disabled="loading"
              @change="applySearch"
            >
              <option value="">All columns</option>
              <option
                v-for="s in meta.statuses"
                :key="s.value"
                :value="s.value"
              >
                {{ s.label }}
              </option>
            </select>
          </div>
          <div class="col-12 col-sm-6 col-lg-3">
            <label class="visually-hidden" for="wm-filter-priority"
              >Priority</label
            >
            <select
              id="wm-filter-priority"
              v-model="query.priority"
              class="form-select staff-datatable-filters__select"
              :disabled="loading"
              @change="applySearch"
            >
              <option value="">All priorities</option>
              <option
                v-for="p in meta.priorities"
                :key="p.value"
                :value="p.value"
              >
                {{ p.label }}
              </option>
            </select>
          </div>
          <div class="col-12 col-sm-6 col-lg-3">
            <label class="visually-hidden" for="wm-filter-assignee"
              >Assignee</label
            >
            <select
              id="wm-filter-assignee"
              v-model="query.assigned_to"
              class="form-select staff-datatable-filters__select"
              :disabled="loading"
              @change="applySearch"
            >
              <option value="">Anyone</option>
              <option
                v-for="u in users"
                :key="u.id"
                :value="String(u.id)"
              >
                {{ u.name }}
              </option>
            </select>
          </div>
          <div class="col-12 col-sm-6 col-lg-2">
            <label class="visually-hidden" for="wm-min-price">Min price</label>
            <input
              id="wm-min-price"
              v-model="query.min_price"
              type="number"
              min="0"
              step="0.01"
              class="form-control staff-datatable-filters__select"
              placeholder="Min price"
              :disabled="loading"
              @change="applySearch"
              @keydown.enter.prevent="applySearch"
            />
          </div>
          <div class="col-12 col-sm-6 col-lg-2">
            <label class="visually-hidden" for="wm-max-price">Max price</label>
            <input
              id="wm-max-price"
              v-model="query.max_price"
              type="number"
              min="0"
              step="0.01"
              class="form-control staff-datatable-filters__select"
              placeholder="Max price"
              :disabled="loading"
              @change="applySearch"
              @keydown.enter.prevent="applySearch"
            />
          </div>
        </div>
      </div>

      <div class="staff-table-toolbar staff-table-toolbar--split">
        <div
          class="staff-toolbar-split d-flex flex-column flex-lg-row align-items-stretch align-items-lg-center justify-content-lg-between gap-3 gap-lg-4"
        >
          <div class="flex-shrink-0 staff-toolbar-per-page">
            <label class="visually-hidden" for="wm-per-page-toolbar"
              >Rows per page</label
            >
            <select
              id="wm-per-page-toolbar"
              class="form-select staff-toolbar-select staff-toolbar-per-page-select"
              :value="query.per_page"
              :disabled="loading"
              @change="onPerPageChange"
            >
              <option v-for="n in PER_PAGE_OPTIONS" :key="n" :value="n">
                {{ n }}
              </option>
            </select>
          </div>
          <div
            class="staff-toolbar-actions d-flex flex-column flex-sm-row flex-wrap align-items-stretch align-items-sm-center"
          >
            <input
              id="wm-search"
              v-model="query.search"
              type="search"
              class="form-control staff-toolbar-search"
              placeholder="Search tasks"
              autocomplete="off"
              @keydown.enter.prevent="applySearch"
            />
            <button
              v-if="canMutateWebmasterTasks"
              type="button"
              class="btn btn-primary staff-page-primary staff-toolbar-btn-add d-inline-flex align-items-center gap-2"
              @click="openAdd"
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
              Add task
            </button>
          </div>
        </div>
      </div>

      <div
        v-if="hasMorePages"
        class="alert alert-warning border-start-0 border-end-0 rounded-0 border-top-0 border-bottom mb-0 py-2 px-3 small"
        role="status"
      >
        Page {{ pagination.current_page }} of {{ pagination.last_page }} —
        {{ rows.length }} tasks loaded on this page. Increase rows per page or
        use pagination below to see more.
      </div>

      <div
        class="px-3 px-md-4 py-4 wm-kanban-board-host"
        :class="{ 'wm-kanban-board-host--loading': loading }"
      >
        <div v-if="loading" class="d-flex justify-content-center py-5">
          <CrmLoadingSpinner message="Loading tasks…" />
        </div>
        <p
          v-else-if="pagination.total === 0"
          class="py-5 text-center text-secondary small mb-0"
        >
          No tasks yet. Add one to get started.
        </p>
        <div
          v-else
          class="row g-3 g-md-4 wm-kanban-grid"
        >
          <div
            v-for="col in boardColumns"
            :key="col.value"
            class="col-12 col-sm-6 col-xl-3"
          >
            <section
              class="wm-kanban-column d-flex flex-column rounded border bg-body-secondary h-100"
              style="min-height: 12rem"
            >
            <header
              class="d-flex flex-shrink-0 align-items-center justify-content-between gap-2 border-bottom px-3 py-3"
            >
              <h2 class="small fw-semibold text-body mb-0">
                {{ col.label }}
              </h2>
              <span
                class="badge rounded-pill text-bg-light border"
              >
                {{ col.tasks.length }}
              </span>
            </header>
            <draggable
              v-model="col.tasks"
              :disabled="!canMutateWebmasterTasks"
              :group="{ name: 'webmaster-tasks', pull: true, put: true }"
              :animation="200"
              :delay="175"
              item-key="id"
              tag="div"
              class="d-flex flex-column flex-grow-1 gap-3 p-3 wm-kanban-dropzone"
              ghost-class="kanban-ghost"
              drag-class="kanban-drag"
              @change="onColumnChange(col.value, $event)"
            >
              <template #item="{ element: task }">
                <article
                  class="wm-kanban-card position-relative rounded border bg-body p-3 shadow-sm"
                  role="button"
                  tabindex="0"
                  @click="onKanbanCardClick(task, $event)"
                  @keydown.enter.prevent="goTaskDetail(task)"
                >
                  <div class="d-flex align-items-start justify-content-between gap-2 pe-1">
                    <h3 class="min-w-0 flex-grow-1 small fw-semibold lh-sm text-body mb-0">
                      {{ task.title }}
                    </h3>
                    <div
                      data-kanban-card-actions
                      class="position-relative flex-shrink-0"
                      @click.stop
                    >
                      <button
                        type="button"
                        class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center justify-content-center p-2"
                        style="width: 2.5rem; height: 2.5rem"
                        :aria-expanded="manageOpenId === task.id"
                        aria-label="Task Actions"
                        @click="toggleManageMenu(task.id, $event)"
                      >
                        <CrmIconRowActions />
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
                          v-if="manageOpenId === task.id"
                          class="dropdown-menu show position-absolute end-0 mt-1 p-0 shadow"
                          style="top: 100%"
                          data-kanban-card-actions
                          role="menu"
                          @click.stop
                        >
                          <div class="d-flex flex-column">
                            <button
                              type="button"
                              class="dropdown-item small"
                              role="menuitem"
                              @click="goTaskDetail(task)"
                            >
                              View details
                            </button>
                            <button
                              v-if="canMutateWebmasterTasks"
                              type="button"
                              class="dropdown-item small"
                              role="menuitem"
                              @click="openEdit(task)"
                            >
                              Edit
                            </button>
                            <button
                              v-if="canMutateWebmasterTasks"
                              type="button"
                              class="dropdown-item small text-danger"
                              role="menuitem"
                              @click="openDeleteModal(task)"
                            >
                              Delete
                            </button>
                          </div>
                        </div>
                      </Transition>
                    </div>
                  </div>
                  <p
                    v-if="descSnippet(task.description)"
                    class="mt-1.5 text-xs leading-relaxed text-gray-500 dark:text-gray-400"
                  >
                    {{ descSnippet(task.description) }}
                  </p>
                  <div class="mt-3 d-flex flex-wrap align-items-center gap-2">
                    <span
                      v-if="formatUsdPrice(task.price)"
                      class="inline-flex max-w-full truncate rounded-md bg-emerald-50 px-2 py-0.5 text-[11px] font-medium text-emerald-900 dark:bg-emerald-500/15 dark:text-emerald-200"
                    >
                      {{ formatUsdPrice(task.price) }}
                    </span>
                    <span
                      class="inline-flex rounded-full px-2 py-0.5 text-[11px] font-medium capitalize ring-1 ring-inset ring-gray-200 dark:ring-gray-600"
                      :class="priorityClass(task.priority)"
                    >
                      {{ priorityLabel(task.priority) }}
                    </span>
                    <span
                      v-if="dueBadgeLabel(task.due_date)"
                      class="inline-flex items-center rounded-md bg-blue-50 px-2 py-0.5 text-[11px] font-medium text-blue-800 dark:bg-blue-500/15 dark:text-blue-200"
                    >
                      {{ dueBadgeLabel(task.due_date) }}
                    </span>
                  </div>
                  <div v-if="task.assignee" class="mt-3 d-flex align-items-center gap-2 border-top pt-3">
                    <span
                      class="d-flex align-items-center justify-content-center rounded-circle flex-shrink-0 small fw-semibold wm-kanban-avatar"
                      :class="avatarClassForUser(task.assignee.email)"
                    >
                      {{ initials(task.assignee.name) }}
                    </span>
                    <span class="min-w-0 text-truncate small text-body-secondary">{{
                      task.assignee.name
                    }}</span>
                  </div>
                  <div v-else class="mt-3 border-top pt-3 small text-body-secondary">
                    Unassigned
                  </div>
                </article>
              </template>
            </draggable>
            </section>
          </div>
          <p
            v-if="!boardColumns.length"
            class="col-12 py-5 text-center text-secondary small mb-0"
          >
            No status columns. Reload the page.
          </p>
        </div>
      </div>

      <div
        class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-lg-between gap-3 border-top staff-table-footer"
      >
        <p
          class="small text-secondary mb-0 order-2 order-lg-1 text-center text-lg-start"
        >
          Showing
          <span class="fw-semibold text-body">{{ showingFrom }}</span>
          to
          <span class="fw-semibold text-body">{{ showingTo }}</span>
          of
          <span class="fw-semibold text-body">{{ pagination.total }}</span>
          tasks
          <span v-if="query.status" class="text-secondary">
            · {{ statusLabel(query.status) }}
          </span>
        </p>
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
                <template v-for="(item, idx) in pageItems" :key="'wm-pi-' + idx">
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
      title="Delete Task"
      :message="deleteMessage"
      confirm-label="Delete"
      cancel-label="Cancel"
      :busy="deleteBusy"
      @close="closeDeleteModal"
      @confirm="confirmDelete"
    />
  </div>
</template>

<style scoped>
.kanban-ghost {
  opacity: 0.65;
}
.kanban-drag {
  opacity: 0.98;
}
.wm-kanban-board-host--loading {
  min-height: 280px;
}
.wm-kanban-grid {
  min-height: min(70vh, 640px);
}
.wm-kanban-dropzone {
  min-height: 200px;
}
.wm-kanban-card {
  cursor: pointer;
}
.wm-kanban-card:hover {
  box-shadow: 0 0.125rem 0.5rem rgba(47, 43, 61, 0.08);
}
.wm-kanban-avatar {
  width: 2rem;
  height: 2rem;
  font-size: 0.6875rem;
}
</style>
