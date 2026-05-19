<script setup>
import { computed, inject, onMounted, onUnmounted, reactive, ref, watch } from "vue";
import { useRouter } from "vue-router";
import api from "../../services/api";
import ConfirmModal from "../../components/common/ConfirmModal.vue";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import CrmSearchableSelect from "../../components/common/CrmSearchableSelect.vue";
import CrmIconRowActions from "../../components/common/CrmIconRowActions.vue";
import BillingCustomBillCreateDrawer from "../../components/billing/BillingCustomBillCreateDrawer.vue";
import { useToast } from "../../composables/useToast.js";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { crmIsAdmin } from "../../utils/crmUser.js";
import { formatCents } from "../../utils/formatMoney.js";
import { formatIsoDate } from "../../utils/formatUserDates.js";
import { DEFAULT_PER_PAGE } from "../../constants/pagination.js";

const crmUser = inject("crmUser", ref(null));
const toast = useToast();
const router = useRouter();

function userHasPerm(key) {
  const u = crmUser.value;
  if (!u) return false;
  if (crmIsAdmin(u) || u.is_crm_owner) return true;
  return Array.isArray(u.permission_keys) && u.permission_keys.includes(key);
}

const canCreate = computed(() => userHasPerm("billing.create"));
const canUpdate = computed(() => userHasPerm("billing.update"));
const canDelete = computed(() => userHasPerm("billing.delete"));

const loading = ref(true);
const rows = ref([]);
const pagination = ref({ current_page: 1, last_page: 1, total: 0, per_page: DEFAULT_PER_PAGE });
const clientAccounts = ref([]);
const createDrawerOpen = ref(false);
const filterMenuOpen = ref(false);
const manageOpenId = ref(null);
const manageMenuPos = ref({ top: 0, left: 0 });
const deleteTarget = ref(null);
const deleteModalOpen = ref(false);
const deleteBusy = ref(false);

const query = reactive({
  search: "",
  status: "all",
  client_account_id: "",
  date_from: "",
  date_to: "",
  per_page: DEFAULT_PER_PAGE,
  page: 1,
  sort_by: "id",
  sort_dir: "desc",
});

let searchDebounce = null;
watch(
  () => query.search,
  () => {
    clearTimeout(searchDebounce);
    searchDebounce = setTimeout(() => {
      query.page = 1;
      fetchRows();
    }, 280);
  },
);

watch(
  () => [query.status, query.client_account_id, query.date_from, query.date_to],
  () => {
    query.page = 1;
    fetchRows();
  },
);

async function fetchMeta() {
  const { data } = await api.get("/invoices/meta");
  clientAccounts.value = Array.isArray(data?.client_accounts) ? data.client_accounts : [];
}

async function fetchRows() {
  loading.value = true;
  try {
    const { data } = await api.get("/custom-bills", {
      params: {
        search: query.search || undefined,
        status: query.status !== "all" ? query.status : undefined,
        client_account_id: query.client_account_id || undefined,
        date_from: query.date_from || undefined,
        date_to: query.date_to || undefined,
        per_page: query.per_page,
        page: query.page,
        sort_by: query.sort_by,
        sort_dir: query.sort_dir,
      },
    });
    rows.value = Array.isArray(data?.data) ? data.data : [];
    pagination.value = {
      current_page: data?.current_page ?? 1,
      last_page: data?.last_page ?? 1,
      total: data?.total ?? 0,
      per_page: data?.per_page ?? query.per_page,
    };
  } catch (e) {
    toast.errorFrom(e, "Could not load custom bills.");
    rows.value = [];
  } finally {
    loading.value = false;
  }
}

function statusBadgeClass(status) {
  return status === "invoiced" ? "bg-success-subtle text-success" : "bg-warning-subtle text-warning";
}

function openManageMenu(row, ev) {
  const btn = ev?.currentTarget;
  if (!btn || !(btn instanceof HTMLElement)) return;
  const rect = btn.getBoundingClientRect();
  manageMenuPos.value = { top: rect.bottom + 4, left: Math.max(8, rect.right - 180) };
  manageOpenId.value = row.id;
}

