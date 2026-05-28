<script setup>
import { computed, inject, nextTick, onMounted, onUnmounted, ref, watch } from "vue";
import { RouterLink, useRoute, useRouter } from "vue-router";
import api from "../../services/api";
import CrmIconRowActions from "../../components/common/CrmIconRowActions.vue";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import ConfirmModal from "../../components/common/ConfirmModal.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast.js";
import { ASN_CARRIER_OPTIONS } from "../../utils/asnCarrierOptions.js";
import { formatAsnDisplay, formatAsnHeading, formatAsnLabel } from "../../utils/formatAsnDisplay.js";
import { formatDateUs } from "../../utils/formatUserDates.js";

const SHIP_TO_ADDRESS_LINES = ["3135 Drane Field Rd #20", "Lakeland, FL 33811"];
const HEADER_MENU_W = 220;
const HEADER_MENU_H = 120;

const toast = useToast();
const route = useRoute();
const router = useRouter();
const crmUser = inject("crmUser", ref(null));

const loading = ref(true);
const asn = ref(null);
const lineBusy = ref(false);
const headerMenuOpen = ref(false);
const headerMenuRect = ref({ top: 0, left: 0 });
const deleteAsnOpen = ref(false);
const deleteAsnBusy = ref(false);
const markReadyOpen = ref(false);
const markReadyBusy = ref(false);
const markReadyBoxes = ref(0);
const markReadyPallets = ref(0);
const markReadyTrackingMode = ref("entered");
const markReadyTrackings = ref([{ carrier: "", tracking_number: "" }]);

const catalog = ref([]);
const catalogLoading = ref(false);
const catalogLoadingMore = ref(false);
const catalogSearchAutoLoading = ref(false);
const catalogRefreshing = ref(false);
const catalogPageInfo = ref({ has_next_page: false, end_cursor: null });
const catalogSearchDraft = ref("");
const catalogSearchCommitted = ref("");
const catalogSearchSkipNext = ref(0);
const addPanelOpen = ref(false);
let catalogSearchRunSeq = 0;

/** GraphQL products page size (aligned with portal inventory paging; capped 25–100 on server). */
const CATALOG_PAGE_SIZE = 50;

const trackingDraft = ref([{ carrier: "", tracking_number: "" }]);
const vendorDraft = ref([{ label: "" }]);
const notesDraft = ref("");
const trackingSaveBusy = ref(false);
const vendorSaveBusy = ref(false);
const notesSaveBusy = ref(false);

const catalogQtyByKey = ref({});

const deleteLineOpen = ref(false);
const lineToDelete = ref(null);

const addNewSkuOpen = ref(false);
const addNewSkuBusy = ref(false);
const addNewSkuName = ref("");
const addNewSkuSku = ref("");
const addNewSkuQty = ref(1);

const reopenEditOpen = ref(false);
const reopenEditBusy = ref(false);

const shipmentBoxesDraft = ref(0);
const shipmentPalletsDraft = ref(0);

const lineMenuOpenId = ref(null);
const lineMenuPos = ref({ top: 0, left: 0 });
const LINE_MENU_W = 200;
const LINE_MENU_H = 160;

const lineMenuStyle = computed(() => ({
  top: `${lineMenuPos.value.top}px`,
  left: `${lineMenuPos.value.left}px`,
  zIndex: 2200,
}));

const clientAccountId = computed(() => Number(crmUser.value?.client_account_id || 0));
const asnId = computed(() => String(route.params.id || ""));

const isDraft = computed(() => String(asn.value?.status || "").toLowerCase() === "draft");
const isPending = computed(() => String(asn.value?.status || "").toLowerCase() === "pending");
const canDeleteAsn = computed(() => {
  const s = String(asn.value?.status || "").toLowerCase();
  return s === "draft" || s === "pending";
});
const shipToAccountName = computed(() => {
  const fromAsn = String(asn.value?.client_account_company_name || "").trim();
  if (fromAsn) return fromAsn;
  return String(crmUser.value?.client_account_company_name || "Save Rack").trim() || "Save Rack";
});

const asnDisplayNumber = computed(() => formatAsnDisplay(asn.value?.asn_number));
const asnHeading = computed(() => formatAsnHeading(asn.value?.asn_number));
const asnLabel = computed(() => formatAsnLabel(asn.value?.asn_number));

function inventoryDetailTo(sku) {
  const s = String(sku || "").trim();
  if (!s) return null;
  return {
    name: "user-inventory-detail",
    params: { sku: s },
    query: { client_account_id: String(clientAccountId.value) },
  };
}

function catalogKey(p) {
  return String(p.id || p.sku || "");
}

function catalogQty(p) {
  const k = catalogKey(p);
  const n = Number(catalogQtyByKey.value[k]);
  return Number.isFinite(n) && n >= 0 ? n : 1;
}

function setCatalogQty(p, v) {
  const k = catalogKey(p);
  catalogQtyByKey.value = { ...catalogQtyByKey.value, [k]: Math.max(0, Number(v) || 0) };
}

function statusLabel(s) {
  if (s === "draft") return "Draft";
  if (s === "in_progress") return "In Progress";
  if (s === "completed") return "Completed";
  if (s === "pending") return "Pending";
  return "Pending";
}

