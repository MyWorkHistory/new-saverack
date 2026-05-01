<script setup>
import { computed, onMounted, reactive, ref } from "vue";
import { useRoute } from "vue-router";
import api from "../../services/api";
import CrmIconRowActions from "../../components/common/CrmIconRowActions.vue";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast.js";

const route = useRoute();
const toast = useToast();

const loading = ref(true);
const saving = ref(false);
const product = ref(null);
const errorMessage = ref("");
const locationSearch = ref("");
const actionMenuLocationId = ref(null);
const actionMenuRect = ref({ top: 0, left: 0 });

const updateModalOpen = ref(false);
const transferModalOpen = ref(false);
const activeLocation = ref(null);
const updateForm = reactive({ quantity: "", reason: "Client-Requested Adjustments" });
const transferForm = reactive({ to_location_id: "", quantity: "", reason: "Inventory Reclassification" });

const inventoryReasons = [
  "Account Setup",
  "Amazon Return",
  "Client-Requested Adjustments",
  "Cycle Counts / Physical Counts",
  "Damaged Inventory",
  "Expiration or Obsolescence",
  "Inbound Receiving Adjustments",
  "Inventory Reclassification",
  "Kitting / Bundling",
  "Lost or Missing Units",
  "Order Fulfilment",
  "Quality Control Holds",
  "Returns Processing",
  "Shipped via Shipstation",
  "System Sync or Integration Corrections",
];

const summaryMetrics = computed(() => product.value?.metrics || {
  on_hand: 0,
  allocated: 0,
  available: 0,
  backorder: 0,
  asn: 0,
});

const allLocations = computed(() => {
  const out = [];
  const p = product.value;
  if (!p?.warehouses) return out;
  p.warehouses.forEach((wh) => {
    (wh.locations || []).forEach((loc) => {
      if (Number(loc?.quantity || 0) <= 0) return;
      out.push({
        ...loc,
        warehouse_id: wh.warehouse_id,
        warehouse_name: wh.warehouse_name,
      });
    });
  });
  return out;
});

const filteredLocations = computed(() => {
  const q = locationSearch.value.trim().toLowerCase();
  if (!q) return allLocations.value;
  return allLocations.value.filter((loc) =>
    String(loc.location_name || loc.location_id || "").toLowerCase().includes(q),
  );
});

const locationOptions = computed(() => filteredLocations.value.map((loc) => ({
  id: String(loc.location_id),
  label: String(loc.location_name || loc.location_id || "Location"),
})));

function displayVal(v) {
  if (v === null || v === undefined) return "—";
  if (typeof v === "string" && v.trim() === "") return "—";
  return v;
}

onMounted(() => {
  setCrmPageMeta({
    title: "Save Rack | Inventory Detail",
    description: "Product inventory detail.",
  });
  loadDetail();
  document.addEventListener("click", onDocClick);
});

function onDocClick(e) {
  if (!e.target?.closest?.("[data-row-actions]")) {
    actionMenuLocationId.value = null;
  }
}

function requestParams() {
  const params = {};
  if (route.query.client_account_id) params.client_account_id = Number(route.query.client_account_id);
  if (route.query.warehouse_id) params.warehouse_id = String(route.query.warehouse_id);
  return params;
}

async function loadDetail() {
  loading.value = true;
  errorMessage.value = "";
  try {
    const sku = String(route.params.sku || "").trim();
    const { data } = await api.get(`/inventory/products/${encodeURIComponent(sku)}`, {
      params: requestParams(),
    });
    product.value = data?.product ?? null;
  } catch (e) {
    errorMessage.value = e.response?.data?.message || "Could not load inventory detail.";
    toast.errorFrom(e, "Could not load inventory detail.");
  } finally {
    loading.value = false;
  }
}

function placeActionMenu(anchorEl) {
  if (!(anchorEl instanceof HTMLElement)) return;
  const rect = anchorEl.getBoundingClientRect();
  const width = 190;
  const height = 96;
  let top = rect.bottom + 4;
  let left = rect.right - width;
  left = Math.max(8, Math.min(left, window.innerWidth - width - 8));
  if (top + height > window.innerHeight - 8) top = Math.max(8, rect.top - height - 4);
  actionMenuRect.value = { top, left };
}

function openActionMenu(location, e) {
  e.stopPropagation();
  const id = String(location.location_id);
  if (actionMenuLocationId.value === id) {
    actionMenuLocationId.value = null;
    return;
  }
  actionMenuLocationId.value = id;
  placeActionMenu(e.currentTarget);
}

