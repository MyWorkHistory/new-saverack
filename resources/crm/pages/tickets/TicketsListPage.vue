<script setup>
import { computed, onMounted, onUnmounted, reactive, ref } from "vue";
import { useRouter } from "vue-router";
import api from "../../services/api";
import PageHeader from "../../components/common/PageHeader.vue";
import ConfirmModal from "../../components/common/ConfirmModal.vue";
import { useToast } from "../../composables/useToast";
import { errorMessage } from "../../utils/apiError";

const router = useRouter();
const toast = useToast();
const loading = ref(true);
const rows = ref([]);
const pagination = ref({ current_page: 1, last_page: 1, total: 0 });
const meta = ref({ statuses: [], priorities: [] });
const users = ref([]);
const deleteTarget = ref(null);
const deleteBusy = ref(false);
const deleteError = ref("");
const modalOpen = ref(false);
const modalBusy = ref(false);
const modalError = ref("");
const editingId = ref(null);

const form = reactive({
  title: "",
  description: "",
  status: "backlog",
  priority: "medium",
  due_date: "",
  assigned_to: "",
});

const query = reactive({
  search: "",
  per_page: 15,
  page: 1,
  sort_by: "created_at",
  sort_dir: "desc",
  status: "",
  priority: "",
  assigned_to: "",
});

const deleteModalOpen = computed(() => deleteTarget.value !== null);

const deleteMessage = computed(() => {
  const t = deleteTarget.value;
  return t ? `Delete ticket “${t.title}”? This cannot be undone.` : "";
});

const statusLabel = (v) => {
  const s = meta.value.statuses.find((x) => x.value === v);
  return s ? s.label : v;
};

const priorityClass = (p) => {
  const x = String(p || "").toLowerCase();
  const map = {
    low: "bg-slate-100 text-slate-800 ring-slate-500/20 dark:bg-slate-800 dark:text-slate-200",
    medium:
      "bg-blue-50 text-blue-800 ring-blue-600/20 dark:bg-blue-500/10 dark:text-blue-200",
    high: "bg-amber-50 text-amber-800 ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-200",
    urgent:
      "bg-red-50 text-red-800 ring-red-600/20 dark:bg-red-500/10 dark:text-red-200",
  };
  return (
    map[x] ||
    "bg-gray-100 text-gray-700 ring-gray-500/20 dark:bg-gray-800 dark:text-gray-300"
  );
};

const statusClass = (s) => {
  const x = String(s || "");
  if (x === "done" || x === "cancelled") {
    return "bg-emerald-50 text-emerald-800 ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-200";
  }
  if (x === "in_progress" || x === "review") {
    return "bg-violet-50 text-violet-800 ring-violet-600/20 dark:bg-violet-500/10 dark:text-violet-200";
  }
  return "bg-gray-100 text-gray-700 ring-gray-500/20 dark:bg-gray-800 dark:text-gray-300";
};

const fetchMeta = async () => {
  try {
    const { data } = await api.get("/tickets/meta");
    meta.value = data;
  } catch {
    meta.value = { statuses: [], priorities: [] };
  }
};

