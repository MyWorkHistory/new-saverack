<script setup>
import { computed, nextTick, onMounted, onUnmounted, reactive, ref, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import api from "../../services/api";
import CrmIconRowActions from "../../components/common/CrmIconRowActions.vue";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import Modal from "../../components/Modal.vue";
import AsnProductCatalogPanel from "../../components/inventory/AsnProductCatalogPanel.vue";
import WholesaleBarcodeUploadModal from "../../components/orders/WholesaleBarcodeUploadModal.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast.js";
import { CARRIER_PRESETS } from "../../utils/carrierPresets.js";
import { formatDateUs, formatDateTimeUs } from "../../utils/formatUserDates.js";
import {
  wholesaleLineStatusBadgeClass,
  wholesaleLineStatusLabel,
  wholesaleStatusBadgeClass,
  wholesaleStatusLabel,
  wholesaleTypeLabel,
  WHOLESALE_BUNDLE_CONFIG_OPTIONS,
  WHOLESALE_COVER_EXISTING_BARCODE_OPTIONS,
  WHOLESALE_MANUAL_STATUS_OPTIONS,
  WHOLESALE_MASTER_CARTON_OPTIONS,
  WHOLESALE_SHIPPING_METHOD_REQUIREMENT_OPTIONS,
  WHOLESALE_SKU_BARCODE_LABEL_OPTIONS,
  WHOLESALE_SKU_PACKAGING_OPTIONS,
} from "../../utils/formatWholesaleOrderDisplay.js";

const route = useRoute();
const router = useRouter();
const toast = useToast();

const LINE_MENU_W = 176;
const LINE_MENU_H = 88;

const CARRIER_LIST = CARRIER_PRESETS;
const METHOD_PRESETS = ["Select", "Ground", "Priority", "Express", "Standard", "A124"];
const METHOD_OPTIONS_BY_CARRIER = {
  cheapest: ["Select", "Ground", "Priority", "Express", "Standard", "A124"],
  ups: ["Ground", "3 Day Select", "2nd Day Air", "Next Day Air Saver", "Next Day Air", "Standard", "Priority", "Express"],
  fedex: [
    "Ground",
    "Home Delivery",
    "Express Saver",
    "2Day",
    "Standard Overnight",
    "Priority Overnight",
    "International Priority",
    "International Economy",
  ],
  usps: ["First Class", "Priority Mail", "Priority Mail Express", "Parcel Select Ground", "Media Mail", "Ground"],
  dhl: ["Express Worldwide", "Express 12", "Express 9", "Express Easy"],
  asendia_one: ["Select", "Ground", "Priority", "Express", "Standard"],
  ontrac: ["Ground", "Express"],
  lasership: ["Select", "Ground", "Next Day"],
};

function carrierPresetKey(carrier) {
  return String(carrier || "").trim().toLowerCase();
}

function resolveCarrierPreset(carrier) {
  const raw = String(carrier || "").trim();
  if (!raw) return "";
  const key = carrierPresetKey(raw);
  for (const p of CARRIER_LIST) {
    if (carrierPresetKey(p) === key) return p;
  }
  return raw;
}

const loading = ref(true);
const lineBusy = ref(false);
const addPanelOpen = ref(false);
const order = ref(null);

const instructionsDraft = ref("");
const instructionsSaving = ref(false);
const statusSaving = ref(false);
const statusModalOpen = ref(false);
const statusDraft = ref("pending");

const readyToShipBusy = ref(false);

const requirementsSaving = ref(false);
const requirementsDraft = reactive({
  sku_barcode_labels: "",
  sku_barcode_labels_comment: "",
  cover_existing_barcodes: "",
  cover_existing_barcodes_comment: "",
  individual_sku_packaging: "",
  individual_sku_packaging_comment: "",
  bundle_configuration: "",
  bundle_configuration_comment: "",
  shipping_method_requirement: "",
  shipping_method_requirement_comment: "",
  master_cartons: "",
  master_cartons_comment: "",
});

const requirementSections = [
  { id: "sku-labels", label: "SKU Barcode Labels", valueKey: "sku_barcode_labels", commentKey: "sku_barcode_labels_comment", options: WHOLESALE_SKU_BARCODE_LABEL_OPTIONS },
  { id: "cover-existing", label: "Cover Existing Barcodes", valueKey: "cover_existing_barcodes", commentKey: "cover_existing_barcodes_comment", options: WHOLESALE_COVER_EXISTING_BARCODE_OPTIONS },
  { id: "packaging", label: "Individual SKU Packaging", valueKey: "individual_sku_packaging", commentKey: "individual_sku_packaging_comment", options: WHOLESALE_SKU_PACKAGING_OPTIONS },
  { id: "bundle", label: "Bundle Configuration", valueKey: "bundle_configuration", commentKey: "bundle_configuration_comment", options: WHOLESALE_BUNDLE_CONFIG_OPTIONS },
  { id: "shipping-method", label: "Shipping Method", valueKey: "shipping_method_requirement", commentKey: "shipping_method_requirement_comment", options: WHOLESALE_SHIPPING_METHOD_REQUIREMENT_OPTIONS },
  { id: "master-cartons", label: "Master Cartons", valueKey: "master_cartons", commentKey: "master_cartons_comment", options: WHOLESALE_MASTER_CARTON_OPTIONS },
];

const carrierField = ref("");
const methodField = ref("");
const shippingLinesSaving = ref(false);
const shippingModalOpen = ref(false);
const shippingSaveBusy = ref(false);
const shippingForm = ref({
  first_name: "",
  last_name: "",
  company: "",
  address1: "",
  address2: "",
  phone: "",
  city: "",
  state: "",
  country: "",
  zip: "",
  email: "",
});

const manualStatusOptions = WHOLESALE_MANUAL_STATUS_OPTIONS;

const barcodeModalOpen = ref(false);
const barcodeUploadBusy = ref(false);
const barcodeLine = ref(null);

const lineMenuOpenId = ref(null);
const lineMenuRect = ref({ top: 0, left: 0 });

const commentBody = ref("");
const commentFile = ref(null);
const commentFileInput = ref(null);
const commentSubmitting = ref(false);
const commentError = ref("");
const imagePreviewUrls = ref({});

const orderId = computed(() => String(route.params.id || ""));
const clientAccountId = computed(() => Number(order.value?.client_account_id || 0));
const isEditable = computed(() => Boolean(order.value?.is_editable));
const lines = computed(() => (Array.isArray(order.value?.lines) ? order.value.lines : []));
const comments = computed(() => (Array.isArray(order.value?.comments) ? order.value.comments : []));

const canReadyToShip = computed(() => Boolean(order.value?.can_ready_to_ship));
const showReadyToShipButton = computed(() => {
  const s = String(order.value?.status || "").toLowerCase();
  return (s === "draft" || s === "pending") && !order.value?.shiphero_order_id;
});

const canClickStatusBadge = computed(() => {
  const s = String(order.value?.status || "").toLowerCase();
  return s === "pending" || s === "completed";
});

const readyToShipDisabledReason = computed(() => {
  const o = order.value;
  if (!o) return "";
  if (!showReadyToShipButton.value) return "";
  if (canReadyToShip.value) return "";
  const missing = [];
  if (!lines.value.length) missing.push("add line items");
  if (!o.has_requirements_filled) missing.push("save requirements");
  if (!o.has_complete_shipping_address) missing.push("complete shipping address");
  const carrier = String(o.shipping_carrier || "").trim();
  const method = String(o.shipping_method || "").trim();
  if (!carrier || !method || method.toLowerCase() === "select") {
    missing.push("save carrier and method");
  }
  return missing.length ? `Complete: ${missing.join(", ")}.` : "Not ready to ship.";
});

const formattedShippingAddress = computed(() => {
  const a = order.value?.shipping_address;
  if (!a || typeof a !== "object") return "";
  const parts = [];
  const name = [a.first_name, a.last_name].filter(Boolean).join(" ").trim();
  if (name) parts.push(name);
  if (a.company) parts.push(String(a.company));
  const line1 = [a.address1, a.address2].filter(Boolean).join(", ").trim();
  if (line1) parts.push(line1);
  const cityLine = [a.city, a.state, a.zip].filter(Boolean).join(", ").trim();
  if (cityLine) parts.push(cityLine);
  if (a.country) parts.push(String(a.country));
  return parts.length ? parts.join("\n") : "—";
});

const carrierSelectOptions = computed(() => {
  const labels = new Map();
  for (const p of CARRIER_LIST) {
    labels.set(carrierPresetKey(p), p);
  }
  const cur = String(carrierField.value || "").trim();
  const curKey = carrierPresetKey(cur);
  if (curKey && !labels.has(curKey)) {
    labels.set(curKey, resolveCarrierPreset(cur));
  }
  return ["", ...labels.values()];
});

const methodSelectOptions = computed(() => {
  const key = carrierPresetKey(carrierField.value);
  const baseList = METHOD_OPTIONS_BY_CARRIER[key] || METHOD_PRESETS;
  const cur = String(methodField.value || "").trim();
  const out = ["", ...baseList];
  if (cur && !baseList.includes(cur) && !out.includes(cur)) {
    out.push(cur);
  }
  return out;
});

const itemsSummary = computed(() => {
  const rows = lines.value;
  const totalQuantity = rows.reduce((sum, line) => sum + Number(line.quantity || 0), 0);
  return {
    totalItems: rows.length,
    totalQuantity,
    quantityToShip: totalQuantity,
  };
});

const shipheroAdminUrl = computed(() => {
  const id = String(order.value?.shiphero_order_id || "").trim();
  if (!id) return null;
  const n = Number(id);
  if (Number.isFinite(n) && n > 0) {
    return `https://app.shiphero.com/dashboard/orders/details/${n}`;
  }
  return null;
});

const lineMenuOpenLine = computed(() => {
  const id = lineMenuOpenId.value;
  if (!id) return null;
  return lines.value.find((l) => l.id === id) ?? null;
});

watch(carrierField, (newCar, oldCar) => {
  if (carrierPresetKey(newCar) === carrierPresetKey(oldCar)) return;
  const key = carrierPresetKey(newCar);
  const baseList = METHOD_OPTIONS_BY_CARRIER[key];
  if (!baseList) return;
  const m = String(methodField.value || "").trim();
  if (m !== "" && !baseList.includes(m)) {
    methodField.value = "";
  }
});

function syncDraftsFromOrder(data) {
  instructionsDraft.value = String(data?.instructions || "");
  requirementsDraft.sku_barcode_labels = String(data?.sku_barcode_labels || "");
  requirementsDraft.sku_barcode_labels_comment = String(data?.sku_barcode_labels_comment || "");
  requirementsDraft.cover_existing_barcodes = String(data?.cover_existing_barcodes || "");
  requirementsDraft.cover_existing_barcodes_comment = String(data?.cover_existing_barcodes_comment || "");
  requirementsDraft.individual_sku_packaging = String(data?.individual_sku_packaging || "");
  requirementsDraft.individual_sku_packaging_comment = String(data?.individual_sku_packaging_comment || "");
  requirementsDraft.bundle_configuration = String(data?.bundle_configuration || "");
  requirementsDraft.bundle_configuration_comment = String(data?.bundle_configuration_comment || "");
  requirementsDraft.shipping_method_requirement = String(data?.shipping_method_requirement || "");
  requirementsDraft.shipping_method_requirement_comment = String(data?.shipping_method_requirement_comment || "");
  requirementsDraft.master_cartons = String(data?.master_cartons || "");
  requirementsDraft.master_cartons_comment = String(data?.master_cartons_comment || "");
  carrierField.value = resolveCarrierPreset(data?.shipping_carrier);
  methodField.value = String(data?.shipping_method || "");
}

function applyOrderData(data) {
  order.value = data;
  syncDraftsFromOrder(data);
}

function orderStatusLabel() {
  return order.value?.status_label || wholesaleStatusLabel(order.value?.status);
}

function lineStatusLabel(line) {
  return line?.status_label || wholesaleLineStatusLabel(line?.status);
}

function openStatusModal() {
  if (!canClickStatusBadge.value) return;
  const status = String(order.value?.status || "").toLowerCase();
  statusDraft.value = status === "completed" || status === "pending" ? status : "pending";
  statusModalOpen.value = true;
}

function closeStatusModal() {
  if (statusSaving.value) return;
  statusModalOpen.value = false;
}

async function saveStatusFromModal() {
  if (!order.value?.id) return;
  const next = String(statusDraft.value || "").toLowerCase();
  if (next === String(order.value.status || "").toLowerCase()) {
    statusModalOpen.value = false;
    return;
  }
  statusSaving.value = true;
  try {
    const { data } = await api.patch(`/admin/wholesale-orders/${order.value.id}`, { status: next });
    applyOrderData(data);
    statusModalOpen.value = false;
    toast.success("Status updated.");
  } catch (e) {
    toast.errorFrom(e, "Could not update status.");
  } finally {
    statusSaving.value = false;
  }
}

function inventoryDetailTo(sku) {
  const s = String(sku || "").trim();
  if (!s) return null;
  const query = clientAccountId.value > 0 ? { client_account_id: String(clientAccountId.value) } : {};
  return { name: "inventory-detail", params: { sku: s }, query };
}

function inventoryDetailHref(sku) {
  const to = inventoryDetailTo(sku);
  if (!to) return "";
  return router.resolve(to).href;
}

function openInventoryInNewTab(line, event) {
  event?.preventDefault?.();
  event?.stopPropagation?.();
  const href = inventoryDetailHref(line?.sku);
  if (!href) return;
  window.open(href, "_blank", "noopener,noreferrer");
}

function isImageMime(mime) {
  return String(mime || "").toLowerCase().startsWith("image/");
}

function initials(name) {
  const parts = String(name || "").trim().split(/\s+/).filter(Boolean);
  if (!parts.length) return "?";
  if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
  return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
}

function formatFileSize(bytes) {
  const n = Number(bytes);
  if (!Number.isFinite(n) || n <= 0) return "";
  if (n < 1024) return `${n} B`;
  if (n < 1024 * 1024) return `${(n / 1024).toFixed(1)} KB`;
  return `${(n / (1024 * 1024)).toFixed(1)} MB`;
}

async function load() {
  loading.value = true;
  try {
    const { data } = await api.get(`/admin/wholesale-orders/${orderId.value}`);
    applyOrderData(data);
    setCrmPageMeta({
      title: `Save Rack | Wholesale | ${data.order_number || "Order"}`,
      description: "Wholesale order detail.",
    });
  } catch (e) {
    toast.errorFrom(e, "Could not load wholesale order.");
    router.push({ name: "wholesale-orders" });
  } finally {
    loading.value = false;
  }
}

async function saveInstructions() {
  if (!order.value?.id || !isEditable.value) return;
  const next = instructionsDraft.value.trim();
  if (next === String(order.value.instructions || "").trim()) return;
  instructionsSaving.value = true;
  try {
    const { data } = await api.patch(`/admin/wholesale-orders/${order.value.id}`, {
      instructions: next || null,
    });
    applyOrderData(data);
    toast.success("Note saved.");
  } catch (e) {
    toast.errorFrom(e, "Could not save note.");
  } finally {
    instructionsSaving.value = false;
  }
}

async function saveRequirements() {
  if (!order.value?.id || !isEditable.value) return;
  requirementsSaving.value = true;
  try {
    const { data } = await api.patch(`/admin/wholesale-orders/${order.value.id}`, {
      sku_barcode_labels: requirementsDraft.sku_barcode_labels || null,
      sku_barcode_labels_comment: requirementsDraft.sku_barcode_labels_comment.trim() || null,
      cover_existing_barcodes: requirementsDraft.cover_existing_barcodes || null,
      cover_existing_barcodes_comment: requirementsDraft.cover_existing_barcodes_comment.trim() || null,
      individual_sku_packaging: requirementsDraft.individual_sku_packaging || null,
      individual_sku_packaging_comment: requirementsDraft.individual_sku_packaging_comment.trim() || null,
      bundle_configuration: requirementsDraft.bundle_configuration || null,
      bundle_configuration_comment: requirementsDraft.bundle_configuration_comment.trim() || null,
      shipping_method_requirement: requirementsDraft.shipping_method_requirement || null,
      shipping_method_requirement_comment: requirementsDraft.shipping_method_requirement_comment.trim() || null,
      master_cartons: requirementsDraft.master_cartons || null,
      master_cartons_comment: requirementsDraft.master_cartons_comment.trim() || null,
    });
    applyOrderData(data);
    toast.success("Requirements saved.");
  } catch (e) {
    toast.errorFrom(e, "Could not save requirements.");
  } finally {
    requirementsSaving.value = false;
  }
}

function openShippingModal() {
  const a = order.value?.shipping_address;
  const src = a && typeof a === "object" ? a : {};
  shippingForm.value = {
    first_name: String(src.first_name || ""),
    last_name: String(src.last_name || ""),
    company: String(src.company || ""),
    address1: String(src.address1 || ""),
    address2: String(src.address2 || ""),
    phone: String(src.phone || ""),
    city: String(src.city || ""),
    state: String(src.state || ""),
    country: String(src.country || ""),
    zip: String(src.zip || ""),
    email: String(src.email || ""),
  };
  shippingModalOpen.value = true;
}

function closeShippingModal() {
  if (shippingSaveBusy.value) return;
  shippingModalOpen.value = false;
}

async function saveShippingAddress() {
  if (!order.value?.id || !isEditable.value) return;
  shippingSaveBusy.value = true;
  try {
    const { data } = await api.patch(`/admin/wholesale-orders/${order.value.id}`, {
      shipping_address: { ...shippingForm.value },
    });
    applyOrderData(data);
    shippingModalOpen.value = false;
    toast.success("Shipping address saved.");
  } catch (e) {
    toast.errorFrom(e, "Could not save shipping address.");
  } finally {
    shippingSaveBusy.value = false;
  }
}

async function saveShippingLines() {
  if (!order.value?.id || !isEditable.value) return;
  shippingLinesSaving.value = true;
  try {
    const { data } = await api.patch(`/admin/wholesale-orders/${order.value.id}`, {
      shipping_carrier: carrierField.value || null,
      shipping_method: methodField.value || null,
    });
    applyOrderData(data);
    toast.success("Carrier and method saved.");
  } catch (e) {
    toast.errorFrom(e, "Could not save carrier and method.");
  } finally {
    shippingLinesSaving.value = false;
  }
}

async function submitReadyToShip() {
  if (!order.value?.id || !canReadyToShip.value || readyToShipBusy.value) return;
  readyToShipBusy.value = true;
  try {
    const { data } = await api.post(`/admin/wholesale-orders/${order.value.id}/ready-to-ship`);
    applyOrderData(data);
    toast.success("Order sent to ShipHero.");
  } catch (e) {
    toast.errorFrom(e, "Could not mark order ready to ship.");
  } finally {
    readyToShipBusy.value = false;
  }
}

function buildLinePayload(product, quantity) {
  const sku = String(product?.sku || "").trim();
  const name = String(product?.name || product?.product_name || sku).trim();
  const imageUrl = product?.image_url || product?.thumbnail || product?.small_image || null;
  return {
    sku,
    name,
    image_url: imageUrl,
    quantity: Math.max(1, Math.floor(Number(quantity) || 0)),
  };
}

async function addFromCatalog({ product, quantity }) {
  if (!order.value?.id || !isEditable.value) return;
  const payload = buildLinePayload(product, quantity);
  if (!payload.sku) {
    toast.error("This product has no SKU.");
    return;
  }
  lineBusy.value = true;
  try {
    const { data } = await api.post(`/admin/wholesale-orders/${order.value.id}/lines`, payload);
    applyOrderData(data);
    toast.success("Product added.");
  } catch (e) {
    toast.errorFrom(e, "Could not add product.");
  } finally {
    lineBusy.value = false;
  }
}

async function saveLineQty(line, rawQty) {
  if (!order.value?.id || !isEditable.value || !line?.id) return;
  const qty = Math.max(1, Number(rawQty) || 1);
  if (qty === Number(line.quantity)) return;
  lineBusy.value = true;
  try {
    const { data } = await api.patch(`/admin/wholesale-orders/${order.value.id}/lines/${line.id}`, {
      quantity: qty,
    });
    applyOrderData(data);
  } catch (e) {
    toast.errorFrom(e, "Could not update quantity.");
    await load();
  } finally {
    lineBusy.value = false;
  }
}

async function removeLine(line) {
  if (!order.value?.id || !isEditable.value || !line?.id) return;
  lineBusy.value = true;
  try {
    const { data } = await api.delete(`/admin/wholesale-orders/${order.value.id}/lines/${line.id}`);
    applyOrderData(data);
    toast.success("Line removed.");
  } catch (e) {
    toast.errorFrom(e, "Could not remove line.");
  } finally {
    lineBusy.value = false;
  }
}

async function markShipAsIs(line) {
  if (!order.value?.id || !isEditable.value || !line?.id) return;
  if (String(line.status || "").toLowerCase() === "ship_as_is") return;
  lineBusy.value = true;
  try {
    const { data } = await api.patch(`/admin/wholesale-orders/${order.value.id}/lines/${line.id}`, {
      barcode_mode: "ship_as_is",
    });
    applyOrderData(data);
    toast.success("Line marked ship as is.");
  } catch (e) {
    toast.errorFrom(e, "Could not update line.");
  } finally {
    lineBusy.value = false;
  }
}

function openBarcodeModal(line) {
  if (!isEditable.value || !line?.id) return;
  barcodeLine.value = line;
  barcodeModalOpen.value = true;
  closeLineMenu();
}

function closeBarcodeModal() {
  if (barcodeUploadBusy.value) return;
  barcodeModalOpen.value = false;
  barcodeLine.value = null;
}

async function uploadBarcode(file) {
  if (!order.value?.id || !barcodeLine.value?.id || !file) return;
  barcodeUploadBusy.value = true;
  const fd = new FormData();
  fd.append("barcode", file);
  try {
    const { data } = await api.post(
      `/admin/wholesale-orders/${order.value.id}/lines/${barcodeLine.value.id}/barcode`,
      fd,
      { headers: { "Content-Type": undefined } },
    );
    applyOrderData(data);
    barcodeModalOpen.value = false;
    barcodeLine.value = null;
    toast.success("Barcode uploaded.");
  } catch (e) {
    toast.errorFrom(e, "Could not upload barcode.");
  } finally {
    barcodeUploadBusy.value = false;
  }
}

async function printBarcode(line) {
  if (!order.value?.id || !line?.id || !line.has_barcode) return;
  try {
    const { data } = await api.get(
      `/admin/wholesale-orders/${order.value.id}/lines/${line.id}/barcode.pdf`,
      { responseType: "blob" },
    );
    const blob = data instanceof Blob ? data : new Blob([data]);
    const url = window.URL.createObjectURL(blob);
    window.open(url, "_blank", "noopener");
    setTimeout(() => window.URL.revokeObjectURL(url), 30000);
  } catch (e) {
    toast.errorFrom(e, "Could not open barcode.");
  }
}

function placeLineMenu(anchorEl) {
  if (!(anchorEl instanceof HTMLElement)) return;
  const r = anchorEl.getBoundingClientRect();
  let top = r.bottom + 4;
  let left = r.right - LINE_MENU_W;
  left = Math.max(8, Math.min(left, window.innerWidth - LINE_MENU_W - 8));
  if (top + LINE_MENU_H > window.innerHeight - 8) {
    top = Math.max(8, r.top - LINE_MENU_H - 4);
  }
  lineMenuRect.value = { top, left };
}

async function toggleLineMenu(lineId, e) {
  e?.stopPropagation?.();
  if (lineMenuOpenId.value === lineId) {
    lineMenuOpenId.value = null;
    return;
  }
  const btn = e?.currentTarget;
  lineMenuOpenId.value = lineId;
  await nextTick();
  requestAnimationFrame(() => {
    if (btn instanceof HTMLElement) placeLineMenu(btn);
  });
}

function closeLineMenu() {
  lineMenuOpenId.value = null;
}

function onLineMenuUpload() {
  const line = lineMenuOpenLine.value;
  closeLineMenu();
  if (line) openBarcodeModal(line);
}

function onLineMenuRemove() {
  const line = lineMenuOpenLine.value;
  closeLineMenu();
  if (line) removeLine(line);
}

function onDocClickMenus(e) {
  if (!e.target?.closest?.("[data-row-actions]")) {
    lineMenuOpenId.value = null;
  }
}

async function submitComment() {
  if (!order.value?.id) return;
  const body = commentBody.value?.trim() || "";
  if (!body) {
    commentError.value = "Write a comment first.";
    return;
  }
  commentSubmitting.value = true;
  commentError.value = "";
  const fd = new FormData();
  fd.append("body", body);
  if (commentFile.value) fd.append("attachment", commentFile.value);
  try {
    const { data } = await api.post(`/admin/wholesale-orders/${order.value.id}/comments`, fd, {
      headers: { "Content-Type": undefined },
    });
    const list = Array.isArray(order.value.comments) ? [...order.value.comments] : [];
    list.push(data);
    order.value = { ...order.value, comments: list };
    commentBody.value = "";
    commentFile.value = null;
    if (commentFileInput.value) commentFileInput.value.value = "";
    toast.success("Comment posted.");
  } catch (e) {
    commentError.value = e?.response?.data?.message || "Could not post comment.";
  } finally {
    commentSubmitting.value = false;
  }
}

async function downloadAttachment(commentId) {
  if (!order.value?.id) return;
  try {
    const { data } = await api.get(
      `/admin/wholesale-orders/${order.value.id}/comments/${commentId}/attachment`,
      { responseType: "blob" },
    );
    const c = comments.value.find((x) => x.id === commentId);
    let name = "attachment";
    if (c?.attachment?.original_name) name = c.attachment.original_name;
    const url = window.URL.createObjectURL(data);
    const a = document.createElement("a");
    a.href = url;
    a.download = name;
    a.click();
    window.URL.revokeObjectURL(url);
  } catch (e) {
    toast.errorFrom(e, "Could not download attachment.");
  }
}

async function loadImagePreview(commentId) {
  if (!order.value?.id || imagePreviewUrls.value[commentId]) return;
  try {
    const { data } = await api.get(
      `/admin/wholesale-orders/${order.value.id}/comments/${commentId}/attachment`,
      { responseType: "blob" },
    );
    imagePreviewUrls.value = {
      ...imagePreviewUrls.value,
      [commentId]: window.URL.createObjectURL(data),
    };
  } catch {
    /* ignore preview failures */
  }
}

onMounted(() => {
  load();
  document.addEventListener("click", onDocClickMenus);
  window.addEventListener("scroll", closeLineMenu, true);
  window.addEventListener("resize", closeLineMenu);
});

onUnmounted(() => {
  document.removeEventListener("click", onDocClickMenus);
  window.removeEventListener("scroll", closeLineMenu, true);
  window.removeEventListener("resize", closeLineMenu);
  Object.values(imagePreviewUrls.value).forEach((url) => {
    if (url) window.URL.revokeObjectURL(url);
  });
});
</script>

<template>
  <div v-if="loading" class="staff-page staff-page--wide py-5">
    <CrmLoadingSpinner message="Loading order…" :center="true" />
  </div>

  <div v-else-if="order" class="staff-page staff-page--wide order-detail-page wholesale-order-detail-page">
    <div class="staff-table-card staff-datatable-card staff-datatable-card--white mb-4">
      <div class="p-4 pb-3">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
          <div class="min-w-0">
            <button
              type="button"
              class="btn btn-link btn-sm text-secondary px-0 py-0 mb-2 text-decoration-none"
              @click="router.push({ name: 'wholesale-orders' })"
            >
              &lt; Wholesale Orders
            </button>
            <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
              <h1 class="h4 mb-0 fw-semibold text-body">Order #{{ order.order_number }}</h1>
              <button
                v-if="canClickStatusBadge"
                type="button"
                class="badge rounded-pill fw-medium border-0 asn-line-status-badge"
                :class="wholesaleStatusBadgeClass(order.status)"
                @click="openStatusModal"
              >
                {{ orderStatusLabel() }}
              </button>
              <span
                v-else
                class="badge rounded-pill fw-medium asn-line-status-badge"
                :class="wholesaleStatusBadgeClass(order.status)"
              >
                {{ orderStatusLabel() }}
              </span>
            </div>
            <p class="small text-secondary mb-0">
              Order placed on {{ formatDateUs(order.created_at) || "—" }} • via Save Rack CRM
            </p>
          </div>
          <div v-if="showReadyToShipButton" class="flex-shrink-0">
            <button
              type="button"
              class="btn btn-primary staff-page-primary"
              :disabled="!canReadyToShip || readyToShipBusy"
              :title="!canReadyToShip ? readyToShipDisabledReason : undefined"
              @click="submitReadyToShip"
            >
              {{ readyToShipBusy ? "Sending…" : "Ready to Ship" }}
            </button>
          </div>
        </div>
      </div>
    </div>

    <div class="row g-4">
      <div class="col-lg-8 d-flex flex-column gap-4">
        <div class="staff-table-card staff-datatable-card staff-datatable-card--white p-0">
          <div class="px-4 py-3 border-bottom d-flex flex-wrap justify-content-between align-items-center gap-2 order-detail-page__section-head">
            <div class="d-flex align-items-center gap-2 min-w-0">
              <span class="order-detail-page__section-icon order-detail-page__section-icon--items" aria-hidden="true">
                <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                </svg>
              </span>
              <h2 class="h6 mb-0 fw-semibold">Items</h2>
            </div>
            <button
              v-if="isEditable"
              type="button"
              class="btn btn-sm btn-primary staff-page-primary"
              :disabled="lineBusy"
              @click="addPanelOpen = !addPanelOpen"
            >
              {{ addPanelOpen ? "Hide Add Products" : "Add Products" }}
            </button>
          </div>

          <div v-if="isEditable && addPanelOpen" class="border-bottom">
            <AsnProductCatalogPanel
              :client-account-id="clientAccountId"
              :wholesale-order-id="orderId"
              :active="addPanelOpen"
              :busy="lineBusy"
              qty-label="Quantity"
              search-input-id="wholesale-order-catalog-search"
              @add="addFromCatalog"
            />
          </div>

          <div class="table-responsive staff-table-wrap">
            <table class="table table-hover align-middle mb-0 staff-data-table">
              <thead class="table-light staff-table-head">
                <tr>
                  <th class="staff-table-head__th order-detail-page__items-col" scope="col">Item</th>
                  <th class="staff-table-head__th" scope="col">SKU</th>
                  <th class="staff-table-head__th text-center" scope="col">Qty</th>
                  <th class="staff-table-head__th text-center" scope="col">Barcodes</th>
                  <th v-if="isEditable" class="staff-table-head__th text-center order-detail-page__items-actions-col" scope="col">
                    Actions
                  </th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="line in lines" :key="line.id">
                  <td class="order-detail-page__items-col">
                    <div class="order-detail-page__item-cell">
                      <div class="asn-line-media">
                        <a
                          v-if="inventoryDetailHref(line.sku)"
                          :href="inventoryDetailHref(line.sku)"
                          target="_blank"
                          rel="noopener noreferrer"
                          class="asn-line-thumb-link text-decoration-none"
                          :aria-label="line.sku ? `View inventory for SKU ${line.sku} in new tab` : undefined"
                          @click="openInventoryInNewTab(line, $event)"
                        >
                          <img
                            v-if="line.image_url"
                            :src="line.image_url"
                            alt=""
                            class="asn-line-thumb asn-line-thumb--lg"
                            loading="lazy"
                          />
                          <div v-else class="asn-line-thumb asn-line-thumb--lg asn-line-thumb--empty" aria-hidden="true" />
                        </a>
                        <template v-else>
                          <img
                            v-if="line.image_url"
                            :src="line.image_url"
                            alt=""
                            class="asn-line-thumb asn-line-thumb--lg"
                            loading="lazy"
                          />
                          <div v-else class="asn-line-thumb asn-line-thumb--lg asn-line-thumb--empty" aria-hidden="true" />
                        </template>
                        <span
                          class="badge rounded-pill fw-medium asn-line-status-badge"
                          :class="wholesaleLineStatusBadgeClass(line.status)"
                        >
                          {{ lineStatusLabel(line) }}
                        </span>
                      </div>
                      <div class="order-detail-page__item-copy">
                        <div class="order-detail-page__item-name-sub" :title="line.name">{{ line.name || "—" }}</div>
                      </div>
                    </div>
                  </td>
                  <td>
                    <div class="order-detail-page__item-sku-title" :title="line.sku || undefined">
                      {{ line.sku || "—" }}
                    </div>
                  </td>
                  <td class="text-center">
                    <input
                      v-if="isEditable"
                      type="number"
                      min="1"
                      class="form-control form-control-sm text-center mx-auto wholesale-line-qty-input"
                      :value="line.quantity"
                      :disabled="lineBusy"
                      @change="saveLineQty(line, $event.target.value)"
                    />
                    <span v-else>{{ line.quantity }}</span>
                  </td>
                  <td class="text-center">
                    <button
                      v-if="line.has_barcode"
                      type="button"
                      class="btn btn-link btn-sm p-0 text-decoration-none"
                      @click="printBarcode(line)"
                    >
                      Print Barcode
                    </button>
                    <button
                      v-else-if="isEditable && String(line.status || '').toLowerCase() !== 'ship_as_is'"
                      type="button"
                      class="btn btn-link btn-sm p-0 text-decoration-none"
                      :disabled="lineBusy"
                      @click="markShipAsIs(line)"
                    >
                      Ship As Is
                    </button>
                    <span v-else-if="String(line.status || '').toLowerCase() === 'ship_as_is'" class="text-secondary small">
                      Ship As Is
                    </span>
                    <span v-else class="text-secondary">—</span>
                  </td>
                  <td v-if="isEditable" class="text-center align-middle order-detail-page__items-actions-col">
                    <div
                      data-row-actions
                      class="staff-actions-inner staff-actions-inner--single justify-content-center"
                      @click.stop
                    >
                      <button
                        type="button"
                        class="staff-action-btn staff-action-btn--more"
                        :class="{ 'is-open': lineMenuOpenId === line.id }"
                        :aria-expanded="lineMenuOpenId === line.id ? 'true' : 'false'"
                        aria-haspopup="true"
                        aria-label="Line item actions"
                        :disabled="lineBusy"
                        @click="toggleLineMenu(line.id, $event)"
                      >
                        <CrmIconRowActions variant="horizontal" />
                      </button>
                    </div>
                  </td>
                </tr>
                <tr v-if="!lines.length">
                  <td :colspan="isEditable ? 5 : 4" class="text-center text-secondary py-4">No items yet.</td>
                </tr>
              </tbody>
            </table>
          </div>

          <div v-if="lines.length" class="order-detail-page__items-summary border-top px-4 py-3">
            <div class="order-detail-page__items-summary-tile">
              <span class="order-detail-page__items-summary-icon order-detail-page__items-summary-icon--items" aria-hidden="true">
                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                </svg>
              </span>
              <div>
                <div class="order-detail-page__items-summary-label">Total Items</div>
                <div class="order-detail-page__items-summary-value">{{ itemsSummary.totalItems }}</div>
              </div>
            </div>
            <div class="order-detail-page__items-summary-tile">
              <span class="order-detail-page__items-summary-icon order-detail-page__items-summary-icon--qty" aria-hidden="true">
                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                </svg>
              </span>
              <div>
                <div class="order-detail-page__items-summary-label">Total Quantity</div>
                <div class="order-detail-page__items-summary-value">{{ itemsSummary.totalQuantity }}</div>
              </div>
            </div>
            <div class="order-detail-page__items-summary-tile">
              <span class="order-detail-page__items-summary-icon order-detail-page__items-summary-icon--ship" aria-hidden="true">
                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4" />
                </svg>
              </span>
              <div>
                <div class="order-detail-page__items-summary-label">Quantity to Ship</div>
                <div class="order-detail-page__items-summary-value">{{ itemsSummary.quantityToShip }}</div>
              </div>
            </div>
            <div class="order-detail-page__items-summary-tile">
              <span class="order-detail-page__items-summary-icon order-detail-page__items-summary-icon--cost" aria-hidden="true">
                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A2 2 0 013 12V7a4 4 0 014-4z" />
                </svg>
              </span>
              <div>
                <div class="order-detail-page__items-summary-label">Label Cost</div>
                <div class="order-detail-page__items-summary-value">—</div>
              </div>
            </div>
          </div>
        </div>

        <div class="staff-table-card staff-datatable-card staff-datatable-card--white p-4">
          <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3 order-detail-page__section-head">
            <div class="d-flex align-items-center gap-2 min-w-0">
              <span class="order-detail-page__section-icon order-detail-page__section-icon--note" aria-hidden="true">
                <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                </svg>
              </span>
              <h2 class="h6 mb-0 fw-semibold">Note for warehouse packer</h2>
            </div>
          </div>
          <textarea
            v-if="isEditable"
            v-model="instructionsDraft"
            class="form-control mb-3"
            rows="5"
            maxlength="20000"
            placeholder="Add a note for the warehouse team…"
            :disabled="instructionsSaving"
          />
          <p v-else class="small mb-0 text-secondary" style="white-space: pre-wrap">
            {{ order.instructions || "—" }}
          </p>
          <button
            v-if="isEditable"
            type="button"
            class="btn btn-primary btn-sm staff-page-primary fw-semibold"
            :disabled="instructionsSaving"
            @click="saveInstructions"
          >
            {{ instructionsSaving ? "Saving…" : "Save Note" }}
          </button>
        </div>

        <div class="staff-table-card staff-datatable-card staff-datatable-card--white p-4">
          <h2 class="h6 fw-semibold mb-3">Comments</h2>
          <ul v-if="comments.length" class="list-unstyled mb-0 pb-4 border-bottom">
            <li v-for="c in comments" :key="c.id" class="d-flex gap-3 mb-4">
              <span
                class="d-flex align-items-center justify-content-center rounded-circle flex-shrink-0 small fw-semibold bg-primary-subtle text-primary-emphasis"
                style="width: 2rem; height: 2rem"
              >
                {{ initials(c.user?.name) }}
              </span>
              <div class="min-w-0 flex-grow-1">
                <div class="d-flex flex-wrap align-items-baseline gap-2">
                  <span class="small fw-medium">{{ c.user?.name || "User" }}</span>
                  <span class="small text-secondary">{{ formatDateTimeUs(c.created_at) }}</span>
                </div>
                <p class="mt-1 mb-0 small" style="white-space: pre-wrap">{{ c.body }}</p>
                <div v-if="c.attachment" class="mt-2">
                  <img
                    v-if="isImageMime(c.attachment.mime)"
                    :src="imagePreviewUrls[c.id]"
                    alt=""
                    class="img-fluid rounded border"
                    style="max-height: 12rem"
                    @load="loadImagePreview(c.id)"
                  />
                  <button
                    type="button"
                    class="btn btn-link btn-sm text-decoration-none p-0"
                    @click="downloadAttachment(c.id)"
                  >
                    {{ c.attachment.original_name || "Download attachment" }}
                    <span v-if="formatFileSize(c.attachment.size)" class="text-secondary">
                      ({{ formatFileSize(c.attachment.size) }})
                    </span>
                  </button>
                </div>
              </div>
            </li>
          </ul>
          <p v-else class="text-secondary small border-bottom pb-4 mb-0">No comments yet.</p>

          <div class="pt-4">
            <label class="form-label small text-secondary" for="wholesale-order-comment">Add comment</label>
            <textarea
              id="wholesale-order-comment"
              v-model="commentBody"
              rows="3"
              class="form-control"
              placeholder="Write an update…"
            />
            <div class="mt-3 d-flex flex-column flex-sm-row align-items-stretch align-items-sm-center justify-content-between gap-2">
              <input
                ref="commentFileInput"
                type="file"
                accept="image/jpeg,image/png,image/gif,image/webp,.pdf,.txt,.doc,.docx"
                class="form-control form-control-sm"
                @change="commentFile = $event.target.files?.[0] || null"
              />
              <button
                type="button"
                class="btn btn-primary staff-page-primary"
                :disabled="commentSubmitting"
                @click="submitComment"
              >
                {{ commentSubmitting ? "Posting…" : "Post Comment" }}
              </button>
            </div>
            <p v-if="commentError" class="text-danger small mt-2 mb-0">{{ commentError }}</p>
          </div>
        </div>
      </div>

      <div class="col-lg-4 d-flex flex-column gap-4 order-detail-page__side-column">
        <div class="staff-table-card staff-datatable-card staff-datatable-card--white p-4 order-detail-page__side-panel wholesale-requirements-card">
          <h3 class="h6 fw-semibold mb-1">Product &amp; Fulfillment Requirements</h3>
          <p class="small text-secondary mb-4 wholesale-requirements-card__subtitle">
            Please select the appropriate options for each requirement and add any relevant comments.
          </p>

          <div
            v-for="section in requirementSections"
            :key="section.id"
            class="wholesale-requirements-card__section"
          >
            <label class="form-label fw-semibold mb-2" :for="`wholesale-req-${section.id}`">
              {{ section.label }} <span class="text-danger">*</span>
            </label>
            <select
              :id="`wholesale-req-${section.id}`"
              v-model="requirementsDraft[section.valueKey]"
              class="form-select"
              :disabled="!isEditable || requirementsSaving"
            >
              <option value="">Select an option</option>
              <option v-for="opt in section.options" :key="opt.value" :value="opt.value">
                {{ opt.label }}
              </option>
            </select>
            <template v-if="section.commentKey">
              <label class="form-label small text-secondary mt-3 mb-1" :for="`wholesale-req-${section.id}-comment`">
                Comments (Optional)
              </label>
              <textarea
                :id="`wholesale-req-${section.id}-comment`"
                v-model="requirementsDraft[section.commentKey]"
                class="form-control wholesale-requirements-card__comment"
                rows="3"
                placeholder="Enter any additional comments..."
                :disabled="!isEditable || requirementsSaving"
              />
            </template>
          </div>

          <button
            v-if="isEditable"
            type="button"
            class="btn btn-primary staff-page-primary fw-semibold mt-2"
            :disabled="requirementsSaving"
            @click="saveRequirements"
          >
            {{ requirementsSaving ? "Saving…" : "Save Requirements" }}
          </button>
        </div>

        <div class="staff-table-card staff-datatable-card staff-datatable-card--white p-4 order-detail-page__side-panel">
          <div class="d-flex justify-content-between align-items-start gap-2 mb-3 order-detail-page__section-head">
            <div class="d-flex align-items-center gap-2 min-w-0">
              <span class="order-detail-page__section-icon order-detail-page__section-icon--shipping" aria-hidden="true">
                <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16V6a1 1 0 00-1-1H4a1 1 0 00-1 1v10a1 1 0 001 1h1m8-1a1 1 0 01-1 1H9m4-1V8a1 1 0 011-1h2.586a1 1 0 01.707.293l3.414 3.414a1 1 0 01.293.707V16a1 1 0 01-1 1h-1m-6-1a1 1 0 001 1h1M5 17a2 2 0 104 0m6 0a2 2 0 104 0" />
                </svg>
              </span>
              <h3 class="h6 fw-semibold mb-0">Shipping Address</h3>
            </div>
            <button
              v-if="isEditable"
              type="button"
              class="btn btn-link btn-sm p-0 text-decoration-none"
              @click="openShippingModal"
            >
              Edit
            </button>
          </div>
          <p class="small mb-3" style="white-space: pre-line">{{ formattedShippingAddress }}</p>
          <div class="mb-3">
            <label class="order-detail-page__detail-label d-block mb-1" for="wholesale-carrier">Shipping Carrier</label>
            <select
              id="wholesale-carrier"
              v-model="carrierField"
              class="form-select form-select-sm"
              :disabled="!isEditable || shippingLinesSaving"
            >
              <option v-for="c in carrierSelectOptions" :key="'c-' + (c || 'empty')" :value="c">
                {{ c === "" ? "—" : c }}
              </option>
            </select>
          </div>
          <div class="mb-3">
            <label class="order-detail-page__detail-label d-block mb-1" for="wholesale-method">Method</label>
            <select
              id="wholesale-method"
              v-model="methodField"
              class="form-select form-select-sm"
              :disabled="!isEditable || shippingLinesSaving"
            >
              <option v-for="m in methodSelectOptions" :key="'m-' + (m || 'empty')" :value="m">
                {{ m === "" ? "—" : m }}
              </option>
            </select>
          </div>
          <button
            v-if="isEditable"
            type="button"
            class="btn btn-primary btn-sm staff-page-primary"
            :disabled="shippingLinesSaving"
            @click="saveShippingLines"
          >
            {{ shippingLinesSaving ? "Saving…" : "Save Carrier & Method" }}
          </button>
        </div>

        <div class="staff-table-card staff-datatable-card staff-datatable-card--white p-4 order-detail-page__side-panel">
          <div class="d-flex align-items-center gap-2 mb-3 order-detail-page__section-head">
            <span class="order-detail-page__section-icon order-detail-page__section-icon--details" aria-hidden="true">
              <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
              </svg>
            </span>
            <h3 class="h6 fw-semibold mb-0">Order Info</h3>
          </div>
          <div class="order-detail-page__detail-rows">
            <div class="order-detail-page__detail-row">
              <span class="order-detail-page__detail-label">Account</span>
              <span class="order-detail-page__detail-value">{{ order.client_account_company_name || "—" }}</span>
            </div>
            <div class="order-detail-page__detail-row">
              <span class="order-detail-page__detail-label">Type</span>
              <span class="order-detail-page__detail-value">{{ order.order_type_label || wholesaleTypeLabel(order.order_type) }}</span>
            </div>
            <div class="order-detail-page__detail-row">
              <span class="order-detail-page__detail-label">Create Date</span>
              <span class="order-detail-page__detail-value">{{ formatDateUs(order.created_at) || "—" }}</span>
            </div>
            <div class="order-detail-page__detail-row">
              <span class="order-detail-page__detail-label">Created By</span>
              <span class="order-detail-page__detail-value">{{ order.created_by_name || "—" }}</span>
            </div>
            <div v-if="shipheroAdminUrl" class="order-detail-page__detail-row">
              <span class="order-detail-page__detail-label">ShipHero Order</span>
              <span class="order-detail-page__detail-value">
                <a
                  :href="shipheroAdminUrl"
                  target="_blank"
                  rel="noopener noreferrer"
                  class="text-primary text-decoration-none d-inline-flex align-items-center gap-1"
                >
                  View in ShipHero
                  <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                  </svg>
                </a>
              </span>
            </div>
            <div v-else-if="order.shiphero_order_id" class="order-detail-page__detail-row">
              <span class="order-detail-page__detail-label">ShipHero Order ID</span>
              <span class="order-detail-page__detail-value text-break">{{ order.shiphero_order_id }}</span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <Modal :open="statusModalOpen" title="Update Status" @close="closeStatusModal">
      <label class="form-label" for="wholesale-order-status-modal">Status</label>
      <select
        id="wholesale-order-status-modal"
        v-model="statusDraft"
        class="form-select mb-4"
        :disabled="statusSaving"
      >
        <option v-for="opt in manualStatusOptions" :key="opt.value" :value="opt.value">
          {{ opt.label }}
        </option>
      </select>
      <div class="d-flex justify-content-end gap-2">
        <button type="button" class="btn btn-outline-secondary" :disabled="statusSaving" @click="closeStatusModal">
          Cancel
        </button>
        <button type="button" class="btn btn-primary staff-page-primary" :disabled="statusSaving" @click="saveStatusFromModal">
          {{ statusSaving ? "Saving…" : "Save" }}
        </button>
      </div>
    </Modal>

    <Modal :open="shippingModalOpen" title="Edit Shipping Address" @close="closeShippingModal">
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label small text-secondary" for="wholesale-ship-fn">First Name</label>
          <input id="wholesale-ship-fn" v-model="shippingForm.first_name" type="text" class="form-control" />
        </div>
        <div class="col-md-6">
          <label class="form-label small text-secondary" for="wholesale-ship-ln">Last Name</label>
          <input id="wholesale-ship-ln" v-model="shippingForm.last_name" type="text" class="form-control" />
        </div>
        <div class="col-12">
          <label class="form-label small text-secondary" for="wholesale-ship-co">Company</label>
          <input id="wholesale-ship-co" v-model="shippingForm.company" type="text" class="form-control" />
        </div>
        <div class="col-12">
          <label class="form-label small text-secondary" for="wholesale-ship-a1">Address</label>
          <input id="wholesale-ship-a1" v-model="shippingForm.address1" type="text" class="form-control" />
        </div>
        <div class="col-12">
          <label class="form-label small text-secondary" for="wholesale-ship-a2">Address 2</label>
          <input id="wholesale-ship-a2" v-model="shippingForm.address2" type="text" class="form-control" />
        </div>
        <div class="col-12">
          <label class="form-label small text-secondary" for="wholesale-ship-ph">Phone</label>
          <input id="wholesale-ship-ph" v-model="shippingForm.phone" type="text" class="form-control" />
        </div>
        <div class="col-md-6">
          <label class="form-label small text-secondary" for="wholesale-ship-city">City</label>
          <input id="wholesale-ship-city" v-model="shippingForm.city" type="text" class="form-control" />
        </div>
        <div class="col-md-6">
          <label class="form-label small text-secondary" for="wholesale-ship-st">State</label>
          <input id="wholesale-ship-st" v-model="shippingForm.state" type="text" class="form-control" />
        </div>
        <div class="col-md-6">
          <label class="form-label small text-secondary" for="wholesale-ship-ct">Country</label>
          <input id="wholesale-ship-ct" v-model="shippingForm.country" type="text" class="form-control" />
        </div>
        <div class="col-md-6">
          <label class="form-label small text-secondary" for="wholesale-ship-zip">ZIP Code</label>
          <input id="wholesale-ship-zip" v-model="shippingForm.zip" type="text" class="form-control" />
        </div>
        <div class="col-12">
          <label class="form-label small text-secondary" for="wholesale-ship-em">Email</label>
          <input id="wholesale-ship-em" v-model="shippingForm.email" type="email" class="form-control" />
        </div>
      </div>
      <div class="d-flex justify-content-end gap-2 mt-4">
        <button type="button" class="btn btn-outline-secondary" :disabled="shippingSaveBusy" @click="closeShippingModal">
          Cancel
        </button>
        <button type="button" class="btn btn-primary staff-page-primary" :disabled="shippingSaveBusy" @click="saveShippingAddress">
          {{ shippingSaveBusy ? "Saving…" : "Save" }}
        </button>
      </div>
    </Modal>

    <WholesaleBarcodeUploadModal
      :open="barcodeModalOpen"
      :busy="barcodeUploadBusy"
      :line-label="barcodeLine ? `${barcodeLine.sku} — ${barcodeLine.name}` : ''"
      @close="closeBarcodeModal"
      @upload="uploadBarcode"
    />

    <Teleport to="body">
      <div
        v-if="lineMenuOpenLine"
        data-row-actions
        class="staff-row-menu fixed z-[300] overflow-hidden"
        role="menu"
        :style="{ top: `${lineMenuRect.top}px`, left: `${lineMenuRect.left}px` }"
        @click.stop
      >
        <button type="button" class="staff-row-menu__item" role="menuitem" @click="onLineMenuUpload">
          Upload Barcode
        </button>
        <button type="button" class="staff-row-menu__item staff-row-menu__item--danger" role="menuitem" @click="onLineMenuRemove">
          Remove
        </button>
      </div>
    </Teleport>
  </div>
</template>

<style scoped>
.wholesale-line-qty-input {
  max-width: 5rem;
}

.wholesale-order-detail-page .asn-line-thumb {
  width: 64px;
  height: 64px;
  border-radius: 0.4rem;
  object-fit: cover;
  border: 1px solid rgba(0, 0, 0, 0.08);
  background: #fff;
  flex-shrink: 0;
}

.wholesale-order-detail-page .asn-line-thumb--lg {
  width: 96px;
  height: 96px;
}

.wholesale-order-detail-page .asn-line-thumb--empty {
  display: block;
  background: rgba(0, 0, 0, 0.05);
}

.wholesale-order-detail-page .order-detail-page__item-cell {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  min-width: 0;
}

.wholesale-order-detail-page .order-detail-page__items-col {
  width: 40%;
  min-width: 14rem;
  vertical-align: middle;
}

.wholesale-order-detail-page .order-detail-page__item-sku-title {
  font-size: 1rem;
  font-weight: 600;
  line-height: 1.35;
  color: var(--bs-body-color);
  word-break: break-word;
}

.wholesale-order-detail-page .order-detail-page__item-name-sub {
  font-size: 0.8125rem;
  line-height: 1.4;
  color: var(--bs-secondary-color);
  word-break: break-word;
}

.wholesale-order-detail-page .asn-line-media {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
  flex-shrink: 0;
}

.wholesale-order-detail-page .asn-line-status-badge {
  font-size: 0.6875rem;
  white-space: nowrap;
}

.wholesale-order-detail-page .asn-line-thumb-link {
  flex-shrink: 0;
  line-height: 0;
}

.wholesale-order-detail-page .asn-line-thumb-link:hover .asn-line-thumb {
  opacity: 0.92;
}

.wholesale-order-detail-page .order-detail-page__section-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 2rem;
  height: 2rem;
  border-radius: 0.5rem;
  flex-shrink: 0;
}