function statusBadgeClass(status) {
  const s = String(status || "").toLowerCase();
  if (s === "draft") return "bg-warning-subtle text-warning-emphasis";
  if (s === "pending") return "bg-secondary-subtle text-secondary-emphasis";
  if (s === "in_progress") return "bg-primary-subtle text-primary-emphasis";
  if (s === "completed") return "bg-success-subtle text-success-emphasis";
  return "bg-body-secondary text-body-secondary";
}

function normalizeAsnStatusPayload(data) {
  if (!data || typeof data !== "object") return;
  const s = String(data.status || "")
    .trim()
    .toLowerCase();
  if (s === "draft" || s === "in_progress" || s === "completed" || s === "pending") {
    data.status = s;
  } else {
    data.status = "pending";
  }
}

function resetMarkReadyForm() {
  if (!asn.value) return;
  markReadyBoxes.value = Number(asn.value.total_boxes) || 0;
  markReadyPallets.value = Number(asn.value.total_pallets) || 0;
  markReadyTrackingMode.value = "entered";
  const t = (asn.value.trackings || []).length
    ? asn.value.trackings.map((x) => ({ carrier: x.carrier || "", tracking_number: x.tracking_number || "" }))
    : [{ carrier: "", tracking_number: "" }];
  markReadyTrackings.value = t;
}

async function loadAsn() {
  if (!asnId.value || !clientAccountId.value) {
    loading.value = false;
    return;
  }
  loading.value = true;
  try {
    const { data } = await api.get(`/asns/${asnId.value}`);
    normalizeAsnStatusPayload(data);
    asn.value = data;
    syncDraftsFromAsn();
    const heading = formatAsnHeading(data?.asn_number);
    setCrmPageMeta({
      title: heading ? `Save Rack | ${heading}` : "Save Rack | ASN",
      description: "ASN detail.",
    });
    if (typeof window !== "undefined" && route.hash === "#user-asn-items") {
      await nextTick();
      requestAnimationFrame(() => {
        document.getElementById("user-asn-items")?.scrollIntoView({ behavior: "smooth", block: "start" });
      });
    }
  } catch (e) {
    toast.errorFrom(e, "Could not load ASN.");
    asn.value = null;
  } finally {
    loading.value = false;
  }
}

function placeHeaderMenu(anchorEl) {
  if (!(anchorEl instanceof HTMLElement)) return;
  const r = anchorEl.getBoundingClientRect();
  let top = r.bottom + 4;
  let left = r.right - HEADER_MENU_W;
  left = Math.max(8, Math.min(left, window.innerWidth - HEADER_MENU_W - 8));
  if (top + HEADER_MENU_H > window.innerHeight - 8) {
    top = Math.max(8, r.top - HEADER_MENU_H - 4);
  }
  headerMenuRect.value = { top, left };
}

async function toggleHeaderMenu(e) {
  e?.stopPropagation?.();
  if (headerMenuOpen.value) {
    headerMenuOpen.value = false;
    return;
  }
  const btn = e?.currentTarget;
  headerMenuOpen.value = true;
  await nextTick();
  requestAnimationFrame(() => {
    if (btn instanceof HTMLElement) placeHeaderMenu(btn);
  });
}

function closeHeaderMenu() {
  headerMenuOpen.value = false;
}

function onDocClickHeaderMenu(e) {
  if (!e.target?.closest?.("[data-asn-header-actions]")) {
    headerMenuOpen.value = false;
  }
}

function openReopenEditFromMenu() {
  closeHeaderMenu();
  if (!isPending.value) {
    toast.error("Only pending ASNs can be reopened for editing.");
    return;
  }
  reopenEditOpen.value = true;
}

async function confirmReopenForEdit() {
  if (!asn.value?.id) return;
  reopenEditBusy.value = true;
  try {
    const { data } = await api.post(`/asns/${asn.value.id}/reopen-for-edit`);
    normalizeAsnStatusPayload(data);
    asn.value = data;
    syncDraftsFromAsn();
    reopenEditOpen.value = false;
    toast.success("ASN reopened for editing.");
    addPanelOpen.value = true;
    await nextTick();
    document.getElementById("user-asn-items")?.scrollIntoView({ behavior: "smooth", block: "start" });
  } catch (e) {
    toast.errorFrom(e, "Could not reopen ASN for editing.");
  } finally {
    reopenEditBusy.value = false;
  }
}

function openAddNewSkuModal() {
  addNewSkuName.value = catalogSearchCommitted.value || "";
  addNewSkuSku.value = "";
  addNewSkuQty.value = 1;
  addNewSkuOpen.value = true;
}

function closeAddNewSkuModal() {
  if (addNewSkuBusy.value) return;
  addNewSkuOpen.value = false;
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
  const duplicate = (asn.value.lines || []).some(
    (line) => String(line.sku || "").trim().toLowerCase() === sku.toLowerCase(),
  );
  if (duplicate) {
    toast.error("This SKU is already on the ASN.");
    return;
  }
  addNewSkuBusy.value = true;
  try {
    await api.post(`/asns/${asn.value.id}/lines`, {
      sku,
      name,
      expected_qty: qty,
      shiphero_product_id: null,
    });
    toast.success("SKU added.");
    addNewSkuOpen.value = false;
    await loadAsn();
    resetCatalogSearchState();
    addPanelOpen.value = false;
  } catch (e) {
    toast.errorFrom(e, "Could not add SKU.");
  } finally {
    addNewSkuBusy.value = false;
  }
}

