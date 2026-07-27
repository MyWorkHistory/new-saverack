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
import LeadCreateDrawer from "../../components/leads/LeadCreateDrawer.vue";
import LeadQuickAddDrawer from "../../components/leads/LeadQuickAddDrawer.vue";
import LeadStatusUpdateModal from "../../components/leads/LeadStatusUpdateModal.vue";
import LeadSummaryCards from "../../components/leads/LeadSummaryCards.vue";
import {
  LEAD_FOLLOW_UP_DAY_OPTIONS,
  LEAD_STATUSES,
  formatFollowUpDays,
  leadStatusLabel,
} from "../../constants/leads.js";

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

const canCreate = computed(() => userHasPerm("leads.create"));
const canUpdate = computed(() => userHasPerm("leads.update"));
const canDelete = computed(() => userHasPerm("leads.delete"));

setCrmPageMeta({ title: "Save Rack | Leads", description: "Sales leads directory." });

const loading = ref(true);
const rows = ref([]);
const meta = ref({ current_page: 1, last_page: 1, per_page: 25, total: 0 });
const directoryStats = ref({
  open: 0,
  contacted: 0,
  interested: 0,
  future_opportunity: 0,
  follow_up: 0,
});
const statuses = ref([...LEAD_STATUSES]);
const followUpDayOptions = ref([...LEAD_FOLLOW_UP_DAY_OPTIONS]);

const query = ref({
  search: "",
  status: "all",
  page: 1,
  per_page: 25,
  sort_by: "created_at",
  sort_dir: "desc",
});

const directorySummaryActiveStatus = computed(() => {
  const s = String(query.value.status || "all");
  return s === "all" ? "" : s;
});

const createOpen = ref(false);
const createBusy = ref(false);
const quickAddOpen = ref(false);
const quickAddBusy = ref(false);

const statusModalOpen = ref(false);
const statusModalRow = ref(null);
const statusModalStatus = ref("open");
const statusModalFollowUpDays = ref(1);
const statusPickerBusy = ref(false);

const deleteOpen = ref(false);
const deleteBusy = ref(false);
const deleteTarget = ref(null);

const manageOpenId = ref(null);
const manageMenuRect = ref({ top: 0, left: 0 });
const manageMenuRow = computed(() => rows.value.find((r) => r.id === manageOpenId.value) ?? null);

let searchTimer = null;

async function loadMeta() {
  try {
    const { data } = await api.get("/leads/meta");
    if (Array.isArray(data?.statuses) && data.statuses.length) {
      statuses.value = data.statuses;
    }
    if (Array.isArray(data?.follow_up_day_options) && data.follow_up_day_options.length) {
      followUpDayOptions.value = data.follow_up_day_options;
    }
    if (data?.directory_stats) {
      directoryStats.value = {
        open: Number(data.directory_stats.open || 0),
        contacted: Number(data.directory_stats.contacted || 0),
        interested: Number(data.directory_stats.interested || 0),
        future_opportunity: Number(data.directory_stats.future_opportunity || 0),
        follow_up: Number(data.directory_stats.follow_up || 0),
      };
    }
  } catch (e) {
    toast.errorFrom(e, "Could not load lead stats.");
  }
}

async function fetchRows() {
  loading.value = true;
  try {
    const params = {
      page: query.value.page,
      per_page: query.value.per_page,
      sort_by: query.value.sort_by,
      sort_dir: query.value.sort_dir,
    };
    if (query.value.status && query.value.status !== "all") {
      params.status = query.value.status;
    }
    const search = String(query.value.search || "").trim();
    if (search) params.search = search;

    const { data } = await api.get("/leads", { params });
    rows.value = Array.isArray(data?.data) ? data.data : [];
    meta.value = {
      current_page: Number(data?.current_page || 1),
      last_page: Number(data?.last_page || 1),
      per_page: Number(data?.per_page || 25),
      total: Number(data?.total || 0),
    };
  } catch (e) {
    toast.errorFrom(e, "Could not load leads.");
    rows.value = [];
  } finally {
    loading.value = false;
  }
}

function setDirectoryStatFilter(status) {
  const next = query.value.status === status ? "all" : status;
  query.value = { ...query.value, status: next, page: 1 };
}

function openStatusModal(row) {
  if (!canUpdate.value || !row) return;
  statusModalRow.value = row;
  statusModalStatus.value = row.status || "open";
  statusModalFollowUpDays.value = Number(row.follow_up_days || 1);
  statusModalOpen.value = true;
}

async function saveStatusFromModal() {
  const row = statusModalRow.value;
  if (!row?.id || statusPickerBusy.value) return;
  statusPickerBusy.value = true;
  try {
    const { data } = await api.patch(`/leads/${row.id}`, {
      status: statusModalStatus.value,
      follow_up_days: Number(statusModalFollowUpDays.value),
    });
    const idx = rows.value.findIndex((r) => r.id === row.id);
    if (idx !== -1) {
      rows.value[idx] = { ...rows.value[idx], ...data };
    }
    statusModalOpen.value = false;
    statusModalRow.value = null;
    toast.success("Lead updated.");
    await loadMeta();
    if (query.value.status !== "all" && data.status !== query.value.status) {
      await fetchRows();
    }
  } catch (e) {
    toast.errorFrom(e, "Could not update lead.");
  } finally {
    statusPickerBusy.value = false;
  }
}

