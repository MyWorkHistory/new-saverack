<script setup>
import { computed, inject, nextTick, onMounted, onUnmounted, ref } from "vue";
import { RouterLink, useRoute, useRouter } from "vue-router";
import api from "../../services/api";
import ConfirmModal from "../../components/common/ConfirmModal.vue";
import CrmIconRowActions from "../../components/common/CrmIconRowActions.vue";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import CrmNoteAuthorAvatar from "../../components/common/CrmNoteAuthorAvatar.vue";
import CrmStatusUpdateModal from "../../components/common/CrmStatusUpdateModal.vue";
import AsnProductCatalogPanel from "../../components/inventory/AsnProductCatalogPanel.vue";
import WholesaleBarcodeUploadModal from "../../components/orders/WholesaleBarcodeUploadModal.vue";
import WholesaleLineBoxBreakdown from "../../components/orders/WholesaleLineBoxBreakdown.vue";
import WholesalePackageInfoModal from "../../components/orders/WholesalePackageInfoModal.vue";
import WholesaleRequirementRow from "../../components/orders/WholesaleRequirementRow.vue";
import WholesaleRequirementsEditDrawer from "../../components/orders/WholesaleRequirementsEditDrawer.vue";
import WholesaleOrderFeesModal from "../../components/orders/WholesaleOrderFeesModal.vue";
import WholesaleOrderCustomFeesModal from "../../components/orders/WholesaleOrderCustomFeesModal.vue";
import WholesaleOrderAddBoxesModal from "../../components/orders/WholesaleOrderAddBoxesModal.vue";
import WholesaleShippingLabelsCard from "../../components/orders/WholesaleShippingLabelsCard.vue";
import CrmMaterialIcon from "../../components/common/CrmMaterialIcon.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast.js";
import { formatDateUs, formatDateTimeUs } from "../../utils/formatUserDates.js";
import { noteAuthorFromRecord } from "../../utils/noteAuthor.js";
import { crmIsAdmin } from "../../utils/crmUser.js";
import { errorMessage } from "../../utils/apiError.js";
import {
  wholesaleLineStatusBadgeClass,
  wholesaleLineStatusLabel,
  wholesaleStatusBadgeClass,
  wholesaleStatusLabel,
  wholesaleTypeLabel,
  WHOLESALE_MANUAL_STATUS_OPTIONS,
  WHOLESALE_REQUIREMENT_SECTIONS,
  wholesaleOptionLabel,
} from "../../utils/formatWholesaleOrderDisplay.js";

const route = useRoute();
const router = useRouter();
const toast = useToast();
const crmUser = inject("crmUser", ref(null));

/** Portal view-only mode: determined by route, not user record (staff always edit on /admin). */
const isPortalView = computed(() => Boolean(route.meta?.userPortal));

const LINE_MENU_W = 220;
const LINE_MENU_H = 132;

const loading = ref(true);
const lineBusy = ref(false);
const addPanelOpen = ref(false);
const order = ref(null);

const statusSaving = ref(false);
const statusModalOpen = ref(false);
const statusDraft = ref("pending");

const readyToShipBusy = ref(false);

const requirementEditOpen = ref(false);
const requirementEditBusy = ref(false);

const feesModalOpen = ref(false);
const feesModalBusy = ref(false);
const feesModalError = ref("");
const feesEditLine = ref(null);
const customFeesModalOpen = ref(false);
const createBillBusy = ref(false);

const palletInfoOpen = ref(false);
const addBoxesModalOpen = ref(false);
const addBoxesModalBusy = ref(false);

const editOrderNumberOpen = ref(false);
const editOrderNumberBusy = ref(false);
const editOrderNumberValue = ref("");

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
const commentEditId = ref(null);
const commentEditBody = ref("");
const commentEditBusy = ref(false);
const commentDeleteOpen = ref(false);
const commentDeleteTarget = ref(null);
const commentDeleteBusy = ref(false);
const lineSlackBusyId = ref(null);
const imagePreviewUrls = ref({});

const orderId = computed(() => String(route.params.id || ""));
const clientAccountId = computed(() => Number(order.value?.client_account_id || 0));
const isEditable = computed(() => Boolean(order.value?.is_editable));
const canEditLines = computed(() => Boolean(order.value?.is_lines_editable));
/** Box/pallet dims are staff warehouse work — not limited to draft/pending like client-facing edit. */
const canEditPackages = computed(() => {
  if (isPortalView.value) return false;
  if (order.value?.is_packages_editable != null) {
    return Boolean(order.value.is_packages_editable);
  }
  const status = String(order.value?.status || "").toLowerCase();
  return status !== "shipped";
});
/**
 * Line SKU add / edit / delete: staff only (portal is view-only).
 * Still limited by order status (draft / pending / in_progress).
 */
const canManageLineItems = computed(() => !isPortalView.value && canEditLines.value);
/**
 * Per-SKU box breakdown: staff until shipped (same window as order Box Info).
 */
const canEditLineBoxes = computed(() => !isPortalView.value && canEditPackages.value);
/** Shipping labels: staff can manage until shipped; portal only while draft/pending. */
const canEditShippingLabels = computed(() => {
  if (isPortalView.value) return isEditable.value;
  if (order.value?.is_shipping_labels_editable != null) {
    return Boolean(order.value.is_shipping_labels_editable);
  }
  const status = String(order.value?.status || "").toLowerCase();
  return status !== "shipped";
});
const canEditOrderNumber = computed(() => {
  if (isPortalView.value) return isEditable.value;
  const status = String(order.value?.status || "").toLowerCase();
  return status !== "shipped";
});
const lines = computed(() => (Array.isArray(order.value?.lines) ? order.value.lines : []));
const comments = computed(() => (Array.isArray(order.value?.comments) ? order.value.comments : []));
const commentsExpanded = ref(false);
const NOTES_PREVIEW_LIMIT = 3;

const visibleComments = computed(() => {
  const list = comments.value;
  if (commentsExpanded.value || list.length <= NOTES_PREVIEW_LIMIT) {
    return list;
  }
  return list.slice(0, NOTES_PREVIEW_LIMIT);
});

const showSeeAllNotes = computed(
  () => !commentsExpanded.value && comments.value.length > NOTES_PREVIEW_LIMIT,
);

