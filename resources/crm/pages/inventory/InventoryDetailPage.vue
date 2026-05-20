<script setup>
import { computed, onMounted, onUnmounted, reactive, ref } from "vue";
import { RouterLink } from "vue-router";
import api from "../../services/api";
import CrmIconRowActions from "../../components/common/CrmIconRowActions.vue";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useInventoryProductDetail } from "../../composables/useInventoryProductDetail.js";
import { useToast } from "../../composables/useToast.js";

const toast = useToast();

const {
  route,
  isPortalView,
  canManageInventoryLocations,
  loading,
  saving,
  product,
  errorMessage,
  metricCards,
  cubicFeetDisplay,
  showKitSection,
  kitComponents,
  allocatedOrders,
  allocatedOrdersLoading,
  allocatedOrdersLoaded,
  backorderOrders,
  backorderOrdersLoading,
  backorderOrdersLoaded,
  displayVal,
  displayNumber,
  loadDetail,
  openBarcodeLabelPdf,
  loadAllocatedOrders,
  loadBackorderOrders,
  formatOrderDate,
  portalOrderDetailHref,
  requestParams,
} = useInventoryProductDetail();
const locationSearch = ref("");
const actionMenuLocationId = ref(null);
const actionMenuRect = ref({ top: 0, left: 0 });

const updateModalOpen = ref(false);
const transferModalOpen = ref(false);
const addLocationModalOpen = ref(false);
const activeLocation = ref(null);
const updateForm = reactive({ quantity: "", reason: "Client-Requested Adjustments" });
const transferForm = reactive({ to_location: "", quantity: "", reason: "Inventory Reclassification" });
const addLocationForm = reactive({ location: "", quantity: "", reason: "Inventory Reclassification" });

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

