<script setup>
import { computed, onMounted, ref } from "vue";
import { useRouter } from "vue-router";
import api from "../../services/api";
import CrmSearchableSelect from "../../components/common/CrmSearchableSelect.vue";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast.js";
import { formatDateUs } from "../../utils/formatUserDates.js";

const toast = useToast();
const router = useRouter();

const accounts = ref([]);
const accountsLoading = ref(false);
const accountFilter = ref("");
const orderNumber = ref("");
const rmaNumber = ref("");
const searching = ref(false);
const hasSearched = ref(false);
const results = ref([]);

const tableColspan = 6;

const accountOptions = computed(() =>
  (accounts.value || [])
    .filter((a) => a?.has_shiphero_customer !== false)
    .map((a) => ({
      id: a.id,
      name: a.company_name || a.label || `Account #${a.id}`,
      email: a.email ? String(a.email) : "",
    })),
);

const canSearch = computed(
  () => orderNumber.value.trim() !== "" || rmaNumber.value.trim() !== "",
);

function normalizeOrderNumber(raw) {
  return String(raw || "")
    .trim()
    .replace(/^#+/, "")
    .toLowerCase();
}

function normalizeRmaNumber(raw) {
  let s = String(raw || "")
    .trim()
    .replace(/^#+/, "")
    .toLowerCase();
  if (s.startsWith("rma")) {
    s = s.slice(3).trim().replace(/^#+/, "");
  }
  return s;
}

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
    toast.errorFrom(e, "Could not load account list.");
    accounts.value = [];
  } finally {
    accountsLoading.value = false;
  }
}

function openReturn(row) {
  router.push({
    name: "admin-process-return-detail",
    params: { id: String(row.id) },
  });
}

async function search() {
  const order = normalizeOrderNumber(orderNumber.value);
  const rma = normalizeRmaNumber(rmaNumber.value);
  if (!order && !rma) {
    toast.error("Enter an order number or RMA number.");
    return;
  }

  searching.value = true;
  hasSearched.value = true;
  results.value = [];

  const params = {};
  if (orderNumber.value.trim()) params.order_number = orderNumber.value.trim().replace(/^#+/, "");
  if (rmaNumber.value.trim()) params.rma_number = rmaNumber.value.trim();
  if (accountFilter.value) params.client_account_id = Number(accountFilter.value);

  try {
    const { data } = await api.get("/admin/returns/process-lookup", { params });
    const rows = Array.isArray(data?.data) ? data.data : [];
    results.value = rows;
    if (rows.length === 1) {
      openReturn(rows[0]);
    }
  } catch (e) {
    toast.errorFrom(e, "Could not search returns.");
  } finally {
    searching.value = false;
  }
}

onMounted(() => {
  setCrmPageMeta({
    title: "Save Rack | Process Returns",
    description: "Find pending returns to process.",
  });
  loadAccounts();
});
</script>

<template>
  <div class="staff-page staff-page--wide admin-returns-page">
    <div class="d-flex flex-wrap align-items-end justify-content-between gap-3 mb-4">
      <div>
        <h1 class="h4 mb-1 fw-semibold text-body">Process Returns</h1>
        <p class="small admin-returns-list__subtitle mb-0">
          Find a pending return by order number or RMA number. Account is optional and helps narrow the search.
        </p>
      </div>
    </div>

    <div
      class="admin-returns-list admin-returns-page-toolbar staff-table-card staff-datatable-card staff-datatable-card--white w-100"
    >
      <div class="staff-table-toolbar">
        <div class="staff-table-toolbar--row admin-returns-toolbar-row">
          <div class="admin-returns-toolbar-account">
            <CrmSearchableSelect
              v-model="accountFilter"
              class="staff-toolbar-search staff-toolbar-search--inline w-100"
              appearance="staff"
              aria-label="Client account"
              :options="accountOptions"
              :disabled="accountsLoading || searching"
              placeholder="All accounts"
              empty-label="All accounts"
              search-placeholder="Search accounts…"
              :allow-empty="true"
            />
          </div>
          <div class="admin-returns-toolbar-order">
            <input
              id="admin-process-return-order"
              v-model="orderNumber"
              type="search"
              class="form-control staff-toolbar-search staff-toolbar-search--inline w-100"
              placeholder="Order #"
              autocomplete="off"
              aria-label="Order number"
              @keydown.enter.prevent="search"
            />
          </div>
          <div class="admin-returns-toolbar-rma">
            <input
              id="admin-process-return-rma"
              v-model="rmaNumber"
              type="search"
              class="form-control staff-toolbar-search staff-toolbar-search--inline w-100"
              placeholder="RMA #"
              autocomplete="off"
              aria-label="RMA number"
              @keydown.enter.prevent="search"
            />
          </div>
          <div class="admin-returns-toolbar-action">
            <button
              type="button"
              class="btn btn-primary staff-page-primary fw-semibold"
              :disabled="searching || !canSearch"
              @click="search"
            >
              Find Return
            </button>
          </div>
        </div>
      </div>

      <div class="table-responsive staff-table-wrap">
        <table class="table table-hover align-middle mb-0 staff-data-table">
          <thead class="table-light staff-table-head">
            <tr>
              <th class="staff-table-head__th text-center" scope="col">RMA #</th>
              <th class="staff-table-head__th text-center" scope="col">Order #</th>
              <th class="staff-table-head__th text-center" scope="col">Account</th>
              <th class="staff-table-head__th text-center" scope="col">Customer</th>
              <th class="staff-table-head__th text-center" scope="col">Items</th>
              <th class="staff-table-head__th text-center" scope="col">Created</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="searching">
              <td :colspan="tableColspan" class="py-5">
                <div class="d-flex justify-content-center py-3">
                  <CrmLoadingSpinner message="Searching returns…" />
                </div>
              </td>
            </tr>
            <tr v-else-if="!results.length">
              <td :colspan="tableColspan" class="text-center text-secondary py-5">
                <template v-if="hasSearched">No pending return found.</template>
                <template v-else>Enter an order number or RMA number and select Find Return.</template>
              </td>
            </tr>
            <tr
              v-for="row in results"
              v-else
              :key="row.id"
              class="align-middle admin-returns-result-row"
              role="button"
              tabindex="0"
              @click="openReturn(row)"
              @keydown.enter.prevent="openReturn(row)"
            >
              <td class="text-center fw-semibold">{{ row.rma_number || "—" }}</td>
              <td class="text-center">{{ row.order_number || "—" }}</td>
              <td class="text-center">{{ row.client_account_company_name || "—" }}</td>
              <td class="text-center">{{ row.customer_name || "—" }}</td>
              <td class="text-center">{{ row.items_count ?? "—" }}</td>
              <td class="text-center small text-secondary">{{ formatDateUs(row.created_at) || "—" }}</td>
            </tr>
          </tbody>
        </table>
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

[data-bs-theme="dark"] .admin-returns-list__subtitle {
  color: #fff !important;
}

.admin-returns-page-toolbar .admin-returns-toolbar-row {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem;
}

.admin-returns-toolbar-account {
  flex: 0 0 auto;
  width: min(280px, 100%);
}

.admin-returns-toolbar-order,
.admin-returns-toolbar-rma {
  flex: 0 0 auto;
  width: min(14rem, 100%);
}

.admin-returns-toolbar-action {
  flex: 0 0 auto;
}

.admin-returns-result-row {
  cursor: pointer;
}
</style>
