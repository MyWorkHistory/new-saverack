<script setup>
import { computed, onMounted, ref } from "vue";
import { useRouter } from "vue-router";
import api from "../../services/api";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import AdminReturnNonCompliantDrawer from "../../components/admin-returns/AdminReturnNonCompliantDrawer.vue";
import AdminReturnThirdPartyDrawer from "../../components/admin-returns/AdminReturnThirdPartyDrawer.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast.js";
import { formatDateUs } from "../../utils/formatUserDates.js";
import {
  listOrderNumberDisplay,
  processDisplayStatusBadgeClass,
  processDisplayStatusLabel,
} from "../../utils/formatReturnDisplay.js";

const toast = useToast();
const router = useRouter();

const accounts = ref([]);
const accountsLoading = ref(false);
const orderNumber = ref("");
const rmaNumber = ref("");
const loading = ref(true);
const searching = ref(false);
const searchMode = ref(false);
const results = ref([]);

const nonCompliantOpen = ref(false);
const nonCompliantBusy = ref(false);
const ncAccountId = ref("");
const ncDeclaredItems = ref(1);
const ncReason = ref("");
const ncReferenceNumber = ref("");

const thirdPartyOpen = ref(false);
const thirdPartyBusy = ref(false);
const tpAccountId = ref("");
const tpThirdPartyType = ref("");
const tpReferenceNumber = ref("");

const tableColspan = 7;

const accountOptions = computed(() =>
  (accounts.value || [])
    .filter((a) => a?.has_shiphero_customer !== false)
    .map((a) => ({
      id: a.id,
      name: a.company_name || a.label || `Account #${a.id}`,
      email: a.email ? String(a.email) : "",
    })),
);

const canSearch = computed(() => {
  return rmaNumber.value.trim() !== "" || orderNumber.value.trim() !== "";
});

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

async function loadPending() {
  loading.value = true;
  searchMode.value = false;
  try {
    const { data } = await api.get("/admin/returns/pending");
    results.value = Array.isArray(data?.data) ? data.data : [];
  } catch (e) {
    toast.errorFrom(e, "Could not load pending returns.");
    results.value = [];
  } finally {
    loading.value = false;
  }
}

function openRow(row) {
  const status = String(row.display_status || "").toLowerCase();
  if (status === "not_returned" && row.shiphero_order_id && row.client_account_id) {
    router.push({
      name: "admin-return-create",
      params: { shipheroOrderId: String(row.shiphero_order_id) },
      query: { client_account_id: String(row.client_account_id) },
    });
    return;
  }
  if (row.id) {
    router.push({
      name: "admin-process-return-detail",
      params: { id: String(row.id) },
    });
  }
}