function commentAuthor(comment) {
  return noteAuthorFromRecord(comment);
}

const showReadyToShipButton = computed(() => {
  const s = String(order.value?.status || "").toLowerCase();
  return (s === "draft" || s === "pending") && !order.value?.shiphero_order_id;
});

const showPickListLink = computed(() => {
  if (isPortalView.value) return false;
  return String(order.value?.status || "").toLowerCase() === "in_progress";
});

const pickListRoute = computed(() => {
  const query = {};
  const accountId = Number(order.value?.client_account_id || 0);
  if (accountId > 0) {
    query.client_account_id = String(accountId);
  }
  return { name: "wholesale-pick-list", query };
});

const canClickStatusBadge = computed(() => !isPortalView.value);

const canManageFees = computed(() => !isPortalView.value);

const feeLines = computed(() =>
  Array.isArray(order.value?.fee_lines) ? order.value.fee_lines : [],
);

const feeChargeOptions = computed(() =>
  Array.isArray(order.value?.fee_charge_options) ? order.value.fee_charge_options : [],
);

const barcodeLabelingDefaultQty = computed(() =>
  lines.value.reduce((sum, line) => {
    if (!line?.has_barcode) return sum;
    return sum + Number(line.quantity || 0);
  }, 0),
);

/** Most fees default to 1; per_item = total units; barcode_labeling = qty of lines with uploaded labels. */
const feeDefaultQuantities = computed(() => {
  const totalQty = itemsSummary.value.totalQuantity;
  const labelingQty = barcodeLabelingDefaultQty.value;
  const map = {
    wholesale_fulfillment: 1,
    master_carton: 1,
    pallet_prep: 1,
    ltl_pickup: 1,
    box: 1,
    per_item: totalQty > 0 ? totalQty : 1,
    barcode_labeling: labelingQty > 0 ? labelingQty : "",
  };
  for (const opt of feeChargeOptions.value) {
    const lt = String(opt?.line_type || "");
    if (!lt || Object.prototype.hasOwnProperty.call(map, lt)) continue;
    map[lt] = 1;
  }
  return map;
});

function formatFeeCents(cents) {
  const n = Number(cents) || 0;
  return `$${(n / 100).toFixed(2)}`;
}

function openAddFeesModal() {
  if (!canManageFees.value) return;
  feesEditLine.value = null;
  feesModalError.value = "";
  feesModalOpen.value = true;
}

function openFeeEdit(line) {
  if (!canManageFees.value) return;
  feesEditLine.value = line;
  feesModalError.value = "";
  feesModalOpen.value = true;
}

async function submitFeesModal(payloads) {
  if (!order.value?.id || !Array.isArray(payloads) || !payloads.length) {
    feesModalError.value = "Enter a quantity for at least one fee.";
    return;
  }
  feesModalError.value = "";
  feesModalBusy.value = true;
  try {
    let latest = order.value;
    for (const item of payloads) {
      if (item.action === "delete" && item.item_id) {
        const { data } = await api.delete(
          `/admin/wholesale-orders/${order.value.id}/fee-lines/${item.item_id}`,
        );
        latest = data;
        continue;
      }
      const body = {
        line_type: item.line_type,
        name: item.name,
        quantity: item.quantity,
        unit_price: item.unit_price,
        source: item.source,
        client_account_fee_id: item.client_account_fee_id,
      };
      if (item.action === "update" && item.item_id) {
        const { data } = await api.put(
          `/admin/wholesale-orders/${order.value.id}/fee-lines/${item.item_id}`,
          body,
        );
        latest = data;
      } else if (item.action === "create") {
        const { data } = await api.post(`/admin/wholesale-orders/${order.value.id}/fee-lines`, body);
        latest = data;
      }
    }
    applyOrderData(latest);
    feesModalOpen.value = false;
    feesEditLine.value = null;
    toast.success("Wholesale fees saved.");
  } catch (e) {
    feesModalError.value = errorMessage(e);
    toast.error(feesModalError.value || "Could not save wholesale fees.");
  } finally {
    feesModalBusy.value = false;
  }
}

async function submitAddBoxesFees(rows) {
  if (!order.value?.id || !Array.isArray(rows) || !rows.length) return;
  addBoxesModalBusy.value = true;
  try {
    let latest = order.value;
    for (const row of rows) {
      const { data } = await api.post(`/admin/wholesale-orders/${order.value.id}/fee-lines`, {
        line_type: row.line_type,
        source: "packaging",
        client_account_fee_id: row.client_account_fee_id,
        name: row.name,
        quantity: row.quantity,
        unit_price: row.unit_price,
      });
      latest = data;
    }
    applyOrderData(latest);
    addBoxesModalOpen.value = false;
    toast.success("Box fees added.");
  } catch (e) {
    toast.errorFrom(e, "Could not add box fees.");
  } finally {
    addBoxesModalBusy.value = false;
  }
}

async function submitCustomFee(payload) {
  if (!order.value?.id) return;
  feesModalBusy.value = true;
  try {
    const { data } = await api.post(`/admin/wholesale-orders/${order.value.id}/fee-lines`, payload);
    applyOrderData(data);
    customFeesModalOpen.value = false;
    toast.success("Custom fee added.");
  } catch (e) {
    toast.errorFrom(e, "Could not add custom fee.");
  } finally {
    feesModalBusy.value = false;
  }
}

async function createBill() {
  if (!order.value?.id || createBillBusy.value) return;
  createBillBusy.value = true;
  try {
    const { data } = await api.post(`/admin/wholesale-orders/${order.value.id}/create-bill`);
    toast.success("Wholesale bill created.");
    await router.push(`/admin/billing/wholesale-bills/${data.id}`);
  } catch (e) {
    toast.errorFrom(e, "Could not create wholesale bill.");
  } finally {
    createBillBusy.value = false;
  }
}

async function deleteFeeFromModal(line) {
  if (!order.value?.id || !line?.id) return;
  feesModalBusy.value = true;
  try {
    const { data } = await api.delete(
      `/admin/wholesale-orders/${order.value.id}/fee-lines/${line.id}`,
    );
    applyOrderData(data);
    feesModalOpen.value = false;
    feesEditLine.value = null;
    toast.success("Fee removed.");
  } catch (e) {
    feesModalError.value = errorMessage(e);
    toast.error(feesModalError.value || "Could not remove fee.");
  } finally {
    feesModalBusy.value = false;
  }
}