function openDeleteAsnFromMenu() {
  closeHeaderMenu();
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
    await api.delete(`/asns/${asn.value.id}`);
    toast.success("ASN removed.");
    deleteAsnOpen.value = false;
    router.push({ name: "user-asn-list" });
  } catch (e) {
    toast.errorFrom(e, "Could not remove ASN.");
  } finally {
    deleteAsnBusy.value = false;
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
    const { data } = await api.post(`/asns/${asn.value.id}/mark-ready`, payload);
    normalizeAsnStatusPayload(data);
    asn.value = data;
    markReadyOpen.value = false;
    toast.success("ASN marked as ready.");
  } catch (e) {
    toast.errorFrom(e, "Could not mark ASN as ready.");
  } finally {
    markReadyBusy.value = false;
  }
}

function placeLineMenuFromButton(btn) {
  if (!(btn instanceof HTMLElement)) return;
  const r = btn.getBoundingClientRect();
  let top = r.bottom + 4;
  let left = r.right - LINE_MENU_W;
  left = Math.max(8, Math.min(left, window.innerWidth - LINE_MENU_W - 8));
  if (top + LINE_MENU_H > window.innerHeight - 8) {
    top = Math.max(8, r.top - LINE_MENU_H - 4);
  }
  lineMenuPos.value = { top, left };
}

async function toggleLineMenu(lineId, e) {
  e?.stopPropagation?.();
  if (lineMenuOpenId.value == lineId) {
    lineMenuOpenId.value = null;
    return;
  }
  const btn = e?.currentTarget;
  lineMenuOpenId.value = lineId;
  await nextTick();
  requestAnimationFrame(() => {
    if (btn instanceof HTMLElement) placeLineMenuFromButton(btn);
  });
}

function closeLineMenu() {
  lineMenuOpenId.value = null;
}

function askDeleteLineFromMenu(line) {
  askDeleteLine(line);
  closeLineMenu();
}

function printBarcodeFromMenu(line) {
  openPrintBarcode(line);
  closeLineMenu();
}

function onDocClickLineMenu(e) {
  if (!e.target?.closest?.("[data-row-actions]")) {
    lineMenuOpenId.value = null;
  }
}

function onWindowCloseLineMenu() {
  lineMenuOpenId.value = null;
}

function commitCatalogSearch() {
  catalogSearchCommitted.value = catalogSearchDraft.value.trim();
  loadCatalogRows(true);
}

function resetCatalogSearchState() {
  catalogSearchRunSeq += 1;
  catalogSearchAutoLoading.value = false;
  catalogSearchDraft.value = "";
  catalogSearchCommitted.value = "";
  catalogSearchSkipNext.value = 0;
  catalogPageInfo.value = { has_next_page: false, end_cursor: null };
  catalog.value = [];
}

function clearCatalogSearch() {
  if (!catalogSearchDraft.value && !catalogSearchCommitted.value) return;
  catalogSearchDraft.value = "";
  catalogSearchCommitted.value = "";
  loadCatalogRows(true);
}

function loadMoreCatalog() {
  if (
    !catalogPageInfo.value.has_next_page ||
    catalogLoadingMore.value ||
    catalogLoading.value ||
    catalogSearchAutoLoading.value ||
    !clientAccountId.value
  ) {
    return;
  }
  loadCatalogRows(false);
}

async function loadCatalogRows(reset, forceRefresh = false) {
  if (!clientAccountId.value) return;
  const runId = reset ? ++catalogSearchRunSeq : catalogSearchRunSeq;
  if (reset) {
    catalogLoading.value = !forceRefresh;
    catalogRefreshing.value = forceRefresh;
    catalogSearchAutoLoading.value = false;
    catalog.value = [];
    catalogPageInfo.value = { has_next_page: false, end_cursor: null };
    catalogSearchSkipNext.value = 0;
  } else {
    catalogLoadingMore.value = true;
  }
  try {
    const params = {
      client_account_id: clientAccountId.value,
      first: CATALOG_PAGE_SIZE,
    };
    const q = catalogSearchCommitted.value;
    if (q) {
      params.query = q;
      params.search_skip = catalogSearchSkipNext.value;
    }
    if (forceRefresh) {
      params.refresh = 1;
    }
    if (!reset && catalogPageInfo.value?.end_cursor) {
      params.after = catalogPageInfo.value.end_cursor;
    }
    const { data } = await api.get("/inventory/asn-product-catalog", { params });
    const chunk = Array.isArray(data?.products) ? data.products : [];
    const pi = data?.page_info || {};
    catalogPageInfo.value = {
      has_next_page: Boolean(pi.has_next_page),
      end_cursor: pi.end_cursor ?? null,
    };
    if (q && typeof pi.next_search_skip === "number") {
      catalogSearchSkipNext.value = Number(pi.next_search_skip);
    }

    const dest = [];
    const seen = new Set();
    if (!reset) {
      for (const p of catalog.value) {
        const k = catalogKey(p);
        seen.add(k);
        dest.push(p);
      }
    }
    for (const p of chunk) {
      const k = catalogKey(p);
      if (seen.has(k)) continue;
      seen.add(k);
      dest.push(p);
    }
    catalog.value = dest;
  } catch (e) {
    toast.errorFrom(e, "Could not load product catalog.");
  } finally {
    catalogLoading.value = false;
    catalogLoadingMore.value = false;
    catalogRefreshing.value = false;
  }
  if (reset && catalogSearchCommitted.value.trim() && catalogPageInfo.value.has_next_page) {
    continueCatalogSearchInBackground(runId);
  }
}

