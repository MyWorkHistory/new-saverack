<script setup>
import { computed, onMounted, onUnmounted, ref } from "vue";
import { RouterLink, useRouter } from "vue-router";
import api from "../../services/api";
import CrmSearchableSelect from "../../components/common/CrmSearchableSelect.vue";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import WholesaleOrderCreateDrawer from "../../components/orders/WholesaleOrderCreateDrawer.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast.js";
import { formatDateUs } from "../../utils/formatUserDates.js";
import {
  WHOLESALE_STATUS_OPTIONS,
  WHOLESALE_TYPE_OPTIONS,
  wholesaleStatusBadgeClass,
  wholesaleStatusLabel,
  wholesaleTypeLabel,
} from "../../utils/formatWholesaleOrderDisplay.js";

const toast = useToast();
const router = useRouter();

const accounts = ref([]);
const accountsLoading = ref(false);
const accountFilter = ref("");
const orderNumber = ref("");
const statusFilter = ref("");
const typeFilter = ref("");
const loading = ref(true);
const results = ref([]);
const filterMenuOpen = ref(false);

const createOpen = ref(false);
const createBusy = ref(false);
const createAccountId = ref("");
const createOrderType = ref("");
const createOrderNumber = ref("");
const createInstructions = ref("");

const tableColspan = 7;

const accountOptions = computed(() =>
  (accounts.value || []).map((a) => ({
    id: a.id,
    name: a.company_name || a.label || `Account #${a.id}`,
    email: a.email ? String(a.email) : "",
  })),
);

const pickListRoute = computed(() => {
  const query = accountFilter.value ? { client_account_id: String(accountFilter.value) } : {};
  return { name: "wholesale-pick-list", query };
});

function onDocClickFilter(e) {
  if (!e.target?.closest?.("[data-toolbar-filter]")) {
    filterMenuOpen.value = false;
  }
}