function currentMenuLocation() {
  return filteredLocations.value.find((loc) => String(loc.location_id) === String(actionMenuLocationId.value)) || null;
}

function openUpdateQtyModal() {
  const loc = currentMenuLocation();
  if (!loc) return;
  activeLocation.value = loc;
  updateForm.quantity = String(loc.quantity || 0);
  updateModalOpen.value = true;
  actionMenuLocationId.value = null;
}

function openTransferQtyModal() {
  const loc = currentMenuLocation();
  if (!loc) return;
  activeLocation.value = loc;
  transferForm.to_location_id = "";
  transferForm.quantity = "";
  transferModalOpen.value = true;
  actionMenuLocationId.value = null;
}

async function submitUpdateQty() {
  if (!activeLocation.value || !product.value) return;
  const qty = parseInt(String(updateForm.quantity || ""), 10);
  if (Number.isNaN(qty) || qty < 0) {
    toast.error("Enter a valid quantity.");
    return;
  }
  saving.value = true;
  try {
    const body = {
      sku: product.value.sku,
      warehouse_id: activeLocation.value.warehouse_id,
      location_id: activeLocation.value.location_id,
      quantity: qty,
      reason: updateForm.reason,
    };
    if (route.query.client_account_id) {
      body.client_account_id = Number(route.query.client_account_id);
    }
    await api.post("/inventory/replace", body);
    toast.success("Quantity updated.");
    updateModalOpen.value = false;
    await loadDetail();
  } catch (e) {
    toast.errorFrom(e, "Could not update quantity.");
  } finally {
    saving.value = false;
  }
}

async function submitTransferQty() {
  if (!activeLocation.value || !product.value) return;
  const qty = parseInt(String(transferForm.quantity || ""), 10);
  if (Number.isNaN(qty) || qty <= 0) {
    toast.error("Enter a valid transfer quantity.");
    return;
  }
  if (!transferForm.to_location_id) {
    toast.error("Select destination location.");
    return;
  }
  saving.value = true;
  try {
    const body = {
      sku: product.value.sku,
      warehouse_id: activeLocation.value.warehouse_id,
      from_location_id: activeLocation.value.location_id,
      to_location_id: transferForm.to_location_id,
      quantity: qty,
      reason: transferForm.reason,
    };
    if (route.query.client_account_id) {
      body.client_account_id = Number(route.query.client_account_id);
    }
    await api.post("/inventory/transfer", body);
    toast.success("Quantity transferred.");
    transferModalOpen.value = false;
    await loadDetail();
  } catch (e) {
    toast.errorFrom(e, "Could not transfer quantity.");
  } finally {
    saving.value = false;
  }
}

async function togglePickable(loc) {
  if (loc.pickable === null || loc.pickable === undefined) {
    toast.error("Pickable update API is not available yet for this location.");
    return;
  }
  toast.error("Pickable update API is not available yet for this account.");
}
</script>

