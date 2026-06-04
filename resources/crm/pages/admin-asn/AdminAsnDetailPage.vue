<script setup>
import { Transition, computed, nextTick, onMounted, onUnmounted, ref, watch } from "vue";
import { RouterLink, useRoute, useRouter } from "vue-router";
import api from "../../services/api";
import CrmIconRowActions from "../../components/common/CrmIconRowActions.vue";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import ConfirmModal from "../../components/common/ConfirmModal.vue";
import AsnProductCatalogPanel from "../../components/inventory/AsnProductCatalogPanel.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast.js";
import { errorMessage } from "../../utils/apiError.js";
import { ASN_CARRIER_OPTIONS } from "../../utils/asnCarrierOptions.js";
import { formatAsnDisplay, formatAsnHeading } from "../../utils/formatAsnDisplay.js";
import { formatDateUs } from "../../utils/formatUserDates.js";

const toast = useToast();
const route = useRoute();
const router = useRouter();

const loading = ref(true);
const asn = ref(null);
const enrichBusy = ref(false);

const productSearch = ref("");
const lineStatusFilter = ref("");

const receiveDraft = ref({});
const rejectDraft = ref({});
const lineSaveBusy = ref({});

const statusMenuOpen = ref(false);
const statusMenuRect = ref({ top: 0, left: 0 });
const headerMenuOpen = ref(false);
const headerMenuRect = ref({ top: 0, left: 0 });
const statusPickerBusy = ref(false);
const HEADER_MENU_W = 220;
const HEADER_MENU_H = 280;

const scanOpen = ref(false);
const scanText = ref("");
const scanBusy = ref(false);

const editReceivedOpen = ref(false);
const editReceivedLine = ref(null);
const editReceivedQty = ref(0);
const editReceivedOnHand = ref(0);
const editReceivedBusy = ref(false);

const editRejectedOpen = ref(false);
const editRejectedLine = ref(null);
const editRejectedQty = ref(0);
const editRejectedBusy = ref(false);

const editItemOpen = ref(false);
const editItemLine = ref(null);
const editItemForm = ref({ barcode: "", weight: "", length: "", width: "", height: "" });
const editItemBusy = ref(false);

const trackingDraft = ref([{ carrier: "", tracking_number: "" }]);
const shipmentBoxesDraft = ref(0);
const shipmentPalletsDraft = ref(0);
const trackingSaveBusy = ref(false);

const reopenBusy = ref(false);

const lineBusy = ref(false);
const addPanelOpen = ref(false);
const markReadyOpen = ref(false);
const markReadyBusy = ref(false);
const markReadyBoxes = ref(0);
const markReadyPallets = ref(0);
const markReadyTrackingMode = ref("entered");
const markReadyTrackings = ref([{ carrier: "", tracking_number: "" }]);
const deleteAsnOpen = ref(false);
const deleteAsnBusy = ref(false);
const deleteLineOpen = ref(false);
const lineToDelete = ref(null);
const addNewSkuOpen = ref(false);
const addNewSkuBusy = ref(false);
const addNewSkuName = ref("");
const addNewSkuSku = ref("");
const addNewSkuQty = ref(1);
const notesDraft = ref("");
const notesSaveBusy = ref(false);

const lineMenuOpenId = ref(null);
const lineMenuRect = ref({ top: 0, left: 0 });
const LINE_MENU_W = 200;
const LINE_MENU_H = 160;

const asnId = computed(() => String(route.params.id || ""));
const isDraft = computed(() => String(asn.value?.status || "").toLowerCase() === "draft");
const isPending = computed(() => String(asn.value?.status || "").toLowerCase() === "pending");
const isNonCompliant = computed(() => String(asn.value?.status || "").toLowerCase() === "non_compliant");
const clientAccountId = computed(() => Number(asn.value?.client_account_id || 0));
const canDeleteAsn = computed(() => {
  const s = String(asn.value?.status || "").toLowerCase();
  return s === "draft" || s === "pending";
});

const lineMenuRow = computed(
  () => (asn.value?.lines || []).find((l) => l.id === lineMenuOpenId.value) ?? null,
);

const STATUS_OPTIONS = [
  { value: "draft", label: "Draft" },
  { value: "pending", label: "Pending" },
  { value: "in_progress", label: "In Progress" },
  { value: "completed", label: "Completed" },
  { value: "non_compliant", label: "Non-Compliant" },
];

function inventoryDetailTo(sku) {
  const s = String(sku || "").trim();
  const accountId = Number(asn.value?.client_account_id || 0);
  if (!s || !accountId) return null;
  return {
    name: "inventory-detail",
    params: { sku: s },
    query: { client_account_id: String(accountId) },
  };
}

function inventoryDetailHref(sku) {
  const to = inventoryDetailTo(sku);
  if (!to) return "";
  return router.resolve(to).href;
}

function openInventoryInNewTab(line, event) {
  event?.preventDefault?.();
  event?.stopPropagation?.();
  const href = inventoryDetailHref(line.sku);
  if (!href) return;
  window.open(href, "_blank", "noopener,noreferrer");
}

function closeAllHeaderMenus() {
  statusMenuOpen.value = false;
  headerMenuOpen.value = false;
}

function placeHeaderMenu(rectRef, anchorEl, width, height) {
  if (!(anchorEl instanceof HTMLElement)) return;
  const r = anchorEl.getBoundingClientRect();
  let top = r.bottom + 4;
  let left = r.right - width;
  left = Math.max(8, Math.min(left, window.innerWidth - width - 8));
  if (top + height > window.innerHeight - 8) {
    top = Math.max(8, r.top - height - 4);
  }
  rectRef.value = { top, left };
}

async function toggleStatusMenu(e) {
  e?.stopPropagation?.();
  if (statusMenuOpen.value) {
    statusMenuOpen.value = false;
    return;
  }
  closeAllHeaderMenus();
  const btn = e?.currentTarget;
  statusMenuOpen.value = true;
  await nextTick();
  requestAnimationFrame(() => {
    if (btn instanceof HTMLElement) placeHeaderMenu(statusMenuRect, btn, HEADER_MENU_W, HEADER_MENU_H);
  });
}

async function toggleHeaderMenu(e) {
  e?.stopPropagation?.();
  if (headerMenuOpen.value) {
    headerMenuOpen.value = false;
    return;
  }
  closeAllHeaderMenus();
  const btn = e?.currentTarget;
  headerMenuOpen.value = true;
  await nextTick();
  requestAnimationFrame(() => {
    if (btn instanceof HTMLElement) placeHeaderMenu(headerMenuRect, btn, HEADER_MENU_W, HEADER_MENU_H);
  });
}

function openScanFromMenu() {
  scanOpen.value = true;
  closeAllHeaderMenus();
}

async function reopenForEditFromMenu() {
  closeAllHeaderMenus();
  await reopenForEdit();
}

async function setAsnStatusFromMenu(status) {
  closeAllHeaderMenus();
  await setAsnStatus(status);
}

function addTrackingRow() {
  trackingDraft.value = [...trackingDraft.value, { carrier: "", tracking_number: "" }];
}

