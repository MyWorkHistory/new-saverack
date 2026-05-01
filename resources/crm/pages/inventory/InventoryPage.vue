<script setup>
import { computed, onMounted, ref } from "vue";
import { useRouter } from "vue-router";
import api from "../../services/api";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast.js";

const router = useRouter();
const toast = useToast();

const warehousesLoading = ref(true);
const warehouses = ref([]);
const selectedWarehouseId = ref("");
const warehouseWarning = ref("");

const clientAccountsLoading = ref(false);
const clientAccountOptions = ref([]);
const selectedClientAccountId = ref("");

const queryInput = ref("");
const searchBusy = ref(false);
const searchResults = ref([]);
const pageError = ref("");

const resultCards = computed(() =>
  (searchResults.value || []).map((product) => {
    const warehousesList = Array.isArray(product?.warehouses) ? product.warehouses : [];
    let onHand = 0;
    let allocated = 0;
    let backorder = 0;
    warehousesList.forEach((wh) => {
      onHand += Number(wh?.on_hand || 0);
      allocated += Number(wh?.allocated || 0);
      backorder += Number(wh?.backorder || 0);
    });
    return {
      ...product,
      metrics: {
        on_hand: onHand,
        allocated,
        available: Math.max(0, onHand - allocated),
        backorder,
      },
    };
  }),
);

onMounted(() => {
  setCrmPageMeta({
    title: "Save Rack | Inventory",
    description: "ShipHero live inventory search.",
  });
  loadWarehouses();
  loadClientAccountOptions();
});

async function loadWarehouses() {
  warehousesLoading.value = true;
  warehouseWarning.value = "";
  try {
    const { data } = await api.get("/inventory/warehouses");
    warehouses.value = Array.isArray(data?.warehouses) ? data.warehouses : [];
  } catch (e) {
    warehouses.value = [];
    warehouseWarning.value =
      e.response?.data?.message ||
      "Warehouses unavailable from ShipHero. Search will use all warehouses.";
  } finally {
    warehousesLoading.value = false;
  }
}

async function loadClientAccountOptions() {
  clientAccountsLoading.value = true;
  try {
    const { data } = await api.get("/inventory/client-account-options");
    clientAccountOptions.value = Array.isArray(data?.accounts) ? data.accounts : [];
  } catch (e) {
    toast.errorFrom(e, "Could not load client account list.");
  } finally {
    clientAccountsLoading.value = false;
  }
}

async function runSearch() {
  const q = queryInput.value.trim();
  if (!q) {
    toast.error("Enter a SKU or barcode.");
    return;
  }
  searchBusy.value = true;
  pageError.value = "";
  searchResults.value = [];
  try {
    const params = { q };
    if (selectedWarehouseId.value) params.warehouse_id = selectedWarehouseId.value;
    if (selectedClientAccountId.value) params.client_account_id = Number(selectedClientAccountId.value);
    const { data } = await api.get("/inventory/search", { params });
    const product = data?.product ?? null;
    searchResults.value = product ? [product] : [];
    if (!product) toast.error("No product found for that SKU or barcode.");
  } catch (e) {
    pageError.value = e.response?.data?.message || "Search failed.";
    toast.errorFrom(e, "Search failed.");
  } finally {
    searchBusy.value = false;
  }
}

function openDetail(product) {
  if (!product?.sku) return;
  const query = {};
  if (selectedClientAccountId.value) query.client_account_id = String(selectedClientAccountId.value);
  if (selectedWarehouseId.value) query.warehouse_id = String(selectedWarehouseId.value);
  router.push({ name: "inventory-detail", params: { sku: String(product.sku) }, query });
}
</script>

