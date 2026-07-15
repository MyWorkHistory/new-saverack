<script setup>
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast.js";
import { crmIsAdmin } from "../../utils/crmUser.js";
import { formatDateUs } from "../../utils/formatUserDates.js";
import { computed, inject, onMounted, onUnmounted, ref, watch } from "vue";
import { RouterLink, useRoute, useRouter } from "vue-router";
import api from "../../services/api";
import ConfirmModal from "../../components/common/ConfirmModal.vue";
import CrmIconRowActions from "../../components/common/CrmIconRowActions.vue";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import CrmSearchableSelect from "../../components/common/CrmSearchableSelect.vue";
import ProjectCreateDrawer from "../../components/projects/ProjectCreateDrawer.vue";
import ProjectHubSummaryCards from "../../components/projects/ProjectHubSummaryCards.vue";
import ProjectStatusChip from "../../components/projects/ProjectStatusChip.vue";

const crmUser = inject("crmUser", ref(null));
const toast = useToast();
const router = useRouter();
const route = useRoute();

function userHasPerm(key) {
  const u = crmUser.value;
  if (!u) return false;
  if (crmIsAdmin(u) || u.is_crm_owner) return true;
  return Array.isArray(u.permission_keys) && u.permission_keys.includes(key);
}

const canCreate = computed(() => userHasPerm("projects.create"));
const canDelete = computed(() => userHasPerm("projects.delete"));

setCrmPageMeta({ title: "Save Rack | Projects", description: "Client projects." });

const loading = ref(true);
const summaryLoading = ref(true);
const summary = ref({ pending: 0, in_progress: 0, completed: 0 });
const rows = ref([]);
const meta = ref({ current_page: 1, last_page: 1, per_page: 25, total: 0 });

const accounts = ref([]);
const accountsLoading = ref(false);
const accountFilter = ref("");
const statusFilter = ref("pending");
const search = ref("");
const searchDebounced = ref("");
let searchTimer = null;

const statusBusyId = ref(null);
const createOpen = ref(false);
const createBusy = ref(false);
const deleteOpen = ref(false);
const deleteBusy = ref(false);
const deleteTarget = ref(null);
const manageOpenId = ref(null);
const manageMenuRect = ref({ top: 0, left: 0 });

const accountOptions = computed(() =>
  (accounts.value || []).map((a) => ({
    id: a.id,
    name: a.company_name || a.label || `Account #${a.id}`,
    email: a.email ? String(a.email) : "",
  })),
);

const manageMenuRow = computed(() => rows.value.find((r) => r.id === manageOpenId.value) ?? null);

async function loadAccounts() {
  accountsLoading.value = true;
  try {
    const { data } = await api.get("/inventory/client-account-options");
    accounts.value = Array.isArray(data?.accounts)
      ? data.accounts
      : Array.isArray(data?.data)
        ? data.data
        : [];
  } catch (e) {
    toast.errorFrom(e, "Could not load accounts.");
    accounts.value = [];
  } finally {
    accountsLoading.value = false;
  }
}

async function loadSummary() {
  summaryLoading.value = true;
  try {
    const { data } = await api.get("/projects/summary");
    summary.value = {
      pending: Number(data?.pending || 0),
      in_progress: Number(data?.in_progress || 0),
      completed: Number(data?.completed || 0),
    };
  } catch (e) {
    toast.errorFrom(e, "Could not load project summary.");
  } finally {
    summaryLoading.value = false;
  }
}

async function loadList(page = 1) {
  loading.value = true;
  try {
    const params = {
      page,
      per_page: meta.value.per_page || 25,
      sort_by: "created_at",
      sort_dir: "desc",
    };
    if (statusFilter.value && statusFilter.value !== "all") {
      params.status = statusFilter.value;
    }
    if (accountFilter.value) {
      params.client_account_id = accountFilter.value;
    }
    if (searchDebounced.value) {
      params.q = searchDebounced.value;
    }
    const { data } = await api.get("/projects", { params });
    rows.value = Array.isArray(data?.data) ? data.data : [];
    meta.value = {
      current_page: Number(data?.current_page || 1),
      last_page: Number(data?.last_page || 1),
      per_page: Number(data?.per_page || 25),
      total: Number(data?.total || 0),
    };
  } catch (e) {
    toast.errorFrom(e, "Could not load projects.");
    rows.value = [];
  } finally {
    loading.value = false;
  }
}

function setStatusCard(status) {
  statusFilter.value = statusFilter.value === status ? "all" : status;
}