function resetToolbarFilters() {
  statusFilter.value = "";
  typeFilter.value = "";
  filterMenuOpen.value = false;
  loadList();
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

async function loadList() {
  loading.value = true;
  try {
    const params = {};
    if (accountFilter.value) params.client_account_id = Number(accountFilter.value);
    if (orderNumber.value.trim()) params.q = orderNumber.value.trim();
    if (statusFilter.value) params.status = statusFilter.value;
    if (typeFilter.value) params.order_type = typeFilter.value;
    const { data } = await api.get("/admin/wholesale-orders", { params });
    results.value = Array.isArray(data?.data) ? data.data : [];
  } catch (e) {
    toast.errorFrom(e, "Could not load wholesale orders.");
    results.value = [];
  } finally {
    loading.value = false;
  }
}

function openRow(row) {
  if (!row?.id) return;
  router.push({ name: "wholesale-order-detail", params: { id: String(row.id) } });
}

function openCreate() {
  createAccountId.value = accountFilter.value || "";
  createOrderType.value = "";
  createOrderNumber.value = "";
  createInstructions.value = "";
  createOpen.value = true;
}

async function submitCreate() {
  const accountId = Number(createAccountId.value);
  if (!accountId) {
    toast.error("Select an account.");
    return;
  }
  if (!createOrderType.value) {
    toast.error("Select a type.");
    return;
  }
  const num = createOrderNumber.value.trim();
  if (!num) {
    toast.error("Enter an order number.");
    return;
  }
  createBusy.value = true;
  try {
    const { data } = await api.post("/admin/wholesale-orders", {
      client_account_id: accountId,
      order_type: createOrderType.value,
      order_number: num,
      instructions: createInstructions.value.trim() || null,
    });
    createOpen.value = false;
    toast.success("Wholesale order created.");
    if (data?.id) {
      router.push({ name: "wholesale-order-detail", params: { id: String(data.id) } });
    } else {
      await loadList();
    }
  } catch (e) {
    toast.errorFrom(e, "Could not create wholesale order.");
  } finally {
    createBusy.value = false;
  }
}

onMounted(() => {
  setCrmPageMeta({
    title: "Save Rack | Wholesale Orders",
    description: "Manage wholesale fulfillment orders.",
  });
  document.addEventListener("click", onDocClickFilter);
  loadAccounts();
  loadList();
});

onUnmounted(() => {
  document.removeEventListener("click", onDocClickFilter);
});
</script>

<template>
  <div class="staff-page staff-page--wide">
    <div class="d-flex flex-wrap align-items-end justify-content-between gap-3 mb-4">
      <div>
        <h1 class="h4 mb-1 fw-semibold text-body">Wholesale</h1>
        <p class="small text-secondary mb-0">Wholesale fulfillment orders by account.</p>
      </div>
      <div class="d-flex flex-wrap gap-2">
        <RouterLink
          :to="pickListRoute"
          class="btn btn-outline-secondary fw-semibold orders-toolbar-outline-btn"
        >
          Pick List
        </RouterLink>
        <button
          type="button"
          class="btn btn-primary staff-page-primary fw-semibold"
          @click="openCreate"
        >
          Create Order
        </button>
      </div>
    </div>

    <div
      class="staff-table-card staff-datatable-card staff-datatable-card--white w-100 wholesale-orders-page-toolbar"
    >
      <div class="staff-table-toolbar">
        <div class="staff-table-toolbar--row wholesale-orders-toolbar-row">
          <div class="wholesale-orders-toolbar-account flex-shrink-0">
            <CrmSearchableSelect
              v-model="accountFilter"
              class="staff-toolbar-search staff-toolbar-search--inline"
              appearance="staff"
              aria-label="Client account"
              :options="accountOptions"
              :disabled="accountsLoading || loading"
              placeholder="All accounts"
              empty-label="All accounts"
              search-placeholder="Search accounts…"
              :allow-empty="true"
              button-id="wholesale-orders-account-trigger"
            />
          </div>

          <div class="wholesale-orders-search-wrap flex-shrink-0">
            <div class="input-group orders-toolbar-search-group">
              <input
                id="wholesale-orders-order-search"
                v-model.trim="orderNumber"
                type="search"
                class="form-control"
                placeholder="Search by Order #"
                autocomplete="off"
                enterkeyhint="search"
                aria-label="Order number"
                :disabled="loading"
                @keydown.enter.prevent="loadList"
              />
              <button
                type="button"
                class="btn btn-primary staff-page-primary orders-toolbar-search-btn fw-semibold"
                :disabled="loading"
                @click="loadList"
              >
                Search
              </button>
            </div>
          </div>

          <div class="position-relative flex-shrink-0" data-toolbar-filter>
            <button
              type="button"
              class="btn btn-outline-secondary staff-toolbar-btn orders-toolbar-outline-btn d-inline-flex align-items-center gap-2"
              :aria-expanded="filterMenuOpen"
              :disabled="loading"
              @click.stop="filterMenuOpen = !filterMenuOpen"
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
              class="dropdown-menu show shadow border p-0 staff-toolbar-filter-dropdown"
              role="dialog"
              aria-label="Wholesale order filters"
              @click.stop
            >
              <div class="staff-toolbar-filter-dropdown__head">
                <span>Filters</span>
                <button
                  type="button"
                  class="btn btn-link btn-sm text-secondary text-decoration-none p-0"
                  @click="resetToolbarFilters"
                >
                  Reset
                </button>
              </div>
              <div class="staff-toolbar-filter-dropdown__body">
                <label class="form-label" for="wholesale-filter-status">Status</label>
                <select
                  id="wholesale-filter-status"
                  v-model="statusFilter"
                  class="form-select staff-datatable-filters__select mb-3"
                  :disabled="loading"
                  @change="loadList"
                >
                  <option
                    v-for="opt in WHOLESALE_STATUS_OPTIONS"
                    :key="opt.value || 'all-statuses'"
                    :value="opt.value"
                  >
                    {{ opt.label }}
                  </option>
                </select>
                <label class="form-label" for="wholesale-filter-type">Type</label>
                <select
                  id="wholesale-filter-type"
                  v-model="typeFilter"
                  class="form-select staff-datatable-filters__select"
                  :disabled="loading"
                  @change="loadList"
                >
                  <option
                    v-for="opt in WHOLESALE_TYPE_OPTIONS"
                    :key="opt.value || 'all-types'"
                    :value="opt.value"
                  >
                    {{ opt.label }}
                  </option>
                </select>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="table-responsive staff-table-wrap">
        <table class="table table-hover align-middle mb-0 staff-data-table">
          <thead class="table-light staff-table-head">
            <tr>
              <th class="staff-table-head__th text-center" scope="col">Status</th>
              <th class="staff-table-head__th text-center" scope="col">Order #</th>
              <th class="staff-table-head__th text-center" scope="col">Type</th>
              <th class="staff-table-head__th text-center" scope="col">Items</th>
              <th class="staff-table-head__th text-center" scope="col">Account</th>
              <th class="staff-table-head__th text-center" scope="col">Date</th>
              <th class="staff-table-head__th text-center" scope="col">Action</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="loading">
              <td :colspan="tableColspan" class="py-5">
                <div class="d-flex justify-content-center py-3">
                  <CrmLoadingSpinner message="Loading wholesale orders…" />
                </div>
              </td>
            </tr>
            <tr v-else-if="!results.length">
              <td :colspan="tableColspan" class="text-center text-secondary py-5">No wholesale orders found.</td>
            </tr>
            <tr
              v-for="row in results"
              v-else
              :key="row.id"
              class="align-middle wholesale-orders-result-row"
              role="button"
              tabindex="0"
              @click="openRow(row)"
              @keydown.enter.prevent="openRow(row)"
            >
              <td class="text-center">
                <span class="badge rounded-pill fw-medium" :class="wholesaleStatusBadgeClass(row.status)">
                  {{ row.status_label || wholesaleStatusLabel(row.status) }}
                </span>
              </td>
              <td class="text-center fw-semibold">{{ row.order_number || "—" }}</td>
              <td class="text-center">{{ row.order_type_label || wholesaleTypeLabel(row.order_type) }}</td>
              <td class="text-center">{{ row.items_count ?? "—" }}</td>
              <td class="text-center">{{ row.client_account_company_name || "—" }}</td>
              <td class="text-center small text-secondary">{{ formatDateUs(row.created_at) || "—" }}</td>
              <td class="text-center" @click.stop>
                <button
                  type="button"
                  class="btn btn-sm btn-outline-secondary fw-semibold orders-toolbar-outline-btn"
                  @click="openRow(row)"
                >
                  View
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <WholesaleOrderCreateDrawer
      v-model:open="createOpen"
      v-model:account-id="createAccountId"
      v-model:order-type="createOrderType"
      v-model:order-number="createOrderNumber"
      v-model:instructions="createInstructions"
      :account-options="accountOptions"
      :busy="createBusy"
      @submit="submitCreate"
    />
  </div>
</template>

<style scoped>
.wholesale-orders-page-toolbar .staff-table-toolbar--row.wholesale-orders-toolbar-row {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem;
}

.wholesale-orders-toolbar-account {
  flex: 0 0 auto;
  width: min(280px, 100%);
}

.wholesale-orders-search-wrap {
  flex: 0 0 auto;
  width: min(18rem, 100%);
}

.wholesale-orders-result-row {
  cursor: pointer;
}
</style>