const filteredLines = computed(() => {
  let lines = asn.value?.lines || [];
  const q = productSearch.value.trim().toLowerCase();
  if (q) {
    lines = lines.filter(
      (l) =>
        String(l.name || "").toLowerCase().includes(q) ||
        String(l.sku || "").toLowerCase().includes(q) ||
        String(l.barcode || "").toLowerCase().includes(q),
    );
  }
  if (lineStatusFilter.value) {
    lines = lines.filter((l) => String(l.line_status || "pending") === lineStatusFilter.value);
  }
  return lines;
});

function statusLabel(s) {
  const x = String(s || "").toLowerCase();
  if (x === "draft") return "Draft";
  if (x === "pending") return "Pending";
  if (x === "in_progress") return "In Progress";
  if (x === "completed") return "Completed";
  if (x === "non_compliant") return "Non-Compliant";
  if (x === "partial") return "Partial";
  return s || "—";
}

function statusBadgeClass(status) {
  const s = String(status || "").toLowerCase();
  if (s === "draft") return "bg-warning-subtle text-warning-emphasis";
  if (s === "pending") return "bg-secondary-subtle text-secondary-emphasis";
  if (s === "in_progress" || s === "partial") return "bg-primary-subtle text-primary-emphasis";
  if (s === "completed") return "bg-success-subtle text-success-emphasis";
  if (s === "non_compliant") return "bg-danger-subtle text-danger-emphasis";
  return "bg-body-secondary text-body-secondary";
}

function lineStatusBadgeClass(status) {
  const s = String(status || "").toLowerCase();
  if (s === "pending") return "bg-secondary-subtle text-secondary-emphasis";
  if (s === "partial") return "bg-primary-subtle text-primary-emphasis";
  if (s === "completed") return "bg-success-subtle text-success-emphasis";
  return "bg-body-secondary text-body-secondary";
}

function specDisplay(val) {
  if (val === null || val === undefined || val === "") return "";
  const n = Number(val);
  if (!Number.isFinite(n) || n <= 0) return "";
  return String(val);
}

function syncDraftsFromAsn() {
  if (!asn.value) return;
  trackingDraft.value =
    (asn.value.trackings || []).length > 0
      ? asn.value.trackings.map((t) => ({
          carrier: t.carrier || "",
          tracking_number: t.tracking_number || "",
        }))
      : [{ carrier: "", tracking_number: "" }];
  notesDraft.value = asn.value.warehouse_notes || "";
  shipmentBoxesDraft.value = Number(asn.value.total_boxes) || 0;
  shipmentPalletsDraft.value = Number(asn.value.total_pallets) || 0;
}

function resetReceiveRejectDrafts(lines) {
  const rd = {};
  const rj = {};
  for (const l of lines || []) {
    rd[l.id] = "";
    rj[l.id] = "";
  }
  receiveDraft.value = rd;
  rejectDraft.value = rj;
}

function resetMarkReadyForm() {
  if (!asn.value) return;
  markReadyBoxes.value = Number(asn.value.total_boxes) || 0;
  markReadyPallets.value = Number(asn.value.total_pallets) || 0;
  markReadyTrackingMode.value = "entered";
  markReadyTrackings.value =
    (asn.value.trackings || []).length > 0
      ? asn.value.trackings.map((x) => ({
          carrier: x.carrier || "",
          tracking_number: x.tracking_number || "",
        }))
      : [{ carrier: "", tracking_number: "" }];
}

async function loadAsn() {
  loading.value = true;
  try {
    const { data } = await api.get(`/admin/asns/${asnId.value}`);
    asn.value = data;
    syncDraftsFromAsn();
    resetReceiveRejectDrafts(data.lines);
    setCrmPageMeta({
      title: `Save Rack | ${formatAsnDisplay(data.asn_number)}`,
      description: "ASN receiving detail.",
    });
    const needsSpecs = (data.lines || []).some((l) => !l.specs_cached_at);
    const draft = String(data.status || "").toLowerCase() === "draft";
    if (needsSpecs && !isNonCompliant.value && !draft) {
      enrichSpecs(false);
    }
  } catch (e) {
    toast.errorFrom(e, "Could not load ASN.");
  } finally {
    loading.value = false;
  }
}

async function enrichSpecs(force = false) {
  enrichBusy.value = true;
  try {
    const { data } = await api.post(`/admin/asns/${asnId.value}/enrich-specs`, null, {
      params: force ? { force: 1 } : {},
    });
    if (data.asn) {
      asn.value = data.asn;
    }
    toast.success(force ? "Product specs refreshed." : "Product specs loaded.");
  } catch (e) {
    toast.errorFrom(e, "Could not load product specs.");
  } finally {
    enrichBusy.value = false;
  }
}

async function saveReceive(line) {
  const delta = Number(receiveDraft.value[line.id]);
  if (!Number.isFinite(delta) || delta <= 0) {
    toast.error("Enter a received quantity to add.");
    return;
  }
  lineSaveBusy.value = { ...lineSaveBusy.value, [line.id]: true };
  try {
    const { data } = await api.post(`/admin/asns/${asnId.value}/lines/${line.id}/receive`, { delta });
    asn.value = data.asn;
    receiveDraft.value = { ...receiveDraft.value, [line.id]: "" };
    toast.success("Received quantity saved.");
  } catch (e) {
    toast.errorFrom(e, "Could not save received quantity.");
  } finally {
    lineSaveBusy.value = { ...lineSaveBusy.value, [line.id]: false };
  }
}

async function openEditReceived(line) {
  editReceivedLine.value = line;
  editReceivedQty.value = line.accepted_qty;
  editReceivedBusy.value = true;
  editReceivedOpen.value = true;
  try {
    const { data } = await api.get(
      `/admin/asns/${asnId.value}/lines/${line.id}/receiving-on-hand`,
    );
    editReceivedOnHand.value = data.receiving_on_hand ?? 0;
    editReceivedQty.value = data.accepted_qty ?? line.accepted_qty;
  } catch (e) {
    toast.errorFrom(e, "Could not load Receiving on-hand.");
  } finally {
    editReceivedBusy.value = false;
  }
}

async function confirmEditReceived() {
  if (!editReceivedLine.value) return;
  editReceivedBusy.value = true;
  try {
    const { data } = await api.post(
      `/admin/asns/${asnId.value}/lines/${editReceivedLine.value.id}/receive-override`,
      { accepted_qty: Number(editReceivedQty.value) || 0 },
    );
    asn.value = data.asn;
    editReceivedOpen.value = false;
    toast.success("Received quantity updated.");
  } catch (e) {
    toast.errorFrom(e, "Could not update received quantity.");
  } finally {
    editReceivedBusy.value = false;
  }
}

function openEditRejected(line) {
  editRejectedLine.value = line;
  editRejectedQty.value = line.rejected_qty;
  editRejectedOpen.value = true;
}

async function confirmEditRejected() {
  if (!editRejectedLine.value) return;
  editRejectedBusy.value = true;
  try {
    const { data } = await api.post(
      `/admin/asns/${asnId.value}/lines/${editRejectedLine.value.id}/reject-override`,
      { rejected_qty: Number(editRejectedQty.value) || 0 },
    );
    asn.value = data.asn;
    editRejectedOpen.value = false;
    toast.success("Rejected quantity updated.");
  } catch (e) {
    toast.errorFrom(e, "Could not update rejected quantity.");
  } finally {
    editRejectedBusy.value = false;
  }
}