const listRouteName = computed(() =>
  isPortalView.value ? "user-wholesale-orders" : "wholesale-orders",
);

function collectSubmitOrderErrors() {
  const o = order.value;
  if (!o) return ["Order not loaded."];
  const errors = [];
  if (!lines.value.length) {
    errors.push("Please add items to this order");
  }
  if (!o.has_requirements_filled) {
    errors.push("Please complete product & fulfillment requirements");
  }
  if (!String(o.shipping_labels_provider || "").trim()) {
    errors.push("Please select how the shipping & handling will be processed");
  }
  if (String(o.sku_barcode_labels || "") === "apply_new") {
    const missingUpload = lines.value.some((line) => !line.has_barcode);
    if (lines.value.length && missingUpload) {
      errors.push("Please upload barcode labels for each item.");
    }
  }
  return errors;
}

function requirementValueLabel(section) {
  if (!order.value || !section) return null;
  return wholesaleOptionLabel(section.options, order.value[section.valueKey]);
}

function requirementDetailText(section) {
  if (!order.value || !section) return "";
  if (
    section.valueKey === "shipping_method_requirement" &&
    String(order.value.shipping_method_requirement || "") === "custom"
  ) {
    const qty = String(order.value.shipping_packaging_qty_per_box || "").trim();
    const box = String(order.value.shipping_packaging_box_size || "").trim();
    const extras = [];
    if (qty) extras.push(`QTY Per Box: ${qty}`);
    if (box) extras.push(`Box Size: ${box}`);
    return extras.join("; ");
  }
  return "";
}

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
  return parts.length ? parts.join("\n") : "";
});

const itemsSummary = computed(() => {
  const rows = lines.value;
  const totalQuantity = rows.reduce((sum, line) => sum + Number(line.quantity || 0), 0);
  let totalBoxes = 0;
  let totalWeight = 0;
  let hasWeight = false;
  for (const line of rows) {
    const boxes = Array.isArray(line.boxes) ? line.boxes : [];
    totalBoxes += boxes.length;
    for (const box of boxes) {
      if (box?.weight != null && box.weight !== "") {
        hasWeight = true;
        totalWeight += Number(box.weight) || 0;
      }
    }
  }
  if (order.value?.line_boxes_count != null && !rows.some((l) => Array.isArray(l.boxes))) {
    totalBoxes = Number(order.value.line_boxes_count) || 0;
  }
  const orderBoxWeight = order.value?.line_boxes_total_weight;
  return {
    totalItems: rows.length,
    totalQuantity,
    totalBoxes,
    totalWeight: hasWeight
      ? totalWeight
      : orderBoxWeight != null
        ? Number(orderBoxWeight)
        : null,
  };
});

function shipHeroDashboardOrderId(raw) {
  const id = String(raw || "").trim();
  if (!id) return null;
  if (/^\d+$/.test(id)) return id;
  try {
    const decoded = atob(id);
    const match = decoded.match(/^Order:(\d+)$/i);
    if (match?.[1]) return match[1];
  } catch {
    /* not base64 GraphQL */
  }
  return null;
}

const shipheroAdminUrl = computed(() => {
  const legacyId = shipHeroDashboardOrderId(order.value?.shiphero_order_id);
  if (!legacyId) return null;
  return `https://app.shiphero.com/dashboard/orders/details/${legacyId}`;
});

const showMarkAsShippedButton = computed(() => {
  if (isPortalView.value) return false;
  if (!shipheroAdminUrl.value) return false;
  return String(order.value?.status || "").toLowerCase() !== "shipped";
});

function openMarkAsShipped() {
  const url = shipheroAdminUrl.value;
  if (!url) {
    toast.error("This order has no ShipHero link yet.");
    return;
  }
  window.open(url, "_blank", "noopener,noreferrer");
}

const lineMenuOpenLine = computed(() => {
  const id = lineMenuOpenId.value;
  if (!id) return null;
  return lines.value.find((l) => l.id === id) ?? null;
});

function openRequirementsEdit() {
  if (!isEditable.value) return;
  requirementEditOpen.value = true;
}

async function saveRequirementsFromDrawer(payload) {
  if (!order.value?.id || requirementEditBusy.value) return;
  requirementEditBusy.value = true;
  try {
    const { data } = await api.patch(`/admin/wholesale-orders/${order.value.id}`, payload);
    applyOrderData(data);
    requirementEditOpen.value = false;
    toast.success("Requirements saved.");
  } catch (e) {
    toast.errorFrom(e, "Could not save requirements.");
  } finally {
    requirementEditBusy.value = false;
  }
}

function onPackageInfoSaved(data) {
  applyOrderData(data);
}

function applyOrderData(data) {
  order.value = data;
}

function orderStatusLabel() {
  return order.value?.status_label || wholesaleStatusLabel(order.value?.status);
}

function lineStatusLabel(line) {
  return line?.status_label || wholesaleLineStatusLabel(line?.status);
}

function showLineStatusBadge(line) {
  const status = String(line?.status || "").toLowerCase();
  if (status === "barcode_ready") return true;
  if (status === "ship_as_is") {
    return String(order.value?.sku_barcode_labels || "") !== "apply_new";
  }
  return false;
}

function openStatusModal() {
  if (!canClickStatusBadge.value) return;
  const status = String(order.value?.status || "").toLowerCase();
  const allowed = WHOLESALE_MANUAL_STATUS_OPTIONS.map((opt) => opt.value);
  statusDraft.value = allowed.includes(status) ? status : "pending";
  statusModalOpen.value = true;
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
    router.push({ name: listRouteName.value });
  } finally {
    loading.value = false;
  }
}

function onShippingLabelsSaved(data) {
  applyOrderData(data);
  toast.success("Shipping labels saved.");
}

function openEditOrderNumberModal() {
  if (!order.value || !canEditOrderNumber.value) return;
  editOrderNumberValue.value = String(order.value.order_number || "");
  editOrderNumberOpen.value = true;
}

