<script setup>
import { computed, inject, onMounted, reactive, ref } from "vue";
import { RouterLink, useRoute } from "vue-router";
import api from "../../services/api";
import CrmIconRowActions from "../../components/common/CrmIconRowActions.vue";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { usePortalLastRefreshed } from "../../composables/usePortalLastRefreshed.js";
import { useToast } from "../../composables/useToast.js";
import { formatDateTimeUs, formatDateUs } from "../../utils/formatUserDates.js";
import { openApiPdfBlob } from "../../utils/openApiPdfBlob.js";
import { errorMessage as apiErrorMessage } from "../../utils/apiError.js";

const route = useRoute();
const toast = useToast();
const crmUser = inject("crmUser", ref(null));

const isPortalView = computed(() => Boolean(route.meta.userPortal));
const canManageInventoryLocations = computed(() => !isPortalView.value);

const { markRefreshed, lastRefreshedLabel } = usePortalLastRefreshed();

const loading = ref(true);
const refreshing = ref(false);
const barcodePdfLoading = ref(false);
const saving = ref(false);
const product = ref(null);
const productLoadError = ref("");
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

const summaryMetrics = computed(() => product.value?.metrics || {
  on_hand: 0,
  allocated: 0,
  available: 0,
  backorder: 0,
  asn: 0,
});

const allocatedOrders = ref([]);
const backorderOrders = ref([]);
const allocatedLoading = ref(false);
const backorderLoading = ref(false);
const allocatedLoaded = ref(false);
const backorderLoaded = ref(false);
const allocatedTruncatedMessage = ref("");
const backorderTruncatedMessage = ref("");
const allocatedError = ref("");
const backorderError = ref("");
const allocatedLoadedAt = ref(null);
const backorderLoadedAt = ref(null);

const ORDER_SECTION_TIMEOUT_MS = 90000;

const isKitProduct = computed(() => {
  const p = product.value;
  if (!p) return false;
  return Boolean(p.kit || p.kit_build);
});

const kitComponents = computed(() => {
  const list = product.value?.kit_components;
  return Array.isArray(list) ? list : [];
});

const parentKits = computed(() => {
  const list = product.value?.parent_kits;
  return Array.isArray(list) ? list : [];
});

function inventoryDetailTo(sku) {
  const value = String(sku || "").trim();
  if (!value) return { name: isPortalView.value ? "user-inventory-detail" : "inventory-detail", params: {} };
  const query = {};
  const clientId = Number(route.query.client_account_id || 0);
  if (clientId > 0) query.client_account_id = clientId;
  return {
    name: isPortalView.value ? "user-inventory-detail" : "inventory-detail",
    params: { sku: value },
    query,
  };
}

const metricCards = computed(() => ([
  { key: "on_hand", label: "On Hand", iconPath: "M3 7.5 12 3l9 4.5v9L12 21l-9-4.5z M12 12l9-4.5 M12 12 3 7.5 M12 12v9", tone: "blue" },
  { key: "allocated", label: "Allocated", iconPath: "M4 8h16M4 12h16M4 16h16M7 5h10", tone: "amber" },
  { key: "available", label: "Available", iconPath: "M4 12l5 5 11-11", tone: "green" },
  { key: "backorder", label: "Backorder", iconPath: "M12 7v6 M12 17h.01 M3 12a9 9 0 1 0 18 0 9 9 0 1 0-18 0", tone: "red" },
  { key: "asn", label: "ASN", iconPath: "M2 13h11l2-3h7v7h-2 M6 17a2 2 0 1 0 0 .01 M18 17a2 2 0 1 0 0 .01", tone: "purple" },
]).map((item) => ({
  ...item,
  value: Number(summaryMetrics.value?.[item.key] || 0),
})));

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

function displayVal(v) {
  if (v === null || v === undefined) return "—";
  if (typeof v === "string" && v.trim() === "") return "—";
  return v;
}

function displayNumber(v) {
  if (v === null || v === undefined) return 0;
  if (typeof v === "string" && v.trim() === "") return 0;
  const n = Number(v);
  if (Number.isNaN(n)) return 0;
  return n;
}

function displayCubicFeet(v) {
  if (v === null || v === undefined) return "—";
  const n = Number(v);
  if (!Number.isFinite(n)) return "—";
  return String(n);
}

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
  document.addEventListener("click", onDocClick);
});