function refreshCatalogProducts() {
  if (catalogLoading.value || catalogLoadingMore.value || catalogRefreshing.value) return;
  loadCatalogRows(true, true);
}

async function continueCatalogSearchInBackground(runId) {
  if (catalogSearchAutoLoading.value) return;
  catalogSearchAutoLoading.value = true;
  try {
    let guard = 0;
    while (
      runId === catalogSearchRunSeq &&
      catalogSearchCommitted.value.trim() &&
      catalogPageInfo.value.has_next_page &&
      guard < 200
    ) {
      guard += 1;
      await loadCatalogRows(false);
      await nextTick();
    }
  } finally {
    if (runId === catalogSearchRunSeq) {
      catalogSearchAutoLoading.value = false;
    }
  }
}

watch(addPanelOpen, (open) => {
  if (open) {
    catalogSearchDraft.value = catalogSearchCommitted.value;
    loadCatalogRows(true);
  }
});

function syncDraftsFromAsn() {
  if (!asn.value) return;
  const t = (asn.value.trackings || []).length
    ? asn.value.trackings.map((x) => ({
        carrier: x.carrier || "",
        tracking_number: x.tracking_number || "",
      }))
    : [{ carrier: "", tracking_number: "" }];
  trackingDraft.value = t;
  const v = (asn.value.vendor_lines || []).length
    ? asn.value.vendor_lines.map((x) => ({ label: x.label || "" }))
    : [{ label: "" }];
  vendorDraft.value = v;
  notesDraft.value = asn.value.warehouse_notes || "";
  shipmentBoxesDraft.value = Number(asn.value.total_boxes) || 0;
  shipmentPalletsDraft.value = Number(asn.value.total_pallets) || 0;
}

function addTrackingRow() {
  trackingDraft.value = [...trackingDraft.value, { carrier: "", tracking_number: "" }];
}

function addVendorRow() {
  vendorDraft.value = [...vendorDraft.value, { label: "" }];
}

function removeVendorRow(idx) {
  if (vendorDraft.value.length <= 1) {
    vendorDraft.value = [{ label: "" }];
    return;
  }
  vendorDraft.value = vendorDraft.value.filter((_, i) => i !== idx);
}

async function saveTrackings() {
  if (!asn.value?.id) return;
  trackingSaveBusy.value = true;
  try {
    const boxes = Math.max(0, Number(shipmentBoxesDraft.value) || 0);
    const pallets = Math.max(0, Number(shipmentPalletsDraft.value) || 0);
    const { data: trackingData } = await api.put(`/asns/${asn.value.id}/trackings`, {
      trackings: trackingDraft.value,
    });
    normalizeAsnStatusPayload(trackingData);
    const { data } = await api.patch(`/asns/${asn.value.id}`, {
      total_boxes: boxes,
      total_pallets: pallets,
    });
    normalizeAsnStatusPayload(data);
    asn.value = data;
    syncDraftsFromAsn();
    toast.success("Tracking saved.");
  } catch (e) {
    toast.errorFrom(e, "Could not save tracking.");
  } finally {
    trackingSaveBusy.value = false;
  }
}

async function saveVendors() {
  if (!asn.value?.id) return;
  vendorSaveBusy.value = true;
  try {
    const { data } = await api.put(`/asns/${asn.value.id}/vendor-lines`, {
      vendor_lines: vendorDraft.value,
    });
    normalizeAsnStatusPayload(data);
    asn.value = data;
    syncDraftsFromAsn();
    toast.success("Vendor details saved.");
  } catch (e) {
    toast.errorFrom(e, "Could not save vendor details.");
  } finally {
    vendorSaveBusy.value = false;
  }
}

async function saveNotes() {
  if (!asn.value?.id) return;
  notesSaveBusy.value = true;
  try {
    const { data } = await api.patch(`/asns/${asn.value.id}/warehouse-notes`, {
      warehouse_notes: notesDraft.value,
    });
    normalizeAsnStatusPayload(data);
    asn.value = data;
    syncDraftsFromAsn();
    toast.success("Notes saved.");
  } catch (e) {
    toast.errorFrom(e, "Could not save notes.");
  } finally {
    notesSaveBusy.value = false;
  }
}