function closeManageMenu() {
  manageOpenId.value = null;
}

function onDocClick(e) {
  if (!e.target?.closest?.("[data-custom-bill-manage]")) {
    closeManageMenu();
  }
  if (!e.target?.closest?.("[data-toolbar-filter]")) {
    filterMenuOpen.value = false;
  }
}

function openDeleteModal(row) {
  closeManageMenu();
  deleteTarget.value = row;
  deleteModalOpen.value = true;
}

async function confirmDelete() {
  if (!deleteTarget.value) return;
  deleteBusy.value = true;
  try {
    await api.delete(`/custom-bills/${deleteTarget.value.id}`);
    toast.success("Custom bill deleted.");
    deleteModalOpen.value = false;
    deleteTarget.value = null;
    await fetchRows();
  } catch (e) {
    toast.errorFrom(e, "Could not delete custom bill.");
  } finally {
    deleteBusy.value = false;
  }
}

function onCreated(data) {
  createDrawerOpen.value = false;
  if (data?.id) {
    router.push(`/admin/billing/custom-bills/${data.id}`);
  } else {
    fetchRows();
  }
}

function goToPage(page) {
  query.page = page;
  fetchRows();
}

onMounted(async () => {
  document.addEventListener("click", onDocClick);
  setCrmPageMeta({
    title: "Save Rack | Custom Bills",
    description: "Custom bills for client accounts.",
  });
  try {
    await fetchMeta();
  } catch {
    /* optional */
  }
  await fetchRows();
});

onUnmounted(() => {
  document.removeEventListener("click", onDocClick);
  clearTimeout(searchDebounce);
});
</script>

