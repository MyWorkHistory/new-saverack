<script setup>
import { computed, onMounted, ref, watch } from "vue";
import { useRouter } from "vue-router";
import api from "../../services/api";
import CrmSearchableSelect from "../../components/common/CrmSearchableSelect.vue";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast.js";
import { formatDateUs } from "../../utils/formatUserDates.js";
import {
  processDisplayStatusBadgeClass,
  processDisplayStatusLabel,
  returnTypeLabel,
} from "../../utils/formatReturnDisplay.js";

const toast = useToast();
const router = useRouter();

const loading = ref(true);
const rows = ref([]);
const meta = ref({ current_page: 1, last_page: 1, per_page: 25, total: 0 });
const search = ref("");
const searchDebounced = ref("");
const accountFilter = ref("");
const accounts = ref([]);
const accountsLoading = ref(false);
const sortBy = ref("processed_at");
const sortDir = ref("desc");
let searchTimer = null;

const tableColspan = 9;

const accountOptions = computed(() =>
  (accounts.value || []).map((a) => ({
    id: a.id,
    name: a.company_name || a.label || `Account #${a.id}`,
    email: a.email ? String(a.email) : "",
  })),
);

watch(search, (v) => {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => {
    searchDebounced.value = String(v).trim().replace(/^#+/, "");
    meta.value.current_page = 1;
    load();
  }, 300);
});

watch(accountFilter, () => {
  meta.value.current_page = 1;
  load();
});

function sortIndicator(column) {
  if (sortBy.value !== column) return "";
  return sortDir.value === "asc" ? "↑" : "↓";
}

function toggleSort(column) {
  if (sortBy.value !== column) {
    sortBy.value = column;
    sortDir.value = "asc";
  } else {
    sortDir.value = sortDir.value === "asc" ? "desc" : "asc";
  }
  meta.value.current_page = 1;
  load();
}

async function loadAccounts() {
  accountsLoading.value = true;
  try {
    const { data } = await api.get("/inventory/client-account-options");
    accounts.value = Array.isArray(data?.accounts) ? data.accounts : [];
  } catch {
    accounts.value = [];
  } finally {
    accountsLoading.value = false;
  }
}

async function load() {
  loading.value = true;
  try {
    const params = {
      q: searchDebounced.value || undefined,
      page: meta.value.current_page,
      per_page: meta.value.per_page,
      sort_by: sortBy.value,
      sort_dir: sortDir.value,
    };
    if (accountFilter.value) params.client_account_id = Number(accountFilter.value);
    const { data } = await api.get("/admin/returns/orders", { params });
    rows.value = Array.isArray(data?.data) ? data.data : [];
    if (data?.meta) meta.value = { ...meta.value, ...data.meta };
  } catch (e) {
    toast.errorFrom(e, "Could not load returned orders.");
    rows.value = [];
  } finally {
    loading.value = false;
  }
}

function openRow(row) {
  if (!row?.id) return;
  router.push({ name: "admin-process-return-detail", params: { id: String(row.id) } });
}

function goPage(page) {
  meta.value.current_page = page;
  load();
}

onMounted(() => {
  setCrmPageMeta({
    title: "Save Rack | Returned Orders",
    description: "Processed returns by order.",
  });
  loadAccounts();
  load();
});
</script>

