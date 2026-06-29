<script setup>
import { computed, inject, onMounted, reactive, ref, watch, nextTick } from "vue";
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
const usePortalDetailLayout = computed(
  () => isPortalView.value || route.name === "inventory-detail",
);
const inventoryListRoute = computed(() =>
  isPortalView.value ? "/users/inventory" : "/admin/inventory",
);
const inventoryListBreadcrumbLabel = computed(() => "Inventory");
const canManageInventoryLocations = computed(() => !isPortalView.value);

const { markRefreshed, lastRefreshedLabel } = usePortalLastRefreshed();

const loading = ref(true);
const refreshing = ref(false);
const barcodePdfLoading = ref(false);
const imageUploadBusy = ref(false);
const imageInputRef = ref(null);
const saving = ref(false);
const product = ref(null);
const productLoadError = ref("");
const locationSearch = ref("");
const locationFilterMenuOpen = ref(false);
const locationBinTypeFilter = ref("");
const locationPickableFilter = ref("");
const actionMenuLocationId = ref(null);
const actionMenuRect = ref({ top: 0, left: 0 });

const updateModalOpen = ref(false);
const transferModalOpen = ref(false);
const addLocationModalOpen = ref(false);
const activeLocation = ref(null);
const updateForm = reactive({ quantity: "", reason: "Client-Requested Adjustments" });
const transferForm = reactive({
  transfer_type: "current",
  to_location_id: "",
  to_location: "",
  quantity: "",
  reason: "Restock",
});
const addLocationForm = reactive({ location: "", quantity: "0", reason: "Account Setup" });
const addLocationQtyInputRef = ref(null);

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
const defaultTransferReason = ref("Restock");
const defaultAddLocationReason = ref("Account Setup");

const summaryMetrics = computed(() => product.value?.metrics || {
  on_hand: 0,
  allocated: 0,
  available: 0,
  backorder: 0,
  asn: 0,
});

const parentKitsList = ref([]);
const kitComponentsList = ref([]);
const parentKitsLoading = ref(false);
const kitComponentsLoading = ref(false);
const parentKitsLoaded = ref(false);
const kitComponentsLoaded = ref(false);
const parentKitsError = ref("");
const kitComponentsError = ref("");
const parentKitsLoadedAt = ref(null);
const kitComponentsLoadedAt = ref(null);

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

const shipheroProductUrl = computed(() => {
  const legacyId = Number(product.value?.shiphero_legacy_id || 0);
  if (legacyId <= 0) return null;
  return `https://app.shiphero.com/dashboard/products/details/${legacyId}`;
});

const canUploadProductImage = computed(() => {
  const u = crmUser.value;
  if (!u || !product.value?.sku) return false;
  if (isPortalView.value) {
    return Array.isArray(u.permission_keys) && u.permission_keys.includes("inventory.view");
  }
  return Array.isArray(u.permission_keys) && u.permission_keys.includes("inventory.update");
});

const detailClientAccountId = computed(() => {
  const portalAccountId = Number(crmUser.value?.client_account_id || 0);
  const queryAccountId = Number(route.query.client_account_id || 0);
  return isPortalView.value ? portalAccountId || queryAccountId : queryAccountId || portalAccountId;
});

function sectionActionLabel({ loading, loaded }) {
  if (loading) return loaded ? "Refreshing…" : "Loading…";
  return loaded ? "Refresh" : "Load";
}

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
      if (!usePortalDetailLayout.value && Number(loc?.quantity || 0) <= 0) return;
      out.push({
        ...loc,
        warehouse_id: wh.warehouse_id,
        warehouse_name: wh.warehouse_name,
      });
    });
  });
  return out;
});