async function onCreate(payload) {
  createBusy.value = true;
  try {
    const { data } = await api.post("/leads", payload);
    createOpen.value = false;
    toast.success("Lead created.");
    await loadMeta();
    await fetchRows();
    if (data?.id) {
      router.push({ name: "lead-detail", params: { id: data.id } });
    }
  } catch (e) {
    toast.errorFrom(e, "Could not create lead.");
  } finally {
    createBusy.value = false;
  }
}

async function onQuickAdd(payload) {
  quickAddBusy.value = true;
  try {
    const { data } = await api.post("/leads/quick-add", payload);
    quickAddOpen.value = false;
    toast.success("Lead created.");
    await loadMeta();
    await fetchRows();
    if (data?.id) {
      router.push({ name: "lead-detail", params: { id: data.id } });
    }
  } catch (e) {
    toast.errorFrom(e, "Could not create lead from pasted text.");
  } finally {
    quickAddBusy.value = false;
  }
}

function openDelete(row) {
  deleteTarget.value = row;
  deleteOpen.value = true;
  closeManageMenu();
}

async function confirmDelete() {
  const row = deleteTarget.value;
  if (!row?.id || deleteBusy.value) return;
  deleteBusy.value = true;
  try {
    await api.delete(`/leads/${row.id}`);
    deleteOpen.value = false;
    deleteTarget.value = null;
    toast.success("Lead deleted.");
    await loadMeta();
    await fetchRows();
  } catch (e) {
    toast.errorFrom(e, "Could not delete lead.");
  } finally {
    deleteBusy.value = false;
  }
}

function closeManageMenu() {
  manageOpenId.value = null;
}

function toggleManageMenu(row, event) {
  if (manageOpenId.value === row.id) {
    closeManageMenu();
    return;
  }
  const btn = event?.currentTarget;
  if (btn?.getBoundingClientRect) {
    const rect = btn.getBoundingClientRect();
    manageMenuRect.value = {
      top: rect.bottom + 4,
      left: Math.max(8, rect.right - 160),
    };
  }
  manageOpenId.value = row.id;
}

function onDocClick() {
  closeManageMenu();
}

function websiteHref(website) {
  const raw = String(website || "").trim();
  if (!raw) return "";
  if (/^https?:\/\//i.test(raw)) return raw;
  return `https://${raw}`;
}

watch(
  () => query.value.search,
  () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
      query.value = { ...query.value, page: 1 };
      fetchRows();
    }, 280);
  },
);

watch(
  () => [query.value.status, query.value.page, query.value.sort_by, query.value.sort_dir],
  () => {
    fetchRows();
  },
);

onMounted(async () => {
  document.addEventListener("click", onDocClick);
  const statusFromQuery = String(route.query.status || "").toLowerCase();
  if (LEAD_STATUSES.includes(statusFromQuery)) {
    query.value.status = statusFromQuery;
  }
  await loadMeta();
  await fetchRows();
});

onUnmounted(() => {
  document.removeEventListener("click", onDocClick);
  clearTimeout(searchTimer);
});
</script>