function openEditItem(line) {
  editItemLine.value = line;
  editItemForm.value = {
    barcode: line.barcode || "",
    weight: line.weight || "",
    length: line.length || "",
    width: line.width || "",
    height: line.height || "",
  };
  editItemOpen.value = true;
}

async function confirmEditItem() {
  if (!editItemLine.value) return;
  editItemBusy.value = true;
  try {
    const { data } = await api.patch(
      `/admin/asns/${asnId.value}/lines/${editItemLine.value.id}/specs`,
      editItemForm.value,
    );
    const lines = (asn.value.lines || []).map((l) => (l.id === data.id ? { ...l, ...data } : l));
    asn.value = { ...asn.value, lines };
    editItemOpen.value = false;
    toast.success("Item updated.");
  } catch (e) {
    toast.errorFrom(e, "Could not save item.");
  } finally {
    editItemBusy.value = false;
  }
}

async function openPdf(path, fallbackMessage) {
  try {
    const { data } = await api.get(path, { responseType: "blob" });
    const blob = new Blob([data], { type: "application/pdf" });
    const url = window.URL.createObjectURL(blob);
    window.open(url, "_blank", "noopener");
    setTimeout(() => window.URL.revokeObjectURL(url), 30000);
  } catch (e) {
    toast.errorFrom(e, fallbackMessage);
  }
}

function printBarcode(line) {
  openPdf(`/asns/${asnId.value}/lines/${line.id}/barcode.pdf`, "Could not open barcode PDF.");
}

async function submitScan() {
  scanBusy.value = true;
  try {
    const { data } = await api.post(`/admin/asns/${asnId.value}/scan-barcodes`, {
      barcodes: scanText.value,
    });
    asn.value = data.asn;
    const unmatched = data.unmatched || [];
    if (unmatched.length) {
      toast.error(`No match for: ${unmatched.slice(0, 3).join(", ")}${unmatched.length > 3 ? "…" : ""}`);
    } else {
      toast.success(`Processed ${data.matched || 0} item(s).`);
    }
    scanOpen.value = false;
    scanText.value = "";
  } catch (e) {
    toast.errorFrom(e, "Could not process barcodes.");
  } finally {
    scanBusy.value = false;
  }
}

async function setAsnStatus(status) {
  statusPickerBusy.value = true;
  try {
    const { data } = await api.patch(`/admin/asns/${asnId.value}/status`, { status });
    asn.value = data;
    statusMenuOpen.value = false;
    toast.success("Status updated.");
  } catch (e) {
    toast.errorFrom(e, "Could not update status.");
  } finally {
    statusPickerBusy.value = false;
  }
}

async function saveTracking() {
  trackingSaveBusy.value = true;
  try {
    const boxes = Math.max(0, Number(shipmentBoxesDraft.value) || 0);
    const pallets = Math.max(0, Number(shipmentPalletsDraft.value) || 0);
    await api.put(`/asns/${asnId.value}/trackings`, { trackings: trackingDraft.value });
    const { data } = await api.patch(`/asns/${asnId.value}`, {
      total_boxes: boxes,
      total_pallets: pallets,
    });
    asn.value = data;
    shipmentBoxesDraft.value = Number(data.total_boxes) || 0;
    shipmentPalletsDraft.value = Number(data.total_pallets) || 0;
    syncDraftsFromAsn();
    toast.success("Tracking saved.");
  } catch (e) {
    toast.errorFrom(e, "Could not save tracking.");
  } finally {
    trackingSaveBusy.value = false;
  }
}

async function reopenForEdit() {
  reopenBusy.value = true;
  try {
    const { data } = await api.post(`/asns/${asnId.value}/reopen-for-edit`);
    asn.value = { ...asn.value, ...data };
    syncDraftsFromAsn();
    addPanelOpen.value = true;
    toast.success("ASN reopened for editing.");
  } catch (e) {
    toast.errorFrom(e, "Could not reopen ASN.");
  } finally {
    reopenBusy.value = false;
  }
}

function openMarkReadyModal() {
  resetMarkReadyForm();
  markReadyOpen.value = true;
}

function addMarkReadyTrackingRow() {
  markReadyTrackings.value = [...markReadyTrackings.value, { carrier: "", tracking_number: "" }];
}

async function confirmMarkReady() {
  if (!asn.value) return;
  const boxes = Math.max(0, Number(markReadyBoxes.value) || 0);
  const pallets = Math.max(0, Number(markReadyPallets.value) || 0);
  if (boxes <= 0 && pallets <= 0) {
    toast.error("Enter total boxes or pallets being shipped in this ASN.");
    return;
  }
  const payload = {
    tracking_mode: markReadyTrackingMode.value,
    total_boxes: boxes,
    total_pallets: pallets,
  };
  if (markReadyTrackingMode.value === "entered") {
    const hasTracking = markReadyTrackings.value.some((row) => String(row.tracking_number || "").trim() !== "");
    if (!hasTracking) {
      toast.error("Enter at least one tracking number.");
      return;
    }
    payload.trackings = markReadyTrackings.value;
  }
  markReadyBusy.value = true;
  try {
    const { data } = await api.post(`/asns/${asnId.value}/mark-ready`, payload);
    asn.value = data;
    syncDraftsFromAsn();
    markReadyOpen.value = false;
    toast.success("ASN marked as ready.");
    await loadAsn();
  } catch (e) {
    toast.errorFrom(e, "Could not mark ASN as ready.");
  } finally {
    markReadyBusy.value = false;
  }
}

function buildAsnLinePayload(product, quantity) {
  const p = product && typeof product === "object" ? product : {};
  const sku = String(p.sku ?? "").trim();
  const name = String(p.name ?? "").trim() || sku;
  const rawId = p.id ?? p.shiphero_product_id ?? null;
  const shipheroProductId =
    rawId !== null && rawId !== undefined && String(rawId).trim() !== ""
      ? String(rawId).trim()
      : null;
  let imageUrl = p.image_url != null ? String(p.image_url).trim() : null;
  if (imageUrl === "") {
    imageUrl = null;
  } else if (imageUrl && imageUrl.length > 2048) {
    imageUrl = imageUrl.slice(0, 2048);
  }
  return {
    shiphero_product_id: shipheroProductId,
    sku,
    name,
    image_url: imageUrl,
    expected_qty: Math.max(1, Math.floor(Number(quantity) || 0)),
  };
}

async function addFromCatalog({ product, quantity }) {
  if (!asn.value || !isDraft.value) return;
  const payload = buildAsnLinePayload(product, quantity);
  if (!payload.sku) {
    toast.error("This product has no SKU.");
    return;
  }
  lineBusy.value = true;
  try {
    await api.post(`/asns/${asnId.value}/lines`, payload);
    toast.success("Product added.");
    await loadAsn();
    addPanelOpen.value = false;
  } catch (e) {
    toast.error(errorMessage(e, "Could not add product."));
  } finally {
    lineBusy.value = false;
  }
}