<template>
  <div class="staff-page staff-page--wide">
    <div class="mb-4">
      <h1 class="h4 mb-1 fw-semibold text-body">Inventory</h1>
      <p class="text-secondary small mb-0">
        Search by SKU or barcode, then open product view page.
      </p>
    </div>

    <div v-if="warehousesLoading" class="py-5 text-center">
      <CrmLoadingSpinner message="Loading warehouses…" :center="true" />
    </div>

    <template v-else>
      <p v-if="pageError" class="alert alert-warning small">{{ pageError }}</p>

      <div class="row g-3 align-items-end mb-4">
        <div class="col-12 col-md-6">
          <label class="form-label small text-secondary mb-1">CRM client (3PL)</label>
          <select
            v-model="selectedClientAccountId"
            class="form-select"
            :disabled="clientAccountsLoading"
          >
            <option value="">Default (see .env SHIPHERO_CUSTOMER_ACCOUNT_ID if set)</option>
            <option
              v-for="a in clientAccountOptions"
              :key="a.id"
              :value="String(a.id)"
              :disabled="!a.has_shiphero_customer"
            >
              {{ a.company_name }}
              {{ a.has_shiphero_customer ? "" : " (no ShipHero ID)" }}
            </option>
          </select>
        </div>
      </div>

      <div class="row g-3 align-items-end mb-4">
        <div class="col-12 col-md-4">
          <label class="form-label small text-secondary mb-1">SKU or barcode</label>
          <input
            v-model="queryInput"
            type="text"
            class="form-control"
            autocomplete="off"
            placeholder="Scan or type SKU / barcode"
            @keyup.enter="runSearch"
          />
        </div>
        <div class="col-12 col-md-4">
          <label class="form-label small text-secondary mb-1">Warehouse</label>
          <select v-model="selectedWarehouseId" class="form-select">
            <option value="">All warehouses</option>
            <option v-for="w in warehouses" :key="w.id" :value="w.id">
              {{ w.label || w.identifier || w.id }}
            </option>
          </select>
          <p v-if="warehouseWarning" class="small text-warning mb-0 mt-1">
            {{ warehouseWarning }}
          </p>
        </div>
        <div class="col-12 col-md-4 d-grid d-md-block">
          <button
            type="button"
            class="btn btn-primary w-100 w-md-auto"
            :disabled="searchBusy"
            @click="runSearch"
          >
            <span v-if="searchBusy" class="spinner-border spinner-border-sm me-1" />
            Search
          </button>
        </div>
      </div>

      <div v-if="!searchResults.length" class="text-secondary small py-4 text-center">
        Search for a product to view summary cards.
      </div>

      <div v-else class="row g-3">
        <div
          v-for="product in resultCards"
          :key="product.sku"
          class="col-12"
        >
          <button
            type="button"
            class="card w-100 text-start border-0 shadow-sm inventory-summary-card"
            @click="openDetail(product)"
          >
            <div class="card-body">
              <div class="d-flex align-items-start justify-content-between gap-3 flex-wrap">
                <div class="min-w-0">
                  <h2 class="h6 mb-1 fw-semibold text-body">{{ product.name || "Product" }}</h2>
                  <p class="mb-0 small text-secondary">SKU: {{ product.sku || "—" }}</p>
                  <p v-if="product.barcode" class="mb-0 small text-secondary">
                    Barcode: {{ product.barcode }}
                  </p>
                </div>
                <div class="d-flex gap-3 flex-wrap small">
                  <span>On Hand: <strong>{{ product.metrics.on_hand }}</strong></span>
                  <span>Allocated: <strong>{{ product.metrics.allocated }}</strong></span>
                  <span>Available: <strong>{{ product.metrics.available }}</strong></span>
                  <span>Backorder: <strong>{{ product.metrics.backorder }}</strong></span>
                </div>
              </div>
            </div>
          </button>
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped>
.inventory-summary-card {
  transition: transform 0.15s ease, box-shadow 0.15s ease;
}
.inventory-summary-card:hover {
  transform: translateY(-1px);
  box-shadow: 0 8px 18px rgba(15, 23, 42, 0.1) !important;
}
</style>