const locationBinTypeOptions = computed(() => {
  const types = new Set();
  allLocations.value.forEach((loc) => {
    const t = String(loc.type || "").trim();
    if (t) types.add(t);
  });
  return [...types].sort((a, b) => a.localeCompare(b));
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

function clearLocationFilters() {
  locationBinTypeFilter.value = "";
  locationPickableFilter.value = "";
}

function metricsFromWarehouses(warehouses, previousMetrics = {}) {
  let onHand = 0;
  (warehouses || []).forEach((wh) => {
    (wh.locations || []).forEach((loc) => {
      onHand += Number(loc.quantity ?? 0);
    });
  });
  const allocated = Number(previousMetrics.allocated ?? 0);
  return {
    ...previousMetrics,
    on_hand: onHand,
    available: Math.max(0, onHand - allocated),
  };
}

function applyWarehouseSliceToProduct(warehouseSlice) {
  if (!product.value || !warehouseSlice?.warehouse_id) return;
  const whId = String(warehouseSlice.warehouse_id);
  const incomingLocs = Array.isArray(warehouseSlice.locations) ? warehouseSlice.locations : [];

  const warehouses = Array.isArray(product.value.warehouses) ? [...product.value.warehouses] : [];
  const whIndex = warehouses.findIndex((wh) => String(wh.warehouse_id || "") === whId);
  if (whIndex < 0) {
    warehouses.push({
      warehouse_id: whId,
      warehouse_name: warehouseSlice.warehouse_name || "",
      locations: incomingLocs.map((loc) => ({ ...loc })),
    });
  } else {
    warehouses[whIndex] = {
      ...warehouses[whIndex],
      warehouse_name: warehouseSlice.warehouse_name || warehouses[whIndex].warehouse_name || "",
      locations: incomingLocs.map((loc) => ({ ...loc })),
    };
  }

  product.value = {
    ...product.value,
    warehouses,
    metrics: metricsFromWarehouses(warehouses, product.value.metrics),
  };
}

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
  setCrmPageMeta({
    title: isPortalView.value ? "Save Rack | Inventory | Product Detail" : "Save Rack | Inventory Detail",
    description: "Product inventory detail.",
  });
  loadAdjustmentReasons();
  loadDetail();
  document.addEventListener("click", onDocClick);
});

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
      transferForm.reason = defaultReason;
    }
    const addLocationReason = String(data?.default_add_location_reason || "").trim();
    if (addLocationReason) {
      defaultAddLocationReason.value = addLocationReason;
    }
  } catch {
    /* keep fallback list */
  }
}

watch(
  () => String(route.params.sku || "").trim(),
  (next, prev) => {
    if (next && next !== prev) {
      loadDetail();
    }
  },
);