async function search() {
  const order = orderNumber.value.trim();
  const rma = rmaNumber.value.trim();
  if (!rma && !order) {
    toast.error("Enter an order number or RMA number.");
    return;
  }

  searching.value = true;
  searchMode.value = true;
  results.value = [];

  try {
    if (rma) {
      const { data } = await api.get("/admin/returns/rma-lookup", {
        params: { rma_number: rma },
      });
      results.value = data?.data ? [data.data] : [];
      return;
    }

    const { data } = await api.get("/admin/returns/order-lookup", {
      params: {
        order_number: order.replace(/^#+/, ""),
      },
    });

    const ret = data?.return;
    const ord = data?.order || {};
    const accountId = Number(data?.client_account_id || 0);
    results.value = [
      {
        id: ret?.id ?? null,
        rma_number: ret?.rma_number ?? null,
        order_number: ord.order_number || order,
        client_account_id: accountId || ret?.client_account_id || null,
        client_account_company_name:
          ret?.client_account_company_name || data?.client_account_company_name || "—",
        customer_name: ret?.customer_name || ord.recipient_name || "—",
        items_count: ret?.items_count ?? null,
        display_status: data?.display_status || "not_returned",
        shiphero_order_id: ord.id || ret?.shiphero_order_id || null,
        created_at: ret?.created_at ?? null,
      },
    ];
  } catch (e) {
    toast.errorFrom(e, "Could not search.");
    results.value = [];
  } finally {
    searching.value = false;
  }
}

function clearSearch() {
  orderNumber.value = "";
  rmaNumber.value = "";
  loadPending();
}

function openNonCompliant() {
  ncAccountId.value = "";
  ncDeclaredItems.value = 1;
  ncReason.value = "";
  ncReferenceNumber.value = "";
  nonCompliantOpen.value = true;
}

async function submitNonCompliant() {
  const id = Number(ncAccountId.value);
  if (!id) {
    toast.error("Select an account.");
    return;
  }
  if (!ncReason.value) {
    toast.error("Select a reason.");
    return;
  }
  const items = Math.max(1, Number(ncDeclaredItems.value) || 1);
  nonCompliantBusy.value = true;
  try {
    const { data } = await api.post("/admin/returns/non-compliant", {
      client_account_id: id,
      declared_items: items,
      reason: ncReason.value,
      reference_number: String(ncReferenceNumber.value || "").trim() || null,
    });
    nonCompliantOpen.value = false;
    toast.success("Non-compliant return created.");
    if (data?.id) {
      router.push({
        name: "admin-process-return-detail",
        params: { id: String(data.id) },
      });
    } else {
      await loadPending();
    }
  } catch (e) {
    toast.errorFrom(e, "Could not create non-compliant return.");
  } finally {
    nonCompliantBusy.value = false;
  }
}

function openThirdParty() {
  tpAccountId.value = "";
  tpThirdPartyType.value = "";
  tpReferenceNumber.value = "";
  thirdPartyOpen.value = true;
}

async function submitThirdParty() {
  const id = Number(tpAccountId.value);
  if (!id) {
    toast.error("Select an account.");
    return;
  }
  if (!tpThirdPartyType.value) {
    toast.error("Select a 3rd party channel.");
    return;
  }
  thirdPartyBusy.value = true;
  try {
    const { data } = await api.post("/admin/returns/third-party", {
      client_account_id: id,
      third_party_type: tpThirdPartyType.value,
      reference_number: String(tpReferenceNumber.value || "").trim() || null,
    });
    thirdPartyOpen.value = false;
    toast.success("3rd party return created.");
    if (data?.id) {
      router.push({
        name: "admin-process-return-detail",
        params: { id: String(data.id) },
      });
    } else {
      await loadPending();
    }
  } catch (e) {
    toast.errorFrom(e, "Could not create 3rd party return.");
  } finally {
    thirdPartyBusy.value = false;
  }
}

onMounted(() => {
  setCrmPageMeta({
    title: "Save Rack | Process Returns",
    description: "Review pending returns and search by order or RMA.",
  });
  loadAccounts();
  loadPending();
});
</script>

<template>
  <div class="staff-page staff-page--wide admin-returns-page">
    <div class="d-flex flex-wrap align-items-end justify-content-between gap-3 mb-4">
      <div>
        <h1 class="h4 mb-1 fw-semibold text-body">Process Returns</h1>
        <p class="small admin-returns-list__subtitle mb-0">
          Pending returns are listed below. Search by order number or RMA number.
        </p>
      </div>
      <div class="d-flex flex-wrap gap-2 align-items-center">
        <button
          v-if="searchMode"
          type="button"
          class="btn btn-outline-secondary btn-sm"
          @click="clearSearch"
        >
          Show All Pending
        </button>
        <button
          type="button"
          class="btn btn-outline-secondary orders-toolbar-outline-btn fw-semibold"
          @click="openThirdParty"
        >
          3rd Party Return
        </button>
        <button
          type="button"
          class="btn btn-outline-secondary orders-toolbar-outline-btn fw-semibold"
          @click="openNonCompliant"
        >
          Non-Compliant
        </button>
      </div>
    </div>

    <div
      class="admin-returns-list admin-returns-page-toolbar staff-table-card staff-datatable-card staff-datatable-card--white w-100"
    >
      <div class="staff-table-toolbar">
        <div class="staff-table-toolbar--row admin-returns-toolbar-row">
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
              :disabled="searching || loading || !canSearch"
              @click="search"
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
              <th class="staff-table-head__th text-center" scope="col">RMA #</th>
              <th class="staff-table-head__th text-center" scope="col">Order #</th>
              <th class="staff-table-head__th text-center" scope="col">Company</th>
              <th class="staff-table-head__th text-center" scope="col">Customer</th>
              <th class="staff-table-head__th text-center" scope="col">Items</th>
              <th class="staff-table-head__th text-center" scope="col">Created</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="loading || searching">
              <td :colspan="tableColspan" class="py-5">
                <div class="d-flex justify-content-center py-3">
                  <CrmLoadingSpinner :message="searching ? 'Searching…' : 'Loading pending returns…'" />
                </div>
              </td>
            </tr>
            <tr v-else-if="!results.length">
              <td :colspan="tableColspan" class="text-center text-secondary py-5">
                <template v-if="searchMode">No matching return found.</template>
                <template v-else>No pending returns.</template>
              </td>
            </tr>
            <tr
              v-for="(row, idx) in results"
              v-else
              :key="row.id ?? 'search-' + idx"
              class="align-middle admin-returns-result-row"
              role="button"
              tabindex="0"
              @click="openRow(row)"
              @keydown.enter.prevent="openRow(row)"
            >
              <td class="text-center">
                <span
                  class="badge rounded-pill fw-medium"
                  :class="processDisplayStatusBadgeClass(row.display_status)"
                >
                  {{ processDisplayStatusLabel(row.display_status) }}
                </span>
              </td>
              <td class="text-center fw-semibold">{{ row.rma_number || "—" }}</td>
              <td class="text-center">{{ listOrderNumberDisplay(row) }}</td>
              <td class="text-center">{{ row.client_account_company_name || "—" }}</td>
              <td class="text-center">{{ row.customer_name || "—" }}</td>
              <td class="text-center">{{ row.items_count ?? "—" }}</td>
              <td class="text-center small text-secondary">{{ formatDateUs(row.created_at) || "—" }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <AdminReturnNonCompliantDrawer
      v-model:open="nonCompliantOpen"
      v-model:account-id="ncAccountId"
      v-model:declared-items="ncDeclaredItems"
      v-model:reason="ncReason"
      v-model:reference-number="ncReferenceNumber"
      :account-options="accountOptions"
      :busy="nonCompliantBusy"
      @submit="submitNonCompliant"
    />

    <AdminReturnThirdPartyDrawer
      v-model:open="thirdPartyOpen"
      v-model:account-id="tpAccountId"
      v-model:third-party-type="tpThirdPartyType"
      v-model:reference-number="tpReferenceNumber"
      :account-options="accountOptions"
      :busy="thirdPartyBusy"
      @submit="submitThirdParty"
    />
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
