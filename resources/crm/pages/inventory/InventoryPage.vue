<script setup>
import { computed, inject, onMounted, ref, watch } from "vue";
import api from "../../services/api";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast.js";
import { crmIsAdmin } from "../../utils/crmUser.js";

const crmUser = inject("crmUser", ref(null));
const toast = useToast();

const warehousesLoading = ref(true);
const warehouses = ref([]);
/** Empty string = all warehouses */
const selectedWarehouseId = ref("");

const queryInput = ref("");
const searchBusy = ref(false);
const product = ref(null);
const pageError = ref("");

/** key: `${warehouse_id}::${location_id}` -> quantity string */
const editQty = ref({});

const savingRowKey = ref(null);

const canUpdateInventory = computed(() => {
  const u = crmUser?.value;
  if (!u) return false;
  if (crmIsAdmin(u) || u.is_crm_owner) return true;
  const k = u.permission_keys;
  return Array.isArray(k) && k.includes("inventory.update");
});

function rowKey(warehouseId, locationId) {
  return `${warehouseId}::${locationId}`;
}

function syncEditQtyFromProduct() {
  const next = {};
  const p = product.value;
  if (!p?.warehouses) {
    editQty.value = next;
    return;
  }
  for (const wh of p.warehouses) {
    for (const loc of wh.locations || []) {
      next[rowKey(wh.warehouse_id, loc.location_id)] = String(loc.quantity ?? 0);
    }
  }
  editQty.value = next;
}

watch(product, syncEditQtyFromProduct);

onMounted(() => {
  setCrmPageMeta({
    title: "Save Rack | Inventory",
    description: "ShipHero live inventory search and location quantities.",
  });
  loadWarehouses();
});

async function loadWarehouses() {
  warehousesLoading.value = true;
  pageError.value = "";
  try {
    const { data } = await api.get("/inventory/warehouses");
    warehouses.value = Array.isArray(data?.warehouses) ? data.warehouses : [];
  } catch (e) {
    pageError.value =
      e.response?.data?.message || "Could not load warehouses from ShipHero.";
    toast.errorFrom(e, "Could not load warehouses.");
  } finally {
    warehousesLoading.value = false;
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
  product.value = null;
  try {
    const params = { q };
    if (selectedWarehouseId.value) {
      params.warehouse_id = selectedWarehouseId.value;
    }
    const { data } = await api.get("/inventory/search", { params });
    product.value = data?.product ?? null;
    if (!product.value) {
      toast.error("No product found for that SKU or barcode.");
    }
  } catch (e) {
    pageError.value = e.response?.data?.message || "Search failed.";
    toast.errorFrom(e, "Search failed.");
  } finally {
    searchBusy.value = false;
  }
}

async function saveRow(warehouseBlock, loc) {
  const p = product.value;
  if (!p?.sku || !warehouseBlock?.warehouse_id || !loc?.location_id) return;

  const key = rowKey(warehouseBlock.warehouse_id, loc.location_id);
  const raw = editQty.value[key];
  const qty = parseInt(String(raw ?? "0"), 10);
  if (Number.isNaN(qty) || qty < 0) {
    toast.error("Enter a valid quantity (0 or greater).");
    return;
  }

  savingRowKey.value = key;
  try {
    const { data } = await api.post("/inventory/replace", {
      sku: p.sku,
      warehouse_id: warehouseBlock.warehouse_id,
      location_id: loc.location_id,
      quantity: qty,
      reason: "CRM inventory adjustment",
    });
    const updated = data?.warehouse;
    if (updated?.warehouse_id && Array.isArray(p.warehouses)) {
      const idx = p.warehouses.findIndex((w) => w.warehouse_id === updated.warehouse_id);
      if (idx !== -1) {
        p.warehouses[idx] = {
          ...p.warehouses[idx],
          ...updated,
          locations: updated.locations || [],
        };
      }
      for (const nl of updated.locations || []) {
        editQty.value[rowKey(updated.warehouse_id, nl.location_id)] = String(
          nl.quantity ?? 0,
        );
      }
    }
    toast.success("Quantity updated.");
  } catch (e) {
    toast.errorFrom(e, "Could not update quantity.");
  } finally {
    savingRowKey.value = null;
  }
}
</script>

<template>
  <div class="staff-page staff-page--wide">
    <div class="mb-4">
      <h1 class="h4 mb-1 fw-semibold text-body">Inventory</h1>
      <p class="text-secondary small mb-0">
        Live data from ShipHero — search by SKU or barcode, then adjust quantity per
        location.
      </p>
    </div>

    <div v-if="warehousesLoading" class="py-5 text-center">
      <CrmLoadingSpinner message="Loading warehouses…" :center="true" />
    </div>

    <template v-else>
      <p v-if="pageError" class="alert alert-warning small">{{ pageError }}</p>

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

      <div v-if="!product" class="text-secondary small py-4 text-center">
        Search for a product to see quantities by location.
      </div>

      <div v-else class="inventory-result">
        <div class="mb-3">
          <h2 class="h5 mb-1 fw-semibold">{{ product.sku || "—" }}</h2>
          <p v-if="product.name" class="mb-1 text-body">{{ product.name }}</p>
          <p v-if="product.barcode" class="small text-secondary mb-0">
            Barcode: {{ product.barcode }}
          </p>
        </div>

        <div
          v-for="wh in product.warehouses || []"
          :key="wh.warehouse_id"
          class="card mb-3"
        >
          <div class="card-header py-2 small fw-semibold">
            {{ wh.warehouse_name || wh.warehouse_id }}
          </div>
          <div class="card-body p-0">
            <div v-if="!(wh.locations || []).length" class="p-3 text-secondary small">
              No locations for this warehouse.
            </div>
            <div v-else class="table-responsive">
              <table class="table table-sm align-middle mb-0">
                <thead>
                  <tr>
                    <th class="ps-3">Location</th>
                    <th style="width: 8rem">QTY</th>
                    <th v-if="canUpdateInventory" class="pe-3" style="width: 7rem" />
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="loc in wh.locations" :key="loc.location_id">
                    <td class="ps-3">
                      <span class="text-body">{{
                        loc.location_name || loc.location_id
                      }}</span>
                    </td>
                    <td>
                      <span v-if="!canUpdateInventory" class="fw-medium">{{
                        loc.quantity
                      }}</span>
                      <input
                        v-else
                        v-model="editQty[rowKey(wh.warehouse_id, loc.location_id)]"
                        type="number"
                        min="0"
                        class="form-control form-control-sm"
                      />
                    </td>
                    <td v-if="canUpdateInventory" class="pe-3 text-end">
                      <button
                        type="button"
                        class="btn btn-sm btn-outline-primary"
                        :disabled="
                          savingRowKey === rowKey(wh.warehouse_id, loc.location_id)
                        "
                        @click="saveRow(wh, loc)"
                      >
                        <span
                          v-if="savingRowKey === rowKey(wh.warehouse_id, loc.location_id)"
                          class="spinner-border spinner-border-sm"
                          aria-hidden="true"
                        />
                        <span v-else>Save</span>
                      </button>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>