async function changeStatus(row, status) {
  if (!row?.id || statusBusyId.value) return;
  statusBusyId.value = row.id;
  try {
    const { data } = await api.patch(`/projects/${row.id}/status`, { status });
    const idx = rows.value.findIndex((r) => r.id === row.id);
    if (idx !== -1) {
      rows.value[idx] = {
        ...rows.value[idx],
        status: data.status,
        status_label: data.status_label,
        completed_at: data.completed_at,
      };
    }
    await loadSummary();
    if (statusFilter.value !== "all" && statusFilter.value !== status) {
      await loadList(meta.value.current_page);
    }
    toast.success("Status updated.");
  } catch (e) {
    toast.errorFrom(e, "Could not update status.");
  } finally {
    statusBusyId.value = null;
  }
}

function openManage(row, event) {
  const btn = event?.currentTarget;
  const rect = btn?.getBoundingClientRect?.();
  const MENU_W = 200;
  if (rect) {
    let left = Math.round(rect.right - MENU_W);
    left = Math.max(8, Math.min(left, window.innerWidth - MENU_W - 8));
    manageMenuRect.value = {
      top: Math.round(rect.bottom + 4),
      left,
    };
  }
  manageOpenId.value = manageOpenId.value === row.id ? null : row.id;
}

function openDelete(row) {
  if (!canDelete.value) {
    toast.error("You do not have permission to delete projects.");
    return;
  }
  deleteTarget.value = row;
  manageOpenId.value = null;
  deleteOpen.value = true;
}

async function confirmDelete() {
  if (!deleteTarget.value?.id) return;
  deleteBusy.value = true;
  try {
    await api.delete(`/projects/${deleteTarget.value.id}`);
    toast.success("Project deleted.");
    deleteOpen.value = false;
    deleteTarget.value = null;
    await Promise.all([loadSummary(), loadList(meta.value.current_page)]);
  } catch (e) {
    toast.errorFrom(e, "Could not delete project.");
  } finally {
    deleteBusy.value = false;
  }
}

async function submitCreate(payload) {
  createBusy.value = true;
  try {
    const { data } = await api.post("/projects", payload);
    createOpen.value = false;
    toast.success("Project created.");
    await router.push(`/admin/clients/projects/${data.id}`);
  } catch (e) {
    toast.errorFrom(e, "Could not create project.");
  } finally {
    createBusy.value = false;
  }
}

function onDocClick() {
  manageOpenId.value = null;
}

watch(search, (v) => {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => {
    searchDebounced.value = String(v || "").trim();
  }, 300);
});

watch([statusFilter, accountFilter, searchDebounced], () => {
  loadList(1);
});

onMounted(async () => {
  document.addEventListener("click", onDocClick);
  const qStatus = String(route.query.status || "").trim();
  if (["pending", "in_progress", "completed", "all"].includes(qStatus)) {
    statusFilter.value = qStatus;
  }
  await Promise.all([loadAccounts(), loadSummary(), loadList(1)]);
});

onUnmounted(() => {
  document.removeEventListener("click", onDocClick);
  clearTimeout(searchTimer);
});
</script>