.wholesale-order-detail-page .order-detail-page__section-icon--items {
  background: rgba(var(--bs-primary-rgb), 0.1);
  color: var(--bs-primary);
}

.wholesale-order-detail-page .order-detail-page__section-icon--note {
  background: rgba(var(--bs-info-rgb), 0.12);
  color: var(--bs-info);
}

.wholesale-order-detail-page .order-detail-page__section-icon--shipping {
  background: rgba(var(--bs-warning-rgb), 0.15);
  color: var(--bs-warning-text-emphasis, #664d03);
}

.wholesale-order-detail-page .order-detail-page__section-icon--details {
  background: rgba(var(--bs-secondary-rgb), 0.12);
  color: var(--bs-secondary);
}

.wholesale-order-detail-page .order-detail-page__detail-label {
  font-size: 0.75rem;
  color: var(--bs-secondary-color);
}

.wholesale-order-detail-page .order-detail-page__detail-value {
  font-size: 0.875rem;
}

.wholesale-order-detail-page .order-detail-page__detail-row {
  display: flex;
  justify-content: space-between;
  gap: 1rem;
  padding: 0.35rem 0;
  border-bottom: 1px solid rgba(0, 0, 0, 0.06);
}

.wholesale-order-detail-page .order-detail-page__detail-row:last-child {
  border-bottom: none;
}

.wholesale-order-detail-page .order-detail-page__items-summary {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 1rem;
}

.wholesale-order-detail-page .order-detail-page__items-summary-tile {
  display: flex;
  align-items: flex-start;
  gap: 0.65rem;
  min-width: 0;
}

.wholesale-order-detail-page .order-detail-page__items-summary-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 2rem;
  height: 2rem;
  border-radius: 0.5rem;
  flex-shrink: 0;
}