<template>
  <div class="staff-page staff-page--wide">
    <div v-if="loading" class="py-5 text-center">
      <CrmLoadingSpinner message="Loading Product..." :center="true" />
    </div>

    <template v-else>
      <p v-if="errorMessage" class="alert alert-warning small">{{ errorMessage }}</p>
      <div v-else-if="!product" class="text-secondary small py-4 text-center">
        Product not found.
      </div>
      <template v-else>
        <h1 class="h4 mb-3 fw-semibold text-body">Inventory Detail</h1>
        <div class="row g-3 mb-3">
          <div class="col-12 col-xl-3">
            <div class="staff-table-card p-3 h-100">
              <div class="text-center">
                <img
                  v-if="product.image_url"
                  :src="product.image_url"
                  alt=""
                  class="inventory-detail__image mb-2"
                />
                <div v-else class="inventory-detail__image inventory-detail__image--empty mb-2" />
                <h2 class="h6 fw-semibold mb-1">{{ product.name || "Product" }}</h2>
                <p class="small text-secondary mb-0">{{ product.sku }}</p>
              </div>
              <hr />
              <div class="small">
                <div class="d-flex justify-content-between py-1"><span>SKU:</span><span>{{ product.sku || "—" }}</span></div>
                <div class="d-flex justify-content-between py-1"><span>Barcode:</span><span>{{ product.barcode || "—" }}</span></div>
                <div class="d-flex justify-content-between py-1"><span>Weight:</span><span>{{ displayVal(product.dimensions?.weight) }}</span></div>
                <div class="d-flex justify-content-between py-1"><span>Height:</span><span>{{ displayVal(product.dimensions?.height) }}</span></div>
                <div class="d-flex justify-content-between py-1"><span>Width:</span><span>{{ displayVal(product.dimensions?.width) }}</span></div>
                <div class="d-flex justify-content-between py-1"><span>Length:</span><span>{{ displayVal(product.dimensions?.length) }}</span></div>
                <div class="d-flex justify-content-between py-1"><span>Custom Value:</span><span>{{ displayVal(product.customs_value) }}</span></div>
                <div class="py-1">
                  <div class="text-secondary">Custom Description:</div>
                  <div>{{ displayVal(product.customs_description) }}</div>
                </div>
              </div>
            </div>
          </div>

          <div class="col-12 col-xl-9">
            <div class="row g-2 mb-3">
              <div class="col-6 col-md">
                <div class="staff-table-card p-3 text-center"><div class="small text-secondary">On Hand</div><div class="h5 mb-0">{{ summaryMetrics.on_hand }}</div></div>
              </div>
              <div class="col-6 col-md">
                <div class="staff-table-card p-3 text-center"><div class="small text-secondary">Allocated</div><div class="h5 mb-0">{{ summaryMetrics.allocated }}</div></div>
              </div>
              <div class="col-6 col-md">
                <div class="staff-table-card p-3 text-center"><div class="small text-secondary">Available</div><div class="h5 mb-0">{{ summaryMetrics.available }}</div></div>
              </div>
              <div class="col-6 col-md">
                <div class="staff-table-card p-3 text-center"><div class="small text-secondary">Backorder</div><div class="h5 mb-0">{{ summaryMetrics.backorder }}</div></div>
              </div>
              <div class="col-6 col-md">
                <div class="staff-table-card p-3 text-center"><div class="small text-secondary">ASN</div><div class="h5 mb-0">{{ summaryMetrics.asn }}</div></div>
              </div>
            </div>

            <div class="staff-table-card p-0 mb-3">
              <div class="staff-table-toolbar border-0">
                <div class="staff-table-toolbar--row">
                  <input
                    v-model="locationSearch"
                    type="search"
                    class="form-control staff-toolbar-search staff-toolbar-search--inline"
                    placeholder="Search locations"
                  />
                  <button type="button" class="btn btn-outline-secondary staff-toolbar-btn" disabled>Filters</button>
                  <button type="button" class="btn btn-primary staff-toolbar-btn" disabled title="Add Location API not available yet">
                    Add Location
                  </button>
                </div>
              </div>

              <div class="table-responsive staff-table-wrap">
                <table class="table table-hover align-middle mb-0 staff-data-table">
                  <thead class="table-light staff-table-head">
                    <tr>
                      <th class="staff-table-head__th">Location Name</th>
                      <th class="staff-table-head__th">Pickable</th>
                      <th class="staff-table-head__th">QTY</th>
                      <th class="staff-table-head__th">Type</th>
                      <th class="staff-table-head__th text-center">Action</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-if="!filteredLocations.length">
                      <td colspan="5" class="text-center text-secondary py-4">No locations with quantity.</td>
                    </tr>
                    <tr v-for="loc in filteredLocations" :key="`${loc.warehouse_id}-${loc.location_id}`">
                      <td>{{ loc.location_name || loc.location_id }}</td>
                      <td>
                        <button
                          type="button"
                          class="inventory-detail__toggle"
                          :class="{ 'inventory-detail__toggle--on': !!loc.pickable, 'inventory-detail__toggle--off': !loc.pickable }"
                          @click="togglePickable(loc)"
                        >
                          {{ loc.pickable ? "Yes" : "No" }}
                        </button>
                      </td>
                      <td>{{ loc.quantity }}</td>
                      <td>{{ loc.type || "—" }}</td>
                      <td class="text-center">
                        <div data-row-actions class="d-inline-flex">
                          <button
                            type="button"
                            class="staff-action-btn staff-action-btn--more"
                            :aria-expanded="actionMenuLocationId === String(loc.location_id)"
                            @click="openActionMenu(loc, $event)"
                          >
                            <CrmIconRowActions variant="horizontal" />
                          </button>
                        </div>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>

            <div v-if="(product.kit_components || []).length" class="staff-table-card p-0">
              <div class="px-3 py-2 border-bottom">
                <h3 class="h6 mb-0">Kit Components</h3>
              </div>
              <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                  <thead class="table-light">
                    <tr>
                      <th>SKU</th>
                      <th>Quantity</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="component in product.kit_components" :key="component.sku">
                      <td>{{ component.sku }}</td>
                      <td>{{ component.quantity }}</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
      </template>

      <Teleport to="body">
        <div
          v-if="actionMenuLocationId"
          data-row-actions
          class="staff-row-menu fixed z-[300] overflow-hidden"
          :style="{ top: actionMenuRect.top + 'px', left: actionMenuRect.left + 'px' }"
          role="menu"
          @click.stop
        >
          <button type="button" class="staff-row-menu__item" role="menuitem" @click="openUpdateQtyModal">
            Update QTY
          </button>
          <button type="button" class="staff-row-menu__item" role="menuitem" @click="openTransferQtyModal">
            Transfer QTY
          </button>
        </div>
      </Teleport>

      <Teleport to="body">
        <div v-if="updateModalOpen" class="crm-vx-modal-overlay" @click.self="updateModalOpen = false">
          <div class="crm-vx-modal crm-vx-modal--sm">
            <header class="crm-vx-modal__head">
              <h2 class="crm-vx-modal__title">Update QTY</h2>
            </header>
            <div class="crm-vx-modal__body">
              <p class="small text-secondary">Current QTY: {{ activeLocation?.quantity ?? 0 }}</p>
              <label class="form-label small">New QTY</label>
              <input v-model="updateForm.quantity" type="number" min="0" class="form-control mb-3" />
              <label class="form-label small">Reason</label>
              <select v-model="updateForm.reason" class="form-select">
                <option v-for="reason in inventoryReasons" :key="reason" :value="reason">{{ reason }}</option>
              </select>
            </div>
            <footer class="crm-vx-modal__footer">
              <button type="button" class="crm-vx-modal-btn crm-vx-modal-btn--secondary" :disabled="saving" @click="updateModalOpen = false">
                Cancel
              </button>
              <button type="button" class="crm-vx-modal-btn crm-vx-modal-btn--primary" :disabled="saving" @click="submitUpdateQty">
                {{ saving ? "Please wait..." : "Update" }}
              </button>
            </footer>
          </div>
        </div>
      </Teleport>

      <Teleport to="body">
        <div v-if="transferModalOpen" class="crm-vx-modal-overlay" @click.self="transferModalOpen = false">
          <div class="crm-vx-modal crm-vx-modal--sm">
            <header class="crm-vx-modal__head">
              <h2 class="crm-vx-modal__title">Transfer QTY</h2>
            </header>
            <div class="crm-vx-modal__body">
              <label class="form-label small">Transfer To</label>
              <select v-model="transferForm.to_location_id" class="form-select mb-3">
                <option value="">Select location</option>
                <option
                  v-for="loc in locationOptions"
                  :key="loc.id"
                  :value="loc.id"
                >
                  {{ loc.label }}
                </option>
              </select>
              <label class="form-label small">QTY</label>
              <input v-model="transferForm.quantity" type="number" min="1" class="form-control mb-3" />
              <label class="form-label small">Reason</label>
              <select v-model="transferForm.reason" class="form-select">
                <option v-for="reason in inventoryReasons" :key="reason" :value="reason">{{ reason }}</option>
              </select>
            </div>
            <footer class="crm-vx-modal__footer">
              <button type="button" class="crm-vx-modal-btn crm-vx-modal-btn--secondary" :disabled="saving" @click="transferModalOpen = false">
                Cancel
              </button>
              <button type="button" class="crm-vx-modal-btn crm-vx-modal-btn--primary" :disabled="saving" @click="submitTransferQty">
                {{ saving ? "Please wait..." : "Transfer" }}
              </button>
            </footer>
          </div>
        </div>
      </Teleport>
    </template>
  </div>
</template>

<style scoped>
.inventory-detail__image {
  width: 120px;
  height: 120px;
  border-radius: 12px;
  object-fit: cover;
  background: #f8fafc;
  border: 1px solid rgba(15, 23, 42, 0.08);
}
.inventory-detail__image--empty {
  display: inline-block;
}
.inventory-detail__toggle {
  border: 0;
  border-radius: 999px;
  padding: 0.2rem 0.8rem;
  font-size: 0.75rem;
  font-weight: 600;
}
.inventory-detail__toggle--on {
  background: #22c55e;
  color: #fff;
}
.inventory-detail__toggle--off {
  background: #ef4444;
  color: #fff;
}
</style>
