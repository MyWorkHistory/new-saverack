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
const filterOpen = ref(false);
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

const applyFilterPanel = () => {
  filterOpen.value = false;
  applySearch();
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
  if (!e.target.closest("[data-filter-root]")) {
    filterOpen.value = false;
  }
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

function goTaskPage(p) {
  if (p < 1 || p > pagination.value.last_page) return;
  query.page = p;
  fetchTasks();
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
  <div class="space-y-4">
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

    <p v-if="deleteError" class="text-sm text-red-600 dark:text-red-400">
      {{ deleteError }}
    </p>
    <p
      v-if="statusUpdateError"
      class="text-sm text-red-600 dark:text-red-400"
    >
      {{ statusUpdateError }}
    </p>

    <!-- Same shell as Staff: rounded-2xl outer card, header strip, inner card + search toolbar, pagination below inner card -->
    <div
      class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900"
    >
      <div
        class="border-b border-gray-100 px-4 py-5 dark:border-gray-800 sm:px-6"
      >
        <div
          class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between"
        >
          <div class="flex items-center gap-3">
            <div>
              <h1 class="text-xl font-bold text-gray-900 dark:text-white">
                Webmaster
              </h1>
              <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">
                Site Development Tasks — Drag Cards Between Columns To Change
                Status
              </p>
            </div>
            <button
              type="button"
              class="rounded-lg p-2 text-gray-500 transition hover:bg-gray-100 dark:hover:bg-white/10 dark:hover:text-gray-300"
              :disabled="loading"
              title="Refresh"
              aria-label="Refresh List"
              @click="fetchTasks"
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
            <div
              class="relative flex shrink-0 items-center gap-2"
              data-filter-root
            >
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
                        >Status</label
                      >
                      <select
                        v-model="query.status"
                        class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                      >
                        <option value="">All Columns</option>
                        <option
                          v-for="s in meta.statuses"
                          :key="s.value"
                          :value="s.value"
                        >
                          {{ s.label }}
                        </option>
                      </select>
                    </div>
                    <div>
                      <label
                        class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400"
                        >Priority</label
                      >
                      <select
                        v-model="query.priority"
                        class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                      >
                        <option value="">All</option>
                        <option
                          v-for="p in meta.priorities"
                          :key="p.value"
                          :value="p.value"
                        >
                          {{ p.label }}
                        </option>
                      </select>
                    </div>
                    <div>
                      <label
                        class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400"
                        >Assignee</label
                      >
                      <select
                        v-model="query.assigned_to"
                        class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white"
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
                    <div class="grid grid-cols-2 gap-2">
                      <div>
                        <label
                          class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400"
                          >Min Price</label
                        >
                        <input
                          v-model="query.min_price"
                          type="number"
                          min="0"
                          step="0.01"
                          class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                          placeholder="0"
                        />
                      </div>
                      <div>
                        <label
                          class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400"
                          >Max Price</label
                        >
                        <input
                          v-model="query.max_price"
                          type="number"
                          min="0"
                          step="0.01"
                          class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                          placeholder="Any"
                        />
                      </div>
                    </div>
                    <div class="grid grid-cols-2 gap-2 pt-1">
                      <button
                        type="button"
                        class="inline-flex min-h-10 items-center justify-center rounded-lg bg-[#2563eb] px-3 text-xs font-semibold text-white transition hover:opacity-95 disabled:opacity-50"
                        :disabled="loading"
                        @click="applyFilterPanel"
                      >
                        Apply
                      </button>
                      <button
                        type="button"
                        class="inline-flex min-h-10 items-center justify-center rounded-lg border border-gray-200 bg-white px-3 text-xs font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700"
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
              v-if="canMutateWebmasterTasks"
              type="button"
              class="inline-flex h-11 shrink-0 items-center justify-center gap-2 rounded-lg bg-[#2563eb] px-4 text-sm font-semibold text-white shadow-sm transition hover:opacity-95 focus:outline-none focus:ring-2 focus:ring-[#2563eb]/40 focus:ring-offset-2 dark:focus:ring-offset-gray-900"
              @click="openAdd"
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
              Add Task
            </button>
          </div>
        </div>
      </div>

      <div class="px-4 py-4 sm:px-6 sm:pb-6">
        <div
          class="overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]"
        >
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
                  placeholder="Search Tasks…"
                  class="h-11 w-full rounded-lg border border-gray-200 bg-white py-2.5 pl-10 pr-3 text-sm text-gray-900 placeholder:text-gray-400 focus:border-[#2563eb] focus:outline-none focus:ring-2 focus:ring-[#2563eb]/20 dark:border-gray-600 dark:bg-gray-900 dark:text-white dark:placeholder:text-gray-500"
                  @keydown.enter.prevent="applySearch"
                />
              </div>
            </div>
          </div>

          <div
            v-if="hasMorePages"
            class="border-b border-amber-100 bg-amber-50/90 px-4 py-2.5 text-xs text-amber-900 dark:border-amber-900/40 dark:bg-amber-950/40 dark:text-amber-100 sm:px-6"
          >
            Page {{ pagination.current_page }} Of {{ pagination.last_page }} —
            Showing {{ rows.length }} Tasks Loaded. Increase “Rows Per Page” Or
            Use Pagination Below To See More.
          </div>

          <div
            class="space-y-4 p-4 sm:p-6"
            :class="loading ? 'min-h-[280px]' : ''"
          >
        <div v-if="loading" class="flex justify-center py-12">
          <CrmLoadingSpinner message="Loading Tasks…" />
        </div>
        <p
          v-else-if="pagination.total === 0"
          class="py-16 text-center text-sm text-gray-500 dark:text-gray-400"
        >
          No Tasks Yet. Add One To Get Started.
        </p>
        <div
          v-else
          class="grid min-h-[min(70vh,640px)] grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4"
        >
          <section
            v-for="col in boardColumns"
            :key="col.value"
            class="flex min-h-[12rem] min-w-0 flex-col rounded-xl border border-gray-200 bg-gray-50/90 dark:border-gray-700 dark:bg-gray-800/50"
          >
            <header
              class="flex shrink-0 items-center justify-between gap-2 border-b border-gray-200 px-4 py-3 dark:border-gray-700"
            >
              <h2 class="text-sm font-semibold text-gray-900 dark:text-white">
                {{ col.label }}
              </h2>
              <span
                class="inline-flex h-6 min-w-[1.5rem] items-center justify-center rounded-full bg-white px-2 text-xs font-medium text-gray-600 ring-1 ring-gray-200 dark:bg-gray-900 dark:text-gray-300 dark:ring-gray-600"
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
              class="flex min-h-[200px] flex-1 flex-col gap-3 p-3"
              ghost-class="kanban-ghost"
              drag-class="kanban-drag"
              @change="onColumnChange(col.value, $event)"
            >
              <template #item="{ element: task }">
                <article
                  class="group relative cursor-pointer rounded-xl border border-gray-200 bg-white p-3.5 shadow-sm transition hover:border-blue-300 hover:shadow-md active:cursor-grabbing dark:border-gray-600 dark:bg-gray-900"
                  role="button"
                  tabindex="0"
                  @click="onKanbanCardClick(task, $event)"
                  @keydown.enter.prevent="goTaskDetail(task)"
                >
                  <div class="flex items-start justify-between gap-2 pr-1">
                    <h3 class="min-w-0 flex-1 text-sm font-semibold leading-snug text-gray-900 dark:text-white">
                      {{ task.title }}
                    </h3>
                    <div
                      data-kanban-card-actions
                      class="relative shrink-0"
                      @click.stop
                    >
                      <button
                        type="button"
                        class="inline-flex h-10 w-10 items-center justify-center rounded-lg border border-gray-200 bg-white text-gray-600 shadow-sm transition hover:border-gray-300 hover:bg-gray-50 hover:text-gray-900 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-500 dark:hover:bg-white/10 dark:hover:text-white"
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
                          class="absolute right-0 top-full z-20 mt-1 w-44 overflow-hidden rounded-lg border border-gray-200 bg-white py-1 shadow-lg dark:border-gray-700 dark:bg-gray-900"
                          data-kanban-card-actions
                          role="menu"
                          @click.stop
                        >
                          <div class="flex flex-col">
                            <button
                              type="button"
                              class="w-full px-3 py-2 text-left text-sm font-medium text-gray-800 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-white/5"
                              role="menuitem"
                              @click="goTaskDetail(task)"
                            >
                              View Details
                            </button>
                            <button
                              v-if="canMutateWebmasterTasks"
                              type="button"
                              class="w-full border-t border-gray-100 px-3 py-2 text-left text-sm font-medium text-gray-800 hover:bg-gray-50 dark:border-gray-800 dark:text-gray-200 dark:hover:bg-white/5"
                              role="menuitem"
                              @click="openEdit(task)"
                            >
                              Edit
                            </button>
                            <button
                              v-if="canMutateWebmasterTasks"
                              type="button"
                              class="w-full border-t border-gray-100 px-3 py-2 text-left text-sm font-medium text-red-600 hover:bg-red-50 dark:border-gray-800 dark:text-red-400 dark:hover:bg-red-950/25"
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
                  <div class="mt-3 flex flex-wrap items-center gap-2">
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
                  <div v-if="task.assignee" class="mt-3 flex items-center gap-2 border-t border-gray-100 pt-3 dark:border-gray-800">
                    <span
                      class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-[11px] font-semibold"
                      :class="avatarClassForUser(task.assignee.email)"
                    >
                      {{ initials(task.assignee.name) }}
                    </span>
                    <span class="min-w-0 truncate text-xs text-gray-700 dark:text-gray-300">{{
                      task.assignee.name
                    }}</span>
                  </div>
                  <div v-else class="mt-3 border-t border-gray-100 pt-3 text-xs text-gray-400 dark:border-gray-800">
                    Unassigned
                  </div>
                </article>
              </template>
            </draggable>
          </section>
          <p
            v-if="!boardColumns.length"
            class="col-span-full py-12 text-center text-sm text-gray-500 dark:text-gray-400"
          >
            No Status Columns. Reload The Page.
          </p>
        </div>
          </div>
        </div>

        <div
          class="mt-5 flex flex-col gap-4 border-t border-gray-100 pt-5 dark:border-gray-800 lg:flex-row lg:items-center lg:justify-between"
        >
        <div
          class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center sm:gap-6"
        >
          <div class="flex items-center gap-2">
            <label
              for="webmaster-per-page"
              class="whitespace-nowrap text-sm text-gray-600 dark:text-gray-400"
              >Rows Per Page</label
            >
            <select
              id="webmaster-per-page"
              class="h-9 rounded-lg border border-gray-200 bg-white px-2 pr-8 text-sm text-gray-900 dark:border-gray-600 dark:bg-gray-800 dark:text-white"
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
            <span class="font-semibold text-gray-900 dark:text-white">{{
              pagination.total
            }}</span>
            Tasks
            <span v-if="query.status" class="text-gray-500">
              · Filtered By {{ statusLabel(query.status) }}
            </span>
          </p>
        </div>
        <div
          v-if="pagination.last_page > 1"
          class="flex w-full min-w-0 flex-wrap items-center gap-y-2"
        >
          <div class="flex flex-grow basis-0 justify-start">
            <button
              type="button"
              class="inline-flex h-9 items-center justify-center rounded-md border border-gray-200 px-3 text-sm text-gray-600 hover:bg-gray-50 disabled:opacity-40 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-800"
              :disabled="loading || pagination.current_page <= 1"
              @click="goTaskPage(pagination.current_page - 1)"
            >
              Previous
            </button>
          </div>
          <div class="flex flex-grow basis-0 justify-center">
            <span class="text-sm text-gray-600 dark:text-gray-400">
              Page
              <span class="font-medium text-gray-900 dark:text-white">{{
                pagination.current_page
              }}</span>
              /
              <span class="font-medium text-gray-900 dark:text-white">{{
                pagination.last_page
              }}</span>
            </span>
          </div>
          <div class="flex flex-grow basis-0 justify-end">
            <button
              type="button"
              class="inline-flex h-9 items-center justify-center rounded-md border border-gray-200 px-3 text-sm text-gray-600 hover:bg-gray-50 disabled:opacity-40 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-800"
              :disabled="
                loading || pagination.current_page >= pagination.last_page
              "
              @click="goTaskPage(pagination.current_page + 1)"
            >
              Next
            </button>
          </div>
        </div>
        </div>
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
</style>