const fetchUsers = async () => {
  try {
    const { data } = await api.get("/users", { params: { per_page: 100, page: 1 } });
    users.value = data.data || [];
  } catch {
    users.value = [];
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
  if (query.status) p.status = query.status;
  if (query.priority) p.priority = query.priority;
  if (query.assigned_to) p.assigned_to = query.assigned_to;
  return p;
};

const fetchTickets = async () => {
  loading.value = true;
  deleteError.value = "";
  try {
    const { data } = await api.get("/tickets", { params: buildParams() });
    rows.value = data.data;
    pagination.value = {
      current_page: data.current_page,
      last_page: data.last_page,
      total: data.total,
    };
  } catch (e) {
    rows.value = [];
    if (e.response?.status === 403) {
      router.replace("/dashboard");
    }
  } finally {
    loading.value = false;
  }
};

const applySearch = () => {
  query.page = 1;
  fetchTickets();
};

const clearFilters = () => {
  query.search = "";
  query.status = "";
  query.priority = "";
  query.assigned_to = "";
  query.page = 1;
  fetchTickets();
};

const goPage = (p) => {
  if (p < 1 || p > pagination.value.last_page) return;
  query.page = p;
  fetchTickets();
};

const openCreate = () => {
  editingId.value = null;
  modalError.value = "";
  form.title = "";
  form.description = "";
  form.status = "backlog";
  form.priority = "medium";
  form.due_date = "";
  form.assigned_to = "";
  modalOpen.value = true;
};

const openEdit = (row) => {
  editingId.value = row.id;
  modalError.value = "";
  form.title = row.title;
  form.description = row.description || "";
  form.status = row.status;
  form.priority = row.priority;
  form.due_date = row.due_date || "";
  form.assigned_to = row.assigned_to ? String(row.assigned_to) : "";
  modalOpen.value = true;
};

const closeModal = () => {
  if (modalBusy.value) return;
  modalOpen.value = false;
};

const saveModal = async () => {
  modalBusy.value = true;
  modalError.value = "";
  try {
    const payload = {
      title: form.title,
      description: form.description || null,
      status: form.status,
      priority: form.priority,
      due_date: form.due_date || null,
      assigned_to: form.assigned_to ? parseInt(form.assigned_to, 10) : null,
    };
    if (editingId.value) {
      await api.patch(`/tickets/${editingId.value}`, payload);
      toast.success("Ticket updated.");
    } else {
      await api.post("/tickets", payload);
      toast.success("Ticket created.");
    }
    modalOpen.value = false;
    await fetchTickets();
  } catch (e) {
    modalError.value = errorMessage(e, "Could not save ticket.");
    toast.errorFrom(e, "Could not save ticket.");
  } finally {
    modalBusy.value = false;
  }
};

const openRow = (row) => {
  router.push(`/tickets/${row.id}`);
};

const openDelete = (row, e) => {
  e.stopPropagation();
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
    await api.delete(`/tickets/${t.id}`);
    deleteTarget.value = null;
    toast.success("Ticket deleted.");
    await fetchTickets();
  } catch (e) {
    deleteError.value = errorMessage(e, "Could not delete ticket.");
    toast.errorFrom(e, "Could not delete ticket.");
  } finally {
    deleteBusy.value = false;
  }
};

const escClose = (e) => {
  if (e.key === "Escape" && modalOpen.value) closeModal();
};

onMounted(async () => {
  document.addEventListener("keydown", escClose);
  await fetchMeta();
  await fetchUsers();
  await fetchTickets();
});

onUnmounted(() => {
  document.removeEventListener("keydown", escClose);
});
</script>