function onDocClick(e) {
  if (!e.target?.closest?.("[data-row-actions]")) {
    actionMenuLocationId.value = null;
  }
}

function requestParams({ refresh = false } = {}) {
  const params = {};
  const portalAccountId = Number(crmUser.value?.client_account_id || 0);
  const queryAccountId = Number(route.query.client_account_id || 0);
  const clientAccountId = isPortalView.value
    ? portalAccountId || queryAccountId
    : queryAccountId || portalAccountId;
  if (clientAccountId > 0) params.client_account_id = clientAccountId;
  if (route.query.warehouse_id) params.warehouse_id = String(route.query.warehouse_id);
  if (refresh) params.refresh = 1;
  return params;
}

function formatOrderDate(val) {
  if (!val) return "—";
  return formatDateUs(val);
}

function portalOrderTo(orderId) {
  return {
    name: "user-order-detail",
    params: { shipheroOrderId: String(orderId) },
  };
}

async function openBarcodeLabelPdf() {
  if (!product.value?.sku || barcodePdfLoading.value) return;
  const params = { ...requestParams() };
  const barcode = String(product.value.barcode || "").trim();
  if (barcode) {
    params.barcode = barcode;
  }
  const path = `/inventory/products/${encodeURIComponent(product.value.sku)}/barcode-label.pdf`;
  barcodePdfLoading.value = true;
  try {
    await openApiPdfBlob(api, path, { params });
  } catch (e) {
    const msg = e instanceof Error ? e.message : "";
    toast.error(msg || "Could not open barcode label PDF.");
  } finally {
    barcodePdfLoading.value = false;
  }
}

async function loadPortalOrderSections({ refresh = false } = {}) {
  if (!isPortalView.value || !product.value?.sku) return;
  await loadAllocatedOrders({ refresh });
}

function finishOrderSectionLoad(section, { ok, data, errText = "" }) {
  if (section === "allocated") {
    allocatedLoading.value = false;
    allocatedLoaded.value = true;
    if (ok) {
      allocatedOrders.value = Array.isArray(data?.rows) ? data.rows : [];
      allocatedTruncatedMessage.value = data?.message ? String(data.message) : "";
      allocatedError.value = "";
      allocatedLoadedAt.value = new Date();
    } else {
      allocatedOrders.value = [];
      allocatedTruncatedMessage.value = "";
      allocatedError.value = errText;
      allocatedLoadedAt.value = null;
    }
    return;
  }
  if (section === "backorder") {
    backorderLoading.value = false;
    backorderLoaded.value = true;
    if (ok) {
      backorderOrders.value = Array.isArray(data?.rows) ? data.rows : [];
      backorderTruncatedMessage.value = data?.message ? String(data.message) : "";
      backorderError.value = "";
      backorderLoadedAt.value = new Date();
    } else {
      backorderOrders.value = [];
      backorderTruncatedMessage.value = "";
      backorderError.value = errText;
      backorderLoadedAt.value = null;
    }
  }
}

async function loadAllocatedOrders({ refresh = false } = {}) {
  if (!product.value?.sku) return;
  const params = requestParams({ refresh });
  if (!params.client_account_id) {
    const msg = "Account is required to load allocated orders.";
    toast.error(msg);
    finishOrderSectionLoad("allocated", { ok: false, errText: msg });
    return;
  }
  allocatedLoading.value = true;
  allocatedLoaded.value = false;
  allocatedTruncatedMessage.value = "";
  allocatedError.value = "";
  try {
    const sku = String(route.params.sku || product.value.sku).trim();
    const { data } = await api.get(
      `/inventory/products/${encodeURIComponent(sku)}/allocated-orders`,
      { params, timeout: ORDER_SECTION_TIMEOUT_MS },
    );
    finishOrderSectionLoad("allocated", { ok: true, data });
  } catch (e) {
    const msg = apiErrorMessage(e, "Could not load allocated orders.");
    finishOrderSectionLoad("allocated", { ok: false, errText: msg });
    toast.errorFrom(e, "Could not load allocated orders.");
  }
}

