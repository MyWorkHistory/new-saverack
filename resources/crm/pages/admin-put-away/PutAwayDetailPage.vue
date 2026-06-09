<script setup>
import { computed, onMounted, onUnmounted, reactive, ref, watch } from "vue";
import { RouterLink, useRoute } from "vue-router";
import api from "../../services/api";
import CrmIconRowActions from "../../components/common/CrmIconRowActions.vue";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import ConfirmModal from "../../components/common/ConfirmModal.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast.js";
import { errorMessage as apiErrorMessage } from "../../utils/apiError.js";

const RECEIVING_LOCATION_NAME = "Receiving";
const PUT_AWAY_REASON = "Inbound Receiving Adjustments";
const DELETE_LOCATION_REASON = "Inventory Reclassification";

const route = useRoute();
const toast = useToast();

const loading = ref(true);
const refreshing = ref(false);
const saving = ref(false);
const product = ref(null);
const productLoadError = ref("");

const locationSearch = ref("");
const locationFilterMenuOpen = ref(false);
const locationBinTypeFilter = ref("");
const locationPickableFilter = ref("");
const actionMenuLocationId = ref(null);
const actionMenuRect = ref({ top: 0, left: 0 });

const transferAllOpen = ref(false);
const transferAllLocation = ref("");
const transferAllFromRow = ref(false);
const transferAllConfirmOpen = ref(false);

const partialTransferOpen = ref(false);
const partialTransferQty = ref("");
const partialTransferLocation = ref("");

const deleteLocationOpen = ref(false);
const deleteLocationTarget = ref(null);

const addLocationModalOpen = ref(false);
const addLocationForm = reactive({ location: "", quantity: "", reason: DELETE_LOCATION_REASON });

const clientAccountId = computed(() => Number(route.query.client_account_id || 0));

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

function isReceivingLocation(loc) {
  return String(loc?.location_name || "").trim().toLowerCase() === RECEIVING_LOCATION_NAME.toLowerCase();
}

const receivingLocation = computed(
  () => allLocations.value.find((loc) => isReceivingLocation(loc)) ?? null,
);

const receivingQty = computed(() => Number(receivingLocation.value?.quantity ?? 0));

const putAwayMetrics = computed(() => {
  let pickable = 0;
  let nonPickable = 0;
  allLocations.value.forEach((loc) => {
    const qty = Number(loc.quantity ?? 0);
    if (loc.pickable === true) pickable += qty;
    else if (loc.pickable === false) nonPickable += qty;
  });
  const m = product.value?.metrics || {};
  return {
    receiving: receivingQty.value,
    pickable,
    non_pickable: nonPickable,
    on_hand: Number(m.on_hand ?? 0),
    backorder: Number(m.backorder ?? 0),
  };
});

const metricCards = computed(() =>
  [
    {
      key: "receiving",
      label: "Receiving",
      tone: "blue",
      iconPath: "M3 7.5 12 3l9 4.5v9L12 21l-9-4.5z M12 12l9-4.5 M12 12 3 7.5 M12 12v9",
    },
    {
      key: "pickable",
      label: "Pickable",
      tone: "amber",
      iconPath: "M4 8h16M4 12h16M4 16h16M7 5h10",
    },
    {
      key: "non_pickable",
      label: "Non-Pickable",
      tone: "green",
      iconPath: "M4 12l5 5 11-11",
    },
    {
      key: "on_hand",
      label: "On-Hand",
      tone: "purple",
      iconPath: "M2 13h11l2-3h7v7h-2 M6 17a2 2 0 1 0 0 .01 M18 17a2 2 0 1 0 0 .01",
    },
    {
      key: "backorder",
      label: "Backorder",
      tone: "red",
      iconPath: "M12 7v6 M12 17h.01 M3 12a9 9 0 1 0 18 0 9 9 0 1 0-18 0",
    },
  ].map((item) => ({
    ...item,
    value: Number(putAwayMetrics.value[item.key] ?? 0),
  })),
);