<template>
  <div class="staff-page staff-page--wide admin-returns-page">
    <div class="d-flex flex-wrap align-items-end justify-content-between gap-3 mb-4">
      <div>
        <h1 class="h4 mb-1 fw-semibold text-body">Returned Orders</h1>
        <p class="small admin-returns-list__subtitle mb-0">
          Orders that have been processed at the warehouse.
        </p>
      </div>
    </div>

    <div class="admin-returns-list staff-table-card staff-datatable-card staff-datatable-card--white w-100">
      <div class="staff-table-toolbar">
        <div class="staff-table-toolbar--row admin-returns-toolbar-row">
          <div class="admin-returns-toolbar-account">
            <CrmSearchableSelect
              v-model="accountFilter"
              class="staff-toolbar-search staff-toolbar-search--inline w-100"
              appearance="staff"
              aria-label="Client account"
              :options="accountOptions"
              :disabled="accountsLoading || loading"
              placeholder="All accounts"
              empty-label="All accounts"
              search-placeholder="Search accounts…"
              :allow-empty="true"
            />
          </div>
          <input
            v-model="search"
            type="search"
            class="form-control staff-toolbar-search staff-toolbar-search--inline"
            placeholder="Search RMA, order, or customer"
            aria-label="Search returned orders"
            autocomplete="off"
          />
        </div>
      </div>

      <div class="table-responsive staff-table-wrap">
        <table class="table table-hover align-middle mb-0 staff-data-table">
          <thead class="table-light staff-table-head">
            <tr>
              <th class="staff-table-head__th text-center staff-table-head__th--sortable" scope="col" @click="toggleSort('rma_number')">
                RMA # {{ sortIndicator("rma_number") }}
              </th>
              <th class="staff-table-head__th text-center staff-table-head__th--sortable" scope="col" @click="toggleSort('order_number')">
                Order # {{ sortIndicator("order_number") }}
              </th>
              <th class="staff-table-head__th text-center" scope="col">Company</th>
              <th class="staff-table-head__th text-center staff-table-head__th--sortable" scope="col" @click="toggleSort('customer_name')">
                Customer {{ sortIndicator("customer_name") }}
              </th>
              <th class="staff-table-head__th text-center" scope="col">Type</th>
              <th class="staff-table-head__th text-center staff-table-head__th--sortable" scope="col" @click="toggleSort('items_count')">
                Items {{ sortIndicator("items_count") }}
              </th>
              <th class="staff-table-head__th text-center" scope="col">Status</th>
              <th class="staff-table-head__th text-center staff-table-head__th--sortable" scope="col" @click="toggleSort('processed_at')">
                Date Processed {{ sortIndicator("processed_at") }}
              </th>
              <th class="staff-table-head__th text-center" scope="col">Processed By</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="loading">
              <td :colspan="tableColspan" class="py-5">
                <div class="d-flex justify-content-center py-3">
                  <CrmLoadingSpinner message="Loading returned orders…" />
                </div>
              </td>
            </tr>
            <tr v-else-if="!rows.length">
              <td :colspan="tableColspan" class="text-center text-secondary py-5">No returned orders found.</td>
            </tr>
            <tr
              v-for="row in rows"
              v-else
              :key="row.id"
              class="admin-returns-result-row"
              role="button"
              tabindex="0"
              @click="openRow(row)"
              @keydown.enter.prevent="openRow(row)"
            >
              <td class="text-center fw-semibold">{{ row.rma_number || "—" }}</td>
              <td class="text-center">{{ row.order_number || "—" }}</td>
              <td class="text-center">{{ row.client_account_company_name || "—" }}</td>
              <td class="text-center">{{ row.customer_name || "—" }}</td>
              <td class="text-center">{{ returnTypeLabel(row.return_type) }}</td>
              <td class="text-center">{{ row.items_count ?? "—" }}</td>
              <td class="text-center">
                <span class="badge rounded-pill fw-medium" :class="processDisplayStatusBadgeClass('returned')">
                  {{ processDisplayStatusLabel("returned") }}
                </span>
              </td>
              <td class="text-center small text-secondary">{{ formatDateUs(row.processed_at) || "—" }}</td>
              <td class="text-center">{{ row.processed_by_name || "—" }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div
        v-if="meta.last_page > 1"
        class="d-flex justify-content-center gap-2 py-3 border-top"
      >
        <button
          type="button"
          class="btn btn-outline-secondary btn-sm"
          :disabled="loading || meta.current_page <= 1"
          @click="goPage(meta.current_page - 1)"
        >
          Previous
        </button>
        <span class="small text-secondary align-self-center">
          Page {{ meta.current_page }} of {{ meta.last_page }}
        </span>
        <button
          type="button"
          class="btn btn-outline-secondary btn-sm"
          :disabled="loading || meta.current_page >= meta.last_page"
          @click="goPage(meta.current_page + 1)"
        >
          Next
        </button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.admin-returns-list__subtitle {
  font-size: 0.8125rem;
  font-weight: 500;
  color: var(--bs-secondary-color, #6c757d);
}
.admin-returns-toolbar-row {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem;
}
.admin-returns-toolbar-account {
  flex: 0 0 auto;
  width: min(280px, 100%);
}
.admin-returns-result-row {
  cursor: pointer;
}
</style>
