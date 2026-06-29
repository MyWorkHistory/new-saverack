<script setup>
import { computed, onMounted, onUnmounted, reactive, ref, watch, nextTick } from "vue";
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
const putAwayRow = ref(null);
const productLoadError = ref("");

const locationSearch = ref("");
const locationFilterMenuOpen = ref(false);
const locationBinTypeFilter = ref("");
const locationPickableFilter = ref("");
const actionMenuLocationId = ref(null);
const actionMenuRect = ref({ top: 0, left: 0 });

const transferModalOpen = ref(false);
const activeLocation = ref(null);
const updateModalOpen = ref(false);
const updateForm = reactive({ quantity: "", reason: "Client-Requested Adjustments" });
const transferForm = reactive({
  transfer_type: "new",
  to_location_id: "",
  to_location: "",
  quantity: "",
  reason: PUT_AWAY_REASON,
});
const defaultTransferReason = ref(PUT_AWAY_REASON);

const deleteLocationOpen = ref(false);
const deleteLocationTarget = ref(null);

const addLocationModalOpen = ref(false);
const addLocationForm = reactive({ location: "", quantity: "", reason: "Account Setup" });
const addLocationInputRef = ref(null);
const inventoryReasons = ref([
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
  "Restock",
  "Returns Processing",
  "Shipped via Shipstation",
  "System Sync or Integration Corrections",
]);
const defaultAddLocationReason = ref("Account Setup");

const clientAccountId = computed(() => Number(route.query.client_account_id || 0));

function isReceivingLocation(loc) {
  return String(loc?.location_name || "").trim().toLowerCase() === RECEIVING_LOCATION_NAME.toLowerCase();
}

function receivingQtyFromProduct(p) {
  if (!p?.warehouses) return 0;
  let total = 0;
  p.warehouses.forEach((wh) => {
    (wh.locations || []).forEach((loc) => {
      if (isReceivingLocation(loc)) {
        total += Number(loc?.quantity || 0);
      }
    });
  });
  return total;
}

const allLocations = computed(() => {
  const out = [];
  const p = product.value;
  if (!p?.warehouses) return out;
  p.warehouses.forEach((wh) => {
    (wh.locations || []).forEach((loc) => {
      out.push({
        ...loc,
        quantity: Number(loc?.quantity || 0),
        warehouse_id: wh.warehouse_id,
        warehouse_name: wh.warehouse_name,
      });
    });
  });
  return out;
});

// Same backend source as the put-away list (PutAwayRowBuilder via /admin/put-away/products/{sku}).
const putAwayMetrics = computed(() => ({
  receiving: Number(putAwayRow.value?.receiving_qty ?? 0),
  pickable: Number(putAwayRow.value?.pickable_qty ?? 0),
  non_pickable: Number(putAwayRow.value?.non_pickable_qty ?? 0),
  on_hand: Number(putAwayRow.value?.on_hand ?? 0),
  backorder: Number(putAwayRow.value?.backorder ?? 0),
}));

const receivingQty = computed(() =>
  Math.max(Number(putAwayMetrics.value.receiving ?? 0), receivingQtyFromProduct(product.value)),
);

const receivingLocation = computed(() => {
  const rowLoc = putAwayRow.value?.receiving_location;
  if (rowLoc?.location_id && rowLoc?.warehouse_id) {
    return {
      location_id: String(rowLoc.location_id),
      location_name: String(rowLoc.location_name || RECEIVING_LOCATION_NAME),
      warehouse_id: String(rowLoc.warehouse_id),
      quantity: receivingQty.value,
    };
  }

  const whId = activeWarehouseId();
  const p = product.value;
  if (!p?.warehouses) return null;
  for (const wh of p.warehouses) {
    if (whId && String(wh.warehouse_id || "") !== whId) continue;
    for (const loc of wh.locations || []) {
      if (!isReceivingLocation(loc)) continue;
      const locId = String(loc.location_id || "").trim();
      if (!locId) continue;
      return {
        ...loc,
        warehouse_id: wh.warehouse_id,
        warehouse_name: wh.warehouse_name,
        quantity: receivingQty.value,
      };
    }
  }
  for (const wh of p.warehouses) {
    for (const loc of wh.locations || []) {
      if (!isReceivingLocation(loc)) continue;
      const locId = String(loc.location_id || "").trim();
      if (!locId) continue;
      return {
        ...loc,
        warehouse_id: wh.warehouse_id,
        warehouse_name: wh.warehouse_name,
        quantity: receivingQty.value,
      };
    }
  }

  return null;
});