<template>
  <div class="staff-page">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
      <div>
        <h1 class="staff-page-title mb-0">Projects</h1>
        <p class="text-secondary small mb-0">Track client projects and quotes.</p>
      </div>
      <button
        v-if="canCreate"
        type="button"
        class="btn btn-primary staff-page-primary"
        @click="createOpen = true"
      >
        Add Project
      </button>
    </div>

    <div class="mb-4">
      <ProjectHubSummaryCards
        :loading="summaryLoading"
        :active-status="statusFilter"
        :values="summary"
        @select="setStatusCard"
      />
    </div>

    <div class="staff-table-card staff-datatable-card staff-datatable-card--white projects-list-table">
      <div class="staff-table-toolbar">
        <div class="staff-table-toolbar--row projects-toolbar-row">
          <div class="projects-toolbar-account">
            <CrmSearchableSelect
              v-model="accountFilter"
              class="staff-toolbar-search staff-toolbar-search--inline w-100"
              appearance="staff"
              aria-label="Client account"
              :options="accountOptions"
              placeholder="All accounts"
              :allow-empty="true"
              empty-label="All accounts"
            />
          </div>
          <div class="projects-toolbar-search">
            <input
              id="project-search"
              v-model="search"
              type="search"
              class="form-control staff-toolbar-search staff-toolbar-search--inline w-100"
              placeholder="Search PID or project name"
              autocomplete="off"
              aria-label="Search PID or project name"
            />
          </div>
        </div>
      </div>

      <div v-if="loading" class="p-5 d-flex justify-content-center">
        <CrmLoadingSpinner message="Loading projects…" />
      </div>
      <div v-else class="table-responsive staff-table-wrap">
        <table class="table table-hover align-middle mb-0 staff-data-table">
          <thead class="table-light staff-table-head">
            <tr>
              <th class="staff-table-head__th text-center" scope="col">Status</th>
              <th class="staff-table-head__th text-center" scope="col">PID</th>
              <th class="staff-table-head__th" scope="col">Project Name</th>
              <th class="staff-table-head__th text-center" scope="col">Date Created</th>
              <th class="staff-table-head__th text-center" scope="col">Date Completed</th>
              <th class="staff-table-head__th staff-actions-col text-center" scope="col">Action</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="rows.length === 0">
              <td colspan="6" class="text-center text-secondary py-5">No projects found.</td>
            </tr>
            <tr v-for="row in rows" :key="row.id">
              <td class="text-center">
                <ProjectStatusChip
                  :status="row.status"
                  :disabled="statusBusyId === row.id"
                  @change="(s) => changeStatus(row, s)"
                />
              </td>
              <td class="text-center">
                <RouterLink
                  class="fw-semibold text-decoration-none"
                  :to="`/admin/clients/projects/${row.id}`"
                >
                  {{ row.pid }}
                </RouterLink>
              </td>
              <td>
                <RouterLink
                  class="text-decoration-none text-body"
                  :to="`/admin/clients/projects/${row.id}`"
                >
                  {{ row.name }}
                </RouterLink>
                <div v-if="row.client_account_name" class="small text-secondary">
                  {{ row.client_account_name }}
                </div>
              </td>
              <td class="text-center">{{ formatDateUs(row.created_at) || "—" }}</td>
              <td class="text-center">{{ formatDateUs(row.completed_at) || "—" }}</td>
              <td class="staff-actions-cell text-center" @click.stop>
                <div
                  data-row-actions
                  class="staff-actions-inner staff-actions-inner--single justify-content-center"
                >
                  <button
                    type="button"
                    class="staff-action-btn staff-action-btn--more"
                    :class="{ 'is-open': manageOpenId === row.id }"
                    :aria-expanded="manageOpenId === row.id"
                    aria-haspopup="true"
                    aria-label="Row actions"
                    @click="(e) => openManage(row, e)"
                  >
                    <CrmIconRowActions variant="horizontal" />
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div
        v-if="meta.last_page > 1"
        class="staff-table-footer card-footer d-flex flex-column flex-sm-row align-items-stretch align-items-sm-center justify-content-between gap-2"
      >
        <span class="small text-secondary">
          Page {{ meta.current_page }} of {{ meta.last_page }}
        </span>
        <div class="btn-group btn-group-sm ms-sm-auto">
          <button
            type="button"
            class="btn btn-outline-secondary"
            :disabled="meta.current_page <= 1"
            @click="loadList(meta.current_page - 1)"
          >
            Previous
          </button>
          <button
            type="button"
            class="btn btn-outline-secondary"
            :disabled="meta.current_page >= meta.last_page"
            @click="loadList(meta.current_page + 1)"
          >
            Next
          </button>
        </div>
      </div>
    </div>

    <Teleport to="body">
      <div
        v-if="manageMenuRow"
        data-row-actions
        class="staff-row-menu fixed z-[300] overflow-hidden"
        role="menu"
        :style="{
          top: `${manageMenuRect.top}px`,
          left: `${manageMenuRect.left}px`,
        }"
        @click.stop
      >
        <RouterLink
          class="staff-row-menu__item"
          role="menuitem"
          :to="`/admin/clients/projects/${manageMenuRow.id}`"
          @click="manageOpenId = null"
        >
          View
        </RouterLink>
        <template v-if="canDelete">
          <hr class="staff-row-menu__divider" />
          <button
            type="button"
            class="staff-row-menu__item staff-row-menu__item--danger"
            role="menuitem"
            @click="openDelete(manageMenuRow)"
          >
            Delete
          </button>
        </template>
      </div>
    </Teleport>

    <ProjectCreateDrawer
      v-model:open="createOpen"
      :busy="createBusy"
      :account-options="accountOptions"
      :accounts-loading="accountsLoading"
      @submit="submitCreate"
    />

    <ConfirmModal
      :open="deleteOpen"
      title="Delete Project"
      :message="
        deleteTarget
          ? `Delete project ${deleteTarget.pid}? The linked custom bill will be kept.`
          : ''
      "
      confirm-label="Delete"
      :busy="deleteBusy"
      @close="deleteOpen = false"
      @confirm="confirmDelete"
    />
  </div>
</template>

<style scoped>
.projects-toolbar-row {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem;
}

.projects-toolbar-account {
  flex: 0 0 auto;
  width: min(280px, 100%);
}

.projects-toolbar-search {
  flex: 0 0 auto;
  width: min(18rem, 100%);
}
</style>