const locationBinTypeOptions = computed(() => {
  const types = new Set();
  allLocations.value.forEach((loc) => {
    const t = String(loc.type || "").trim();
    if (t) types.add(t);
  });
  return [...types].sort((a, b) => a.localeCompare(b));
});

const filteredLocations = computed(() => {
  let rows = allLocations.value;
  const q = locationSearch.value.trim().toLowerCase();
  if (q) {
    rows = rows.filter((loc) =>
      String(loc.location_name || loc.location_id || "").toLowerCase().includes(q),
    );
  }
  if (locationBinTypeFilter.value) {
    rows = rows.filter((loc) => String(loc.type || "") === locationBinTypeFilter.value);
  }
  if (locationPickableFilter.value === "yes") {
    rows = rows.filter((loc) => loc.pickable === true);
  } else if (locationPickableFilter.value === "no") {
    rows = rows.filter((loc) => loc.pickable === false);
  }
  return rows;
});

const canTransfer = computed(() => receivingQty.value > 0 && Boolean(receivingLocation.value));

function displayVal(v) {
  if (v === null || v === undefined) return "—";
  if (typeof v === "string" && v.trim() === "") return "—";
  return v;
}

function requestParams({ refresh = false } = {}) {
  const params = {};
  if (clientAccountId.value > 0) params.client_account_id = clientAccountId.value;
  if (route.query.warehouse_id) params.warehouse_id = String(route.query.warehouse_id);
  if (refresh) params.refresh = 1;
  return params;
}

function applyWarehouseSliceToProduct(warehouseSlice) {
  if (!product.value || !warehouseSlice?.warehouse_id) return;
  const whId = String(warehouseSlice.warehouse_id);
  const incomingLocs = Array.isArray(warehouseSlice.locations) ? warehouseSlice.locations : [];
  if (incomingLocs.length === 0) return;

  const qtyByLocId = new Map();
  incomingLocs.forEach((loc) => {
    const id = String(loc.location_id || "").trim();
    if (id) qtyByLocId.set(id, Number(loc.quantity ?? 0));
  });

  const warehouses = Array.isArray(product.value.warehouses) ? [...product.value.warehouses] : [];
  let whIndex = warehouses.findIndex((wh) => String(wh.warehouse_id || "") === whId);
  if (whIndex < 0) {
    warehouses.push({
      warehouse_id: whId,
      warehouse_name: warehouseSlice.warehouse_name || "",
      locations: incomingLocs.map((loc) => ({ ...loc })),
    });
  } else {
    const wh = { ...warehouses[whIndex] };
    const locations = Array.isArray(wh.locations) ? [...wh.locations] : [];
    const nextLocations = locations.map((loc) => {
      const id = String(loc.location_id || "");
      if (!qtyByLocId.has(id)) return { ...loc };
      return { ...loc, quantity: qtyByLocId.get(id) };
    });
    incomingLocs.forEach((inc) => {
      const id = String(inc.location_id || "");
      if (!id || nextLocations.some((row) => String(row.location_id) === id)) return;
      nextLocations.push({ ...inc });
    });
    wh.locations = nextLocations;
    warehouses[whIndex] = wh;
  }

  product.value = { ...product.value, warehouses };
}

function activeWarehouseId() {
  const routeWarehouse = String(route.query.warehouse_id || "").trim();
  if (routeWarehouse) return routeWarehouse;
  return String(product.value?.warehouses?.[0]?.warehouse_id || receivingLocation.value?.warehouse_id || "").trim();
}

async function loadProduct({ refresh = false } = {}) {
  productLoadError.value = "";
  try {
    const sku = String(route.params.sku || "").trim();
    const { data } = await api.get(`/inventory/products/${encodeURIComponent(sku)}`, {
      params: requestParams({ refresh }),
    });
    product.value = data?.product ?? null;
    return true;
  } catch (e) {
    productLoadError.value = apiErrorMessage(e, "Could not load product.");
    toast.errorFrom(e, "Could not load product.");
    return false;
  }
}