const transferDestinationOptions = computed(() => {
  const source = activeLocation.value;
  if (!source) return [];
  const whId = String(source.warehouse_id || "");
  const fromId = String(source.location_id || "");
  return allLocations.value.filter(
    (loc) => String(loc.warehouse_id || "") === whId && String(loc.location_id || "") !== fromId,
  );
});

const transferSourceMaxQty = computed(() => {
  const loc = activeLocation.value;
  if (!loc) return 0;
  if (isReceivingLocation(loc)) return receivingQty.value;
  return Number(loc.quantity || 0);
});

function metricsAllZero() {
  const m = putAwayMetrics.value;
  return (
    Number(m.receiving || 0) === 0
    && Number(m.pickable || 0) === 0
    && Number(m.non_pickable || 0) === 0
    && Number(m.on_hand || 0) === 0
    && Number(m.backorder || 0) === 0
  );
}

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
      label: "On Hand",
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
    value:
      item.key === "receiving"
        ? receivingQty.value
        : Number(putAwayMetrics.value[item.key] ?? 0),
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

const canTransfer = computed(() => receivingQty.value > 0 && Boolean(receivingLocation.value?.location_id));

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

async function loadPutAwayRow({ refresh = false } = {}) {
  if (!clientAccountId.value) {
    putAwayRow.value = null;
    return false;
  }
  try {
    const sku = String(route.params.sku || "").trim();
    const params = { client_account_id: clientAccountId.value };
    if (refresh) params.refresh = 1;
    const { data } = await api.get(`/admin/put-away/products/${encodeURIComponent(sku)}`, { params });
    putAwayRow.value = data?.row ?? null;
    return true;
  } catch {
    putAwayRow.value = null;
    return false;
  }
}

async function reloadProductData({ refresh = false } = {}) {
  await Promise.all([loadProduct({ refresh }), loadPutAwayRow({ refresh })]);
}

async function loadDetail() {
  loading.value = true;
  await reloadProductData({ refresh: false });
  if (product.value && metricsAllZero()) {
    await reloadProductData({ refresh: true });
  }
  loading.value = false;
}

async function refreshDetail() {
  if (loading.value || refreshing.value) return;
  refreshing.value = true;
  await reloadProductData({ refresh: true });
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

function locationQty(loc) {
  if (!loc) return 0;
  if (isReceivingLocation(loc)) return receivingQty.value;
  return Number(loc.quantity || 0);
}

function openUpdateQtyModal() {
  const loc = currentMenuLocation();
  if (!loc) return;
  activeLocation.value = loc;
  updateForm.quantity = String(locationQty(loc));
  updateModalOpen.value = true;
  actionMenuLocationId.value = null;
}

function openTransferQtyModal() {
  const loc = currentMenuLocation();
  if (!loc) return;
  activeLocation.value = loc;
  transferForm.transfer_type = "current";
  transferForm.to_location_id = "";
  transferForm.to_location = "";
  transferForm.quantity = "";
  transferForm.reason = defaultTransferReason.value;
  transferModalOpen.value = true;
  actionMenuLocationId.value = null;
}

function openTransferAllModal() {
  if (!canTransfer.value) {
    toast.error("No quantity in Receiving to transfer.");
    return;
  }
  activeLocation.value = receivingLocation.value;
  transferForm.transfer_type = "new";
  transferForm.to_location_id = "";
  transferForm.to_location = "";
  transferForm.quantity = String(receivingQty.value);
  transferForm.reason = defaultTransferReason.value;
  transferModalOpen.value = true;
  actionMenuLocationId.value = null;
}

function openPartialTransferModal() {
  if (!canTransfer.value) {
    toast.error("No quantity in Receiving to transfer.");
    return;
  }
  activeLocation.value = receivingLocation.value;
  transferForm.transfer_type = "new";
  transferForm.to_location_id = "";
  transferForm.to_location = "";
  transferForm.quantity = "";
  transferForm.reason = defaultTransferReason.value;
  transferModalOpen.value = true;
  actionMenuLocationId.value = null;
}

function fillTransferAllQty() {
  transferForm.quantity = String(transferSourceMaxQty.value);
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
    if (clientAccountId.value > 0) {
      body.client_account_id = clientAccountId.value;
    }
    const { data } = await api.post("/inventory/replace", body);
    applyWarehouseSliceToProduct(data?.warehouse);
    toast.success("Quantity updated.");
    updateModalOpen.value = false;
    await reloadProductData({ refresh: true });
    applyWarehouseSliceToProduct(data?.warehouse);
  } catch (e) {
    toast.errorFrom(e, "Could not update quantity.");
  } finally {
    saving.value = false;
  }
}