function onDocClick(e) {
  if (!e.target?.closest?.("[data-row-actions]")) {
    actionMenuLocationId.value = null;
  }
  if (!e.target?.closest?.("[data-toolbar-filter]")) {
    locationFilterMenuOpen.value = false;
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

function orderDetailTo(orderId) {
  const query = {};
  const clientId = Number(route.query.client_account_id || 0);
  if (clientId > 0) query.client_account_id = String(clientId);
  return {
    name: isPortalView.value ? "user-order-detail" : "order-detail",
    params: { shipheroOrderId: String(orderId) },
    query,
  };
}

function openImageUploadPicker() {
  if (!canUploadProductImage.value || imageUploadBusy.value) return;
  imageInputRef.value?.click();
}

async function onProductImageSelected(ev) {
  const input = ev?.target;
  const file = input?.files?.[0];
  if (input) input.value = "";
  if (!file || !product.value?.sku) return;

  const maxBytes = 5 * 1024 * 1024;
  if (file.size > maxBytes) {
    toast.error("Image must be 5 MB or smaller.");
    return;
  }
  if (!String(file.type || "").startsWith("image/")) {
    toast.error("Choose a JPG, PNG, GIF, or WebP image.");
    return;
  }

  if (!isPortalView.value && detailClientAccountId.value <= 0) {
    toast.error("Select a client account on the inventory list before uploading an image.");
    return;
  }

  const sku = String(product.value.sku).trim();
  const formData = new FormData();
  formData.append("image", file);
  if (detailClientAccountId.value > 0) {
    formData.append("client_account_id", String(detailClientAccountId.value));
  }

  imageUploadBusy.value = true;
  try {
    await api.post(`/inventory/products/${encodeURIComponent(sku)}/image`, formData);
    toast.success("Product image updated in ShipHero.");
    await loadProduct({ refresh: true });
  } catch (e) {
    toast.errorFrom(e, "Could not update product image.");
  } finally {
    imageUploadBusy.value = false;
  }
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

function resetDeferredSections() {
  parentKitsList.value = [];
  kitComponentsList.value = [];
  parentKitsLoading.value = false;
  kitComponentsLoading.value = false;
  parentKitsLoaded.value = false;
  kitComponentsLoaded.value = false;
  parentKitsError.value = "";
  kitComponentsError.value = "";
  parentKitsLoadedAt.value = null;
  kitComponentsLoadedAt.value = null;

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
}

function finishKitSectionLoad(section, { ok, data, errText = "" }) {
  if (section === "parent_kits") {
    parentKitsLoading.value = false;
    parentKitsLoaded.value = true;
    if (ok) {
      parentKitsList.value = Array.isArray(data?.rows) ? data.rows : [];
      parentKitsError.value = "";
      parentKitsLoadedAt.value = new Date();
    } else {
      parentKitsList.value = [];
      parentKitsError.value = errText;
      parentKitsLoadedAt.value = null;
    }
    return;
  }
  kitComponentsLoading.value = false;
  kitComponentsLoaded.value = true;
  if (ok) {
    kitComponentsList.value = Array.isArray(data?.rows) ? data.rows : [];
    kitComponentsError.value = "";
    kitComponentsLoadedAt.value = new Date();
  } else {
    kitComponentsList.value = [];
    kitComponentsError.value = errText;
    kitComponentsLoadedAt.value = null;
  }
}

async function loadParentKits({ refresh = false } = {}) {
  if (!product.value?.sku) return;
  parentKitsLoading.value = true;
  if (!refresh) {
    parentKitsLoaded.value = false;
  }
  parentKitsError.value = "";
  try {
    const sku = String(route.params.sku || product.value.sku).trim();
    const { data } = await api.get(
      `/inventory/products/${encodeURIComponent(sku)}/parent-kits`,
      { params: requestParams({ refresh }), timeout: ORDER_SECTION_TIMEOUT_MS },
    );
    finishKitSectionLoad("parent_kits", { ok: true, data });
  } catch (e) {
    const msg = apiErrorMessage(e, "Could not load kits.");
    finishKitSectionLoad("parent_kits", { ok: false, errText: msg });
    toast.errorFrom(e, "Could not load kits.");
  }
}

async function loadKitComponents({ refresh = false } = {}) {
  if (!product.value?.sku) return;
  kitComponentsLoading.value = true;
  if (!refresh) {
    kitComponentsLoaded.value = false;
  }
  kitComponentsError.value = "";
  try {
    const sku = String(route.params.sku || product.value.sku).trim();
    const { data } = await api.get(
      `/inventory/products/${encodeURIComponent(sku)}/kit-components`,
      { params: requestParams({ refresh }), timeout: ORDER_SECTION_TIMEOUT_MS },
    );
    finishKitSectionLoad("kit_components", { ok: true, data });
  } catch (e) {
    const msg = apiErrorMessage(e, "Could not load kit components.");
    finishKitSectionLoad("kit_components", { ok: false, errText: msg });
    toast.errorFrom(e, "Could not load kit components.");
  }
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
    productLoadError.value = apiErrorMessage(e, "Could not load inventory detail.");
    toast.errorFrom(e, "Could not load inventory detail.");
    return false;
  }
}

async function loadDetail() {
  loading.value = true;
  resetDeferredSections();
  await loadProduct({ refresh: false });
  loading.value = false;
}

async function refreshDetail() {
  if (loading.value || refreshing.value) return;
  refreshing.value = true;
  const ok = await loadProduct({ refresh: true });
  refreshing.value = false;
  if (ok && isPortalView.value) {
    markRefreshed();
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
  transferForm.transfer_type = "current";
  transferForm.to_location_id = "";
  transferForm.to_location = "";
  transferForm.quantity = "";
  transferForm.reason = defaultTransferReason.value;
  transferModalOpen.value = true;
  actionMenuLocationId.value = null;
}

function fillTransferAllQty() {
  transferForm.quantity = String(activeLocation.value?.quantity ?? 0);
}

function focusAddLocationQtyInput() {
  nextTick(() => {
    const el = addLocationQtyInputRef.value;
    if (el instanceof HTMLInputElement) {
      el.focus();
      el.select();
    }
  });
}

function openAddLocationModal() {
  addLocationForm.location = "";
  addLocationForm.quantity = "0";
  addLocationForm.reason = defaultAddLocationReason.value;
  addLocationModalOpen.value = true;
  focusAddLocationQtyInput();
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
    const { data } = await api.post("/inventory/replace", body);
    const warehouseSlice = data?.warehouse;
    applyWarehouseSliceToProduct(warehouseSlice);
    toast.success("Quantity updated.");
    updateModalOpen.value = false;
    await loadProduct({ refresh: true });
    applyWarehouseSliceToProduct(warehouseSlice);
  } catch (e) {
    toast.errorFrom(e, "Could not update quantity.");
  } finally {
    saving.value = false;
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
    if (route.query.client_account_id) {
      body.client_account_id = Number(route.query.client_account_id);
    }
    const { data } = await api.post("/inventory/locations/add-qty", body);
    const warehouseSlice = data?.warehouse;
    applyWarehouseSliceToProduct(warehouseSlice);
    toast.success("Location quantity updated.");
    addLocationModalOpen.value = false;
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
    if (route.query.client_account_id) {
      body.client_account_id = Number(route.query.client_account_id);
    }
    const { data } = await api.post("/inventory/transfer", body);
    const warehouseSlice = data?.warehouse;
    applyWarehouseSliceToProduct(warehouseSlice);
    toast.success("Quantity transferred.");
    transferModalOpen.value = false;
    await loadProduct({ refresh: true });
    applyWarehouseSliceToProduct(warehouseSlice);
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
    await loadProduct({ refresh: true });
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
      <div v-else-if="usePortalDetailLayout" class="staff-user-view staff-page--wide inventory-portal-detail">
        <nav
          class="staff-user-view__breadcrumb d-flex flex-wrap align-items-center gap-1"
          aria-label="Breadcrumb"
        >
          <RouterLink :to="inventoryListRoute" class="fw-bold">{{ inventoryListBreadcrumbLabel }}</RouterLink>
          <span class="text-secondary" aria-hidden="true">/</span>
          <span class="text-body-secondary fw-bold">Detail</span>
        </nav>

        <div class="staff-user-view__title-row inventory-portal-detail__title-row d-flex flex-wrap align-items-center gap-2 mb-3">
          <a
            v-if="!isPortalView && shipheroProductUrl"
            :href="shipheroProductUrl"
            target="_blank"
            rel="noopener noreferrer"
            class="btn btn-outline-secondary btn-sm orders-toolbar-outline-btn me-auto"
          >
            View in ShipHero
          </a>
          <div v-else class="me-auto" />
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

        <p v-if="product.asn_line_only" class="alert alert-info small py-2 mb-3">
          This SKU is on your ASN. Inventory counts will appear after it is received in ShipHero.
        </p>

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
                <input
                  ref="imageInputRef"
                  type="file"
                  accept="image/jpeg,image/png,image/gif,image/webp"
                  class="d-none"
                  aria-hidden="true"
                  tabindex="-1"
                  @change="onProductImageSelected"
                />
                <button
                  type="button"
                  class="inventory-portal-detail__hero-image-btn"
                  :class="{ 'inventory-portal-detail__hero-image-btn--disabled': !canUploadProductImage }"
                  :disabled="!canUploadProductImage || imageUploadBusy"
                  :title="canUploadProductImage ? 'Click to upload a product image' : undefined"
                  @click="openImageUploadPicker"
                >
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
                  <span
                    v-if="canUploadProductImage && imageUploadBusy"
                    class="inventory-portal-detail__hero-uploading"
                  >
                    Uploading…
                  </span>
                </button>
                <div class="inventory-portal-detail__hero-text">
                  <h2 class="inventory-portal-detail__hero-name">
                    {{ product.name || "Product" }}
                  </h2>
                  <p class="inventory-portal-detail__hero-sku mb-0">{{ product.sku }}</p>
                  <p
                    v-if="canUploadProductImage"
                    class="small text-body-secondary mb-0 mt-1"
                  >
                    Click image to upload (updates in ShipHero)
                  </p>
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
            <div class="staff-table-card inventory-portal-detail__section p-0 mb-3">
              <div class="inventory-portal-detail__section-head">
                <div>
                  <h2 class="inventory-portal-detail__section-title">Locations</h2>
                  <p class="small text-body-secondary mb-0">
                    Warehouse locations for this SKU
                  </p>
                </div>
              </div>
              <div class="staff-table-toolbar border-bottom">
                <div class="staff-table-toolbar--row flex-wrap align-items-end gap-2 gap-md-3">
                  <input
                    v-model="locationSearch"
                    type="search"
                    class="form-control staff-toolbar-search staff-toolbar-search--inline"
                    placeholder="Search locations"
                    aria-label="Search locations"
                  />
                  <div class="position-relative flex-shrink-0" data-toolbar-filter>
                    <button
                      type="button"
                      class="btn btn-outline-secondary staff-toolbar-btn d-inline-flex align-items-center gap-2"
                      :aria-expanded="locationFilterMenuOpen"
                      aria-haspopup="true"
                      aria-controls="inventory-locations-filter-panel"
                      @click.stop="locationFilterMenuOpen = !locationFilterMenuOpen"
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
                      v-if="locationFilterMenuOpen"
                      id="inventory-locations-filter-panel"
                      class="dropdown-menu dropdown-menu-end show shadow border p-0 staff-toolbar-filter-dropdown"
                      role="dialog"
                      aria-label="Location filters"
                      @click.stop
                    >
                      <div class="staff-toolbar-filter-dropdown__head">
                        <span>Filters</span>
                        <button
                          type="button"
                          class="btn btn-link btn-sm text-secondary text-decoration-none p-0"
                          @click="
                            clearLocationFilters();
                            locationFilterMenuOpen = false;
                          "
                        >
                          Reset
                        </button>
                      </div>
                      <div class="staff-toolbar-filter-dropdown__body">
                        <label class="form-label" for="inventory-loc-filter-bin-type">Bin Type</label>
                        <select
                          id="inventory-loc-filter-bin-type"
                          v-model="locationBinTypeFilter"
                          class="form-select staff-datatable-filters__select mb-3"
                        >
                          <option value="">All bin types</option>
                          <option v-for="binType in locationBinTypeOptions" :key="binType" :value="binType">
                            {{ binType }}
                          </option>
                        </select>
                        <label class="form-label" for="inventory-loc-filter-pickable">Pickable</label>
                        <select
                          id="inventory-loc-filter-pickable"
                          v-model="locationPickableFilter"
                          class="form-select staff-datatable-filters__select"
                        >
                          <option value="">All</option>
                          <option value="yes">Yes</option>
                          <option value="no">No</option>
                        </select>
                      </div>
                    </div>
                  </div>
                  <div
                    v-if="canManageInventoryLocations"
                    class="d-flex flex-wrap align-items-center ms-md-auto"
                  >
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
                      <th v-if="canManageInventoryLocations" class="staff-table-head__th text-center">Action</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-if="!filteredLocations.length">
                      <td :colspan="canManageInventoryLocations ? 5 : 4" class="text-center text-secondary py-4">
                        No locations found for this product.
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

            <div class="staff-table-card inventory-portal-detail__section p-0">
              <div class="inventory-portal-detail__section-head">
                <div>
                  <h2 class="inventory-portal-detail__section-title">Kits</h2>
                  <p class="small text-body-secondary mb-0">
                    Kit products that include this SKU as a component
                  </p>
                  <p v-if="parentKitsLoaded" class="small text-secondary mb-0">
                    <span v-if="parentKitsLoadedAt">Loaded: {{ formatDateTimeUs(parentKitsLoadedAt) }} · </span>
                    {{ parentKitsList.length }} kit{{ parentKitsList.length === 1 ? "" : "s" }}
                  </p>
                </div>
                <button
                  type="button"
                  class="btn btn-outline-secondary btn-sm orders-toolbar-outline-btn d-inline-flex align-items-center gap-2"
                  :disabled="parentKitsLoading"
                  :title="parentKitsLoaded ? 'Refresh kits' : 'Load kits'"
                  :aria-label="parentKitsLoaded ? 'Refresh kits' : 'Load kits'"
                  @click="loadParentKits({ refresh: parentKitsLoaded })"
                >
                  <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                  </svg>
                  {{ sectionActionLabel({ loading: parentKitsLoading, loaded: parentKitsLoaded }) }}
                </button>
              </div>
              <div
                v-if="parentKitsLoaded && parentKitsList.length"
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
                    <tr v-for="kit in parentKitsList" :key="kit.sku">
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
              <p v-else-if="parentKitsLoading" class="inventory-portal-detail__empty">
                Loading kits…
              </p>
              <p v-else-if="parentKitsError" class="inventory-portal-detail__empty text-danger">
                {{ parentKitsError }}
              </p>
              <p v-else-if="parentKitsLoaded && !parentKitsList.length" class="inventory-portal-detail__empty">
                No kits found that use this SKU as a component.
              </p>
              <p v-else class="inventory-portal-detail__empty">
                Kits are not loaded yet. Select Load to fetch them from ShipHero.
              </p>
            </div>

            <div class="staff-table-card inventory-portal-detail__section p-0">
              <div class="inventory-portal-detail__section-head">
                <div>
                  <h2 class="inventory-portal-detail__section-title">Kit Components</h2>
                  <p class="small text-body-secondary mb-0">
                    Products that make up this kit
                  </p>
                  <p v-if="kitComponentsLoaded" class="small text-secondary mb-0">
                    <span v-if="kitComponentsLoadedAt">Loaded: {{ formatDateTimeUs(kitComponentsLoadedAt) }} · </span>
                    {{ kitComponentsList.length }} component{{ kitComponentsList.length === 1 ? "" : "s" }}
                  </p>
                </div>
                <button
                  type="button"
                  class="btn btn-outline-secondary btn-sm orders-toolbar-outline-btn d-inline-flex align-items-center gap-2"
                  :disabled="kitComponentsLoading"
                  :title="kitComponentsLoaded ? 'Refresh kit components' : 'Load kit components'"
                  :aria-label="kitComponentsLoaded ? 'Refresh kit components' : 'Load kit components'"
                  @click="loadKitComponents({ refresh: kitComponentsLoaded })"
                >
                  <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                  </svg>
                  {{ sectionActionLabel({ loading: kitComponentsLoading, loaded: kitComponentsLoaded }) }}
                </button>
              </div>
              <div
                v-if="kitComponentsLoaded && kitComponentsList.length"
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
                    <tr v-for="component in kitComponentsList" :key="component.sku">
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
              <p v-else-if="kitComponentsLoading" class="inventory-portal-detail__empty">
                Loading kit components…
              </p>
              <p v-else-if="kitComponentsError" class="inventory-portal-detail__empty text-danger">
                {{ kitComponentsError }}
              </p>
              <p v-else-if="kitComponentsLoaded && !kitComponentsList.length" class="inventory-portal-detail__empty">
                No kit components configured for this SKU.
              </p>
              <p v-else class="inventory-portal-detail__empty">
                Kit components are not loaded yet. Select Load to fetch them from ShipHero.
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
                  :title="allocatedLoaded ? 'Refresh allocated orders' : 'Load allocated orders'"
                  :aria-label="allocatedLoaded ? 'Refresh allocated orders' : 'Load allocated orders'"
                  @click="loadAllocatedOrders({ refresh: allocatedLoaded })"
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
                  {{ sectionActionLabel({ loading: allocatedLoading, loaded: allocatedLoaded }) }}
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
                        <RouterLink :to="orderDetailTo(row.order_id)">
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
                No open ready-to-ship orders with allocated quantity for this SKU.
              </p>
              <p
                v-else-if="!allocatedLoaded"
                class="inventory-portal-detail__empty"
              >
                Allocated orders are not loaded yet. Select Load to fetch them from ShipHero.
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
                        <RouterLink :to="orderDetailTo(row.order_id)">
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
            Transfer To
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
              <p class="small text-secondary mb-1">
                Transfer From: {{ activeLocation?.location_name || activeLocation?.location_id || "—" }}
              </p>
              <p class="small text-secondary mb-3">QTY: {{ activeLocation?.quantity ?? 0 }}</p>
              <label class="form-label small" for="transfer-type">Transfer Type</label>
              <select id="transfer-type" v-model="transferForm.transfer_type" class="form-select mb-3">
                <option value="current">Current Locations</option>
                <option value="new">Transfer New</option>
              </select>
              <label class="form-label small" for="transfer-to">Transfer To</label>
              <select
                v-if="transferForm.transfer_type === 'current'"
                id="transfer-to"
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
                id="transfer-to"
                v-model="transferForm.to_location"
                type="text"
                class="form-control mb-3"
                placeholder="Type location name"
              />
              <div class="row g-2 align-items-end mb-3">
                <div class="col-6">
                  <label class="form-label small" for="transfer-qty">QTY</label>
                  <input
                    id="transfer-qty"
                    v-model="transferForm.quantity"
                    type="number"
                    min="1"
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
              <input
                v-model="addLocationForm.location"
                type="text"
                class="form-control mb-3"
                placeholder="Type location name"
              />
              <label class="form-label small">QTY</label>
              <input
                ref="addLocationQtyInputRef"
                v-model="addLocationForm.quantity"
                type="number"
                min="0"
                class="form-control mb-3"
              />
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
.inventory-portal-detail__hero-image-btn {
  display: block;
  padding: 0;
  border: none;
  background: transparent;
  cursor: pointer;
  position: relative;
  flex-shrink: 0;
}
.inventory-portal-detail__hero-image-btn--disabled {
  cursor: default;
}
.inventory-portal-detail__hero-image-btn:not(.inventory-portal-detail__hero-image-btn--disabled):hover .inventory-portal-detail__hero-image,
.inventory-portal-detail__hero-image-btn:not(.inventory-portal-detail__hero-image-btn--disabled):focus-visible .inventory-portal-detail__hero-image {
  outline: 2px solid var(--bs-primary);
  outline-offset: 2px;
}
.inventory-portal-detail__hero-uploading {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgba(0, 0, 0, 0.45);
  color: #fff;
  font-size: 0.75rem;
  border-radius: 12px;
}
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