<template>
  <div class="staff-page staff-page--wide">
    <div
      class="d-flex flex-column flex-md-row align-items-start align-items-md-center gap-3 mb-4"
    >
      <div class="min-w-0 flex-grow-1">
        <h1 class="h4 mb-1 fw-semibold text-body">Custom Bills</h1>
        <p class="text-secondary small mb-0">
          Create bills and add their lines to draft invoices when ready.
        </p>
      </div>
      <div v-if="canCreate" class="ms-md-auto">
        <button
          type="button"
          class="btn btn-primary staff-page-primary"
          @click="createDrawerOpen = true"
        >
          Create Bill
        </button>
      </div>
    </div>

    <div class="staff-table-card staff-datatable-card staff-datatable-card--white">
      <div class="staff-table-toolbar">
        <div class="staff-table-toolbar--row">
          <input
            v-model="query.search"
            type="search"
            class="form-control staff-toolbar-search staff-toolbar-search--inline"
            placeholder="Search bill # or account"
            autocomplete="off"
          />
          <div class="position-relative flex-shrink-0" data-toolbar-filter>
            <button
              type="button"
              class="btn btn-outline-secondary staff-toolbar-btn d-inline-flex align-items-center gap-2"
              @click.stop="filterMenuOpen = !filterMenuOpen"
            >
              Filters
            </button>
            <div
              v-if="filterMenuOpen"
              class="dropdown-menu dropdown-menu-end show shadow border p-0 staff-toolbar-filter-dropdown"
              @click.stop
            >
              <div class="staff-toolbar-filter-dropdown__head">
                <span>Filters</span>
                <button
                  type="button"
                  class="btn btn-link btn-sm text-secondary text-decoration-none p-0"
                  @click="
                    query.status = 'all';
                    query.client_account_id = '';
                    query.date_from = '';
                    query.date_to = '';
                    filterMenuOpen = false;
                  "
                >
                  Reset
                </button>
              </div>
              <div class="staff-toolbar-filter-dropdown__body">
                <label class="form-label">Status</label>
                <select v-model="query.status" class="form-select mb-3">
                  <option value="all">All</option>
                  <option value="open">Open</option>
                  <option value="invoiced">Invoiced</option>
                </select>
                <label class="form-label">Account</label>
                <CrmSearchableSelect
                  v-model="query.client_account_id"
                  appearance="staff"
                  :options="clientAccounts"
                  placeholder="All accounts"
                  search-placeholder="Search accounts…"
                  empty-label="All accounts"
                />
                <label class="form-label mt-3">Date from</label>
                <input v-model="query.date_from" type="date" class="form-control mb-3" />
                <label class="form-label">Date to</label>
                <input v-model="query.date_to" type="date" class="form-control" />
              </div>
            </div>
          </div>
        </div>
      </div>

      <div v-if="loading" class="d-flex justify-content-center py-5">
        <CrmLoadingSpinner message="Loading custom bills…" />
      </div>
      <div v-else class="table-responsive">
        <table class="table table-hover staff-table mb-0">
          <thead class="staff-table-head">
            <tr>
              <th scope="col">Status</th>
              <th scope="col">Bill #</th>
              <th scope="col">Account</th>
              <th scope="col">Date</th>
              <th scope="col" class="text-end">Price</th>
              <th scope="col" class="text-end">Action</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="!rows.length">
              <td colspan="6" class="text-center text-secondary py-5">No custom bills found.</td>
            </tr>
            <tr v-for="row in rows" :key="row.id">
              <td>
                <span class="badge rounded-pill fw-medium" :class="statusBadgeClass(row.status)">
                  {{ row.status_label }}
                </span>
              </td>
              <td>
                <RouterLink
                  :to="`/admin/billing/custom-bills/${row.id}`"
                  class="fw-semibold text-decoration-none"
                >
                  {{ row.bill_number }}
                </RouterLink>
              </td>
              <td>{{ row.client_account_name || "—" }}</td>
              <td>{{ formatIsoDate(row.bill_date) }}</td>
              <td class="text-end">{{ formatCents(row.total_cents) }}</td>
              <td class="text-end" data-custom-bill-manage>
                <CrmIconRowActions
                  variant="horizontal"
                  @click="openManageMenu(row, $event)"
                />
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div
        v-if="!loading && pagination.last_page > 1"
        class="d-flex justify-content-center gap-2 py-3 border-top"
      >
        <button
          type="button"
          class="btn btn-sm btn-outline-secondary"
          :disabled="pagination.current_page <= 1"
          @click="goToPage(pagination.current_page - 1)"
        >
          Previous
        </button>
        <span class="small text-secondary align-self-center">
          Page {{ pagination.current_page }} of {{ pagination.last_page }}
        </span>
        <button
          type="button"
          class="btn btn-sm btn-outline-secondary"
          :disabled="pagination.current_page >= pagination.last_page"
          @click="goToPage(pagination.current_page + 1)"
        >
          Next
        </button>
      </div>
    </div>

    <Teleport to="body">
      <div
        v-if="manageOpenId"
        class="staff-row-menu dropdown-menu show shadow"
        :style="{ position: 'fixed', top: `${manageMenuPos.top}px`, left: `${manageMenuPos.left}px` }"
        data-custom-bill-manage
      >
        <RouterLink
          :to="`/admin/billing/custom-bills/${manageOpenId}`"
          class="dropdown-item"
          @click="closeManageMenu"
        >
          View
        </RouterLink>
        <button
          v-if="canDelete && rows.find((r) => r.id === manageOpenId)?.status === 'open'"
          type="button"
          class="dropdown-item text-danger"
          @click="openDeleteModal(rows.find((r) => r.id === manageOpenId))"
        >
          Delete
        </button>
      </div>
    </Teleport>

    <BillingCustomBillCreateDrawer
      v-model:open="createDrawerOpen"
      :client-accounts="clientAccounts"
      @created="onCreated"
    />

    <ConfirmModal
      v-model:open="deleteModalOpen"
      title="Delete Custom Bill"
      :message="deleteTarget ? `Delete bill #${deleteTarget.bill_number}? This cannot be undone.` : ''"
      confirm-label="Delete"
      variant="danger"
      :busy="deleteBusy"
      @confirm="confirmDelete"
    />
  </div>
</template>