async function loadDetail() {
  loading.value = true;
  await loadProduct({ refresh: false });
  loading.value = false;
}

async function refreshDetail() {
  if (loading.value || refreshing.value) return;
  refreshing.value = true;
  await loadProduct({ refresh: true });
  refreshing.value = false;
}

function onDocClick(e) {
  if (!e.target?.closest?.("[data-row-actions]")) {
    actionMenuLocationId.value = null;
  }
  if (!e.target?.closest?.("[data-toolbar-filter]")) {
    locationFilterMenuOpen.value = false;
  }
}

function placeActionMenu(anchorEl) {
  if (!(anchorEl instanceof HTMLElement)) return;
  const rect = anchorEl.getBoundingClientRect();
  const width = 190;
  const height = 120;
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

function openTransferAllModal(fromRow = false) {
  if (!canTransfer.value) {
    toast.error("No quantity in Receiving to transfer.");
    return;
  }
  transferAllFromRow.value = fromRow;
  transferAllLocation.value = "";
  transferAllOpen.value = true;
  actionMenuLocationId.value = null;
}

function openPartialTransferModal() {
  if (!canTransfer.value) {
    toast.error("No quantity in Receiving to transfer.");
    return;
  }
  partialTransferQty.value = "";
  partialTransferLocation.value = "";
  partialTransferOpen.value = true;
  actionMenuLocationId.value = null;
}

function openPartialTransferFromMenu() {
  openPartialTransferModal();
}

function submitTransferAllLocation() {
  if (!transferAllLocation.value.trim()) {
    toast.error("Enter a location.");
    return;
  }
  transferAllOpen.value = false;
  if (transferAllFromRow.value) {
    transferAllConfirmOpen.value = true;
  } else {
    executeTransfer(receivingQty.value, transferAllLocation.value.trim());
  }
}

async function confirmTransferAll() {
  transferAllConfirmOpen.value = false;
  await executeTransfer(receivingQty.value, transferAllLocation.value.trim());
}

async function submitPartialTransfer() {
  const qty = parseInt(String(partialTransferQty.value || ""), 10);
  if (Number.isNaN(qty) || qty <= 0) {
    toast.error("Enter a valid quantity.");
    return;
  }
  if (qty > receivingQty.value) {
    toast.error(`Quantity cannot exceed Receiving qty (${receivingQty.value}).`);
    return;
  }
  if (!partialTransferLocation.value.trim()) {
    toast.error("Enter a location.");
    return;
  }
  partialTransferOpen.value = false;
  await executeTransfer(qty, partialTransferLocation.value.trim());
}

async function executeTransfer(quantity, toLocation) {
  if (!product.value || !receivingLocation.value) return;
  saving.value = true;
  try {
    const body = {
      sku: product.value.sku,
      warehouse_id: receivingLocation.value.warehouse_id,
      from_location_id: receivingLocation.value.location_id,
      to_location: toLocation,
      quantity,
      reason: PUT_AWAY_REASON,
    };
    if (clientAccountId.value > 0) {
      body.client_account_id = clientAccountId.value;
    }
    const { data } = await api.post("/inventory/transfer", body);
    applyWarehouseSliceToProduct(data?.warehouse);
    toast.success("Quantity transferred.");
    await loadProduct({ refresh: true });
    applyWarehouseSliceToProduct(data?.warehouse);
  } catch (e) {
    toast.errorFrom(e, "Could not transfer quantity.");
  } finally {
    saving.value = false;
  }
}

function openDeleteLocationModal(loc) {
  deleteLocationTarget.value = loc;
  deleteLocationOpen.value = true;
  actionMenuLocationId.value = null;
}

async function confirmDeleteLocation() {
  const loc = deleteLocationTarget.value;
  if (!loc || !product.value) return;
  saving.value = true;
  try {
    const body = {
      sku: product.value.sku,
      warehouse_id: loc.warehouse_id,
      location_id: loc.location_id,
      quantity: 0,
      reason: DELETE_LOCATION_REASON,
    };
    if (clientAccountId.value > 0) {
      body.client_account_id = clientAccountId.value;
    }
    const { data } = await api.post("/inventory/replace", body);
    applyWarehouseSliceToProduct(data?.warehouse);
    toast.success("Location cleared.");
    deleteLocationOpen.value = false;
    deleteLocationTarget.value = null;
    await loadProduct({ refresh: true });
    applyWarehouseSliceToProduct(data?.warehouse);
  } catch (e) {
    toast.errorFrom(e, "Could not delete location.");
  } finally {
    saving.value = false;
  }
}

function openAddLocationModal() {
  addLocationForm.location = "";
  addLocationForm.quantity = "";
  addLocationModalOpen.value = true;
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
      reason: DELETE_LOCATION_REASON,
    };
    if (clientAccountId.value > 0) {
      body.client_account_id = clientAccountId.value;
    }
    const { data } = await api.post("/inventory/locations/add-qty", body);
    applyWarehouseSliceToProduct(data?.warehouse);
    toast.success("Location quantity updated.");
    addLocationModalOpen.value = false;
    await loadProduct({ refresh: true });
    applyWarehouseSliceToProduct(data?.warehouse);
  } catch (e) {
    toast.errorFrom(e, "Location not found or quantity update failed.");
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
    if (clientAccountId.value > 0) {
      body.client_account_id = clientAccountId.value;
    }
    await api.post("/inventory/locations/pickable", body);
    const warehouses = Array.isArray(product.value?.warehouses) ? product.value.warehouses : [];
    warehouses.forEach((wh) => {
      (wh?.locations || []).forEach((row) => {
        if (String(row?.location_id) === String(loc.location_id)) {
          row.pickable = nextPickable;
        }
      });
    });
    toast.success("Pickable updated.");
    await loadProduct({ refresh: true });
  } catch (e) {
    toast.errorFrom(e, "Could not update pickable.");
  } finally {
    saving.value = false;
  }
}

