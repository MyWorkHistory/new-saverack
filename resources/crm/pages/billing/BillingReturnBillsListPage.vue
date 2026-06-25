<script setup>
import { computed, inject, onMounted, reactive, ref, watch } from "vue";
import { useRouter } from "vue-router";
import api from "../../services/api";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import CrmSearchableSelect from "../../components/common/CrmSearchableSelect.vue";
import CrmIconRowActions from "../../components/common/CrmIconRowActions.vue";
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

const canUpdate = computed(() => userHasPerm("billing.update"));

const loading = ref(true);
const rows = ref([]);
const pagination = ref({ current_page: 1, last_page: 1, total: 0, per_page: DEFAULT_PER_PAGE });
const clientAccounts = ref([]);
const manageOpenId = ref(null);
const manageMenuRect = ref({ top: 0, left: 0 });
const MENU_W = 160;
const MENU_H = 96;

const manageMenuRow = computed(() => rows.value.find((r) => r.id === manageOpenId.value) ?? null);

const showingFrom = computed(() => {
  if (!pagination.value.total) return 0;
  return (pagination.value.current_page - 1) * pagination.value.per_page + 1;
});

const showingTo = computed(() =>
  Math.min(pagination.value.current_page * pagination.value.per_page, pagination.value.total),
);

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
    const { data } = await api.get("/return-bills", {
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
    toast.errorFrom(e, "Could not load return bills.");
    rows.value = [];
  } finally {
    loading.value = false;
  }
}

function statusBadgeClass(status) {
  return status === "invoiced" ? "bg-success-subtle text-success" : "bg-warning-subtle text-warning";
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

function toggleManageMenu(rowId, event) {
  if (manageOpenId.value === rowId) {
    closeManageMenu();
    return;
  }
  manageOpenId.value = rowId;
  placeManageMenu(event?.currentTarget);
}

function goToBillDetail(row) {
  closeManageMenu();
  router.push(`/admin/billing/return-bills/${row.id}`);
}

function goToPage(page) {
  query.page = page;
  fetchRows();
}

function onDocClick(e) {
  if (!e.target?.closest?.("[data-row-actions]")) {
    closeManageMenu();
  }
}

onMounted(() => {
  setCrmPageMeta({
    title: "Save Rack | Returns Bills",
    description: "Return processing bills for client accounts.",
  });
  document.addEventListener("click", onDocClick);
  fetchMeta();
  fetchRows();
});
</script>

<template>
  <div class="staff-page staff-page--wide billing-return-bills-list">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
      <div>
        <h1 class="h4 mb-1 fw-semibold text-body">Returns Bills</h1>
        <p class="text-secondary small mb-0">Bills created when returns are processed.</p>
      </div>
    </div>

    <div class="staff-table-card staff-datatable-card staff-datatable-card--white p-0 mb-4">
      <div class="px-4 py-3 border-bottom d-flex flex-wrap gap-3 align-items-end">
        <div class="flex-grow-1" style="min-width: 12rem">
          <label class="form-label small text-secondary mb-1" for="rb-search">Search</label>
          <input
            id="rb-search"
            v-model="query.search"
            type="search"
            class="form-control form-control-sm"
            placeholder="Bill #, account, RMA, order…"
          />
        </div>
        <div style="min-width: 9rem">
          <label class="form-label small text-secondary mb-1" for="rb-status">Status</label>
          <select id="rb-status" v-model="query.status" class="form-select form-select-sm">
            <option value="all">All</option>
            <option value="open">Open</option>
            <option value="invoiced">Invoiced</option>
          </select>
        </div>
        <div style="min-width: 14rem">
          <label class="form-label small text-secondary mb-1">Account</label>
          <CrmSearchableSelect
            v-model="query.client_account_id"
            :options="clientAccounts"
            value-key="id"
            label-key="company_name"
            placeholder="All accounts"
            clearable
          />
        </div>
      </div>

      <div class="table-responsive staff-table-wrap">
        <table class="table table-hover align-middle mb-0 staff-data-table">
          <thead class="table-light staff-table-head">
            <tr>
              <th class="staff-table-head__th" scope="col">Status</th>
              <th class="staff-table-head__th" scope="col">Bill #</th>
              <th class="staff-table-head__th" scope="col">Account</th>
              <th class="staff-table-head__th" scope="col">RMA</th>
              <th class="staff-table-head__th" scope="col">Date</th>
              <th class="staff-table-head__th text-end" scope="col">Total</th>
              <th class="staff-table-head__th text-center" scope="col">Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="loading">
              <td colspan="7" class="py-5">
                <div class="d-flex justify-content-center py-3">
                  <CrmLoadingSpinner message="Loading return bills…" />
                </div>
              </td>
            </tr>
            <tr v-for="row in rows" v-else :key="row.id" class="align-middle">
              <td>
                <span class="badge rounded-pill fw-medium" :class="statusBadgeClass(row.status)">
                  {{ row.status_label }}
                </span>
              </td>
              <td class="fw-medium text-body">
                <RouterLink
                  :to="`/admin/billing/return-bills/${row.id}`"
                  class="text-decoration-none text-body"
                >
                  {{ row.bill_number }}
                </RouterLink>
              </td>
              <td class="text-secondary">{{ row.client_account_name || "—" }}</td>
              <td class="text-secondary">{{ row.rma_number || "—" }}</td>
              <td class="text-nowrap">{{ formatIsoDate(row.bill_date) }}</td>
              <td class="text-end">{{ formatCents(row.total_cents) }}</td>
              <td class="text-center">
                <div data-row-actions class="staff-actions-inner staff-actions-inner--single d-inline-flex">
                  <button
                    type="button"
                    class="staff-action-btn staff-action-btn--more"
                    :class="{ 'is-open': manageOpenId === row.id }"
                    aria-label="Row actions"
                    @click="toggleManageMenu(row.id, $event)"
                  >
                    <CrmIconRowActions variant="horizontal" />
                  </button>
                </div>
              </td>
            </tr>
            <tr v-if="!loading && !rows.length">
              <td colspan="7" class="px-4 py-5 text-center text-secondary">No return bills found.</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div
        v-if="!loading && pagination.total > 0"
        class="staff-table-footer card-footer d-flex flex-wrap justify-content-between gap-3"
      >
        <span class="small text-secondary">
          Showing {{ showingFrom }}–{{ showingTo }} of {{ pagination.total }}
        </span>
        <div v-if="pagination.last_page > 1" class="d-flex align-items-center gap-2">
          <button
            type="button"
            class="btn btn-sm btn-outline-secondary"
            :disabled="pagination.current_page <= 1"
            @click="goToPage(pagination.current_page - 1)"
          >
            Previous
          </button>
          <span class="small text-secondary">
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
    </div>

    <Teleport to="body">
      <div
        v-if="manageMenuRow"
        data-row-actions
        class="staff-row-menu fixed z-[300] overflow-hidden"
        role="menu"
        :style="{ top: `${manageMenuRect.top}px`, left: `${manageMenuRect.left}px` }"
        @click.stop
      >
        <button type="button" class="staff-row-menu__item" role="menuitem" @click="goToBillDetail(manageMenuRow)">
          View
        </button>
        <RouterLink
          v-if="manageMenuRow.status === 'invoiced' && manageMenuRow.invoice_id"
          :to="`/admin/billing/invoices/${manageMenuRow.invoice_id}`"
          class="staff-row-menu__item text-decoration-none text-body"
          role="menuitem"
          @click="closeManageMenu"
        >
          View Invoice
        </RouterLink>
      </div>
    </Teleport>
  </div>
</template>