async function submitTransferQty() {
  if (!product.value || !activeLocation.value) return;
  const maxQty = transferSourceMaxQty.value;
  const qty = parseInt(String(transferForm.quantity || ""), 10);
  if (Number.isNaN(qty) || qty <= 0) {
    toast.error("Enter a valid transfer quantity.");
    return;
  }
  if (qty > maxQty) {
    toast.error(`Quantity cannot exceed available qty (${maxQty}).`);
    return;
  }
  if (transferForm.transfer_type === "current") {
    if (!String(transferForm.to_location_id || "").trim()) {
      toast.error("Select a destination location.");
      return;
    }
  } else if (!transferForm.to_location.trim()) {
    toast.error("Enter destination location.");
    return;
  }
  saving.value = true;
  try {
    const body = {
      sku: product.value.sku,
      warehouse_id: activeLocation.value.warehouse_id,
      from_location_id: activeLocation.value.location_id,
      quantity: qty,
      reason: transferForm.reason,
    };
    if (transferForm.transfer_type === "current") {
      body.to_location_id = String(transferForm.to_location_id).trim();
    } else {
      body.to_location = transferForm.to_location.trim();
    }
    if (clientAccountId.value > 0) {
      body.client_account_id = clientAccountId.value;
    }
    const { data } = await api.post("/inventory/transfer", body);
    applyWarehouseSliceToProduct(data?.warehouse);
    toast.success("Quantity transferred.");
    transferModalOpen.value = false;
    await reloadProductData({ refresh: true });
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
    await reloadProductData({ refresh: true });
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
  addLocationForm.reason = defaultAddLocationReason.value;
  addLocationModalOpen.value = true;
  nextTick(() => addLocationInputRef.value?.focus());
}

async function loadAdjustmentReasons() {
  try {
    const { data } = await api.get("/inventory/adjustment-reasons");
    const reasons = Array.isArray(data?.reasons) ? data.reasons.filter(Boolean) : [];
    if (reasons.length) {
      inventoryReasons.value = reasons;
    }
    const defaultReason = String(data?.default_transfer_reason || "").trim();
    if (defaultReason) {
      defaultTransferReason.value = defaultReason;
    }
    const addLocationReason = String(data?.default_add_location_reason || "").trim();
    if (addLocationReason) {
      defaultAddLocationReason.value = addLocationReason;
    }
    if (inventoryReasons.value.includes(PUT_AWAY_REASON)) {
      transferForm.reason = PUT_AWAY_REASON;
      defaultTransferReason.value = PUT_AWAY_REASON;
    } else {
      transferForm.reason = defaultTransferReason.value;
    }
  } catch {
    /* keep fallback list */
  }
}

async function submitAddLocationQty() {
  if (!product.value) return;
  const rawQty = String(addLocationForm.quantity ?? "").trim();
  const qty = rawQty === "" ? 0 : parseInt(rawQty, 10);
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
    if (clientAccountId.value > 0) {
      body.client_account_id = clientAccountId.value;
    }
    const { data } = await api.post("/inventory/locations/add-qty", body);
    applyWarehouseSliceToProduct(data?.warehouse);
    toast.success("Location quantity updated.");
    addLocationModalOpen.value = false;
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
    await reloadProductData({ refresh: true });
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
  loadAdjustmentReasons();
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
              <div class="inventory-portal-detail__metric-value">{{ card.value.toLocaleString() }}</div>
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
                  :disabled="saving"
                  @click="openTransferAllModal"
                >
                  Transfer All
                </button>
                <button
                  type="button"
                  class="btn btn-outline-primary fw-semibold"
                  :disabled="saving"
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
                      <td>{{ locationQty(loc) }}</td>
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
        <button type="button" class="staff-row-menu__item" role="menuitem" @click="openUpdateQtyModal">
          Update QTY
        </button>
        <button
          v-if="locationQty(currentMenuLocation()) > 0"
          type="button"
          class="staff-row-menu__item"
          role="menuitem"
          @click="openTransferQtyModal"
        >
          Transfer To
        </button>
        <button
          v-if="locationQty(currentMenuLocation()) === 0"
          type="button"
          class="staff-row-menu__item staff-row-menu__item--danger"
          role="menuitem"
          @click="openDeleteLocationModal(currentMenuLocation())"
        >
          Delete Location
        </button>
      </div>
    </Teleport>

    <Teleport to="body">
      <div v-if="transferModalOpen" class="crm-vx-modal-overlay" @click.self="transferModalOpen = false">
        <div class="crm-vx-modal crm-vx-modal--sm">
          <header class="crm-vx-modal__head">
            <h2 class="crm-vx-modal__title">Transfer QTY</h2>
          </header>
          <div class="crm-vx-modal__body">
            <p class="small text-secondary mb-1">
              Transfer From: {{ activeLocation?.location_name || activeLocation?.location_id || "—" }}
            </p>
            <p class="small text-secondary mb-3">QTY: {{ transferSourceMaxQty }}</p>
            <label class="form-label small" for="put-away-transfer-type">Transfer Type</label>
            <select id="put-away-transfer-type" v-model="transferForm.transfer_type" class="form-select mb-3">
              <option value="current">Current Locations</option>
              <option value="new">Transfer New</option>
            </select>
            <label class="form-label small" for="put-away-transfer-to">Transfer To</label>
            <select
              v-if="transferForm.transfer_type === 'current'"
              id="put-away-transfer-to"
              v-model="transferForm.to_location_id"
              class="form-select mb-3"
            >
              <option value="">Select location</option>
              <option
                v-for="dest in transferDestinationOptions"
                :key="`${dest.warehouse_id}-${dest.location_id}`"
                :value="dest.location_id"
              >
                {{ dest.location_name || dest.location_id }}
              </option>
            </select>
            <input
              v-else
              id="put-away-transfer-to"
              v-model="transferForm.to_location"
              type="text"
              class="form-control mb-3"
              placeholder="Type location name"
            />
            <div class="row g-2 align-items-end mb-3">
              <div class="col-6">
                <label class="form-label small" for="put-away-transfer-qty">QTY</label>
                <input
                  id="put-away-transfer-qty"
                  v-model="transferForm.quantity"
                  type="number"
                  min="1"
                  :max="transferSourceMaxQty"
                  class="form-control"
                />
              </div>
              <div class="col-6">
                <button
                  type="button"
                  class="btn inventory-detail__transfer-all-btn w-100"
                  @click="fillTransferAllQty"
                >
                  Transfer All
                </button>
              </div>
            </div>
            <label class="form-label small">Reason</label>
            <select v-model="transferForm.reason" class="form-select">
              <option v-for="reason in inventoryReasons" :key="reason" :value="reason">{{ reason }}</option>
            </select>
          </div>
          <footer class="crm-vx-modal__footer">
            <button
              type="button"
              class="crm-vx-modal-btn crm-vx-modal-btn--secondary"
              :disabled="saving"
              @click="transferModalOpen = false"
            >
              Cancel
            </button>
            <button
              type="button"
              class="crm-vx-modal-btn crm-vx-modal-btn--primary"
              :disabled="saving"
              @click="submitTransferQty"
            >
              {{ saving ? "Please wait..." : "Transfer" }}
            </button>
          </footer>
        </div>
      </div>
    </Teleport>

    <Teleport to="body">
      <div v-if="updateModalOpen" class="crm-vx-modal-overlay" @click.self="updateModalOpen = false">
        <div class="crm-vx-modal crm-vx-modal--sm">
          <header class="crm-vx-modal__head">
            <h2 class="crm-vx-modal__title">Update QTY</h2>
          </header>
          <div class="crm-vx-modal__body">
            <p class="small text-secondary">Current QTY: {{ locationQty(activeLocation) }}</p>
            <label class="form-label small">New QTY</label>
            <input v-model="updateForm.quantity" type="number" min="0" class="form-control mb-3" />
            <label class="form-label small">Reason</label>
            <select v-model="updateForm.reason" class="form-select">
              <option v-for="reason in inventoryReasons" :key="reason" :value="reason">{{ reason }}</option>
            </select>
          </div>
          <footer class="crm-vx-modal__footer">
            <button
              type="button"
              class="crm-vx-modal-btn crm-vx-modal-btn--secondary"
              :disabled="saving"
              @click="updateModalOpen = false"
            >
              Cancel
            </button>
            <button
              type="button"
              class="crm-vx-modal-btn crm-vx-modal-btn--primary"
              :disabled="saving"
              @click="submitUpdateQty"
            >
              {{ saving ? "Please wait..." : "Update" }}
            </button>
          </footer>
        </div>
      </div>
    </Teleport>

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

    <Teleport to="body">
      <div v-if="addLocationModalOpen" class="crm-vx-modal-overlay" @click.self="addLocationModalOpen = false">
        <div class="crm-vx-modal crm-vx-modal--sm">
          <header class="crm-vx-modal__head">
            <h2 class="crm-vx-modal__title">Add Location</h2>
          </header>
          <div class="crm-vx-modal__body">
            <label class="form-label small">Location</label>
            <input
              ref="addLocationInputRef"
              v-model="addLocationForm.location"
              type="text"
              class="form-control mb-3"
              placeholder="Type location name"
            />
            <label class="form-label small">QTY</label>
            <input v-model="addLocationForm.quantity" type="number" min="0" class="form-control mb-3" />
            <label class="form-label small">Reason</label>
            <select v-model="addLocationForm.reason" class="form-select">
              <option v-for="reason in inventoryReasons" :key="reason" :value="reason">{{ reason }}</option>
            </select>
          </div>
          <footer class="crm-vx-modal__footer">
            <button
              type="button"
              class="crm-vx-modal-btn crm-vx-modal-btn--secondary"
              :disabled="saving"
              @click="addLocationModalOpen = false"
            >
              Cancel
            </button>
            <button
              type="button"
              class="crm-vx-modal-btn crm-vx-modal-btn--primary"
              :disabled="saving"
              @click="submitAddLocationQty"
            >
              {{ saving ? "Please wait..." : "Update" }}
            </button>
          </footer>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<style lang="scss">
@import "../../styles/inventory-portal-detail.scss";
</style>

<style scoped>
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
.inventory-detail__transfer-all-btn {
  border: 1px solid var(--bs-primary);
  color: var(--bs-primary);
  background: transparent;
  font-weight: 600;
}
.inventory-detail__transfer-all-btn:hover,
.inventory-detail__transfer-all-btn:focus-visible {
  background: var(--bs-primary);
  border-color: var(--bs-primary);
  color: #fff;
}
</style>