async function loadBackorderOrders({ refresh = false } = {}) {
  if (!product.value?.sku) return;
  const params = requestParams({ refresh });
  if (!params.client_account_id) {
    const msg = "Account is required to load backorder orders.";
    toast.error(msg);
    finishOrderSectionLoad("backorder", { ok: false, errText: msg });
    return;
  }
  backorderLoading.value = true;
  backorderLoaded.value = false;
  backorderTruncatedMessage.value = "";
  backorderError.value = "";
  try {
    const sku = String(route.params.sku || product.value.sku).trim();
    const { data } = await api.get(
      `/inventory/products/${encodeURIComponent(sku)}/backorder-orders`,
      { params, timeout: ORDER_SECTION_TIMEOUT_MS },
    );
    finishOrderSectionLoad("backorder", { ok: true, data });
  } catch (e) {
    const msg = apiErrorMessage(e, "Could not load backorder orders.");
    finishOrderSectionLoad("backorder", { ok: false, errText: msg });
  }
}

async function loadDetail({ refresh = false } = {}) {
  loading.value = true;
  productLoadError.value = "";
  allocatedOrders.value = [];
  backorderOrders.value = [];
  allocatedLoading.value = false;
  backorderLoading.value = false;
  allocatedLoaded.value = false;
  backorderLoaded.value = false;
  allocatedLoadedAt.value = null;
  backorderLoadedAt.value = null;
  allocatedTruncatedMessage.value = "";
  backorderTruncatedMessage.value = "";
  allocatedError.value = "";
  backorderError.value = "";
  let loadedOk = false;
  try {
    const sku = String(route.params.sku || "").trim();
    const { data } = await api.get(`/inventory/products/${encodeURIComponent(sku)}`, {
      params: requestParams({ refresh }),
    });
    product.value = data?.product ?? null;
    loadedOk = true;
  } catch (e) {
    productLoadError.value = apiErrorMessage(e, "Could not load inventory detail.");
    toast.errorFrom(e, "Could not load inventory detail.");
  } finally {
    loading.value = false;
    refreshing.value = false;
    if (loadedOk && isPortalView.value) {
      if (refresh) markRefreshed();
      void loadPortalOrderSections({ refresh });
    }
  }
}