async function saveLineExpectedQty(line, rawQty) {
  if (!asn.value || !isDraft.value || !line?.id) return;
  const qty = Math.max(0, Number(rawQty) || 0);
  if (qty === Number(line.expected_qty)) return;
  lineBusy.value = true;
  try {
    await api.patch(`/asns/${asnId.value}/lines/${line.id}`, { expected_qty: qty });
    await loadAsn();
  } catch (e) {
    toast.errorFrom(e, "Could not update quantity.");
    await loadAsn();
  } finally {
    lineBusy.value = false;
  }
}

function openAddNewSkuModal() {
  addNewSkuName.value = productSearch.value.trim();
  addNewSkuSku.value = "";
  addNewSkuQty.value = 1;
  addNewSkuOpen.value = true;
}

async function submitAddNewSku() {
  if (!asn.value?.id || !isDraft.value) return;
  const sku = addNewSkuSku.value.trim();
  const name = addNewSkuName.value.trim();
  const qty = Math.max(0, Number(addNewSkuQty.value) || 0);
  if (!sku || !name) {
    toast.error("Enter product name and SKU.");
    return;
  }
  if (qty <= 0) {
    toast.error("Enter expected quantity.");
    return;
  }
  const accountId = Number(asn.value?.client_account_id || 0);
  if (accountId <= 0) {
    toast.error("Client account is required to create a SKU.");
    return;
  }
  addNewSkuBusy.value = true;
  try {
    const { data: created } = await api.post("/inventory/catalog-products", {
      sku,
      name,
      client_account_id: accountId,
    });
    await api.post(
      `/asns/${asnId.value}/lines`,
      buildAsnLinePayload(
        {
          sku,
          name,
          id: created?.id ?? null,
          image_url: created?.image_url ?? null,
        },
        qty,
      ),
    );
    toast.success("SKU added.");
    addNewSkuOpen.value = false;
    await loadAsn();
    addPanelOpen.value = false;
  } catch (e) {
    toast.errorFrom(e, "Could not add SKU.");
  } finally {
    addNewSkuBusy.value = false;
  }
}

function askDeleteLine(row) {
  lineToDelete.value = row;
  deleteLineOpen.value = true;
}

async function confirmDeleteLine() {
  if (!asn.value || !lineToDelete.value) return;
  lineBusy.value = true;
  try {
    await api.delete(`/asns/${asnId.value}/lines/${lineToDelete.value.id}`);
    deleteLineOpen.value = false;
    lineToDelete.value = null;
    lineMenuOpenId.value = null;
    toast.success("Line removed.");
    await loadAsn();
  } catch (e) {
    toast.errorFrom(e, "Delete failed.");
  } finally {
    lineBusy.value = false;
  }
}

function askDeleteLineFromMenu(line) {
  askDeleteLine(line);
  closeLineMenu();
}

function openDeleteAsnFromMenu() {
  closeAllHeaderMenus();
  if (!canDeleteAsn.value) {
    toast.error("Only draft or pending ASNs can be removed.");
    return;
  }
  deleteAsnOpen.value = true;
}

async function confirmDeleteAsn() {
  if (!asn.value?.id) return;
  deleteAsnBusy.value = true;
  try {
    await api.delete(`/asns/${asnId.value}`);
    toast.success("ASN removed.");
    deleteAsnOpen.value = false;
    router.push({ name: "admin-asn-hub" });
  } catch (e) {
    toast.errorFrom(e, "Could not remove ASN.");
  } finally {
    deleteAsnBusy.value = false;
  }
}

async function saveNotes() {
  if (!asn.value?.id) return;
  notesSaveBusy.value = true;
  try {
    const { data } = await api.patch(`/asns/${asnId.value}/warehouse-notes`, {
      warehouse_notes: notesDraft.value,
    });
    asn.value = data;
    syncDraftsFromAsn();
    toast.success("Notes saved.");
  } catch (e) {
    toast.errorFrom(e, "Could not save notes.");
  } finally {
    notesSaveBusy.value = false;
  }
}

function openPrintSlip() {
  openPdf(`/asns/${asnId.value}/packing-slip.pdf`, "Could not open packing slip PDF.");
}

function openPrintLabel() {
  openPdf(`/asns/${asnId.value}/identification-label.pdf`, "Could not open identification label PDF.");
}

