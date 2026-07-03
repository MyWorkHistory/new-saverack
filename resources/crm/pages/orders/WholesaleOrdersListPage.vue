<script setup>
import { computed, onMounted, ref } from "vue";
import { useRouter } from "vue-router";
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
  loadAccounts();
  loadList();
});
</script>

<template>
  <div class="staff-page staff-page--wide">
    <div class="d-flex flex-wrap align-items-end justify-content-between gap-3 mb-4">
      <div>
        <h1 class="h4 mb-1 fw-semibold text-body">Wholesale</h1>
        <p class="small text-secondary mb-0">Wholesale fulfillment orders by account.</p>
      </div>
      <button
        type="button"
        class="btn btn-primary staff-page-primary fw-semibold"
        @click="openCreate"
      >
        Create Order
      </button>
    </div>

    <div
      class="staff-table-card staff-datatable-card staff-datatable-card--white w-100 wholesale-orders-toolbar"
    >
      <div class="staff-table-toolbar">
        <div class="staff-table-toolbar--row wholesale-orders-toolbar-row">
          <div class="wholesale-orders-toolbar-account">
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
          <div class="wholesale-orders-toolbar-order">
            <input
              v-model="orderNumber"
              type="search"
              class="form-control staff-toolbar-search staff-toolbar-search--inline w-100"
              placeholder="Order #"
              autocomplete="off"
              aria-label="Order number"
              @keydown.enter.prevent="loadList"
            />
          </div>
          <div class="wholesale-orders-toolbar-status">
            <select
              v-model="statusFilter"
              class="form-select staff-toolbar-search staff-toolbar-search--inline w-100"
              aria-label="Status"
            >
              <option v-for="opt in WHOLESALE_STATUS_OPTIONS" :key="opt.value || 'all'" :value="opt.value">
                {{ opt.label }}
              </option>
            </select>
          </div>
          <div class="wholesale-orders-toolbar-type">
            <select
              v-model="typeFilter"
              class="form-select staff-toolbar-search staff-toolbar-search--inline w-100"
              aria-label="Type"
            >
              <option v-for="opt in WHOLESALE_TYPE_OPTIONS" :key="opt.value || 'all-types'" :value="opt.value">
                {{ opt.label }}
              </option>
            </select>
          </div>
          <div class="wholesale-orders-toolbar-action">
            <button
              type="button"
              class="btn btn-primary staff-page-primary fw-semibold"
              :disabled="loading"
              @click="loadList"
            >
              Search
            </button>
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
.wholesale-orders-toolbar .wholesale-orders-toolbar-row {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem;
}

.wholesale-orders-toolbar-account {
  flex: 0 0 auto;
  width: min(280px, 100%);
}

.wholesale-orders-toolbar-order,
.wholesale-orders-toolbar-status,
.wholesale-orders-toolbar-type {
  flex: 0 0 auto;
  width: min(12rem, 100%);
}

.wholesale-orders-toolbar-action {
  flex: 0 0 auto;
}

.wholesale-orders-result-row {
  cursor: pointer;
}
</style>