async function refreshDetail() {
  if (loading.value || refreshing.value) return;
  refreshing.value = true;
  await loadDetail({ refresh: true });
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
      <p v-if="productLoadError" class="alert alert-warning small">{{ productLoadError }}</p>
      <div v-else-if="!product" class="text-secondary small py-4 text-center">
        Product not found.
      </div>
      <div v-else-if="isPortalView" class="staff-user-view staff-page--wide inventory-portal-detail">
        <nav
          class="staff-user-view__breadcrumb d-flex flex-wrap align-items-center gap-1"
          aria-label="Breadcrumb"
        >
          <RouterLink to="/users/inventory">Products</RouterLink>
          <span class="text-secondary" aria-hidden="true">/</span>
          <span class="text-body-secondary">Detail</span>
        </nav>

        <div class="staff-user-view__title-row inventory-portal-detail__title-row d-flex flex-wrap align-items-center justify-content-end gap-2 mb-3">
          <div class="d-flex align-items-center gap-2 flex-shrink-0">
            <p v-if="lastRefreshedLabel" class="small text-secondary mb-0">
              Last refreshed: {{ lastRefreshedLabel }}
            </p>
            <button
              type="button"
              class="btn btn-outline-secondary btn-sm orders-toolbar-outline-btn d-inline-flex align-items-center gap-2"
              :disabled="loading || refreshing"
              title="Refresh"
              aria-label="Refresh product from ShipHero"
              @click="refreshDetail"
            >
              <svg
                width="18"
                height="18"
                fill="none"
                stroke="currentColor"
                viewBox="0 0 24 24"
                aria-hidden="true"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  stroke-width="2"
                  d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"
                />
              </svg>
              {{ refreshing ? "Refreshing…" : "Refresh" }}
            </button>
          </div>
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
                <div
                  v-else
                  class="inventory-portal-detail__hero-image inventory-portal-detail__hero-image--empty"
                />
                <div class="inventory-portal-detail__hero-text">
                  <h2 class="inventory-portal-detail__hero-name">
                    {{ product.name || "Product" }}
                  </h2>
                  <p class="inventory-portal-detail__hero-sku mb-0">{{ product.sku }}</p>
                </div>
              </div>

              <button
                type="button"
                class="btn btn-outline-secondary btn-sm orders-toolbar-outline-btn w-100 mb-3"
                :disabled="barcodePdfLoading"
                @click="openBarcodeLabelPdf"
              >
                {{ barcodePdfLoading ? "Generating Label… Please Wait" : "Print Barcode Label" }}
              </button>

              <h3 class="staff-user-profile__details-title mb-2">Details</h3>
              <dl class="staff-user-profile__dl">
                <div>
                  <dt class="staff-user-profile__dt">SKU</dt>
                  <dd class="staff-user-profile__dd">{{ displayVal(product.sku) }}</dd>
                </div>
                <div>
                  <dt class="staff-user-profile__dt">Barcode</dt>
                  <dd class="staff-user-profile__dd">{{ displayVal(product.barcode) }}</dd>
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
                  <dt class="staff-user-profile__dt">Cubic Feet</dt>
                  <dd class="staff-user-profile__dd">{{ displayCubicFeet(product.storage_cubic_feet) }}</dd>
                </div>
                <div>
                  <dt class="staff-user-profile__dt">Custom Value</dt>
                  <dd class="staff-user-profile__dd">{{ displayNumber(product.customs_value) }}</dd>
                </div>
                <div>
                  <dt class="staff-user-profile__dt">Custom Description</dt>
                  <dd class="staff-user-profile__dd">{{ displayVal(product.customs_description) }}</dd>
                </div>
                <div>
                  <dt class="staff-user-profile__dt">Kit</dt>
                  <dd class="staff-user-profile__dd">
                    {{
                      isKitProduct
                        ? product.kit_build
                          ? "Kit build"
                          : "Yes"
                        : "No"
                    }}
                  </dd>
                </div>
              </dl>
            </aside>
          </div>

          <div class="col-12 col-xl-8">
            <div class="staff-table-card inventory-portal-detail__section p-0">
              <div class="inventory-portal-detail__section-head">
                <h2 class="inventory-portal-detail__section-title">Kits</h2>
                <p class="small text-body-secondary mb-0">
                  Kit products that include this SKU as a component
                </p>
              </div>
              <div
                v-if="parentKits.length"
                class="table-responsive inventory-portal-detail__table-wrap"
              >
                <table class="table table-hover align-middle mb-0 staff-data-table">
                  <thead class="table-light staff-table-head">
                    <tr>
                      <th class="staff-table-head__th">SKU</th>
                      <th class="staff-table-head__th">Name</th>
                      <th class="staff-table-head__th text-end">Qty in kit</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="kit in parentKits" :key="kit.sku">
                      <td>
                        <RouterLink :to="inventoryDetailTo(kit.sku)">
                          {{ kit.sku }}
                        </RouterLink>
                      </td>
                      <td>{{ kit.name || "—" }}</td>
                      <td class="text-end">{{ kit.quantity }}</td>
                    </tr>
                  </tbody>
                </table>
              </div>
              <p v-else class="inventory-portal-detail__empty">
                No kits found that use this SKU as a component.
              </p>
            </div>

            <div class="staff-table-card inventory-portal-detail__section p-0">
              <div class="inventory-portal-detail__section-head">
                <h2 class="inventory-portal-detail__section-title">Kit Components</h2>
                <p class="small text-body-secondary mb-0">
                  Products that make up this kit
                </p>
              </div>
              <div
                v-if="kitComponents.length"
                class="table-responsive inventory-portal-detail__table-wrap"
              >
                <table class="table table-hover align-middle mb-0 staff-data-table">
                  <thead class="table-light staff-table-head">
                    <tr>
                      <th class="staff-table-head__th">SKU</th>
                      <th class="staff-table-head__th text-end">Quantity</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="component in kitComponents" :key="component.sku">
                      <td>
                        <RouterLink :to="inventoryDetailTo(component.sku)">
                          {{ component.sku }}
                        </RouterLink>
                      </td>
                      <td class="text-end">{{ component.quantity }}</td>
                    </tr>
                  </tbody>
                </table>
              </div>
              <p v-else class="inventory-portal-detail__empty">
                No kit components configured for this SKU.
              </p>
            </div>

            <div class="staff-table-card inventory-portal-detail__section p-0">
              <div class="inventory-portal-detail__section-head">
                <div>
                  <h2 class="inventory-portal-detail__section-title">Allocated Orders</h2>
                  <p class="small text-body-secondary mb-0">
                    Open ready-to-ship orders with inventory allocated to this SKU
                  </p>
                  <p v-if="allocatedLoaded" class="small text-secondary mb-0">
                    <span v-if="allocatedLoadedAt">Loaded: {{ formatDateTimeUs(allocatedLoadedAt) }} · </span>
                    {{ allocatedOrders.length }} order{{ allocatedOrders.length === 1 ? "" : "s" }}
                  </p>
                </div>
                <button
                  type="button"
                  class="btn btn-outline-secondary btn-sm orders-toolbar-outline-btn d-inline-flex align-items-center gap-2"
                  :disabled="allocatedLoading"
                  title="Refresh allocated orders"
                  aria-label="Refresh allocated orders"
                  @click="loadAllocatedOrders({ refresh: true })"
                >
                  <svg
                    width="16"
                    height="16"
                    fill="none"
                    stroke="currentColor"
                    viewBox="0 0 24 24"
                    aria-hidden="true"
                  >
                    <path
                      stroke-linecap="round"
                      stroke-linejoin="round"
                      stroke-width="2"
                      d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"
                    />
                  </svg>
                  {{ allocatedLoading ? "Refreshing…" : "Refresh" }}
                </button>
              </div>
              <p v-if="allocatedTruncatedMessage" class="inventory-portal-detail__truncated">
                {{ allocatedTruncatedMessage }}
              </p>
              <div v-if="allocatedLoaded && allocatedOrders.length" class="table-responsive inventory-portal-detail__table-wrap">
                <table class="table table-hover align-middle mb-0 staff-data-table">
                  <thead class="table-light staff-table-head">
                    <tr>
                      <th class="staff-table-head__th">Order #</th>
                      <th class="staff-table-head__th">Order Date</th>
                      <th class="staff-table-head__th text-end">Allocated Qty</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="row in allocatedOrders" :key="`${row.order_id}-${row.quantity}`">
                      <td>
                        <RouterLink :to="portalOrderTo(row.order_id)">
                          {{ row.order_number || row.order_id }}
                        </RouterLink>
                      </td>
                      <td>{{ formatOrderDate(row.order_date) }}</td>
                      <td class="text-end">{{ row.quantity }}</td>
                    </tr>
                  </tbody>
                </table>
              </div>
              <p
                v-else-if="allocatedLoading"
                class="inventory-portal-detail__empty"
              >
                Loading allocated orders…
              </p>
              <p
                v-else-if="allocatedError"
                class="inventory-portal-detail__empty text-danger"
              >
                {{ allocatedError }}
              </p>
              <p
                v-else-if="allocatedLoaded && !allocatedOrders.length"
                class="inventory-portal-detail__empty"
              >
                No open ready-to-ship orders with allocated quantity for this SKU. Use Refresh to reload.
              </p>
            </div>

            <div class="staff-table-card inventory-portal-detail__section p-0">
              <div class="inventory-portal-detail__section-head">
                <div>
                  <h2 class="inventory-portal-detail__section-title">Backorder Orders</h2>
                  <p class="small text-body-secondary mb-0">
                    Orders with backorder quantity for this SKU (last 180 days)
                  </p>
                  <p v-if="backorderLoaded" class="small text-secondary mb-0">
                    <span v-if="backorderLoadedAt">Loaded: {{ formatDateTimeUs(backorderLoadedAt) }} · </span>
                    {{ backorderOrders.length }} order{{ backorderOrders.length === 1 ? "" : "s" }}
                  </p>
                </div>
                <button
                  type="button"
                  class="btn btn-outline-secondary btn-sm orders-toolbar-outline-btn d-inline-flex align-items-center gap-2"
                  :disabled="backorderLoading"
                  :title="backorderLoaded ? 'Refresh backorder orders' : 'Load backorder orders'"
                  :aria-label="backorderLoaded ? 'Refresh backorder orders' : 'Load backorder orders'"
                  @click="loadBackorderOrders({ refresh: backorderLoaded })"
                >
                  <svg
                    width="16"
                    height="16"
                    fill="none"
                    stroke="currentColor"
                    viewBox="0 0 24 24"
                    aria-hidden="true"
                  >
                    <path
                      stroke-linecap="round"
                      stroke-linejoin="round"
                      stroke-width="2"
                      d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"
                    />
                  </svg>
                  {{
                    backorderLoading
                      ? backorderLoaded
                        ? "Refreshing…"
                        : "Loading…"
                      : backorderLoaded
                        ? "Refresh"
                        : "Load"
                  }}
                </button>
              </div>
              <p v-if="backorderTruncatedMessage" class="inventory-portal-detail__truncated">
                {{ backorderTruncatedMessage }}
              </p>
              <div v-if="backorderLoaded && backorderOrders.length" class="table-responsive inventory-portal-detail__table-wrap">
                <table class="table table-hover align-middle mb-0 staff-data-table">
                  <thead class="table-light staff-table-head">
                    <tr>
                      <th class="staff-table-head__th">Order #</th>
                      <th class="staff-table-head__th">Order Date</th>
                      <th class="staff-table-head__th text-end">Backorder Qty</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="row in backorderOrders" :key="`${row.order_id}-${row.quantity}`">
                      <td>
                        <RouterLink :to="portalOrderTo(row.order_id)">
                          {{ row.order_number || row.order_id }}
                        </RouterLink>
                      </td>
                      <td>{{ formatOrderDate(row.order_date) }}</td>
                      <td class="text-end">{{ row.quantity }}</td>
                    </tr>
                  </tbody>
                </table>
              </div>
              <p
                v-else-if="backorderLoading"
                class="inventory-portal-detail__empty"
              >
                Loading backorder orders…
              </p>
              <p
                v-else-if="backorderError"
                class="inventory-portal-detail__empty text-danger"
              >
                {{ backorderError }}
              </p>
              <p
                v-else-if="backorderLoaded && !backorderOrders.length"
                class="inventory-portal-detail__empty"
              >
                No backorder orders found for this SKU in the last 180 days. Use Refresh to reload.
              </p>
              <p
                v-else-if="!backorderLoaded"
                class="inventory-portal-detail__empty"
              >
                Backorder orders are not loaded yet. Select Load to fetch them from ShipHero.
              </p>
            </div>
          </div>
        </div>
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

            <div class="staff-table-card p-0">
              <div class="px-3 py-2 border-bottom">
                <h3 class="h6 mb-0">Kits</h3>
                <p class="small text-body-secondary mb-0">
                  Kit products that include this SKU as a component
                </p>
              </div>
              <div v-if="parentKits.length" class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                  <thead class="table-light">
                    <tr>
                      <th>SKU</th>
                      <th>Name</th>
                      <th class="text-end">Qty in kit</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="kit in parentKits" :key="kit.sku">
                      <td>
                        <RouterLink :to="inventoryDetailTo(kit.sku)">{{ kit.sku }}</RouterLink>
                      </td>
                      <td>{{ kit.name || "—" }}</td>
                      <td class="text-end">{{ kit.quantity }}</td>
                    </tr>
                  </tbody>
                </table>
              </div>
              <p v-else class="text-secondary small px-3 py-3 mb-0">
                No kits found that use this SKU as a component.
              </p>
            </div>

            <div class="staff-table-card p-0">
              <div class="px-3 py-2 border-bottom">
                <h3 class="h6 mb-0">Kit Components</h3>
                <p class="small text-body-secondary mb-0">
                  Products that make up this kit
                </p>
              </div>
              <div v-if="kitComponents.length" class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                  <thead class="table-light">
                    <tr>
                      <th>SKU</th>
                      <th class="text-end">Quantity</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="component in kitComponents" :key="component.sku">
                      <td>
                        <RouterLink :to="inventoryDetailTo(component.sku)">{{ component.sku }}</RouterLink>
                      </td>
                      <td class="text-end">{{ component.quantity }}</td>
                    </tr>
                  </tbody>
                </table>
              </div>
              <p v-else class="text-secondary small px-3 py-3 mb-0">
                No kit components configured for this SKU.
              </p>
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

<style lang="scss">
@import "../../styles/inventory-portal-detail.scss";
</style>