function printBarcodeFromMenu(line) {
  printBarcode(line);
  closeLineMenu();
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

function onDocClickMenus(e) {
  if (!e.target?.closest?.("[data-row-actions]")) {
    lineMenuOpenId.value = null;
  }
  if (!e.target?.closest?.("[data-asn-header-actions]")) {
    closeAllHeaderMenus();
  }
}

function onWindowCloseMenus() {
  lineMenuOpenId.value = null;
  closeAllHeaderMenus();
}

watch(asnId, () => loadAsn());

onMounted(() => {
  loadAsn();
  document.addEventListener("click", onDocClickMenus);
  window.addEventListener("scroll", onWindowCloseMenus, true);
  window.addEventListener("resize", onWindowCloseMenus);
});

onUnmounted(() => {
  document.removeEventListener("click", onDocClickMenus);
  window.removeEventListener("scroll", onWindowCloseMenus, true);
  window.removeEventListener("resize", onWindowCloseMenus);
});
</script>

<template>
  <div v-if="loading" class="staff-page staff-page--wide py-5">
    <CrmLoadingSpinner message="Loading ASN…" />
  </div>
  <div v-else-if="!asn" class="staff-page staff-page--wide py-5 text-secondary">ASN not found.</div>
  <div v-else class="staff-page staff-page--wide user-asn-detail-page order-detail-page admin-asn-detail-page">
    <div class="staff-table-card staff-datatable-card staff-datatable-card--white user-asn-detail-page__header-shell mb-4">
      <div class="p-4 pb-3">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
          <div class="min-w-0">
            <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
              <h1 class="h4 mb-0 fw-semibold text-body">{{ formatAsnHeading(asn.asn_number) || "—" }}</h1>
              <button
                type="button"
                data-asn-header-actions
                class="badge rounded-pill fw-medium border-0"
                :class="statusBadgeClass(asn.status)"
                @click="toggleStatusMenu"
              >
                {{ statusLabel(asn.status) }} ▾
              </button>
            </div>
            <p class="small text-secondary mb-1 mt-2">
              <strong>{{ asn.client_account_company_name }}</strong>
            </p>
            <p class="small text-secondary mb-0">
              Created {{ formatDateUs(asn.created_at) }}
              <span v-if="asn.processed_at"> · Processed {{ formatDateUs(asn.processed_at) }}</span>
            </p>
          </div>
          <div class="d-flex flex-wrap gap-2 flex-shrink-0 align-items-center">
            <button
              v-if="isDraft"
              type="button"
              class="btn btn-primary staff-page-primary btn-sm fw-semibold"
              @click="openMarkReadyModal"
            >
              Mark as Ready
            </button>
            <button
              type="button"
              class="btn btn-outline-secondary btn-sm fw-semibold"
              @click="openPrintSlip"
            >
              Print Packing Slip
            </button>
            <div data-asn-header-actions class="position-relative">
              <button
                type="button"
                class="btn btn-outline-secondary btn-sm fw-semibold"
                :class="{ 'is-open': headerMenuOpen }"
                aria-haspopup="true"
                :aria-expanded="headerMenuOpen ? 'true' : 'false'"
                @click="toggleHeaderMenu"
              >
                Manage
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="row g-4">
      <div class="col-lg-8">
        <div v-if="isDraft" class="staff-table-card staff-datatable-card staff-datatable-card--white p-0 mb-4">
          <div class="px-4 py-3 border-bottom d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h2 class="h6 mb-0 fw-semibold">Products</h2>
            <button
              type="button"
              class="btn btn-sm btn-primary staff-page-primary"
              @click="addPanelOpen = !addPanelOpen"
            >
              {{ addPanelOpen ? "Hide Add Products" : "Add Products" }}
            </button>
          </div>

          <div v-show="addPanelOpen" class="border-bottom">
            <AsnProductCatalogPanel
              :client-account-id="clientAccountId"
              :active="addPanelOpen"
              :busy="lineBusy"
              show-add-new-sku
              qty-label="Expected QTY"
              search-input-id="admin-asn-catalog-search"
              @add="addFromCatalog"
              @add-new-sku="openAddNewSkuModal"
            />
          </div>

          <div class="table-responsive staff-table-wrap">
            <table class="table table-hover align-middle mb-0 staff-data-table">
              <thead class="table-light staff-table-head">
                <tr>
                  <th class="staff-table-head__th order-detail-page__items-col">Product</th>
                  <th class="staff-table-head__th text-end" style="width: 9rem">Expected QTY</th>
                  <th class="staff-table-head__th text-center admin-asn-detail-lines-actions-col">Actions</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="line in asn.lines || []" :key="line.id">
                  <td class="order-detail-page__items-col">
                    <div class="order-detail-page__item-cell">
                      <img
                        v-if="line.image_url"
                        :src="line.image_url"
                        alt=""
                        class="asn-line-thumb"
                        loading="lazy"
                      />
                      <div v-else class="asn-line-thumb asn-line-thumb--empty" aria-hidden="true" />
                      <div class="order-detail-page__item-copy">
                        <div class="order-detail-page__item-name" :title="line.name">{{ line.name || "—" }}</div>
                        <div class="order-detail-page__item-sku">SKU {{ line.sku || "—" }}</div>
                      </div>
                    </div>
                  </td>
                  <td class="text-end align-middle">
                    <input
                      type="number"
                      min="0"
                      class="form-control form-control-sm text-end ms-auto asn-line-qty-input"
                      :value="line.expected_qty"
                      :disabled="lineBusy"
                      @change="saveLineExpectedQty(line, $event.target.value)"
                    />
                  </td>
                  <td class="text-center admin-asn-detail-lines-actions-cell" @click.stop>
                    <div data-row-actions class="position-relative d-inline-block">
                      <button
                        type="button"
                        class="staff-action-btn staff-action-btn--more"
                        :class="{ 'is-open': lineMenuOpenId == line.id }"
                        aria-haspopup="true"
                        :aria-expanded="lineMenuOpenId == line.id ? 'true' : 'false'"
                        aria-label="Line actions"
                        @click.stop="toggleLineMenu(line.id, $event)"
                      >
                        <CrmIconRowActions variant="horizontal" />
                      </button>
                    </div>
                  </td>
                </tr>
                <tr v-if="!(asn.lines || []).length">
                  <td colspan="3" class="text-center text-secondary py-4">No products yet.</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <div v-if="!isDraft && !isNonCompliant" class="staff-table-card staff-datatable-card staff-datatable-card--white p-0 mb-4">
          <div class="px-4 py-3 border-bottom d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h2 class="h6 mb-0 fw-semibold">Products</h2>
            <button
              type="button"
              class="btn btn-primary btn-sm staff-page-primary fw-semibold"
              :disabled="enrichBusy"
              @click="enrichSpecs(true)"
            >
              {{ enrichBusy ? "Refreshing…" : "Refresh Specs" }}
            </button>
          </div>

          <div class="staff-table-toolbar border-bottom">
            <div class="staff-table-toolbar--row flex-wrap align-items-end gap-2 gap-md-3">
              <input
                v-model="productSearch"
                type="search"
                class="form-control staff-toolbar-search staff-toolbar-search--inline"
                placeholder="Search by name or SKU"
                autocomplete="off"
                aria-label="Search products"
              />
              <select
                v-model="lineStatusFilter"
                class="form-select form-select-sm staff-toolbar-search staff-toolbar-search--inline"
                style="max-width: 10rem"
                aria-label="Filter by line status"
              >
                <option value="">All status</option>
                <option value="pending">Pending</option>
                <option value="partial">Partial</option>
                <option value="completed">Completed</option>
              </select>
            </div>
          </div>

          <div class="table-responsive staff-table-wrap">
            <table class="table table-hover align-middle mb-0 staff-data-table">
              <thead class="table-light staff-table-head">
                <tr>
                  <th class="staff-table-head__th text-center" style="width: 6rem">Status</th>
                  <th class="staff-table-head__th order-detail-page__items-col">Product</th>
                  <th class="staff-table-head__th">Specs</th>
                  <th class="staff-table-head__th text-end" style="width: 6.5rem">Expected QTY</th>
                  <th class="staff-table-head__th text-end" style="width: 7.5rem">Received QTY</th>
                  <th class="staff-table-head__th text-end" style="width: 7.5rem">Rejected QTY</th>
                  <th
                    class="staff-table-head__th text-center admin-asn-detail-lines-actions-col"
                    style="width: 7rem"
                  >
                    Actions
                  </th>
                </tr>
              </thead>
              <tbody>
                <tr v-if="filteredLines.length === 0">
                  <td colspan="7" class="text-center text-secondary py-4">No products.</td>
                </tr>
                <tr v-for="line in filteredLines" :key="line.id">
                  <td class="text-center align-middle">
                    <span class="badge rounded-pill fw-medium" :class="lineStatusBadgeClass(line.line_status)">
                      {{ statusLabel(line.line_status) }}
                    </span>
                  </td>
                  <td class="order-detail-page__items-col">
                    <a
                      v-if="inventoryDetailHref(line.sku)"
                      :href="inventoryDetailHref(line.sku)"
                      target="_blank"
                      rel="noopener noreferrer"
                      class="order-detail-page__item-cell order-detail-page__item-cell--link text-decoration-none text-body"
                      :title="line.name ? String(line.name) : undefined"
                      :aria-label="line.sku ? `View inventory for SKU ${line.sku} in new tab` : undefined"
                      @click="openInventoryInNewTab(line, $event)"
                    >
                      <img
                        v-if="line.image_url"
                        :src="line.image_url"
                        alt=""
                        class="asn-line-thumb"
                        loading="lazy"
                      />
                      <div v-else class="asn-line-thumb asn-line-thumb--empty" aria-hidden="true" />
                      <div class="order-detail-page__item-copy">
                        <div class="order-detail-page__item-name" :title="line.name">{{ line.name || "—" }}</div>
                        <div
                          class="order-detail-page__item-sku user-inv-table__sku-link"
                          :title="line.sku ? `SKU ${line.sku}` : undefined"
                        >
                          SKU {{ line.sku || "—" }}
                        </div>
                        <button
                          type="button"
                          class="btn btn-link btn-sm px-0 small"
                          @click.stop="openEditItem(line)"
                        >
                          {{ specDisplay(line.barcode) || "Add barcode" }}
                        </button>
                      </div>
                    </a>
                    <div v-else class="order-detail-page__item-cell">
                      <img
                        v-if="line.image_url"
                        :src="line.image_url"
                        alt=""
                        class="asn-line-thumb"
                        loading="lazy"
                      />
                      <div v-else class="asn-line-thumb asn-line-thumb--empty" aria-hidden="true" />
                      <div class="order-detail-page__item-copy">
                        <div class="order-detail-page__item-name" :title="line.name">{{ line.name || "—" }}</div>
                        <div class="order-detail-page__item-sku">SKU {{ line.sku || "—" }}</div>
                        <button
                          type="button"
                          class="btn btn-link btn-sm px-0 small"
                          @click="openEditItem(line)"
                        >
                          {{ specDisplay(line.barcode) || "Add barcode" }}
                        </button>
                      </div>
                    </div>
                  </td>
                  <td class="small text-secondary align-middle">
                    <div>
                      <template v-if="specDisplay(line.weight)">Weight: {{ line.weight }} lbs</template>
                      <button v-else type="button" class="btn btn-link btn-sm px-0" @click="openEditItem(line)">
                        Weight
                      </button>
                    </div>
                    <div>
                      <template v-if="specDisplay(line.length)">L: {{ line.length }}</template>
                      <button v-else type="button" class="btn btn-link btn-sm px-0" @click="openEditItem(line)">L</button>
                      <template v-if="specDisplay(line.width)"> W: {{ line.width }}</template>
                      <button v-else type="button" class="btn btn-link btn-sm px-0" @click="openEditItem(line)">W</button>
                      <template v-if="specDisplay(line.height)"> H: {{ line.height }}</template>
                      <button v-else type="button" class="btn btn-link btn-sm px-0" @click="openEditItem(line)">H</button>
                    </div>
                  </td>
                  <td class="text-end align-middle">{{ Number(line.expected_qty ?? 0).toLocaleString() }}</td>
                  <td class="text-end align-middle">
                    <template v-if="!isDraft">
                      <input
                        v-model="receiveDraft[line.id]"
                        type="number"
                        min="0"
                        class="form-control form-control-sm asn-line-qty-input text-end ms-auto"
                        placeholder="0"
                      />
                      <div class="small text-secondary mt-1">{{ line.accepted_qty }} saved</div>
                    </template>
                    <span v-else>{{ Number(line.accepted_qty ?? 0).toLocaleString() }}</span>
                  </td>
                  <td class="text-end align-middle">
                    <template v-if="!isDraft">
                      <input
                        v-model="rejectDraft[line.id]"
                        type="number"
                        min="0"
                        class="form-control form-control-sm asn-line-qty-input text-end ms-auto"
                      />
                      <div class="small text-secondary mt-1">{{ line.rejected_qty }} saved</div>
                    </template>
                    <span v-else>{{ Number(line.rejected_qty ?? 0).toLocaleString() }}</span>
                  </td>
                  <td class="text-center admin-asn-detail-lines-actions-cell align-middle" @click.stop>
                    <template v-if="!isDraft">
                      <button
                        type="button"
                        class="btn btn-sm btn-primary staff-page-primary d-block w-100 mb-1"
                        :disabled="lineSaveBusy[line.id]"
                        @click="saveReceive(line)"
                      >
                        Save
                      </button>
                      <div data-row-actions class="position-relative d-inline-block">
                        <button
                          type="button"
                          class="staff-action-btn staff-action-btn--more"
                          :class="{ 'is-open': lineMenuOpenId == line.id }"
                          :aria-expanded="lineMenuOpenId == line.id ? 'true' : 'false'"
                          aria-haspopup="true"
                          aria-label="Line actions"
                          @click.stop="toggleLineMenu(line.id, $event)"
                        >
                          <CrmIconRowActions variant="horizontal" />
                        </button>
                      </div>
                    </template>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <p class="staff-table-mobile-scroll-cue d-md-none px-3" aria-hidden="true">
            Scroll sideways or swipe to see all columns.
          </p>
        </div>
      </div>

      <div class="col-lg-4 d-flex flex-column gap-4 order-detail-page__side-column">
        <div class="staff-table-card staff-datatable-card staff-datatable-card--white p-4">
          <h3 class="h6 fw-semibold mb-3">Tracking Details</h3>
          <div class="row g-2 mb-3">
            <div class="col-6">
              <label class="form-label small mb-0">Total Boxes</label>
              <input v-model.number="shipmentBoxesDraft" type="number" min="0" class="form-control form-control-sm" />
            </div>
            <div class="col-6">
              <label class="form-label small mb-0">Total Pallets</label>
              <input v-model.number="shipmentPalletsDraft" type="number" min="0" class="form-control form-control-sm" />
            </div>
          </div>
          <div v-for="(t, idx) in trackingDraft" :key="'trk' + idx" class="mb-2">
            <div class="row g-1">
              <div class="col-5">
                <select v-model="t.carrier" class="form-select form-select-sm">
                  <option value="">Carrier</option>
                  <option v-for="c in ASN_CARRIER_OPTIONS" :key="c" :value="c">{{ c }}</option>
                </select>
              </div>
              <div class="col-7">
                <input
                  v-model="t.tracking_number"
                  class="form-control form-control-sm"
                  placeholder="Tracking #"
                />
              </div>
            </div>
          </div>
          <button type="button" class="btn btn-link btn-sm px-0 mb-2" @click="addTrackingRow">Add Row</button>
          <button
            type="button"
            class="btn btn-sm btn-primary staff-page-primary w-100"
            :disabled="trackingSaveBusy"
            @click="saveTracking"
          >
            Save Tracking
          </button>
        </div>

        <div class="staff-table-card staff-datatable-card staff-datatable-card--white p-4">
          <h3 class="h6 fw-semibold mb-2">Note from Client</h3>
          <template v-if="isDraft">
            <textarea
              v-model="notesDraft"
              class="form-control form-control-sm mb-2"
              rows="4"
              placeholder="Notes for warehouse staff"
            />
            <button
              type="button"
              class="btn btn-sm btn-primary staff-page-primary w-100"
              :disabled="notesSaveBusy"
              @click="saveNotes"
            >
              Save Notes
            </button>
          </template>
          <p v-else class="mb-0 small text-secondary" style="white-space: pre-wrap">
            {{ asn.warehouse_notes || "—" }}
          </p>
        </div>

        <div
          v-if="asn.custom_bill_id"
          class="staff-table-card staff-datatable-card staff-datatable-card--white p-4"
        >
          <h3 class="h6 fw-semibold mb-2">Non-Compliant Fee</h3>
          <p class="mb-1">${{ Number(asn.non_compliant_fee || 0).toFixed(2) }}</p>
          <RouterLink
            :to="{ name: 'billing-custom-bill-detail', params: { id: String(asn.custom_bill_id) } }"
            class="small"
            target="_blank"
            rel="noopener noreferrer"
          >
            View Custom Bill
          </RouterLink>
        </div>
      </div>
    </div>

    <Teleport to="body">
      <Transition
        enter-active-class="transition ease-out duration-100"
        enter-from-class="opacity-0"
        enter-to-class="opacity-100"
        leave-active-class="transition ease-in duration-75"
        leave-from-class="opacity-100"
        leave-to-class="opacity-0"
      >
        <div
          v-if="statusMenuOpen"
          data-asn-header-actions
          class="staff-row-menu fixed z-[300] overflow-hidden"
          role="menu"
          :style="{ top: `${statusMenuRect.top}px`, left: `${statusMenuRect.left}px` }"
          @click.stop
        >
          <button
            v-for="opt in STATUS_OPTIONS"
            :key="opt.value"
            type="button"
            class="staff-row-menu__item"
            role="menuitem"
            :disabled="statusPickerBusy"
            @click="setAsnStatusFromMenu(opt.value)"
          >
            {{ opt.label }}
          </button>
        </div>
      </Transition>

      <Transition
        enter-active-class="transition ease-out duration-100"
        enter-from-class="opacity-0"
        enter-to-class="opacity-100"
        leave-active-class="transition ease-in duration-75"
        leave-from-class="opacity-100"
        leave-to-class="opacity-0"
      >
        <div
          v-if="headerMenuOpen"
          data-asn-header-actions
          class="staff-row-menu fixed z-[300] overflow-hidden"
          role="menu"
          :style="{ top: `${headerMenuRect.top}px`, left: `${headerMenuRect.left}px` }"
          @click.stop
        >
          <button type="button" class="staff-row-menu__item" role="menuitem" @click="openScanFromMenu">
            Scan Items
          </button>
          <button
            v-if="isPending"
            type="button"
            class="staff-row-menu__item"
            role="menuitem"
            :disabled="reopenBusy"
            @click="reopenForEditFromMenu"
          >
            Edit
          </button>
          <button type="button" class="staff-row-menu__item" role="menuitem" @click="openPrintSlip(); closeAllHeaderMenus()">
            Print Packing Slip
          </button>
          <button type="button" class="staff-row-menu__item" role="menuitem" @click="openPrintLabel(); closeAllHeaderMenus()">
            Print Identification Label
          </button>
          <button
            v-if="canDeleteAsn"
            type="button"
            class="staff-row-menu__item staff-row-menu__item--danger"
            role="menuitem"
            @click="openDeleteAsnFromMenu"
          >
            Delete ASN
          </button>
        </div>
      </Transition>

      <Transition
        enter-active-class="transition ease-out duration-100"
        enter-from-class="opacity-0"
        enter-to-class="opacity-100"
        leave-active-class="transition ease-in duration-75"
        leave-from-class="opacity-100"
        leave-to-class="opacity-0"
      >
        <div
          v-if="lineMenuRow"
          data-row-actions
          class="staff-row-menu fixed z-[300] overflow-hidden"
          role="menu"
          :style="{ top: `${lineMenuRect.top}px`, left: `${lineMenuRect.left}px` }"
          @click.stop
        >
          <template v-if="isDraft">
            <button
              type="button"
              class="staff-row-menu__item staff-row-menu__item--danger"
              role="menuitem"
              @click="askDeleteLineFromMenu(lineMenuRow)"
            >
              Remove
            </button>
            <button
              type="button"
              class="staff-row-menu__item"
              role="menuitem"
              @click="printBarcodeFromMenu(lineMenuRow)"
            >
              Print Barcode
            </button>
          </template>
          <template v-else>
            <button
              type="button"
              class="staff-row-menu__item"
              role="menuitem"
              @click="
                closeLineMenu();
                openEditReceived(lineMenuRow);
              "
            >
              Edit Received
            </button>
            <button
              type="button"
              class="staff-row-menu__item"
              role="menuitem"
              @click="
                closeLineMenu();
                openEditRejected(lineMenuRow);
              "
            >
              Edit Rejected
            </button>
            <button
              type="button"
              class="staff-row-menu__item"
              role="menuitem"
              @click="
                closeLineMenu();
                printBarcode(lineMenuRow);
              "
            >
              Print Barcode
            </button>
          </template>
        </div>
      </Transition>
    </Teleport>

    <ConfirmModal
      :open="scanOpen"
      title="Scan Items"
      confirm-label="Save"
      :danger="false"
      :busy="scanBusy"
      @close="scanOpen = false"
      @confirm="submitScan"
    >
      <label class="form-label">Enter barcodes line by line</label>
      <textarea v-model="scanText" class="form-control font-monospace" rows="10" />
    </ConfirmModal>

    <ConfirmModal
      :open="editReceivedOpen"
      title="Edit Received"
      confirm-label="Save"
      :busy="editReceivedBusy"
      @close="editReceivedOpen = false"
      @confirm="confirmEditReceived"
    >
      <p class="small text-secondary">
        On-hand in Receiving: <strong>{{ editReceivedOnHand }}</strong>
      </p>
      <label class="form-label">New QTY</label>
      <input v-model.number="editReceivedQty" type="number" min="0" class="form-control" />
    </ConfirmModal>

    <ConfirmModal
      :open="editRejectedOpen"
      title="Edit Rejected"
      confirm-label="Save"
      :busy="editRejectedBusy"
      @close="editRejectedOpen = false"
      @confirm="confirmEditRejected"
    >
      <label class="form-label">Rejected QTY</label>
      <input v-model.number="editRejectedQty" type="number" min="0" class="form-control" />
    </ConfirmModal>

    <ConfirmModal
      :open="editItemOpen"
      title="Edit Item"
      confirm-label="Save"
      :busy="editItemBusy"
      @close="editItemOpen = false"
      @confirm="confirmEditItem"
    >
      <div v-if="editItemLine" class="mb-3">
        <div class="fw-semibold">{{ editItemLine.name }}</div>
        <div class="small text-secondary">{{ editItemLine.sku }}</div>
      </div>
      <p class="small fw-semibold text-secondary">Information</p>
      <label class="form-label">Barcode</label>
      <input v-model="editItemForm.barcode" type="text" class="form-control mb-3" />
      <p class="small fw-semibold text-secondary">Dimensions &amp; weight</p>
      <div class="row g-2">
        <div class="col-3">
          <label class="form-label small">Length</label>
          <input v-model="editItemForm.length" type="number" step="0.01" min="0" class="form-control" />
        </div>
        <div class="col-3">
          <label class="form-label small">Width</label>
          <input v-model="editItemForm.width" type="number" step="0.01" min="0" class="form-control" />
        </div>
        <div class="col-3">
          <label class="form-label small">Height</label>
          <input v-model="editItemForm.height" type="number" step="0.01" min="0" class="form-control" />
        </div>
        <div class="col-3">
          <label class="form-label small">Weight</label>
          <input v-model="editItemForm.weight" type="number" step="0.01" min="0" class="form-control" />
        </div>
      </div>
    </ConfirmModal>

    <ConfirmModal
      :open="deleteLineOpen"
      title="Remove Product"
      confirm-label="Remove"
      :danger="true"
      :busy="lineBusy"
      @close="deleteLineOpen = false"
      @confirm="confirmDeleteLine"
    >
      <p v-if="lineToDelete" class="mb-0">
        Remove <strong>{{ lineToDelete.name || lineToDelete.sku }}</strong> from this ASN?
      </p>
    </ConfirmModal>

    <ConfirmModal
      :open="deleteAsnOpen"
      title="Delete ASN"
      confirm-label="Delete"
      :danger="true"
      :busy="deleteAsnBusy"
      @close="deleteAsnOpen = false"
      @confirm="confirmDeleteAsn"
    >
      <p class="mb-0">Delete this ASN? This cannot be undone.</p>
    </ConfirmModal>

    <ConfirmModal
      :open="addNewSkuOpen"
      title="Add New SKU"
      confirm-label="Add SKU"
      :busy="addNewSkuBusy"
      @close="addNewSkuOpen = false"
      @confirm="submitAddNewSku"
    >
      <p class="small text-secondary mb-3">Creates the SKU in ShipHero and adds it to this ASN.</p>
      <label class="form-label small mb-1" for="admin-asn-new-sku-name">Product Name</label>
      <input id="admin-asn-new-sku-name" v-model="addNewSkuName" type="text" class="form-control form-control-sm mb-3" />
      <label class="form-label small mb-1" for="admin-asn-new-sku-code">SKU</label>
      <input id="admin-asn-new-sku-code" v-model="addNewSkuSku" type="text" class="form-control form-control-sm mb-3" />
      <label class="form-label small mb-1" for="admin-asn-new-sku-qty">Expected QTY</label>
      <input id="admin-asn-new-sku-qty" v-model.number="addNewSkuQty" type="number" min="1" class="form-control form-control-sm" />
    </ConfirmModal>

    <div
      v-if="markReadyOpen"
      class="modal d-block"
      tabindex="-1"
      style="background: rgba(0, 0, 0, 0.35)"
      @click.self="markReadyOpen = false"
    >
      <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
          <div class="modal-header">
            <h2 class="modal-title h6">Mark as Ready</h2>
            <button type="button" class="btn-close" aria-label="Close" @click="markReadyOpen = false" />
          </div>
          <div class="modal-body">
            <p class="small text-secondary">
              Enter how many boxes or pallets are being shipped. Tracking can be added now, updated later, or replaced with an identification label on each box.
            </p>
            <div class="row g-2 mb-3">
              <div class="col-sm-6">
                <label class="form-label small mb-0">Total Boxes</label>
                <input v-model.number="markReadyBoxes" type="number" min="0" class="form-control form-control-sm" />
              </div>
              <div class="col-sm-6">
                <label class="form-label small mb-0">Total Pallets</label>
                <input v-model.number="markReadyPallets" type="number" min="0" class="form-control form-control-sm" />
              </div>
            </div>
            <fieldset class="mb-3">
              <legend class="form-label small mb-2">Tracking</legend>
              <div class="form-check mb-1">
                <input id="admin-asn-trk-entered" v-model="markReadyTrackingMode" class="form-check-input" type="radio" value="entered" />
                <label class="form-check-label small" for="admin-asn-trk-entered">Tracking number(s) entered</label>
              </div>
              <div class="form-check mb-1">
                <input id="admin-asn-trk-later" v-model="markReadyTrackingMode" class="form-check-input" type="radio" value="update_later" />
                <label class="form-check-label small" for="admin-asn-trk-later">Update tracking later</label>
              </div>
              <div class="form-check">
                <input id="admin-asn-trk-label" v-model="markReadyTrackingMode" class="form-check-input" type="radio" value="id_label" />
                <label class="form-check-label small" for="admin-asn-trk-label">Identification label on each box</label>
              </div>
            </fieldset>
            <div v-if="markReadyTrackingMode === 'entered'" class="border rounded p-3 bg-body-tertiary">
              <div v-for="(row, idx) in markReadyTrackings" :key="'mr' + idx" class="mb-2">
                <div class="row g-1">
                  <div class="col-5">
                    <select v-model="row.carrier" class="form-select form-select-sm">
                      <option value="">Carrier</option>
                      <option v-for="c in ASN_CARRIER_OPTIONS" :key="c" :value="c">{{ c }}</option>
                    </select>
                  </div>
                  <div class="col-7">
                    <input v-model="row.tracking_number" class="form-control form-control-sm" placeholder="Tracking #" />
                  </div>
                </div>
              </div>
              <button type="button" class="btn btn-link btn-sm px-0" @click="addMarkReadyTrackingRow">Add Tracking Row</button>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-sm btn-outline-secondary" @click="markReadyOpen = false">Cancel</button>
            <button
              type="button"
              class="btn btn-sm btn-primary staff-page-primary"
              :disabled="markReadyBusy"
              @click="confirmMarkReady"
            >
              Mark as Ready
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.admin-asn-detail-page .asn-line-thumb {
  width: 64px;
  height: 64px;
  border-radius: 0.4rem;
  object-fit: cover;
  border: 1px solid rgba(0, 0, 0, 0.08);
  background: #fff;
  flex-shrink: 0;
}

.admin-asn-detail-page .asn-line-thumb--empty {
  display: block;
  background: rgba(0, 0, 0, 0.05);
}

.admin-asn-detail-page .order-detail-page__item-cell {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  min-width: 0;
}

.admin-asn-detail-page .order-detail-page__item-cell--link {
  color: inherit;
}

.admin-asn-detail-page .order-detail-page__item-cell--link:hover .order-detail-page__item-name,
.admin-asn-detail-page .order-detail-page__item-cell--link:hover .order-detail-page__item-sku {
  color: var(--bs-link-hover-color, #1d4ed8);
}

.admin-asn-detail-page .order-detail-page__item-copy {
  min-width: 0;
}

.admin-asn-detail-page :deep(.table-responsive.staff-table-wrap) {
  overflow-x: auto;
  -webkit-overflow-scrolling: touch;
  max-width: 100%;
}

.admin-asn-detail-page :deep(.staff-table-wrap .table.staff-data-table) {
  width: 100%;
  min-width: 52rem;
}

.admin-asn-detail-page .btn-outline-secondary:hover:not(:disabled),
.admin-asn-detail-page .btn-outline-secondary:focus-visible {
  background-color: rgba(115, 103, 240, 0.06);
  border-color: rgba(115, 103, 240, 0.35);
  color: var(--bs-body-color);
}

[data-bs-theme="dark"] .admin-asn-detail-page .btn-outline-secondary:hover:not(:disabled),
[data-bs-theme="dark"] .admin-asn-detail-page .btn-outline-secondary:focus-visible {
  background-color: rgba(115, 103, 240, 0.12);
  border-color: rgba(186, 175, 255, 0.35);
  color: var(--bs-body-color);
}

.admin-asn-detail-page :deep(.table.staff-data-table > thead > tr > th.admin-asn-detail-lines-actions-col),
.admin-asn-detail-page :deep(.table.staff-data-table > tbody > tr > td.admin-asn-detail-lines-actions-cell) {
  text-align: center !important;
  width: 7rem;
}

.admin-asn-detail-page :deep(.asn-line-qty-input) {
  max-width: 5.5rem;
}
</style>