.wholesale-order-detail-page .order-detail-page__items-summary-icon--items {
  background: rgba(var(--bs-primary-rgb), 0.1);
  color: var(--bs-primary);
}

.wholesale-order-detail-page .order-detail-page__items-summary-icon--qty {
  background: rgba(var(--bs-info-rgb), 0.12);
  color: var(--bs-info);
}

.wholesale-order-detail-page .order-detail-page__items-summary-icon--ship {
  background: rgba(var(--bs-success-rgb), 0.12);
  color: var(--bs-success);
}

.wholesale-order-detail-page .order-detail-page__items-summary-icon--cost {
  background: rgba(var(--bs-warning-rgb), 0.15);
  color: var(--bs-warning-text-emphasis, #664d03);
}

.wholesale-order-detail-page .order-detail-page__items-summary-label {
  font-size: 0.75rem;
  color: var(--bs-secondary-color);
}

.wholesale-order-detail-page .order-detail-page__items-summary-value {
  font-size: 1.125rem;
  font-weight: 600;
  line-height: 1.2;
}

@media (max-width: 991.98px) {
  .wholesale-order-detail-page .order-detail-page__items-summary {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (max-width: 575.98px) {
  .wholesale-order-detail-page .order-detail-page__items-summary {
    grid-template-columns: 1fr;
  }
}

.wholesale-requirements-card__subtitle {
  line-height: 1.5;
}

.wholesale-requirements-card__section + .wholesale-requirements-card__section {
  margin-top: 1.5rem;
  padding-top: 1.5rem;
  border-top: 1px solid rgba(0, 0, 0, 0.06);
}

.wholesale-requirements-card__section:first-of-type {
  margin-top: 0;
}

.wholesale-requirements-card__comment {
  resize: vertical;
  min-height: 5.5rem;
}
</style>