<template>
  <div class="staff-page">
    <LeadCreateDrawer v-model:open="createOpen" :busy="createBusy" @submit="onCreate" />
    <LeadQuickAddDrawer v-model:open="quickAddOpen" :busy="quickAddBusy" @submit="onQuickAdd" />
    <LeadStatusUpdateModal
      v-model:open="statusModalOpen"
      v-model:status="statusModalStatus"
      v-model:follow-up-days="statusModalFollowUpDays"
      :statuses="statuses"
      :follow-up-day-options="followUpDayOptions"
      :busy="statusPickerBusy"
      @save="saveStatusFromModal"
    />
    <ConfirmModal
      :open="deleteOpen"
      title="Delete Lead?"
      :message="
        deleteTarget
          ? `Delete lead “${deleteTarget.company_name}”? This cannot be undone.`
          : 'Delete this lead?'
      "
      confirm-label="Delete"
      :busy="deleteBusy"
      danger
      @close="deleteOpen = false"
      @confirm="confirmDelete"
    />

    <div
      class="d-flex flex-column flex-md-row align-items-start align-items-md-center gap-3 mb-4"
    >
      <div class="min-w-0 flex-grow-1">
        <h1 class="h4 mb-1 fw-semibold text-body">Leads</h1>
        <p class="text-secondary small mb-0">Track sales leads and follow-ups</p>
      </div>
      <div class="d-flex flex-wrap align-items-center gap-2 ms-md-auto flex-shrink-0">
        <button
          v-if="canCreate"
          type="button"
          class="btn btn-outline-secondary fw-semibold"
          @click="quickAddOpen = true"
        >
          Quick Add
        </button>
        <button
          v-if="canCreate"
          type="button"
          class="btn btn-primary staff-page-primary d-inline-flex align-items-center gap-2"
          @click="createOpen = true"
        >
          <svg width="18" height="18" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z" />
          </svg>
          Add Lead
        </button>
      </div>
    </div>

    <LeadSummaryCards
      :values="directoryStats"
      :active-status="directorySummaryActiveStatus"
      @select="setDirectoryStatFilter"
    />

    <div class="staff-table-card staff-datatable-card staff-datatable-card--white">
      <div class="staff-table-toolbar">
        <div class="staff-table-toolbar--row">
          <input
            v-model="query.search"
            type="search"
            class="form-control staff-toolbar-search staff-toolbar-search--inline"
            placeholder="Search leads"
            autocomplete="off"
          />
        </div>
      </div>

      <div class="table-responsive">
        <table class="table staff-table mb-0 align-middle">
          <thead>
            <tr>
              <th scope="col">Status</th>
              <th scope="col">Company Name</th>
              <th scope="col">Email</th>
              <th scope="col">Website</th>
              <th scope="col">Follow Up</th>
              <th scope="col">Date Created</th>
              <th scope="col" class="text-end">Action</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="loading">
              <td colspan="7" class="text-center py-5 text-secondary">
                <CrmLoadingSpinner class="me-2" />
                Loading…
              </td>
            </tr>
            <tr v-else-if="!rows.length">
              <td colspan="7" class="text-center py-5 text-secondary">No leads found.</td>
            </tr>
            <tr v-for="row in rows" :key="row.id">
              <td>
                <button
                  v-if="canUpdate"
                  type="button"
                  class="btn btn-sm btn-outline-secondary text-nowrap"
                  @click="openStatusModal(row)"
                >
                  {{ leadStatusLabel(row.status) }}
                </button>
                <span v-else class="small">{{ leadStatusLabel(row.status) }}</span>
              </td>
              <td>
                <RouterLink
                  :to="{ name: 'lead-detail', params: { id: row.id } }"
                  class="fw-semibold text-decoration-none"
                >
                  {{ row.company_name }}
                </RouterLink>
              </td>
              <td>
                <a v-if="row.email" :href="`mailto:${row.email}`" class="text-decoration-none">
                  {{ row.email }}
                </a>
                <span v-else class="text-secondary">—</span>
              </td>
              <td>
                <a
                  v-if="row.website"
                  :href="websiteHref(row.website)"
                  class="text-decoration-none"
                  target="_blank"
                  rel="noopener noreferrer"
                >
                  {{ row.website }}
                </a>
                <span v-else class="text-secondary">—</span>
              </td>
              <td>{{ formatFollowUpDays(row.follow_up_days) }}</td>
              <td>{{ formatDateUs(row.created_at) }}</td>
              <td class="text-end">
                <button
                  type="button"
                  class="btn btn-sm btn-light border-0"
                  aria-label="Actions"
                  @click.stop="toggleManageMenu(row, $event)"
                >
                  <CrmIconRowActions />
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div
        v-if="meta.last_page > 1"
        class="d-flex align-items-center justify-content-between gap-2 px-3 py-2 border-top"
      >
        <span class="small text-secondary">
          Page {{ meta.current_page }} of {{ meta.last_page }} ({{ meta.total }} total)
        </span>
        <div class="d-flex gap-2">
          <button
            type="button"
            class="btn btn-sm btn-outline-secondary"
            :disabled="meta.current_page <= 1 || loading"
            @click="query.page = meta.current_page - 1"
          >
            Previous
          </button>
          <button
            type="button"
            class="btn btn-sm btn-outline-secondary"
            :disabled="meta.current_page >= meta.last_page || loading"
            @click="query.page = meta.current_page + 1"
          >
            Next
          </button>
        </div>
      </div>
    </div>

    <Teleport to="body">
      <div
        v-if="manageMenuRow"
        class="staff-row-menu dropdown-menu show shadow"
        :style="{
          position: 'fixed',
          top: `${manageMenuRect.top}px`,
          left: `${manageMenuRect.left}px`,
          zIndex: 1300,
        }"
        @click.stop
      >
        <RouterLink
          class="dropdown-item"
          :to="{ name: 'lead-detail', params: { id: manageMenuRow.id } }"
          @click="closeManageMenu"
        >
          View
        </RouterLink>
        <button
          v-if="canUpdate"
          type="button"
          class="dropdown-item"
          @click="
            openStatusModal(manageMenuRow);
            closeManageMenu();
          "
        >
          Update Status
        </button>
        <button
          v-if="canDelete"
          type="button"
          class="dropdown-item text-danger"
          @click="openDelete(manageMenuRow)"
        >
          Delete
        </button>
      </div>
    </Teleport>
  </div>
</template>