function clearLocationFilters() {
  locationBinTypeFilter.value = "";
  locationPickableFilter.value = "";
}

watch(
  () => String(route.params.sku || "").trim(),
  (next, prev) => {
    if (next && next !== prev) loadDetail();
  },
);

onMounted(() => {
  setCrmPageMeta({
    title: "Save Rack | Put Away Detail",
    description: "Put away product locations and transfers.",
  });
  loadDetail();
  document.addEventListener("click", onDocClick);
});

onUnmounted(() => {
  document.removeEventListener("click", onDocClick);
});
</script>

<template>
  <div class="staff-page staff-page--wide">
    <div v-if="loading" class="py-5 text-center">
      <CrmLoadingSpinner message="Loading Product..." :center="true" />
    </div>

    <template v-else>
      <p v-if="productLoadError" class="alert alert-warning small">{{ productLoadError }}</p>
      <div v-else-if="!product" class="text-secondary small py-4 text-center">Product not found.</div>

      <div v-else class="staff-user-view staff-page--wide inventory-portal-detail">
        <nav class="staff-user-view__breadcrumb d-flex flex-wrap align-items-center gap-1" aria-label="Breadcrumb">
          <RouterLink to="/admin/receiving/put-away" class="fw-bold">Put Away</RouterLink>
          <span class="text-secondary" aria-hidden="true">/</span>
          <span class="text-body-secondary fw-bold">{{ product.sku || "Detail" }}</span>
        </nav>

        <div class="staff-user-view__title-row inventory-portal-detail__title-row d-flex flex-wrap align-items-center gap-2 mb-3">
          <div class="me-auto" />
          <button
            type="button"
            class="btn btn-outline-secondary btn-sm orders-toolbar-outline-btn"
            :disabled="loading || refreshing"
            @click="refreshDetail"
          >
            {{ refreshing ? "Refreshing…" : "Refresh" }}
          </button>
        </div>

        <div class="row g-2 inventory-portal-detail__metrics">
          <div v-for="card in metricCards" :key="card.key" class="col-6 col-md">
            <div class="staff-table-card p-3 inventory-metric-card inventory-portal-detail__metric-card h-100">
              <div class="inventory-metric-card__head">
                <div class="inventory-metric-card__left">
                  <div class="inventory-portal-detail__metric-label">{{ card.label }}</div>
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
              <div class="inventory-portal-detail__metric-value">{{ card.value }}</div>
            </div>
          </div>
        </div>

        <div class="row g-3">
          <div class="col-12 col-xl-4">
            <aside class="staff-user-profile">
              <div class="inventory-portal-detail__hero">
                <img
                  v-if="product.image_url"
                  :src="product.image_url"
                  alt=""
                  class="inventory-portal-detail__hero-image"
                />
                <div v-else class="inventory-portal-detail__hero-image inventory-portal-detail__hero-image--empty" />
              </div>
              <h1 class="inventory-portal-detail__product-title h5 fw-bold mb-1">{{ product.name || "—" }}</h1>
              <p class="small text-secondary mb-3">{{ product.sku || "—" }}</p>

              <dl class="inventory-portal-detail__details mb-4">
                <div class="inventory-portal-detail__detail-row">
                  <dt>SKU</dt>
                  <dd>{{ displayVal(product.sku) }}</dd>
                </div>
                <div class="inventory-portal-detail__detail-row">
                  <dt>Barcode</dt>
                  <dd>{{ displayVal(product.barcode) }}</dd>
                </div>
              </dl>

              <div class="d-grid gap-2">
                <button
                  type="button"
                  class="btn btn-primary staff-page-primary fw-semibold"
                  :disabled="!canTransfer || saving"
                  @click="openTransferAllModal(false)"
                >
                  Transfer All
                </button>
                <button
                  type="button"
                  class="btn btn-outline-primary fw-semibold"
                  :disabled="!canTransfer || saving"
                  @click="openPartialTransferModal"
                >
                  Partial Transfer
                </button>
              </div>
            </aside>
          </div>

          <div class="col-12 col-xl-8">
            <div class="staff-table-card staff-datatable-card staff-datatable-card--white p-0">
              <div class="staff-table-toolbar border-bottom">
                <div class="staff-table-toolbar--row flex-wrap align-items-end gap-2 gap-md-3">
                  <input
                    v-model="locationSearch"
                    type="search"
                    class="form-control staff-toolbar-search staff-toolbar-search--inline"
                    placeholder="Search locations"
                    autocomplete="off"
                    aria-label="Search locations"
                  />
                  <div class="position-relative flex-shrink-0" data-toolbar-filter>
                    <button
                      type="button"
                      class="btn btn-outline-secondary staff-toolbar-btn d-inline-flex align-items-center gap-2"
                      :aria-expanded="locationFilterMenuOpen"
                      @click.stop="locationFilterMenuOpen = !locationFilterMenuOpen"
                    >
                      Filters
                    </button>
                    <div
                      v-if="locationFilterMenuOpen"
                      class="dropdown-menu dropdown-menu-end show shadow border p-3 staff-toolbar-filter-dropdown"
                      @click.stop
                    >
                      <label class="form-label small">Type</label>
                      <select v-model="locationBinTypeFilter" class="form-select form-select-sm mb-2">
                        <option value="">All</option>
                        <option v-for="t in locationBinTypeOptions" :key="t" :value="t">{{ t }}</option>
                      </select>
                      <label class="form-label small">Pickable</label>
                      <select v-model="locationPickableFilter" class="form-select form-select-sm mb-2">
                        <option value="">All</option>
                        <option value="yes">Yes</option>
                        <option value="no">No</option>
                      </select>
                      <button type="button" class="btn btn-link btn-sm p-0" @click="clearLocationFilters">Reset</button>
                    </div>
                  </div>
                  <div class="d-flex flex-wrap align-items-center ms-md-auto">
                    <button type="button" class="btn btn-primary btn-sm staff-toolbar-btn" @click="openAddLocationModal">
                      Add Location
                    </button>
                  </div>
                </div>
              </div>

              <div class="table-responsive inventory-portal-detail__table-wrap">
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
                      <td colspan="5" class="text-center text-secondary py-4">No locations found for this product.</td>
                    </tr>
                    <tr v-for="loc in filteredLocations" :key="`${loc.warehouse_id}-${loc.location_id}`">
                      <td>{{ loc.location_name || loc.location_id }}</td>
                      <td>
                        <button
                          type="button"
                          class="inventory-detail__toggle"
                          :class="{
                            'inventory-detail__toggle--on': loc.pickable === true,
                            'inventory-detail__toggle--off': loc.pickable === false,
                            'inventory-detail__toggle--unknown': loc.pickable !== true && loc.pickable !== false,
                          }"
                          :aria-pressed="loc.pickable === true"
                          @click="togglePickable(loc)"
                        >
                          <span class="inventory-detail__toggle-track">
                            <span class="inventory-detail__toggle-thumb" />
                          </span>
                          <span class="inventory-detail__toggle-label">
                            {{ loc.pickable === true ? "Yes" : loc.pickable === false ? "No" : "—" }}
                          </span>
                        </button>
                      </td>
                      <td>{{ loc.quantity }}</td>
                      <td>{{ loc.type || "—" }}</td>
                      <td class="text-center">
                        <div v-if="isReceivingLocation(loc)" class="d-flex align-items-center justify-content-center gap-1">
                          <button
                            type="button"
                            class="btn btn-sm btn-primary staff-page-primary"
                            :disabled="!canTransfer || saving"
                            @click="openTransferAllModal(true)"
                          >
                            Transfer All
                          </button>
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
                        </div>
                        <div v-else data-row-actions class="d-inline-flex">
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
          </div>
        </div>
      </div>
    </template>

    <Teleport to="body">
      <div
        v-if="actionMenuLocationId"
        data-row-actions
        class="staff-row-menu fixed z-[300] overflow-hidden"
        role="menu"
        :style="{ top: `${actionMenuRect.top}px`, left: `${actionMenuRect.left}px` }"
        @click.stop
      >
        <template v-if="isReceivingLocation(currentMenuLocation())">
          <button type="button" class="staff-row-menu__item" role="menuitem" @click="openPartialTransferFromMenu">
            Partial Transfer
          </button>
          <button
            type="button"
            class="staff-row-menu__item staff-row-menu__item--danger"
            role="menuitem"
            @click="openDeleteLocationModal(currentMenuLocation())"
          >
            Delete Location
          </button>
        </template>
        <button
          v-else
          type="button"
          class="staff-row-menu__item staff-row-menu__item--danger"
          role="menuitem"
          @click="openDeleteLocationModal(currentMenuLocation())"
        >
          Delete Location
        </button>
      </div>
    </Teleport>

    <div v-if="transferAllOpen" class="crm-vx-modal-overlay" @click.self="transferAllOpen = false">
      <div class="crm-vx-modal crm-vx-modal--sm">
        <header class="crm-vx-modal__head">
          <h2 class="crm-vx-modal__title">Transfer All</h2>
        </header>
        <div class="crm-vx-modal__body">
          <p class="small text-secondary mb-3">
            Transfer all {{ receivingQty }} unit(s) from Receiving to the location below.
          </p>
          <label class="form-label small">Location</label>
          <input v-model="transferAllLocation" type="text" class="form-control mb-3" autocomplete="off" />
        </div>
        <footer class="crm-vx-modal__foot">
          <button type="button" class="crm-vx-modal-btn crm-vx-modal-btn--secondary" @click="transferAllOpen = false">
            Cancel
          </button>
          <button
            type="button"
            class="crm-vx-modal-btn crm-vx-modal-btn--primary"
            :disabled="saving"
            @click="submitTransferAllLocation"
          >
            Save
          </button>
        </footer>
      </div>
    </div>

    <ConfirmModal
      :open="transferAllConfirmOpen"
      title="Confirm Transfer All"
      :message="`All items from Receiving will be transferred to location: ${transferAllLocation.trim() || '—'}.`"
      confirm-label="Confirm"
      cancel-label="Cancel"
      :busy="saving"
      :danger="false"
      @close="transferAllConfirmOpen = false"
      @confirm="confirmTransferAll"
    />

    <div v-if="partialTransferOpen" class="crm-vx-modal-overlay" @click.self="partialTransferOpen = false">
      <div class="crm-vx-modal crm-vx-modal--sm">
        <header class="crm-vx-modal__head">
          <h2 class="crm-vx-modal__title">Partial Transfer</h2>
        </header>
        <div class="crm-vx-modal__body">
          <label class="form-label small">QTY</label>
          <input v-model="partialTransferQty" type="number" min="1" class="form-control mb-3" />
          <label class="form-label small">Location</label>
          <input v-model="partialTransferLocation" type="text" class="form-control mb-3" autocomplete="off" />
        </div>
        <footer class="crm-vx-modal__foot">
          <button type="button" class="crm-vx-modal-btn crm-vx-modal-btn--secondary" @click="partialTransferOpen = false">
            Cancel
          </button>
          <button
            type="button"
            class="crm-vx-modal-btn crm-vx-modal-btn--primary"
            :disabled="saving"
            @click="submitPartialTransfer"
          >
            Save
          </button>
        </footer>
      </div>
    </div>

    <ConfirmModal
      :open="deleteLocationOpen"
      title="Delete Location"
      :message="`Clear all quantity at location ${deleteLocationTarget?.location_name || deleteLocationTarget?.location_id || '—'}?`"
      confirm-label="Delete Location"
      cancel-label="Cancel"
      :busy="saving"
      :danger="true"
      @close="deleteLocationOpen = false"
      @confirm="confirmDeleteLocation"
    />

    <div v-if="addLocationModalOpen" class="crm-vx-modal-overlay" @click.self="addLocationModalOpen = false">
      <div class="crm-vx-modal crm-vx-modal--sm">
        <header class="crm-vx-modal__head">
          <h2 class="crm-vx-modal__title">Add Location</h2>
        </header>
        <div class="crm-vx-modal__body">
          <label class="form-label small">Location</label>
          <input v-model="addLocationForm.location" type="text" class="form-control mb-3" autocomplete="off" />
          <label class="form-label small">QTY</label>
          <input v-model="addLocationForm.quantity" type="number" min="0" class="form-control mb-3" />
        </div>
        <footer class="crm-vx-modal__foot">
          <button type="button" class="crm-vx-modal-btn crm-vx-modal-btn--secondary" @click="addLocationModalOpen = false">
            Cancel
          </button>
          <button
            type="button"
            class="crm-vx-modal-btn crm-vx-modal-btn--primary"
            :disabled="saving"
            @click="submitAddLocationQty"
          >
            Save
          </button>
        </footer>
      </div>
    </div>
  </div>
</template>

<style lang="scss">
@import "../../styles/inventory-portal-detail.scss";
</style>

<style scoped>
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
}
.inventory-detail__toggle-thumb {
  width: 14px;
  height: 14px;
  border-radius: 50%;
  background: #fff;
  position: absolute;
  left: 3px;
  top: 3px;
}
.inventory-detail__toggle--on .inventory-detail__toggle-track {
  background: #22c55e;
}
.inventory-detail__toggle--on .inventory-detail__toggle-thumb {
  transform: translateX(14px);
}
.inventory-detail__toggle--off .inventory-detail__toggle-track {
  background: #ef4444;
}
</style>