function closeEditOrderNumberModal(force = false) {
  if (editOrderNumberBusy.value && !force) return;
  editOrderNumberOpen.value = false;
}

async function confirmEditOrderNumber() {
  if (!order.value?.id || editOrderNumberBusy.value) return;
  const orderNumber = String(editOrderNumberValue.value || "").trim();
  if (!orderNumber) {
    toast.error("Order number is required.");
    return;
  }
  editOrderNumberBusy.value = true;
  try {
    const { data } = await api.patch(`/admin/wholesale-orders/${order.value.id}/number`, {
      order_number: orderNumber,
    });
    applyOrderData(data);
    setCrmPageMeta({
      title: data?.order_number ? `Save Rack | Order #${data.order_number}` : "Save Rack | Wholesale Order",
      description: "Wholesale order detail.",
    });
    toast.success("Order Number Updated.");
    closeEditOrderNumberModal(true);
  } catch (e) {
    toast.errorFrom(e, "Could not update order number.");
  } finally {
    editOrderNumberBusy.value = false;
  }
}

async function submitReadyToShip() {
  if (!order.value?.id || readyToShipBusy.value) return;
  const errors = collectSubmitOrderErrors();
  if (errors.length) {
    toast.error(errors.join("\n"));
    return;
  }
  readyToShipBusy.value = true;
  try {
    const { data } = await api.post(`/admin/wholesale-orders/${order.value.id}/ready-to-ship`);
    applyOrderData(data);
    toast.success("Order sent to ShipHero.");
  } catch (e) {
    toast.errorFrom(e, "Could not submit order.");
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
  if (!order.value?.id || !canManageLineItems.value) return;
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
  if (!order.value?.id || !canManageLineItems.value || !line?.id) return;
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
  if (!order.value?.id || !canManageLineItems.value || !line?.id) return;
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
  if (!order.value?.id || !canManageLineItems.value || !line?.id) return;
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
  if (!canManageLineItems.value || !line?.id) return;
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

function canModifyComment(comment) {
  const user = crmUser.value;
  if (!user || !comment) return false;
  if (isPortalView.value) return false;
  if (Number(comment.user?.id || comment.user_id) === Number(user.id)) return true;
  return crmIsAdmin(user) || !!user.is_crm_owner;
}

function startCommentEdit(comment) {
  if (!canModifyComment(comment)) return;
  commentEditId.value = comment.id;
  commentEditBody.value = comment.body || "";
}

function cancelCommentEdit() {
  commentEditId.value = null;
  commentEditBody.value = "";
}

async function saveCommentEdit(comment) {
  if (!order.value?.id || !comment?.id || commentEditBusy.value) return;
  const body = commentEditBody.value?.trim() || "";
  if (!body) return;
  commentEditBusy.value = true;
  try {
    const { data } = await api.patch(
      `/admin/wholesale-orders/${order.value.id}/comments/${comment.id}`,
      { body },
    );
    const list = comments.value.map((c) => (c.id === comment.id ? data : c));
    order.value = { ...order.value, comments: list };
    cancelCommentEdit();
    toast.success("Comment updated.");
  } catch (e) {
    toast.errorFrom(e, "Could not update comment.");
  } finally {
    commentEditBusy.value = false;
  }
}

function requestCommentDelete(comment) {
  if (!canModifyComment(comment)) return;
  commentDeleteTarget.value = comment;
  commentDeleteOpen.value = true;
}

async function confirmCommentDelete() {
  if (!order.value?.id || !commentDeleteTarget.value?.id || commentDeleteBusy.value) return;
  commentDeleteBusy.value = true;
  try {
    await api.delete(
      `/admin/wholesale-orders/${order.value.id}/comments/${commentDeleteTarget.value.id}`,
    );
    const list = comments.value.filter((c) => c.id !== commentDeleteTarget.value.id);
    order.value = { ...order.value, comments: list };
    commentDeleteOpen.value = false;
    commentDeleteTarget.value = null;
    toast.success("Comment deleted.");
  } catch (e) {
    toast.errorFrom(e, "Could not delete comment.");
  } finally {
    commentDeleteBusy.value = false;
  }
}

async function sendLineBoxesSlack(line) {
  if (!order.value?.id || !line?.id || lineSlackBusyId.value) return;
  lineSlackBusyId.value = line.id;
  try {
    await api.post(
      `/admin/wholesale-orders/${order.value.id}/lines/${line.id}/boxes/send-slack`,
    );
    toast.success("Box info sent to Slack.");
  } catch (e) {
    toast.errorFrom(e, "Could not send box info to Slack.");
  } finally {
    lineSlackBusyId.value = null;
  }
}

function onLineMenuSlack() {
  const line = lineMenuOpenLine.value;
  closeLineMenu();
  if (line) sendLineBoxesSlack(line);
}

const orderBoxTotalWeight = computed(() => {
  if (order.value?.line_boxes_total_weight != null) {
    return Number(order.value.line_boxes_total_weight);
  }
  return itemsSummary.value.totalWeight;
});

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
              @click="router.push({ name: listRouteName })"
            >
              &lt; Wholesale Orders
            </button>
            <div class="d-flex flex-wrap align-items-center gap-2 mb-1 wholesale-order-detail-page__title-row">
              <button
                v-if="canEditOrderNumber"
                type="button"
                class="wholesale-order-detail-page__order-num-btn"
                title="Edit Order Number"
                aria-label="Edit Order Number"
                @click="openEditOrderNumberModal"
              >
                <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                </svg>
              </button>
              <h1 class="h4 mb-0 fw-semibold text-body">Order #{{ order.order_number }}</h1>
              <button
                v-if="canClickStatusBadge"
                type="button"
                class="badge rounded-pill fw-medium border-0 asn-line-status-badge wholesale-order-detail-page__status-btn"
                :class="wholesaleStatusBadgeClass(order.status)"
                title="Update Status"
                aria-label="Update Status"
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
              <a
                v-if="shipheroAdminUrl && !isPortalView"
                :href="shipheroAdminUrl"
                target="_blank"
                rel="noopener noreferrer"
                class="small text-primary text-decoration-none order-detail-page__shopify-header-link d-inline-flex align-items-center gap-1"
              >
                View in ShipHero
                <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                </svg>
              </a>
            </div>
            <p class="small text-secondary mb-0">
              Order placed on {{ formatDateUs(order.created_at) || "—" }} • via Save Rack CRM
            </p>
          </div>
          <div
            class="d-flex flex-wrap align-items-center gap-2 flex-shrink-0 align-self-start wholesale-order-detail-page__header-actions"
          >
            <RouterLink
              v-if="!isPortalView && order.wholesale_bill_id"
              :to="`/admin/billing/wholesale-bills/${order.wholesale_bill_id}`"
              class="btn btn-outline-primary"
            >
              View Bill
            </RouterLink>
            <button
              v-else-if="!isPortalView && feeLines.length"
              type="button"
              class="btn btn-primary staff-page-primary"
              :disabled="createBillBusy"
              @click="createBill"
            >
              {{ createBillBusy ? "Creating…" : "Create Bill" }}
            </button>
            <button
              type="button"
              class="staff-detail-tab-btn"
              @click="palletInfoOpen = true"
            >
              <span class="staff-detail-tab-btn__icon" aria-hidden="true">
                <CrmMaterialIcon name="package" :size="18" />
              </span>
              <span class="staff-detail-tab-btn__label">Pallet Info</span>
            </button>
            <RouterLink
              v-if="showPickListLink"
              :to="pickListRoute"
              class="staff-detail-tab-btn text-decoration-none"
            >
              <span class="staff-detail-tab-btn__icon" aria-hidden="true">
                <CrmMaterialIcon name="taskAlt" :size="18" />
              </span>
              <span class="staff-detail-tab-btn__label">Pick List</span>
            </RouterLink>
            <button
              v-if="showMarkAsShippedButton"
              type="button"
              class="btn wholesale-ready-to-ship-btn"
              @click="openMarkAsShipped"
            >
              Mark as Shipped
            </button>
            <button
              v-if="showReadyToShipButton"
              type="button"
              class="btn wholesale-ready-to-ship-btn"
              :disabled="readyToShipBusy"
              @click="submitReadyToShip"
            >
              {{ readyToShipBusy ? "Sending…" : "Submit Order" }}
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
              v-if="canManageLineItems"
              type="button"
              class="btn btn-sm btn-primary staff-page-primary"
              :disabled="lineBusy"
              @click="addPanelOpen = !addPanelOpen"
            >
              {{ addPanelOpen ? "Hide Add Products" : "Add Products" }}
            </button>
          </div>

          <div v-if="canManageLineItems && addPanelOpen" class="border-bottom">
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

          <div class="staff-table-wrap wholesale-items-table-wrap">
            <table class="table table-hover align-middle mb-0 staff-data-table wholesale-items-table">
              <thead class="table-light staff-table-head">
                <tr>
                  <th class="staff-table-head__th order-detail-page__items-col" scope="col">Item</th>
                  <th class="staff-table-head__th text-center" scope="col">Qty</th>
                  <th class="staff-table-head__th text-center wholesale-line-barcodes-col" scope="col">Barcodes</th>
                  <th v-if="!isPortalView" class="staff-table-head__th text-center order-detail-page__items-actions-col" scope="col">
                    Actions
                  </th>
                </tr>
              </thead>
              <tbody>
                <template v-for="line in lines" :key="line.id">
                <tr>
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
                      </div>
                      <div class="order-detail-page__item-copy">
                        <div class="order-detail-page__item-name" :title="line.name">{{ line.name || "—" }}</div>
                        <div v-if="line.sku" class="order-detail-page__item-sku" :title="line.sku">
                          SKU: {{ line.sku }}
                        </div>
                        <span
                          v-if="showLineStatusBadge(line)"
                          class="badge rounded-pill fw-medium asn-line-status-badge"
                          :class="wholesaleLineStatusBadgeClass(line.status)"
                        >
                          {{ lineStatusLabel(line) }}
                        </span>
                      </div>
                    </div>
                  </td>
                  <td class="text-center">
                    <input
                      v-if="canManageLineItems"
                      type="number"
                      min="1"
                      class="form-control form-control-sm text-center mx-auto wholesale-line-qty-input"
                      :value="line.quantity"
                      :disabled="lineBusy"
                      @change="saveLineQty(line, $event.target.value)"
                    />
                    <span v-else>{{ line.quantity }}</span>
                  </td>
                  <td class="text-center wholesale-line-barcodes-col">
                    <button
                      v-if="line.has_barcode"
                      type="button"
                      class="btn btn-link btn-sm p-0 text-decoration-none"
                      @click="printBarcode(line)"
                    >
                      Print Labels
                    </button>
                    <button
                      v-else-if="canManageLineItems"
                      type="button"
                      class="btn btn-link btn-sm p-0 text-decoration-none"
                      :disabled="lineBusy"
                      @click="openBarcodeModal(line)"
                    >
                      Upload Labels
                    </button>
                    <span v-else class="text-secondary">—</span>
                  </td>
                  <td v-if="!isPortalView" class="text-center align-middle order-detail-page__items-actions-col">
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
                <tr
                  v-if="!isPortalView || (Array.isArray(line.boxes) && line.boxes.length)"
                  class="wholesale-line-boxes-row"
                >
                  <td :colspan="!isPortalView ? 4 : 3" class="wholesale-line-boxes-cell">
                    <WholesaleLineBoxBreakdown
                      :order-id="orderId"
                      :line-id="line.id"
                      :line-quantity="line.quantity"
                      :boxes="line.boxes || []"
                      :read-only="isPortalView || !canEditLineBoxes"
                      @saved="applyOrderData"
                    />
                  </td>
                </tr>
                </template>
                <tr v-if="!lines.length">
                  <td :colspan="!isPortalView ? 4 : 3" class="text-center text-secondary py-4">No items yet.</td>
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
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                </svg>
              </span>
              <div>
                <div class="order-detail-page__items-summary-label">Total Boxes</div>
                <div class="order-detail-page__items-summary-value">{{ itemsSummary.totalBoxes }}</div>
              </div>
            </div>
            <div class="order-detail-page__items-summary-tile">
              <span class="order-detail-page__items-summary-icon order-detail-page__items-summary-icon--cost" aria-hidden="true">
                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 6l3 1m0 0l-3 9a5.002 5.002 0 006.001 0M6 7l3 9M6 7l6-2m6 2l3-1m-3 1l-3 9a5.002 5.002 0 006.001 0M18 7l3 9m-3-9l-6-2m0-2v2m0 16V5m0 16H9m3 0h3" />
                </svg>
              </span>
              <div>
                <div class="order-detail-page__items-summary-label">Total Weight</div>
                <div class="order-detail-page__items-summary-value">
                  <template v-if="itemsSummary.totalWeight != null">
                    {{ Number(itemsSummary.totalWeight).toLocaleString(undefined, { maximumFractionDigits: 2 }) }} lb
                  </template>
                  <template v-else>—</template>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div
          class="staff-table-card staff-datatable-card staff-datatable-card--white p-4"
        >
          <div class="d-flex align-items-center justify-content-between gap-2 mb-3">
            <h2 class="h6 fw-semibold mb-0">Comments</h2>
            <button
              v-if="showSeeAllNotes"
              type="button"
              class="btn btn-link btn-sm p-0 text-decoration-none"
              @click="commentsExpanded = true"
            >
              See All Notes
            </button>
          </div>
          <ul v-if="comments.length" class="list-unstyled mb-0 pb-4 border-bottom">
            <li v-for="c in visibleComments" :key="c.id" class="d-flex gap-3 mb-4">
              <CrmNoteAuthorAvatar
                :name="commentAuthor(c).name"
                :email="commentAuthor(c).email"
                :avatar-url="commentAuthor(c).avatarUrl"
              />
              <div class="min-w-0 flex-grow-1">
                <div class="d-flex flex-wrap align-items-baseline gap-2">
                  <span class="small fw-medium">{{ c.user?.name || "User" }}</span>
                  <span class="small text-secondary">{{ formatDateTimeUs(c.created_at) }}</span>
                  <div v-if="canModifyComment(c)" data-row-actions class="ms-auto">
                    <button
                      type="button"
                      class="staff-action-btn staff-action-btn--more"
                      aria-label="Comment actions"
                      @click.stop="startCommentEdit(c)"
                    >
                      <CrmIconRowActions variant="horizontal" />
                    </button>
                  </div>
                </div>
                <div v-if="commentEditId === c.id" class="mt-2">
                  <textarea
                    v-model="commentEditBody"
                    rows="3"
                    class="form-control form-control-sm"
                    :disabled="commentEditBusy"
                  />
                  <div class="d-flex gap-2 mt-2">
                    <button
                      type="button"
                      class="btn btn-sm btn-primary staff-page-primary"
                      :disabled="commentEditBusy"
                      @click="saveCommentEdit(c)"
                    >
                      Save
                    </button>
                    <button
                      type="button"
                      class="btn btn-sm btn-outline-secondary"
                      :disabled="commentEditBusy"
                      @click="cancelCommentEdit"
                    >
                      Cancel
                    </button>
                    <button
                      type="button"
                      class="btn btn-sm btn-outline-danger ms-auto"
                      :disabled="commentEditBusy"
                      @click="requestCommentDelete(c)"
                    >
                      Delete
                    </button>
                  </div>
                </div>
                <p v-else class="mt-1 mb-0 small" style="white-space: pre-wrap">{{ c.body }}</p>
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
          <div class="d-flex flex-wrap align-items-start justify-content-between gap-2 mb-1">
            <h3 class="h6 fw-semibold mb-0">Product &amp; Fulfillment Requirements</h3>
            <button
              v-if="isEditable"
              type="button"
              class="btn btn-sm btn-outline-primary d-inline-flex align-items-center gap-1"
              @click="openRequirementsEdit"
            >
              <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
              </svg>
              Edit
            </button>
          </div>
          <p class="small text-secondary mb-3 wholesale-requirements-card__subtitle">
            Review packing requirements. Use Edit to update all options at once.
          </p>

          <WholesaleRequirementRow
            v-for="section in WHOLESALE_REQUIREMENT_SECTIONS"
            :key="section.id"
            :icon="section.icon"
            :icon-style="section.iconStyle"
            :label="section.label"
            :value-label="requirementValueLabel(section)"
            :comment="requirementDetailText(section)"
            :editable="false"
            :show-edit="false"
          />
        </div>

        <WholesaleShippingLabelsCard
          v-if="order"
          :order="order"
          :editable="canEditShippingLabels"
          :formatted-address="formattedShippingAddress"
          @saved="onShippingLabelsSaved"
        />

        <div
          v-if="canManageFees"
          class="staff-table-card staff-datatable-card staff-datatable-card--white p-4 order-detail-page__side-panel"
        >
          <div class="d-flex align-items-center gap-2 mb-3 order-detail-page__section-head">
            <span class="order-detail-page__section-icon order-detail-page__section-icon--fees" aria-hidden="true">
              <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
            </span>
            <h3 class="h6 fw-semibold mb-0">Pricing</h3>
          </div>
          <div v-if="feeLines.length" class="table-responsive mb-3">
            <table class="table table-sm align-middle mb-0">
              <thead>
                <tr>
                  <th>Service</th>
                  <th class="text-end">QTY</th>
                  <th class="text-end">Price</th>
                  <th class="text-end">Total</th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="fee in feeLines"
                  :key="fee.id"
                  class="wholesale-fee-row--editable"
                  @click="openFeeEdit(fee)"
                >
                  <td>{{ fee.name }}</td>
                  <td class="text-end">{{ fee.quantity }}</td>
                  <td class="text-end">{{ formatFeeCents(fee.unit_price_cents) }}</td>
                  <td class="text-end fw-semibold">{{ formatFeeCents(fee.line_total_cents) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <template v-else>
            <p class="mb-3 small text-secondary">No fees have been added for this order.</p>
            <button
              type="button"
              class="btn btn-sm btn-primary staff-page-primary"
              @click="openAddFeesModal"
            >
              Add Fees
            </button>
            <button
              type="button"
              class="btn btn-sm btn-outline-secondary ms-2"
              @click="customFeesModalOpen = true"
            >
              Add Custom Fee
            </button>
            <button
              type="button"
              class="btn btn-sm btn-outline-secondary ms-2"
              @click="addBoxesModalOpen = true"
            >
              Add Boxes
            </button>
          </template>
          <div v-if="feeLines.length" class="mt-2 d-flex flex-wrap gap-2">
            <button
              type="button"
              class="btn btn-sm btn-outline-secondary"
              @click="openAddFeesModal"
            >
              Add Fees
            </button>
            <button
              type="button"
              class="btn btn-sm btn-outline-secondary"
              @click="customFeesModalOpen = true"
            >
              Add Custom Fee
            </button>
            <button
              type="button"
              class="btn btn-sm btn-outline-secondary"
              @click="addBoxesModalOpen = true"
            >
              Add Boxes
            </button>
            <RouterLink
              v-if="order.wholesale_bill_id"
              :to="`/admin/billing/wholesale-bills/${order.wholesale_bill_id}`"
              class="btn btn-sm btn-outline-primary"
            >
              View Bill
            </RouterLink>
            <button
              v-else
              type="button"
              class="btn btn-sm btn-primary staff-page-primary"
              :disabled="createBillBusy"
              @click="createBill"
            >
              {{ createBillBusy ? "Creating…" : "Create Bill" }}
            </button>
          </div>
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
            <div class="order-detail-page__detail-row">
              <span class="order-detail-page__detail-label">Total Items</span>
              <span class="order-detail-page__detail-value">{{
                order.total_items != null ? order.total_items : itemsSummary.totalQuantity
              }}</span>
            </div>
            <div class="order-detail-page__detail-row">
              <span class="order-detail-page__detail-label">Total Weight</span>
              <span class="order-detail-page__detail-value">{{
                orderBoxTotalWeight != null
                  ? `${Number(orderBoxTotalWeight).toLocaleString(undefined, { maximumFractionDigits: 2 })} lbs`
                  : "—"
              }}</span>
            </div>
            <div v-if="shipheroAdminUrl && !isPortalView" class="order-detail-page__detail-row">
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

    <CrmStatusUpdateModal
      v-if="canClickStatusBadge"
      v-model:open="statusModalOpen"
      v-model:status="statusDraft"
      title="Update Status"
      subtitle="Choose a status for this wholesale order."
      :statuses="manualStatusOptions"
      :busy="statusSaving"
      @save="saveStatusFromModal"
    />

    <WholesaleOrderFeesModal
      v-model:open="feesModalOpen"
      :busy="feesModalBusy"
      :error-msg="feesModalError"
      :charge-options="feeChargeOptions"
      :existing-lines="feeLines"
      :edit-line="feesEditLine"
      :default-quantities="feeDefaultQuantities"
      @submit="submitFeesModal"
      @delete="deleteFeeFromModal"
    />

    <WholesaleOrderCustomFeesModal
      v-model:open="customFeesModalOpen"
      :busy="feesModalBusy"
      @submit="submitCustomFee"
    />

    <WholesaleRequirementsEditDrawer
      v-model:open="requirementEditOpen"
      :order="order"
      :busy="requirementEditBusy"
      @save="saveRequirementsFromDrawer"
    />

    <WholesalePackageInfoModal
      v-if="order"
      v-model:open="palletInfoOpen"
      :order-id="order.id"
      package-type="pallet"
      :packages="order.pallets || []"
      :saved-at="order.pallets_saved_at"
      :read-only="!canEditPackages"
      :hide-slack="isPortalView"
      @saved="onPackageInfoSaved"
    />

    <WholesaleOrderAddBoxesModal
      v-if="order"
      v-model:open="addBoxesModalOpen"
      :order-id="order.id"
      :busy="addBoxesModalBusy"
      @submit="submitAddBoxesFees"
    />

    <ConfirmModal
      :open="commentDeleteOpen"
      title="Delete Comment"
      message="Delete this comment?"
      confirm-label="Delete"
      :busy="commentDeleteBusy"
      danger
      @close="commentDeleteOpen = false"
      @confirm="confirmCommentDelete"
    />

    <WholesaleBarcodeUploadModal
      :open="barcodeModalOpen"
      :busy="barcodeUploadBusy"
      :line-label="barcodeLine ? `${barcodeLine.sku} — ${barcodeLine.name}` : ''"
      @close="closeBarcodeModal"
      @upload="uploadBarcode"
    />

    <Teleport to="body">
      <Transition name="crm-vx-confirm">
        <div
          v-if="editOrderNumberOpen"
          class="crm-vx-modal-overlay"
          role="dialog"
          aria-modal="true"
          aria-labelledby="wholesale-edit-order-number-title"
          @click.self="closeEditOrderNumberModal"
        >
          <div class="crm-vx-modal crm-vx-modal--sm" @click.stop>
            <header class="crm-vx-modal__head border-bottom">
              <h2 id="wholesale-edit-order-number-title" class="crm-vx-modal__title mb-0">
                Edit Order Number
              </h2>
            </header>
            <div class="crm-vx-modal__body">
              <label class="form-label" for="wholesale-edit-order-number-input">Order Number</label>
              <input
                id="wholesale-edit-order-number-input"
                v-model="editOrderNumberValue"
                type="text"
                class="form-control"
                maxlength="128"
                :disabled="editOrderNumberBusy"
                @keydown.enter.prevent="confirmEditOrderNumber"
              />
            </div>
            <footer class="crm-vx-modal__footer d-flex gap-2 justify-content-end">
              <button
                type="button"
                class="crm-vx-modal-btn crm-vx-modal-btn--secondary"
                :disabled="editOrderNumberBusy"
                @click="closeEditOrderNumberModal"
              >
                Cancel
              </button>
              <button
                type="button"
                class="crm-vx-modal-btn crm-vx-modal-btn--primary"
                :disabled="editOrderNumberBusy"
                @click="confirmEditOrderNumber"
              >
                {{ editOrderNumberBusy ? "Saving…" : "Save" }}
              </button>
            </footer>
          </div>
        </div>
      </Transition>
    </Teleport>

    <Teleport to="body">
      <div
        v-if="lineMenuOpenLine"
        data-row-actions
        class="staff-row-menu fixed z-[300] overflow-hidden"
        role="menu"
        :style="{ top: `${lineMenuRect.top}px`, left: `${lineMenuRect.left}px` }"
        @click.stop
      >
        <button
          v-if="canManageLineItems"
          type="button"
          class="staff-row-menu__item"
          role="menuitem"
          @click="onLineMenuUpload"
        >
          Upload Labels
        </button>
        <button
          v-if="!isPortalView"
          type="button"
          class="staff-row-menu__item"
          role="menuitem"
          :disabled="lineSlackBusyId === lineMenuOpenLine?.id"
          @click="onLineMenuSlack"
        >
          Send Box Info to Slack
        </button>
        <button
          v-if="canManageLineItems"
          type="button"
          class="staff-row-menu__item staff-row-menu__item--danger"
          role="menuitem"
          @click="onLineMenuRemove"
        >
          Remove
        </button>
      </div>
    </Teleport>
  </div>
</template>

<style scoped>
.wholesale-order-detail-page__title-row {
  gap: 0.5rem;
}

.wholesale-order-detail-page__order-num-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 0.2rem;
  border: 0;
  background: transparent;
  color: var(--bs-secondary-color, #6c757d);
  border-radius: 0.35rem;
  line-height: 1;
}

.wholesale-order-detail-page__order-num-btn:hover,
.wholesale-order-detail-page__order-num-btn:focus-visible {
  color: var(--bs-body-color, #212529);
  background: rgba(0, 0, 0, 0.04);
}

.wholesale-line-qty-input {
  max-width: 5rem;
  width: 100%;
}

/* Override global .staff-table-wrap { overflow-x: auto } + table { width: max-content }. */
.wholesale-order-detail-page .wholesale-items-table-wrap.staff-table-wrap {
  overflow-x: hidden;
}

.wholesale-order-detail-page .wholesale-items-table-wrap .table.staff-data-table.wholesale-items-table {
  table-layout: fixed;
  width: 100%;
  max-width: 100%;
  min-width: 0;
}

.wholesale-line-boxes-row > .wholesale-line-boxes-cell {
  background: #fafbfc;
  border-top: 0;
  padding-top: 0.15rem;
  padding-bottom: 0.75rem;
  vertical-align: top;
}

.wholesale-line-boxes-row:hover > .wholesale-line-boxes-cell {
  background: #fafbfc;
}

.wholesale-items-table th.text-center:nth-child(2),
.wholesale-items-table td.text-center:nth-child(2) {
  width: 5.5rem;
  min-width: 5.5rem;
}

.wholesale-line-barcodes-col {
  width: 7.5rem;
  min-width: 7.5rem;
  white-space: normal;
}

td.wholesale-line-barcodes-col .btn {
  display: inline-block;
}

.wholesale-order-detail-page .order-detail-page__items-actions-col {
  width: 5.5rem;
  min-width: 5.5rem;
  white-space: nowrap;
}

.wholesale-order-detail-page .asn-line-thumb {
  width: 48px;
  height: 48px;
  border-radius: 0.4rem;
  object-fit: cover;
  border: 1px solid rgba(0, 0, 0, 0.08);
  background: #fff;
  flex-shrink: 0;
}

.wholesale-order-detail-page .asn-line-thumb--lg {
  width: 56px;
  height: 56px;
}

.wholesale-order-detail-page .asn-line-thumb--empty {
  display: block;
  background: rgba(0, 0, 0, 0.05);
}

.wholesale-order-detail-page .order-detail-page__item-cell {
  display: flex;
  align-items: flex-start;
  gap: 0.75rem;
  min-width: 0;
}

.wholesale-order-detail-page .order-detail-page__items-col {
  width: auto;
  min-width: 0;
  vertical-align: middle;
}

.wholesale-order-detail-page .order-detail-page__item-copy {
  min-width: 0;
  flex: 1 1 auto;
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 0.2rem;
}

.wholesale-order-detail-page .order-detail-page__item-name {
  font-size: 0.9375rem;
  font-weight: 600;
  line-height: 1.35;
  color: var(--bs-body-color);
  overflow-wrap: anywhere;
  word-break: break-word;
  display: -webkit-box;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
  line-clamp: 2;
  overflow: hidden;
}

.wholesale-order-detail-page .order-detail-page__item-sku {
  font-size: 0.8125rem;
  line-height: 1.35;
  color: var(--bs-secondary-color);
  overflow-wrap: anywhere;
  word-break: break-word;
}

.wholesale-order-detail-page .asn-line-media {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: flex-start;
  flex-shrink: 0;
}

.wholesale-order-detail-page .asn-line-status-badge {
  font-size: 0.6875rem;
  white-space: nowrap;
  margin-top: 0.15rem;
}

.wholesale-order-detail-page__status-btn {
  cursor: pointer;
}

.wholesale-order-detail-page__status-btn:hover {
  filter: brightness(0.96);
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

.wholesale-order-detail-page .order-detail-page__section-icon--fees {
  background: rgba(var(--bs-success-rgb), 0.12);
  color: var(--bs-success);
}

.wholesale-order-detail-page .wholesale-fee-row--editable {
  cursor: pointer;
}

.wholesale-order-detail-page .wholesale-fee-row--editable:hover td {
  background-color: rgba(0, 0, 0, 0.03);
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

.wholesale-order-detail-page .order-detail-page__shopify-header-link {
  line-height: 1.35;
  white-space: nowrap;
}

.wholesale-order-detail-page .order-detail-page__shopify-header-link:hover,
.wholesale-order-detail-page .order-detail-page__shopify-header-link:focus-visible {
  text-decoration: underline !important;
}

.wholesale-order-detail-page__header-actions .staff-detail-tab-btn__icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  color: var(--bs-secondary-color);
}

.wholesale-order-detail-page__header-actions a.staff-detail-tab-btn {
  color: inherit;
}

.wholesale-ready-to-ship-btn {
  border-color: #2563eb;
  background: #2563eb;
  color: #fff;
  font-weight: 600;
}

.wholesale-ready-to-ship-btn:hover:not(:disabled),
.wholesale-ready-to-ship-btn:focus-visible:not(:disabled) {
  border-color: #1d4ed8;
  background: #1d4ed8;
  color: #fff;
}

.wholesale-ready-to-ship-btn:disabled {
  border-color: #93c5fd;
  background: #93c5fd;
  color: #fff;
}
</style>