<template>
  <div class="flex flex-col gap-6 lg:flex-row lg:items-start">
    <aside
      class="w-full shrink-0 space-y-4 lg:sticky lg:top-4 lg:w-64 xl:w-72"
    >
      <div
        class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900/40"
      >
        <h2
          class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400"
        >
          Tickets
        </h2>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
          Create your first ticket for the next CRM milestone.
        </p>
        <div class="mt-4 space-y-2">
          <button
            type="button"
            class="flex w-full items-center justify-center gap-2 rounded-xl bg-emerald-600 px-4 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-emerald-700"
            @click="openCreate"
          >
            New ticket
          </button>
          <button
            type="button"
            class="flex w-full items-center justify-center gap-2 rounded-xl border border-gray-200 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 transition hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 dark:hover:bg-gray-800"
            :disabled="loading"
            @click="fetchTickets"
          >
            Refresh list
          </button>
          <router-link
            to="/tickets/board"
            class="flex w-full items-center justify-center rounded-xl border border-gray-200 px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-800"
          >
            Open Kanban board
          </router-link>
        </div>
      </div>
    </aside>

    <div class="min-w-0 flex-1 space-y-4">
      <PageHeader
        title="Tickets"
        subtitle="Development backlog for the CRM build"
        :result-count="loading ? undefined : pagination.total"
      />

      <p v-if="deleteError" class="text-sm text-red-600 dark:text-red-400">
        {{ deleteError }}
      </p>

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
              placeholder="Title or description"
              class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:outline-none focus:ring-2 focus:ring-slate-400/30 dark:border-gray-600 dark:bg-gray-900 dark:text-white"
              @keyup.enter="applySearch"
            />
          </div>
          <div class="w-full min-w-[140px] sm:w-44">
            <label
              class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400"
              >Status</label
            >
            <select
              v-model="query.status"
              class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-white"
            >
              <option value="">All</option>
              <option
                v-for="s in meta.statuses"
                :key="s.value"
                :value="s.value"
              >
                {{ s.label }}
              </option>
            </select>
          </div>
          <div class="w-full min-w-[140px] sm:w-44">
            <label
              class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400"
              >Priority</label
            >
            <select
              v-model="query.priority"
              class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-white"
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
          <div class="w-full min-w-[160px] sm:w-48">
            <label
              class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400"
              >Assignee</label
            >
            <select
              v-model="query.assigned_to"
              class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-white"
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
          <div class="flex flex-wrap gap-2 lg:pb-0.5">
            <button
              type="button"
              class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white dark:bg-white dark:text-gray-900"
              @click="applySearch"
            >
              Apply
            </button>
            <button
              type="button"
              class="rounded-lg border border-gray-200 px-4 py-2 text-sm dark:border-gray-600"
              @click="clearFilters"
            >
              Clear
            </button>
          </div>
        </div>
      </div>

      <div
        class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900/30"
      >
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-800">
            <thead class="bg-slate-900 text-left text-xs font-semibold uppercase tracking-wide text-white dark:bg-gray-950">
              <tr>
                <th class="px-4 py-3">Title</th>
                <th class="px-4 py-3">Status</th>
                <th class="px-4 py-3">Priority</th>
                <th class="px-4 py-3">Assignee</th>
                <th class="px-4 py-3">Due</th>
                <th class="px-4 py-3 text-right">Actions</th>
              </tr>
            </thead>
            <tbody
              class="divide-y divide-gray-100 bg-white text-sm dark:divide-gray-800 dark:bg-gray-900/20"
            >
              <tr v-if="loading">
                <td colspan="6" class="px-4 py-8 text-center text-gray-500">
                  Loading…
                </td>
              </tr>
              <tr v-else-if="!rows.length">
                <td colspan="6" class="px-4 py-8 text-center text-gray-500">
                  No tickets yet. Create one from the sidebar.
                </td>
              </tr>
              <tr
                v-for="row in rows"
                :key="row.id"
                class="cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-800/50"
                @click="openRow(row)"
              >
                <td class="px-4 py-3 font-medium text-gray-900 dark:text-white">
                  {{ row.title }}
                </td>
                <td class="px-4 py-3">
                  <span
                    class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset"
                    :class="statusClass(row.status)"
                  >
                    {{ statusLabel(row.status) }}
                  </span>
                </td>
                <td class="px-4 py-3">
                  <span
                    class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset"
                    :class="priorityClass(row.priority)"
                  >
                    {{ row.priority }}
                  </span>
                </td>
                <td class="px-4 py-3 text-gray-600 dark:text-gray-300">
                  {{ row.assignee ? row.assignee.name : "—" }}
                </td>
                <td class="px-4 py-3 text-gray-600 dark:text-gray-300">
                  {{ row.due_date || "—" }}
                </td>
                <td class="px-4 py-3 text-right" @click.stop>
                  <button
                    type="button"
                    class="mr-2 text-emerald-600 hover:underline"
                    @click="openEdit(row)"
                  >
                    Edit
                  </button>
                  <button
                    type="button"
                    class="text-red-600 hover:underline"
                    @click="openDelete(row, $event)"
                  >
                    Delete
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div
          v-if="pagination.last_page > 1"
          class="flex items-center justify-between border-t border-gray-100 px-4 py-3 text-sm dark:border-gray-800"
        >
          <span class="text-gray-600 dark:text-gray-400"
            >Page {{ pagination.current_page }} of
            {{ pagination.last_page }}</span
          >
          <div class="flex gap-2">
            <button
              type="button"
              class="rounded border border-gray-200 px-3 py-1 dark:border-gray-600"
              :disabled="pagination.current_page <= 1"
              @click="goPage(pagination.current_page - 1)"
            >
              Prev
            </button>
            <button
              type="button"
              class="rounded border border-gray-200 px-3 py-1 dark:border-gray-600"
              :disabled="pagination.current_page >= pagination.last_page"
              @click="goPage(pagination.current_page + 1)"
            >
              Next
            </button>
          </div>
        </div>
      </div>
    </div>

    <div
      v-if="modalOpen"
      class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
      role="dialog"
      aria-modal="true"
      @click.self="closeModal"
    >
      <div
        class="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-2xl bg-white p-6 shadow-xl dark:bg-gray-900"
      >
        <h3 class="text-lg font-semibold text-gray-900 dark:text-white">
          {{ editingId ? "Edit ticket" : "New ticket" }}
        </h3>
        <p v-if="modalError" class="mt-2 text-sm text-red-600">{{ modalError }}</p>
        <form class="mt-4 space-y-3" @submit.prevent="saveModal">
          <div>
            <label class="text-xs text-gray-500">Title</label>
            <input
              v-model="form.title"
              required
              class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 dark:border-gray-600 dark:bg-gray-800 dark:text-white"
            />
          </div>
          <div>
            <label class="text-xs text-gray-500">Description</label>
            <textarea
              v-model="form.description"
              rows="4"
              class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 dark:border-gray-600 dark:bg-gray-800 dark:text-white"
            />
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="text-xs text-gray-500">Status</label>
              <select
                v-model="form.status"
                class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 dark:border-gray-600 dark:bg-gray-800 dark:text-white"
              >
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
              <label class="text-xs text-gray-500">Priority</label>
              <select
                v-model="form.priority"
                class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 dark:border-gray-600 dark:bg-gray-800 dark:text-white"
              >
                <option
                  v-for="p in meta.priorities"
                  :key="p.value"
                  :value="p.value"
                >
                  {{ p.label }}
                </option>
              </select>
            </div>
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="text-xs text-gray-500">Due date</label>
              <input
                v-model="form.due_date"
                type="date"
                class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 dark:border-gray-600 dark:bg-gray-800 dark:text-white"
              />
            </div>
            <div>
              <label class="text-xs text-gray-500">Assignee</label>
              <select
                v-model="form.assigned_to"
                class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 dark:border-gray-600 dark:bg-gray-800 dark:text-white"
              >
                <option value="">Unassigned</option>
                <option
                  v-for="u in users"
                  :key="u.id"
                  :value="String(u.id)"
                >
                  {{ u.name }}
                </option>
              </select>
            </div>
          </div>
          <div class="flex justify-end gap-2 pt-2">
            <button
              type="button"
              class="rounded-lg border border-gray-200 px-4 py-2 text-sm dark:border-gray-600"
              @click="closeModal"
            >
              Cancel
            </button>
            <button
              type="submit"
              class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white dark:bg-white dark:text-gray-900"
              :disabled="modalBusy"
            >
              {{ modalBusy ? "Saving…" : "Save" }}
            </button>
          </div>
        </form>
      </div>
    </div>

    <ConfirmModal
      :open="deleteModalOpen"
      title="Delete ticket"
      :message="deleteMessage"
      confirm-label="Delete"
      :busy="deleteBusy"
      @close="closeDeleteModal"
      @confirm="confirmDelete"
    />
  </div>
</template>