const allLocations = computed(() => {
  const out = [];
  const p = product.value;
  if (!p?.warehouses) return out;
  p.warehouses.forEach((wh) => {
    (wh.locations || []).forEach((loc) => {
      if (!isPortalView.value && Number(loc?.quantity || 0) <= 0) return;
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

onMounted(() => {
  if (route.meta.userPortal) {
    setCrmPageMeta({
      title: "Save Rack | Products | Inventory Detail",
      description: "Product inventory detail.",
    });
  } else {
    setCrmPageMeta({
      title: "Save Rack | Inventory Detail",
      description: "Product inventory detail.",
    });
  }
  loadDetail();
  if (!isPortalView.value) {
    document.addEventListener("click", onDocClick);
  }
});

onUnmounted(() => {
  document.removeEventListener("click", onDocClick);
});

function onDocClick(e) {
  if (!e.target?.closest?.("[data-row-actions]")) {
    actionMenuLocationId.value = null;
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
  transferForm.to_location = "";
  transferForm.quantity = "";
  transferModalOpen.value = true;
  actionMenuLocationId.value = null;
}

function openAddLocationModal() {
  addLocationForm.location = "";
  addLocationForm.quantity = "";
  addLocationForm.reason = "Inventory Reclassification";
  addLocationModalOpen.value = true;
}

function activeWarehouseId() {
  const routeWarehouse = String(route.query.warehouse_id || "").trim();
  if (routeWarehouse) return routeWarehouse;
  const firstWarehouse = product.value?.warehouses?.[0]?.warehouse_id;
  return String(firstWarehouse || "").trim();
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

async function submitAddLocationQty() {
  if (!product.value) return;
  const qty = parseInt(String(addLocationForm.quantity || ""), 10);
  if (!addLocationForm.location.trim()) {
    toast.error("Enter location.");
    return;
  }
  if (Number.isNaN(qty) || qty < 0) {
    toast.error("Enter a valid quantity.");
    return;
  }
  const warehouseId = activeWarehouseId();
  if (!warehouseId) {
    toast.error("Warehouse is required for add location.");
    return;
  }
  saving.value = true;
  try {
    const body = {
      sku: product.value.sku,
      warehouse_id: warehouseId,
      location: addLocationForm.location.trim(),
      quantity: qty,
      reason: addLocationForm.reason,
    };
    if (route.query.client_account_id) {
      body.client_account_id = Number(route.query.client_account_id);
    }
    await api.post("/inventory/locations/add-qty", body);
    toast.success("Location quantity updated.");
    addLocationModalOpen.value = false;
    await loadDetail();
  } catch (e) {
    toast.errorFrom(e, "Location not found or quantity update failed.");
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
  if (!transferForm.to_location.trim()) {
    toast.error("Enter destination location.");
    return;
  }
  saving.value = true;
  try {
    const body = {
      sku: product.value.sku,
      warehouse_id: activeLocation.value.warehouse_id,
      from_location_id: activeLocation.value.location_id,
      to_location: transferForm.to_location.trim(),
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
  if (!loc?.location_id) return;
  const nextPickable = !Boolean(loc.pickable);
  saving.value = true;
  try {
    const body = {
      location_id: String(loc.location_id),
      pickable: nextPickable,
    };
    if (route.query.client_account_id) {
      body.client_account_id = Number(route.query.client_account_id);
    }
    await api.post("/inventory/locations/pickable", body);
    // Update source data so computed location rows reflect new value immediately.
    const warehouses = Array.isArray(product.value?.warehouses) ? product.value.warehouses : [];
    warehouses.forEach((wh) => {
      const locations = Array.isArray(wh?.locations) ? wh.locations : [];
      locations.forEach((row) => {
        if (String(row?.location_id) === String(loc.location_id)) {
          row.pickable = nextPickable;
        }
      });
    });
    toast.success("Pickable updated.");
    await loadDetail();
  } catch (e) {
    toast.errorFrom(e, "Could not update pickable.");
  } finally {
    saving.value = false;
  }
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
      <template v-else-if="isPortalView">
        <div class="staff-user-view staff-page--wide">
          <nav
            class="staff-user-view__breadcrumb d-flex flex-wrap align-items-center gap-1"
            aria-label="Breadcrumb"
          >
            <RouterLink to="/users">Home</RouterLink>
            <span class="text-secondary" aria-hidden="true">/</span>
            <RouterLink to="/users/inventory">Products</RouterLink>
            <span class="text-secondary" aria-hidden="true">/</span>
            <span class="text-body-secondary">{{ product.sku || "Product" }}</span>
          </nav>

          <div class="staff-user-view__title-row d-flex flex-wrap align-items-start gap-2">
            <div class="min-w-0">
              <h1 class="staff-user-view__title">Products</h1>
              <p class="text-secondary small mb-0">Inventory detail</p>
            </div>
          </div>

          <div class="inventory-portal-metrics row g-2 g-md-3 mb-3">
            <div v-for="card in metricCards" :key="card.key" class="col-6 col-md">
              <div class="staff-user-profile__stat inventory-portal-metrics__stat h-100">
                <div class="staff-user-profile__stat-val">{{ card.value }}</div>
                <div class="staff-user-profile__stat-lbl">{{ card.label }}</div>
              </div>
            </div>
          </div>

          <div class="row g-3">
            <div class="col-12 col-xl-4">
              <aside class="staff-user-profile">
                <div class="inventory-portal-product__hero">
                  <div class="inventory-portal-product__image-wrap flex-shrink-0">
                    <img
                      v-if="product.image_url"
                      :src="product.image_url"
                      alt=""
                      class="inventory-portal-product__image"
                    />
                    <div v-else class="inventory-portal-product__image inventory-portal-product__image--empty" />
                  </div>
                  <div class="min-w-0 flex-grow-1">
                    <h2 class="staff-user-profile__name text-start mb-2">
                      {{ product.name || "Product" }}
                    </h2>
                    <button
                      type="button"
                      class="btn btn-outline-secondary btn-sm orders-toolbar-outline-btn"
                      @click="openBarcodeLabelPdf"
                    >
                      Print Barcode Label
                    </button>
                  </div>
                </div>

                <h3 class="staff-user-profile__details-title">Details</h3>
                <dl class="staff-user-profile__dl">
                  <div>
                    <dt class="staff-user-profile__dt">SKU</dt>
                    <dd class="staff-user-profile__dd">{{ product.sku || "—" }}</dd>
                  </div>
                  <div>
                    <dt class="staff-user-profile__dt">Barcode</dt>
                    <dd class="staff-user-profile__dd">{{ product.barcode || "—" }}</dd>
                  </div>
                  <div>
                    <dt class="staff-user-profile__dt">Cubic Feet</dt>
                    <dd class="staff-user-profile__dd">{{ cubicFeetDisplay }}</dd>
                  </div>
                  <div>
                    <dt class="staff-user-profile__dt">Weight</dt>
                    <dd class="staff-user-profile__dd">{{ displayNumber(product.dimensions?.weight) }}</dd>
                  </div>
                  <div>
                    <dt class="staff-user-profile__dt">Height</dt>
                    <dd class="staff-user-profile__dd">{{ displayNumber(product.dimensions?.height) }}</dd>
                  </div>
                  <div>
                    <dt class="staff-user-profile__dt">Width</dt>
                    <dd class="staff-user-profile__dd">{{ displayNumber(product.dimensions?.width) }}</dd>
                  </div>
                  <div>
                    <dt class="staff-user-profile__dt">Length</dt>
                    <dd class="staff-user-profile__dd">{{ displayNumber(product.dimensions?.length) }}</dd>
                  </div>
                  <div>
                    <dt class="staff-user-profile__dt">Custom Value</dt>
                    <dd class="staff-user-profile__dd">{{ displayNumber(product.customs_value) }}</dd>
                  </div>
                  <div>
                    <dt class="staff-user-profile__dt">Custom Description</dt>
                    <dd class="staff-user-profile__dd text-start">{{ displayVal(product.customs_description) }}</dd>
                  </div>
                </dl>
              </aside>
            </div>

            <div class="col-12 col-xl-8 d-flex flex-column gap-3">
              <div v-if="showKitSection" class="staff-surface p-3 p-md-4">
                <h3 class="staff-user-section-title mb-2">Kit</h3>
                <p class="small text-secondary mb-0">
                  This product is a kit{{ product.kit_build ? " (kit build)" : "" }}.
                </p>
              </div>

              <div v-if="kitComponents.length" class="staff-surface p-0 overflow-hidden">
                <div class="px-3 py-3 border-bottom">
                  <h3 class="staff-user-section-title mb-0">Kit Components</h3>
                </div>
                <div class="table-responsive staff-table-wrap">
                  <table class="table table-hover align-middle mb-0 staff-data-table">
                    <thead class="table-light staff-table-head">
                      <tr>
                        <th class="staff-table-head__th">SKU</th>
                        <th class="staff-table-head__th text-end">Quantity</th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr v-for="component in kitComponents" :key="component.sku">
                        <td class="fw-semibold">{{ component.sku }}</td>
                        <td class="text-end">{{ component.quantity }}</td>
                      </tr>
                    </tbody>
                  </table>
                </div>
              </div>

              <div class="staff-surface p-3 p-md-4">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                  <h3 class="staff-user-section-title mb-0">Allocated Orders</h3>
                  <button
                    v-if="!allocatedOrdersLoaded"
                    type="button"
                    class="btn btn-outline-secondary btn-sm orders-toolbar-outline-btn"
                    :disabled="allocatedOrdersLoading"
                    @click="loadAllocatedOrders"
                  >
                    {{ allocatedOrdersLoading ? "Loading…" : "Load Allocated Orders" }}
                  </button>
                </div>
                <div v-if="allocatedOrdersLoading" class="py-3 text-center">
                  <CrmLoadingSpinner message="Loading allocated orders…" :center="true" />
                </div>
                <p v-else-if="allocatedOrdersLoaded && !allocatedOrders.length" class="small text-secondary mb-0">
                  No allocated orders for this SKU.
                </p>
                <div v-else-if="allocatedOrders.length" class="table-responsive staff-table-wrap">
                  <table class="table table-hover align-middle mb-0 staff-data-table">
                    <thead class="table-light staff-table-head">
                      <tr>
                        <th class="staff-table-head__th">Order #</th>
                        <th class="staff-table-head__th">Date</th>
                        <th class="staff-table-head__th">Status</th>
                        <th class="staff-table-head__th text-end">Allocated</th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr v-for="row in allocatedOrders" :key="row.order_id">
                        <td class="fw-semibold">
                          <RouterLink
                            v-if="portalOrderDetailHref(row)"
                            :to="portalOrderDetailHref(row)"
                            class="text-decoration-none"
                            target="_blank"
                            rel="noopener noreferrer"
                          >
                            {{ row.order_number || "—" }}
                          </RouterLink>
                          <span v-else>{{ row.order_number || "—" }}</span>
                        </td>
                        <td>{{ formatOrderDate(row.order_date) }}</td>
                        <td>{{ row.status || "—" }}</td>
                        <td class="text-end">{{ Number(row.quantity_allocated || 0) }}</td>
                      </tr>
                    </tbody>
                  </table>
                </div>
              </div>

              <div class="staff-surface p-3 p-md-4">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                  <h3 class="staff-user-section-title mb-0">Backorder Orders</h3>
                  <button
                    v-if="!backorderOrdersLoaded"
                    type="button"
                    class="btn btn-outline-secondary btn-sm orders-toolbar-outline-btn"
                    :disabled="backorderOrdersLoading"
                    @click="loadBackorderOrders"
                  >
                    {{ backorderOrdersLoading ? "Loading…" : "Load Backorder Orders" }}
                  </button>
                </div>
                <div v-if="backorderOrdersLoading" class="py-3 text-center">
                  <CrmLoadingSpinner message="Loading backorder orders…" :center="true" />
                </div>
                <p v-else-if="backorderOrdersLoaded && !backorderOrders.length" class="small text-secondary mb-0">
                  No backorder orders for this SKU.
                </p>
                <div v-else-if="backorderOrders.length" class="table-responsive staff-table-wrap">
                  <table class="table table-hover align-middle mb-0 staff-data-table">
                    <thead class="table-light staff-table-head">
                      <tr>
                        <th class="staff-table-head__th">Order #</th>
                        <th class="staff-table-head__th">Date</th>
                        <th class="staff-table-head__th">Status</th>
                        <th class="staff-table-head__th text-end">Backorder</th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr v-for="row in backorderOrders" :key="row.order_id">
                        <td class="fw-semibold">
                          <RouterLink
                            v-if="portalOrderDetailHref(row)"
                            :to="portalOrderDetailHref(row)"
                            class="text-decoration-none"
                            target="_blank"
                            rel="noopener noreferrer"
                          >
                            {{ row.order_number || "—" }}
                          </RouterLink>
                          <span v-else>{{ row.order_number || "—" }}</span>
                        </td>
                        <td>{{ formatOrderDate(row.order_date) }}</td>
                        <td>{{ row.status || "—" }}</td>
                        <td class="text-end">{{ Number(row.backorder_quantity || 0) }}</td>
                      </tr>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
          </div>
        </div>
      </template>

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
                <div class="d-flex justify-content-between py-1"><span>Weight:</span><span>{{ displayNumber(product.dimensions?.weight) }}</span></div>
                <div class="d-flex justify-content-between py-1"><span>Height:</span><span>{{ displayNumber(product.dimensions?.height) }}</span></div>
                <div class="d-flex justify-content-between py-1"><span>Width:</span><span>{{ displayNumber(product.dimensions?.width) }}</span></div>
                <div class="d-flex justify-content-between py-1"><span>Length:</span><span>{{ displayNumber(product.dimensions?.length) }}</span></div>
                <div class="d-flex justify-content-between py-1"><span>Custom Value:</span><span>{{ displayNumber(product.customs_value) }}</span></div>
                <div class="py-1">
                  <div class="text-secondary">Custom Description:</div>
                  <div>{{ displayVal(product.customs_description) }}</div>
                </div>
              </div>
            </div>
          </div>

          <div class="col-12 col-xl-9">
            <div class="row g-2 mb-3">
              <div v-for="card in metricCards" :key="card.key" class="col-6 col-md">
                <div class="staff-table-card p-3 inventory-metric-card">
                  <div class="inventory-metric-card__head">
                    <div class="inventory-metric-card__left">
                      <div class="small text-secondary">{{ card.label }}</div>
                    </div>
                    <div class="inventory-metric-card__right">
                      <svg
                        class="inventory-metric-card__icon"
                        :class="`inventory-metric-card__icon--${card.tone}`"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        aria-hidden="true"
                      >
                        <path :d="card.iconPath" />
                      </svg>
                    </div>
                  </div>
                  <div class="h5 mb-0">{{ card.value }}</div>
                </div>
              </div>
            </div>

            <div class="staff-table-card p-0 mb-3">
              <div class="px-3 py-2 border-bottom">
                <h3 class="h6 mb-0 fw-semibold">Locations</h3>
              </div>
              <div class="staff-table-toolbar border-0">
                <div class="staff-table-toolbar--row">
                  <input
                    v-model="locationSearch"
                    type="search"
                    class="form-control staff-toolbar-search staff-toolbar-search--inline"
                    placeholder="Search locations"
                  />
                  <template v-if="canManageInventoryLocations">
                    <button type="button" class="btn btn-outline-secondary staff-toolbar-btn" disabled>Filters</button>
                    <button type="button" class="btn btn-primary staff-toolbar-btn" @click="openAddLocationModal">
                      Add Location
                    </button>
                  </template>
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
                      <th v-if="canManageInventoryLocations" class="staff-table-head__th text-center">Action</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-if="!filteredLocations.length">
                      <td :colspan="canManageInventoryLocations ? 5 : 4" class="text-center text-secondary py-4">
                        {{ isPortalView ? "No locations found for this product." : "No locations with quantity." }}
                      </td>
                    </tr>
                    <tr v-for="loc in filteredLocations" :key="`${loc.warehouse_id}-${loc.location_id}`">
                      <td>{{ loc.location_name || loc.location_id }}</td>
                      <td>
                        <button
                          v-if="canManageInventoryLocations"
                          type="button"
                          class="inventory-detail__toggle"
                          :class="{
                            'inventory-detail__toggle--on': loc.pickable === true,
                            'inventory-detail__toggle--off': loc.pickable === false,
                            'inventory-detail__toggle--unknown': loc.pickable !== true && loc.pickable !== false
                          }"
                          @click="togglePickable(loc)"
                          :aria-pressed="loc.pickable === true"
                        >
                          <span class="inventory-detail__toggle-track">
                            <span class="inventory-detail__toggle-thumb" />
                          </span>
                          <span class="inventory-detail__toggle-label">
                            {{ loc.pickable === true ? "Yes" : (loc.pickable === false ? "No" : "—") }}
                          </span>
                        </button>
                        <span v-else>
                          {{ loc.pickable === true ? "Yes" : loc.pickable === false ? "No" : "—" }}
                        </span>
                      </td>
                      <td>{{ loc.quantity }}</td>
                      <td>{{ loc.type || "—" }}</td>
                      <td v-if="canManageInventoryLocations" class="text-center">
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

      <Teleport v-if="canManageInventoryLocations" to="body">
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

      <Teleport v-if="canManageInventoryLocations" to="body">
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

      <Teleport v-if="canManageInventoryLocations" to="body">
        <div v-if="transferModalOpen" class="crm-vx-modal-overlay" @click.self="transferModalOpen = false">
          <div class="crm-vx-modal crm-vx-modal--sm">
            <header class="crm-vx-modal__head">
              <h2 class="crm-vx-modal__title">Transfer QTY</h2>
            </header>
            <div class="crm-vx-modal__body">
              <label class="form-label small">Transfer To</label>
              <input v-model="transferForm.to_location" type="text" class="form-control mb-3" placeholder="Type location name" />
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

      <Teleport v-if="canManageInventoryLocations" to="body">
        <div v-if="addLocationModalOpen" class="crm-vx-modal-overlay" @click.self="addLocationModalOpen = false">
          <div class="crm-vx-modal crm-vx-modal--sm">
            <header class="crm-vx-modal__head">
              <h2 class="crm-vx-modal__title">Add Location</h2>
            </header>
            <div class="crm-vx-modal__body">
              <label class="form-label small">Location</label>
              <input v-model="addLocationForm.location" type="text" class="form-control mb-3" placeholder="Type location name" />
              <label class="form-label small">QTY</label>
              <input v-model="addLocationForm.quantity" type="number" min="0" class="form-control mb-3" />
              <label class="form-label small">Reason</label>
              <select v-model="addLocationForm.reason" class="form-select">
                <option v-for="reason in inventoryReasons" :key="reason" :value="reason">{{ reason }}</option>
              </select>
            </div>
            <footer class="crm-vx-modal__footer">
              <button type="button" class="crm-vx-modal-btn crm-vx-modal-btn--secondary" :disabled="saving" @click="addLocationModalOpen = false">
                Cancel
              </button>
              <button type="button" class="crm-vx-modal-btn crm-vx-modal-btn--primary" :disabled="saving" @click="submitAddLocationQty">
                {{ saving ? "Please wait..." : "Update" }}
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
  border: 1px solid rgba(15, 23, 42, 0.12);
  border-radius: 999px;
  padding: 0.25rem 0.55rem;
  font-size: 0.78rem;
  font-weight: 600;
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
  background: #fff;
  color: #334155;
}
.inventory-detail__toggle-track {
  width: 34px;
  height: 20px;
  border-radius: 999px;
  background: #d1d5db;
  position: relative;
  transition: background-color 0.15s ease;
}
.inventory-detail__toggle-thumb {
  width: 14px;
  height: 14px;
  border-radius: 50%;
  background: #fff;
  position: absolute;
  left: 3px;
  top: 3px;
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.25);
  transition: transform 0.15s ease;
}
.inventory-detail__toggle-label {
  min-width: 22px;
  text-align: left;
}
.inventory-detail__toggle--on {
  border-color: rgba(34, 197, 94, 0.45);
  color: #166534;
}
.inventory-detail__toggle--on .inventory-detail__toggle-track {
  background: #22c55e;
}
.inventory-detail__toggle--on .inventory-detail__toggle-thumb {
  transform: translateX(14px);
}
.inventory-detail__toggle--off {
  border-color: rgba(239, 68, 68, 0.4);
  color: #991b1b;
}
.inventory-detail__toggle--off .inventory-detail__toggle-track {
  background: #ef4444;
}
.inventory-detail__toggle--unknown {
  border-color: rgba(100, 116, 139, 0.35);
  color: #475569;
}
.inventory-detail__toggle--unknown .inventory-detail__toggle-track {
  background: #94a3b8;
}
.inventory-metric-card {
  text-align: left;
  position: relative;
  min-height: 96px;
}
.inventory-metric-card__head {
  display: block;
  margin-bottom: 0.5rem;
}
.inventory-metric-card__left {
  padding-right: 56px;
}
.inventory-metric-card__right {
  width: 44px;
  height: 44px;
  position: absolute;
  right: 0.75rem;
  top: 50%;
  transform: translateY(-50%);
  display: flex;
  align-items: center;
  justify-content: center;
}
.inventory-metric-card__icon {
  width: 44px;
  height: 44px;
  padding: 9px;
  border-radius: 999px;
  flex-shrink: 0;
  margin: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  line-height: 0;
}
.inventory-metric-card__icon path {
  display: block;
}
.staff-table-toolbar .btn.btn-primary.staff-toolbar-btn {
  color: #fff;
}
.inventory-metric-card__icon--blue {
  color: #1d4ed8;
  background: #dbeafe;
}
.inventory-metric-card__icon--amber {
  color: #b45309;
  background: #fef3c7;
}
.inventory-metric-card__icon--green {
  color: #15803d;
  background: #dcfce7;
}
.inventory-metric-card__icon--red {
  color: #b91c1c;
  background: #fee2e2;
}
.inventory-metric-card__icon--purple {
  color: #6d28d9;
  background: #ede9fe;
}
</style>