async function addFromCatalog(p) {
  if (!asn.value || !isDraft.value) return;
  const qty = catalogQty(p);
  if (qty <= 0) {
    toast.error("Enter expected quantity.");
    return;
  }
  lineBusy.value = true;
  try {
    await api.post(`/asns/${asn.value.id}/lines`, {
      shiphero_product_id: p.id,
      sku: p.sku,
      name: p.name,
      image_url: p.image_url || null,
      expected_qty: qty,
    });
    toast.success("Product added.");
    await loadAsn();
    resetCatalogSearchState();
    addPanelOpen.value = false;
  } catch (e) {
    toast.errorFrom(e, "Could not add product.");
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
    await api.patch(`/asns/${asn.value.id}/lines/${line.id}`, { expected_qty: qty });
    line.expected_qty = qty;
    await loadAsn();
  } catch (e) {
    toast.errorFrom(e, "Could not update quantity.");
    await loadAsn();
  } finally {
    lineBusy.value = false;
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

function askDeleteLine(row) {
  lineToDelete.value = row;
  deleteLineOpen.value = true;
}

async function confirmDeleteLine() {
  if (!asn.value || !lineToDelete.value) return;
  lineBusy.value = true;
  try {
    await api.delete(`/asns/${asn.value.id}/lines/${lineToDelete.value.id}`);
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

function openPrintSlip() {
  openPdf(`/asns/${asnId.value}/packing-slip.pdf`, "Could not open packing slip PDF.");
}

function openPrintLabel() {
  openPdf(`/asns/${asnId.value}/identification-label.pdf`, "Could not open identification label PDF.");
}

function openPrintBarcode(line) {
  openPdf(`/asns/${asnId.value}/lines/${line.id}/barcode.pdf`, "Could not open barcode PDF.");
}

onMounted(() => {
  setCrmPageMeta({ title: "Save Rack | ASN", description: "ASN detail." });
  loadAsn();
  document.addEventListener("click", onDocClickLineMenu);
  document.addEventListener("click", onDocClickHeaderMenu);
  window.addEventListener("scroll", onWindowCloseLineMenu, true);
  window.addEventListener("resize", onWindowCloseLineMenu);
  window.addEventListener("scroll", closeHeaderMenu, true);
  window.addEventListener("resize", closeHeaderMenu);
});

onUnmounted(() => {
  document.removeEventListener("click", onDocClickLineMenu);
  document.removeEventListener("click", onDocClickHeaderMenu);
  window.removeEventListener("scroll", onWindowCloseLineMenu, true);
  window.removeEventListener("resize", onWindowCloseLineMenu);
  window.removeEventListener("scroll", closeHeaderMenu, true);
  window.removeEventListener("resize", closeHeaderMenu);
});
</script>

<template>
  <div v-if="loading" class="staff-page staff-page--wide py-5">
    <CrmLoadingSpinner message="Loading ASN…" />
  </div>
  <div v-else-if="!asn" class="staff-page staff-page--wide py-5 text-secondary">ASN not found.</div>
  <div v-else class="staff-page staff-page--wide user-asn-detail-page order-detail-page">
    <div class="staff-table-card staff-datatable-card staff-datatable-card--white user-asn-detail-page__header-shell mb-4">
      <div class="p-4 pb-3">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
          <div class="min-w-0">
            <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
              <h1 class="h4 mb-0 fw-semibold text-body">{{ asnHeading || "—" }}</h1>
              <span class="badge rounded-pill fw-medium" :class="statusBadgeClass(asn.status)">{{
                statusLabel(asn.status)
              }}</span>
            </div>
            <p class="small text-secondary mb-0 mt-2">Created {{ formatDateUs(asn.created_at) }}</p>
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
            <button type="button" class="btn btn-outline-secondary btn-sm fw-semibold" @click="openPrintSlip">
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
        <div id="user-asn-items" class="staff-table-card staff-datatable-card staff-datatable-card--white p-0 mb-4">
          <div class="px-4 py-3 border-bottom d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h2 class="h6 mb-0 fw-semibold">Products</h2>
            <button
              v-if="isDraft"
              type="button"
              class="btn btn-sm btn-primary staff-page-primary"
              @click="addPanelOpen = !addPanelOpen"
            >
              {{ addPanelOpen ? "Hide Add Products" : "Add Products" }}
            </button>
          </div>

          <div v-show="addPanelOpen">
            <div class="staff-table-toolbar border-bottom">
              <div class="staff-table-toolbar--row flex-wrap align-items-end gap-2 gap-md-3">
                <input
                  id="asn-catalog-search"
                  v-model.trim="catalogSearchDraft"
                  type="search"
                  class="form-control staff-toolbar-search staff-toolbar-search--inline"
                  placeholder="Search by SKU or name"
                  autocomplete="off"
                  aria-label="Search product catalog"
                  @keydown.enter.prevent="commitCatalogSearch"
                />
                <button
                  type="button"
                  class="btn btn-sm btn-outline-secondary fw-semibold staff-page-secondary"
                  :disabled="catalogLoading"
                  @click="commitCatalogSearch"
                >
                  Search
                </button>
                <button
                  v-if="catalogSearchDraft || catalogSearchCommitted"
                  type="button"
                  class="btn btn-sm btn-outline-secondary fw-semibold staff-page-secondary"
                  :disabled="catalogLoading"
                  @click="clearCatalogSearch"
                >
                  Clear
                </button>
                <button
                  type="button"
                  class="btn btn-sm btn-outline-secondary fw-semibold staff-page-secondary"
                  :disabled="catalogLoading || catalogLoadingMore || catalogRefreshing"
                  @click="refreshCatalogProducts"
                >
                  {{ catalogRefreshing ? "Refreshing…" : "Refresh Products" }}
                </button>
                <button
                  type="button"
                  class="btn btn-sm btn-outline-secondary fw-semibold staff-page-secondary"
                  @click="openAddNewSkuModal"
                >
                  Add New SKU
                </button>
              </div>
            </div>
            <div class="p-4 bg-body-tertiary border-bottom">
              <div v-if="catalogLoading" class="d-flex justify-content-center py-4">
                <CrmLoadingSpinner message="Loading products…" />
              </div>
              <template v-else>
                <p class="small fw-semibold mb-2">From catalog</p>
                <div class="asn-catalog-grid border rounded bg-white">
                  <div
                    v-for="p in catalog"
                    :key="catalogKey(p)"
                    class="asn-catalog-grid__row d-flex align-items-center gap-2 border-bottom py-2 px-2"
                  >
                    <img
                      v-if="p.image_url"
                      :src="p.image_url"
                      alt=""
                      class="asn-catalog-thumb"
                      loading="lazy"
                    />
                    <div v-else class="asn-catalog-thumb asn-catalog-thumb--empty" aria-hidden="true" />
                    <div class="min-w-0 flex-grow-1">
                      <div class="fw-semibold small text-truncate">{{ p.sku }}</div>
                      <div class="text-secondary small text-truncate">{{ p.name }}</div>
                    </div>
                    <div class="d-flex align-items-center gap-1 flex-shrink-0">
                      <label class="visually-hidden" :for="'catalog-qty-' + catalogKey(p)">Expected QTY</label>
                      <input
                        :id="'catalog-qty-' + catalogKey(p)"
                        type="number"
                        min="0"
                        class="form-control form-control-sm asn-catalog-grid__qty"
                        :value="catalogQty(p)"
                        @input="setCatalogQty(p, $event.target.value)"
                      />
                      <button
                        type="button"
                        class="btn btn-sm btn-primary staff-page-primary"
                        :disabled="lineBusy"
                        @click="addFromCatalog(p)"
                      >
                        Add
                      </button>
                    </div>
                  </div>
                  <div v-if="catalog.length === 0" class="p-3 small text-secondary">
                    <template v-if="catalogSearchCommitted">
                      No matches.
                      <button type="button" class="btn btn-link btn-sm p-0 align-baseline" @click="openAddNewSkuModal">
                        Add New SKU
                      </button>
                    </template>
                    <template v-else>No products on this page.</template>
                  </div>
                </div>
                <div class="d-flex justify-content-center mt-3" v-if="catalogPageInfo.has_next_page">
                  <div v-if="catalogSearchAutoLoading" class="small text-secondary py-1" aria-live="polite">
                    Searching More Matches…
                  </div>
                  <button
                    v-else
                    type="button"
                    class="btn btn-sm btn-outline-secondary fw-semibold staff-page-secondary px-4"
                    :disabled="catalogLoadingMore"
                    @click="loadMoreCatalog"
                  >
                    {{ catalogLoadingMore ? "Loading…" : "Load 50 More" }}
                  </button>
                </div>
              </template>
            </div>
          </div>

          <div class="table-responsive staff-table-wrap">
            <table class="table table-hover align-middle mb-0 staff-data-table">
              <thead class="table-light staff-table-head">
                <tr>
                  <th class="staff-table-head__th order-detail-page__items-col">Product</th>
                  <th class="staff-table-head__th text-end" style="width: 9rem">Expected QTY</th>
                  <th
                    v-if="isDraft"
                    class="staff-table-head__th text-center user-asn-detail-lines-actions-col"
                  >
                    Actions
                  </th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="line in asn.lines" :key="line.id">
                  <td class="order-detail-page__items-col">
                    <div class="order-detail-page__item-cell">
                      <img
                        v-if="line.image_url"
                        :src="line.image_url"
                        alt=""
                        class="asn-line-thumb"
                        loading="lazy"
                      />
                      <div v-else class="asn-line-thumb asn-line-thumb--empty" aria-hidden="true"></div>
                      <div class="order-detail-page__item-copy">
                        <RouterLink
                          v-if="inventoryDetailTo(line.sku)"
                          :to="inventoryDetailTo(line.sku)"
                          class="order-detail-page__item-name user-inv-table__sku-link text-decoration-none d-block"
                          :title="line.name"
                        >
                          {{ line.name || "—" }}
                        </RouterLink>
                        <div v-else class="order-detail-page__item-name" :title="line.name">{{ line.name || "—" }}</div>
                        <RouterLink
                          v-if="inventoryDetailTo(line.sku)"
                          :to="inventoryDetailTo(line.sku)"
                          class="order-detail-page__item-sku user-inv-table__sku-link text-decoration-none d-block"
                          :title="line.sku ? `SKU ${line.sku}` : undefined"
                        >
                          SKU {{ line.sku || "—" }}
                        </RouterLink>
                        <div v-else class="order-detail-page__item-sku" :title="line.sku ? `SKU ${line.sku}` : undefined">
                          SKU {{ line.sku || "—" }}
                        </div>
                      </div>
                    </div>
                  </td>
                  <td class="text-end align-middle">
                    <input
                      v-if="isDraft"
                      type="number"
                      min="0"
                      class="form-control form-control-sm text-end ms-auto asn-line-qty-input"
                      :value="line.expected_qty"
                      :disabled="lineBusy"
                      @change="saveLineExpectedQty(line, $event.target.value)"
                    />
                    <span v-else>{{ Number(line.expected_qty ?? 0).toLocaleString() }}</span>
                  </td>
                  <td v-if="isDraft" class="text-center user-asn-detail-lines-actions-cell" @click.stop>
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
                      <div
                        v-if="lineMenuOpenId != null && lineMenuOpenId == line.id"
                        data-row-actions
                        class="staff-row-menu overflow-hidden"
                        role="menu"
                        :style="lineMenuStyle"
                        @click.stop
                      >
                        <button
                          type="button"
                          class="staff-row-menu__item staff-row-menu__item--danger"
                          role="menuitem"
                          @click="askDeleteLineFromMenu(line)"
                        >
                          Remove
                        </button>
                        <button
                          type="button"
                          class="staff-row-menu__item"
                          role="menuitem"
                          @click="printBarcodeFromMenu(line)"
                        >
                          Print Barcode
                        </button>
                      </div>
                    </div>
                  </td>
                </tr>
                <tr v-if="!asn.lines?.length">
                  <td :colspan="isDraft ? 3 : 2" class="text-center text-secondary py-4">No products yet.</td>
                </tr>
              </tbody>
            </table>
          </div>
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
          <div v-for="(row, idx) in trackingDraft" :key="'trk' + idx" class="mb-2">
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
          <button type="button" class="btn btn-link btn-sm px-0 mb-2" @click="addTrackingRow">Add Row</button>
          <button
            type="button"
            class="btn btn-sm btn-primary staff-page-primary w-100"
            :disabled="trackingSaveBusy"
            @click="saveTrackings"
          >
            Save Tracking
          </button>
        </div>

        <div class="staff-table-card staff-datatable-card staff-datatable-card--white p-4">
          <h3 class="h6 fw-semibold mb-3">Vendor Details</h3>
          <div v-for="(row, idx) in vendorDraft" :key="'vnd' + idx" class="d-flex gap-1 mb-2">
            <input v-model="row.label" class="form-control form-control-sm" placeholder="Vendor line" />
            <button
              v-if="vendorDraft.length > 1"
              type="button"
              class="btn btn-sm btn-outline-secondary flex-shrink-0"
              aria-label="Remove vendor line"
              @click="removeVendorRow(idx)"
            >
              ×
            </button>
          </div>
          <button type="button" class="btn btn-link btn-sm px-0 mb-2" @click="addVendorRow">Add Row</button>
          <button
            type="button"
            class="btn btn-sm btn-primary staff-page-primary w-100"
            :disabled="vendorSaveBusy"
            @click="saveVendors"
          >
            Save Vendor Details
          </button>
        </div>

        <div class="staff-table-card staff-datatable-card staff-datatable-card--white p-4">
          <h3 class="h6 fw-semibold mb-3">Warehouse Notes</h3>
          <textarea v-model="notesDraft" class="form-control form-control-sm mb-2" rows="4" placeholder="Notes for warehouse staff" />
          <button
            type="button"
            class="btn btn-sm btn-primary staff-page-primary w-100"
            :disabled="notesSaveBusy"
            @click="saveNotes"
          >
            Save Notes
          </button>
        </div>

        <div class="staff-table-card staff-datatable-card staff-datatable-card--white p-4">
          <h3 class="h6 fw-semibold mb-3">Ship To</h3>
          <div class="user-asn-ship-to-block small">
            <p class="mb-1 fw-semibold text-body">{{ shipToAccountName }}</p>
            <p class="mb-1 fw-semibold text-body">{{ asnLabel || "—" }}</p>
            <p v-for="(ln, i) in SHIP_TO_ADDRESS_LINES" :key="i" class="mb-0 text-secondary">{{ ln }}</p>
          </div>
          <button type="button" class="btn btn-outline-secondary btn-sm mt-3 w-100" @click="openPrintLabel">
            Print Identification Label
          </button>
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
          v-if="headerMenuOpen"
          data-asn-header-actions
          class="staff-row-menu fixed z-[300] overflow-hidden"
          role="menu"
          :style="{ top: `${headerMenuRect.top}px`, left: `${headerMenuRect.left}px` }"
          @click.stop
        >
          <button
            v-if="isPending"
            type="button"
            class="staff-row-menu__item"
            role="menuitem"
            @click="openReopenEditFromMenu"
          >
            Edit ASN
          </button>
          <button type="button" class="staff-row-menu__item" role="menuitem" @click="openPrintSlip(); closeHeaderMenu()">
            Print Packing Slip
          </button>
          <button type="button" class="staff-row-menu__item" role="menuitem" @click="openPrintLabel(); closeHeaderMenu()">
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
    </Teleport>

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
              Enter how many boxes or pallets you are shipping. Tracking can be added now, updated later, or replaced with an identification label on each box.
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
                <input id="asn-trk-entered" v-model="markReadyTrackingMode" class="form-check-input" type="radio" value="entered" />
                <label class="form-check-label small" for="asn-trk-entered">I have tracking number(s)</label>
              </div>
              <div class="form-check mb-1">
                <input id="asn-trk-later" v-model="markReadyTrackingMode" class="form-check-input" type="radio" value="update_later" />
                <label class="form-check-label small" for="asn-trk-later">I will update tracking later</label>
              </div>
              <p v-if="markReadyTrackingMode === 'update_later'" class="small text-warning-emphasis bg-warning-subtle rounded p-2 mt-2 mb-2">
                Please upload tracking numbers before your shipment arrives at our warehouse. ASNs submitted without tracking information may slow down the receiving process and could be subject to a non-compliance administrative fee.
              </p>
              <div class="form-check">
                <input id="asn-trk-label" v-model="markReadyTrackingMode" class="form-check-input" type="radio" value="id_label" />
                <label class="form-check-label small" for="asn-trk-label">I will attach an ASN identification label to each box</label>
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

    <Teleport to="body">
      <Transition name="crm-vx-confirm">
        <div
          v-if="addNewSkuOpen"
          class="crm-vx-modal-overlay"
          role="dialog"
          aria-modal="true"
          aria-labelledby="asn-add-new-sku-title"
          @click.self="closeAddNewSkuModal"
        >
          <div class="crm-vx-modal crm-vx-modal--sm" @click.stop>
            <button
              type="button"
              class="crm-vx-modal__close"
              aria-label="Close"
              :disabled="addNewSkuBusy"
              @click="closeAddNewSkuModal"
            >
              <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
            <header class="crm-vx-modal__head">
              <h2 id="asn-add-new-sku-title" class="crm-vx-modal__title">Add New SKU</h2>
            </header>
            <div class="crm-vx-modal__body">
              <p class="small text-secondary mb-3">
                Add a product that is not in your ShipHero catalog yet.
              </p>
              <label class="form-label small mb-1" for="asn-new-sku-name">Product Name</label>
              <input
                id="asn-new-sku-name"
                v-model="addNewSkuName"
                type="text"
                class="form-control form-control-sm mb-3"
                :disabled="addNewSkuBusy"
              />
              <label class="form-label small mb-1" for="asn-new-sku-code">SKU</label>
              <input
                id="asn-new-sku-code"
                v-model="addNewSkuSku"
                type="text"
                class="form-control form-control-sm mb-3"
                :disabled="addNewSkuBusy"
              />
              <label class="form-label small mb-1" for="asn-new-sku-qty">Expected QTY</label>
              <input
                id="asn-new-sku-qty"
                v-model.number="addNewSkuQty"
                type="number"
                min="1"
                class="form-control form-control-sm"
                :disabled="addNewSkuBusy"
              />
            </div>
            <footer class="crm-vx-modal__footer d-flex gap-2 justify-content-end">
              <button
                type="button"
                class="crm-vx-modal-btn crm-vx-modal-btn--secondary"
                :disabled="addNewSkuBusy"
                @click="closeAddNewSkuModal"
              >
                Cancel
              </button>
              <button
                type="button"
                class="crm-vx-modal-btn crm-vx-modal-btn--primary"
                :disabled="addNewSkuBusy"
                @click="submitAddNewSku"
              >
                {{ addNewSkuBusy ? "Adding…" : "Add SKU" }}
              </button>
            </footer>
          </div>
        </div>
      </Transition>
    </Teleport>

    <ConfirmModal
      :open="reopenEditOpen"
      title="Edit ASN"
      message="Editing will move this ASN back to Draft so you can change products and shipment details. You will need to mark it as ready again when finished."
      confirm-label="Edit ASN"
      :busy="reopenEditBusy"
      @close="reopenEditOpen = false"
      @confirm="confirmReopenForEdit"
    />

    <ConfirmModal
      :open="deleteAsnOpen"
      title="Delete ASN"
      :message="asn ? `Delete ${asnHeading || asn.asn_number}? Only draft or pending ASNs can be removed.` : ''"
      confirm-label="Delete"
      :busy="deleteAsnBusy"
      danger
      @close="deleteAsnOpen = false"
      @confirm="confirmDeleteAsn"
    />

    <ConfirmModal
      :open="deleteLineOpen"
      title="Remove Line"
      message="Remove this line from the ASN?"
      confirm-label="Remove"
      :busy="lineBusy"
      danger
      @close="deleteLineOpen = false"
      @confirm="confirmDeleteLine"
    />
  </div>
</template>

<style scoped>
.asn-catalog-thumb,
.asn-line-thumb {
  width: 40px;
  height: 40px;
  border-radius: 0.35rem;
  object-fit: cover;
  border: 1px solid rgba(0, 0, 0, 0.08);
  background: #fff;
  flex-shrink: 0;
}

.asn-catalog-thumb--empty,
.asn-line-thumb--empty {
  display: block;
  background: rgba(0, 0, 0, 0.05);
}

.user-asn-detail-page .order-detail-page__item-cell {
  display: flex;
  align-items: center;
  gap: 0.65rem;
  min-width: 0;
}

.user-asn-detail-page .order-detail-page__item-copy {
  min-width: 0;
}

.user-asn-ship-to-block {
  line-height: 1.45;
}

.asn-catalog-grid {
  max-height: 280px;
  overflow: auto;
}

.asn-catalog-grid__qty {
  width: 4.5rem;
}

.asn-line-qty-input {
  max-width: 5.5rem;
}

.user-asn-detail-page :deep(.table-responsive.staff-table-wrap) {
  overflow-x: clip;
  max-width: 100%;
}

.user-asn-detail-page :deep(.staff-table-wrap .table.staff-data-table) {
  width: 100%;
  min-width: 0;
  table-layout: fixed;
}

.user-asn-detail-page .btn-outline-secondary:hover:not(:disabled),
.user-asn-detail-page .btn-outline-secondary:focus-visible {
  background-color: rgba(115, 103, 240, 0.06);
  border-color: rgba(115, 103, 240, 0.35);
  color: var(--bs-body-color);
}

[data-bs-theme="dark"] .user-asn-detail-page .btn-outline-secondary:hover:not(:disabled),
[data-bs-theme="dark"] .user-asn-detail-page .btn-outline-secondary:focus-visible {
  background-color: rgba(115, 103, 240, 0.12);
  border-color: rgba(186, 175, 255, 0.35);
  color: var(--bs-body-color);
}

.user-asn-detail-page :deep(.table.staff-data-table > thead > tr > th.user-asn-detail-lines-actions-col),
.user-asn-detail-page :deep(.table.staff-data-table > tbody > tr > td.user-asn-detail-lines-actions-cell) {
  text-align: center !important;
  width: 7rem;
  min-width: 7rem;
  max-width: 7rem;
}
</style>
