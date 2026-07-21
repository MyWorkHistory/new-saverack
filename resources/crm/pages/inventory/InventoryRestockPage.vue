<script setup>
import { Transition, computed, inject, nextTick, onMounted, onUnmounted, reactive, ref, watch } from "vue";
import { RouterLink, useRouter } from "vue-router";
import api from "../../services/api";
import CrmIconRowActions from "../../components/common/CrmIconRowActions.vue";
import CrmSearchableSelect from "../../components/common/CrmSearchableSelect.vue";
import InventoryRestockTransferModal from "../../components/inventory/InventoryRestockTransferModal.vue";
import {
  RESTOCK_STATUS_COMPLETE,
  RESTOCK_STATUS_PENDING,
  RESTOCK_STATUS_TRANSFER_CART,
  TRANSFER_CART_LOCATIONS,
  isTransferCartLocationName,
  matchTransferCartCode,
  restockStatusBadgeClass,
  restockStatusLabel,
} from "../../constants/restockTransferCart.js";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast.js";
import { formatIsoDate } from "../../utils/formatUserDates.js";

const toast = useToast();
const router = useRouter();
const crmUser = inject("crmUser", ref(null));

const ENRICH_POLL_MS = 2000;
const LINE_MENU_W = 180;
const LINE_MENU_H = 120;

const rows = ref([]);
const loading = ref(false);
const accountsLoading = ref(false);
const accounts = ref([]);
const selectedAccountId = ref("");
const searchQuery = ref("");
const selectedStatus = ref("all");
const filterMenuOpen = ref(false);
const uploadModalOpen = ref(false);
const uploadBusy = ref(false);
const uploadFile = ref(null);
const meta = ref({
  original_filename: null,
  row_count: 0,
  active_row_count: 0,
  restock_needed_total: 0,
  uploaded_at: null,
  enrichment_status: "completed",
  enrichment_error: null,
});

const lineMenuSku = ref(null);
const lineMenuRect = ref({ top: 0, left: 0 });

const transferModalOpen = ref(false);
const transferBusy = ref(false);
const transferLoading = ref(false);
const transferRow = ref(null);
const transferProduct = ref(null);
const transferMode = ref("pending");
const transferFromLocationId = ref("");
const transferForm = reactive({
  destination_mode: "current",
  to_location_id: "",
  to_location: "",
  cart_location: "",
  quantity: "",
  reason: "Restock",
});

const inventoryReasons = ref([
  "Account Setup",
  "Client-Requested Adjustments",
  "Cycle Counts / Physical Counts",
  "Damaged Inventory",
  "Restock",
  "Returns Processing",
]);

let enrichPollTimer = null;

const canTransfer = computed(() => {
  const u = crmUser.value;
  if (!u) return false;
  const keys = Array.isArray(u.permission_keys) ? u.permission_keys : [];
  return (
    keys.includes("inventory_restock.update") ||
    keys.includes("inventory.update")
  );
});

const accountOptions = computed(() =>
  (accounts.value || [])
    .filter((a) => a?.has_shiphero_customer)
    .map((a) => ({
      id: a.id,
      name: a.company_name || `Account #${a.id}`,
      email: "",
    })),
);

const lastUploadDateLabel = computed(() => {
  const raw = meta.value.uploaded_at;
  if (!raw) return null;
  return formatIsoDate(raw);
});

const isEnriching = computed(() => {
  const s = String(meta.value.enrichment_status || "");
  return s === "pending" || s === "running";
});

const showEnrichmentBanner = computed(() => isEnriching.value);

const lineMenuRow = computed(
  () => rows.value.find((r) => String(r.sku) === String(lineMenuSku.value)) ?? null,
);

const transferFromOptions = computed(() => {
  const whId = preferredWarehouseId(transferProduct.value, transferRow.value);
  const all = flattenProductLocations(transferProduct.value, { includeEmpty: false });
  if (transferMode.value === RESTOCK_STATUS_TRANSFER_CART) {
    return buildCartFromOptions(transferProduct.value, transferRow.value);
  }
  return all.filter(
    (loc) =>
      loc.pickable === false &&
      (!whId || String(loc.warehouse_id || "") === whId),
  );
});

const transferFromLocation = computed(() => {
  const id = String(transferFromLocationId.value || "");
  if (!id) return null;
  return (
    transferFromOptions.value.find((loc) => String(loc.location_id || "") === id) || null
  );
});

const transferPickOptions = computed(() => {
  const source = transferFromLocation.value;
  const whId = source
    ? String(source.warehouse_id || "")
    : preferredWarehouseId(transferProduct.value, transferRow.value);
  const fromId = String(source?.location_id || "");
  return flattenProductLocations(transferProduct.value, { includeEmpty: true }).filter(
    (loc) =>
      loc.pickable === true &&
      (!whId || String(loc.warehouse_id || "") === whId) &&
      String(loc.location_id || "") !== fromId,
  );
});

watch(transferFromLocationId, (id, prev) => {
  if (String(id || "") === String(prev || "")) return;
  // Keep QTY blank until the user enters a value or clicks Transfer All.
  transferForm.to_location_id = "";
});

watch(
  () => transferForm.destination_mode,
  () => {
    transferForm.to_location_id = "";
    transferForm.to_location = "";
    transferForm.cart_location = "";
  },
);

const filteredRows = computed(() => {
  const accountId = Number(selectedAccountId.value || 0);
  const statusFilter = String(selectedStatus.value || "all").toLowerCase();
  const q = searchQuery.value.trim().toLowerCase();
  return rows.value.filter((row) => {
    if (accountId > 0 && Number(row?.client_account_id || 0) !== accountId) {
      return false;
    }
    if (statusFilter !== "all" && rowStatus(row) !== statusFilter) {
      return false;
    }
    if (!q) return true;
    const sku = String(row?.sku || "").toLowerCase();
    const name = String(row?.name || "").toLowerCase();
    const account = String(row?.account_name || "").toLowerCase();
    return sku.includes(q) || name.includes(q) || account.includes(q);
  });
});

function isEnrichmentActive(status) {
  const s = String(status || "");
  return s === "pending" || s === "running";
}

function splitLocationText(text) {
  const raw = String(text || "").trim();
  if (!raw || raw === "—") return [];
  return raw
    .split(/\s*[,;|]\s*|\n+/)
    .map((part) => part.trim())
    .filter((part) => part && part !== "—");
}

function pickLocationText(row) {
  if (!row || typeof row !== "object") return "";
  const primary = String(row.pick_location || "").trim();
  if (primary && primary !== "—") return primary;
  if (Array.isArray(row.pick_locations) && row.pick_locations.length) {
    return row.pick_locations.map((p) => String(p || "").trim()).filter(Boolean).join(", ");
  }
  return String(row.pickable_locations || "").trim();
}

function parseBackstockLocationName(text) {
  const raw = String(text || "").trim();
  if (!raw) return "";
  const match = raw.match(/^(.+?)\s*\(QTY:/i);
  return match ? match[1].trim() : raw;
}

function formatQty(value) {
  if (value === null || value === undefined || value === "") return "—";
  const n = Number(value);
  if (Number.isNaN(n)) return "—";
  return n.toLocaleString();
}

function formatLocationWithQty(loc) {
  const name = loc?.location_name || loc?.location_id || "—";
  const qty = Number(loc?.quantity ?? 0);
  return `${name} (QTY: ${qty.toLocaleString()})`;
}

function inventoryDetailHref(row) {
  const sku = String(row?.sku || "").trim();
  if (!sku) return "#";
  const accountId = Number(row?.client_account_id || 0);
  const query = accountId > 0 ? { client_account_id: String(accountId) } : {};
  return router.resolve({ name: "inventory-detail", params: { sku }, query }).href;
}

function accountDetailTo(accountId) {
  const id = Number(accountId || 0);
  if (id <= 0) return null;
  return { name: "client-account-detail", params: { id: String(id) } };
}

function preferredWarehouseId(product, row) {
  const fromRow = String(row?.warehouse_id || "").trim();
  if (fromRow) return fromRow;
  const warehouses = Array.isArray(product?.warehouses) ? product.warehouses : [];
  return String(warehouses[0]?.warehouse_id || "").trim();
}

function flattenProductLocations(product, { includeEmpty = false } = {}) {
  const out = [];
  const warehouses = Array.isArray(product?.warehouses) ? product.warehouses : [];
  warehouses.forEach((wh) => {
    (wh.locations || []).forEach((loc) => {
      const qty = Number(loc?.quantity || 0);
      if (!includeEmpty && qty <= 0) return;
      out.push({
        ...loc,
        quantity: qty,
        warehouse_id: wh.warehouse_id,
        warehouse_name: wh.warehouse_name,
      });
    });
  });
  return out;
}

function pickLocationsFromProduct(product, row = null) {
  const whId = preferredWarehouseId(product, row);
  return flattenProductLocations(product, { includeEmpty: true }).filter(
    (loc) =>
      loc.pickable === true &&
      (!whId || String(loc.warehouse_id || "") === whId),
  );
}

function defaultBackstockLocationId(product, row) {
  const locs = flattenProductLocations(product, { includeEmpty: false }).filter(
    (loc) => loc.pickable === false,
  );
  const names = splitLocationText(row?.backstock_locations).map(parseBackstockLocationName);
  for (const name of names) {
    const lower = name.toLowerCase();
    if (!lower) continue;
    const match = locs.find((loc) => {
      const locName = String(loc.location_name || loc.location_id || "").toLowerCase();
      return locName === lower || locName.includes(lower) || lower.includes(locName);
    });
    if (match) return String(match.location_id || "");
  }
  return locs[0] ? String(locs[0].location_id || "") : "";
}

function defaultCartLocationId(product, row) {
  const carts = buildCartFromOptions(product, row).filter((loc) => Number(loc.quantity || 0) > 0);
  if (carts[0]) return String(carts[0].location_id || "");
  const any = buildCartFromOptions(product, row);
  return any[0] ? String(any[0].location_id || "") : "";
}

/** Cart bins on the product, or fixed T-01…T-06 fallbacks for Mode B. */
function buildCartFromOptions(product, row) {
  const whId = preferredWarehouseId(product, row);
  const withQty = flattenProductLocations(product, { includeEmpty: false }).filter((loc) =>
    isTransferCartLocationName(loc.location_name || loc.location_id),
  );
  const preferred = whId
    ? withQty.filter((loc) => String(loc.warehouse_id || "") === whId)
    : withQty;
  if (preferred.length) return preferred;
  if (withQty.length) return withQty;

  const anyCart = flattenProductLocations(product, { includeEmpty: true }).filter((loc) =>
    isTransferCartLocationName(loc.location_name || loc.location_id),
  );
  const anyPreferred = whId
    ? anyCart.filter((loc) => String(loc.warehouse_id || "") === whId)
    : anyCart;
  if (anyPreferred.length) return anyPreferred;
  if (anyCart.length) return anyCart;

  return TRANSFER_CART_LOCATIONS.map((code) => ({
    location_id: code,
    location_name: code,
    quantity: 0,
    warehouse_id: whId,
    pickable: false,
  }));
}

function resolveCartDestination(product, row, cartCode) {
  const code = String(cartCode || "").trim().toUpperCase();
  if (!code) return null;
  const locs = flattenProductLocations(product, { includeEmpty: true });
  const match = locs.find((loc) => matchTransferCartCode(loc.location_name || loc.location_id) === code);
  if (match?.location_id) {
    return { to_location_id: String(match.location_id) };
  }
  return { to_location: code };
}

function rowStatus(row) {
  return String(row?.status || "pending").toLowerCase();
}

function canShowTransferAction(row) {
  if (!canTransfer.value) return false;
  return rowStatus(row) !== RESTOCK_STATUS_COMPLETE;
}

async function enrichMissingPickLocations(list) {
  const targets = (Array.isArray(list) ? list : []).filter((row) => {
    if (splitLocationText(pickLocationText(row)).length) return false;
    return Boolean(row?.sku) && Number(row?.client_account_id || 0) > 0;
  });
  if (!targets.length) return;

  const concurrency = 4;
  let index = 0;
  async function worker() {
    while (index < targets.length) {
      const current = index;
      index += 1;
      const row = targets[current];
      try {
        const { data } = await api.get(`/inventory/products/${encodeURIComponent(row.sku)}`, {
          params: { client_account_id: Number(row.client_account_id) },
        });
        const picks = pickLocationsFromProduct(data?.product, row);
        if (!picks.length) continue;
        const label = picks.map(formatLocationWithQty).join(", ");
        const match = rows.value.find(
          (r) =>
            String(r.sku) === String(row.sku) &&
            Number(r.client_account_id || 0) === Number(row.client_account_id || 0),
        );
        if (match && !splitLocationText(pickLocationText(match)).length) {
          match.pick_location = label;
        }
      } catch {
        /* ignore per-SKU enrichment failures */
      }
    }
  }
  await Promise.all(
    Array.from({ length: Math.min(concurrency, targets.length) }, () => worker()),
  );
}

function applySnapshot(data, { silent = false } = {}) {
  rows.value = Array.isArray(data?.rows) ? data.rows : [];
  meta.value = {
    original_filename: data?.original_filename ?? null,
    row_count: Number(data?.row_count || 0),
    active_row_count: Number(data?.active_row_count ?? rows.value.length),
    restock_needed_total: Number(data?.restock_needed_total || 0),
    uploaded_at: data?.uploaded_at ?? null,
    enrichment_status: data?.enrichment_status ?? "completed",
    enrichment_error: data?.enrichment_error ?? null,
  };
  scheduleEnrichmentPoll();
  if (!silent && meta.value.enrichment_status === "failed" && meta.value.enrichment_error) {
    toast.error(meta.value.enrichment_error);
  }
  for (const row of rows.value) {
    const transferError = String(row?.transfer_error || "").trim();
    if (transferError) {
      toast.error(transferError);
    }
  }
  if (!isEnrichmentActive(meta.value.enrichment_status)) {
    nextTick(() => {
      enrichMissingPickLocations(rows.value);
    });
  }
}

function stopEnrichmentPoll() {
  if (enrichPollTimer !== null) {
    clearInterval(enrichPollTimer);
    enrichPollTimer = null;
  }
}

function scheduleEnrichmentPoll() {
  stopEnrichmentPoll();
  if (!isEnrichmentActive(meta.value.enrichment_status)) return;
  enrichPollTimer = setInterval(async () => {
    try {
      const { data } = await api.get("/inventory/restock-beta");
      applySnapshot(data, { silent: true });
      if (!isEnrichmentActive(data?.enrichment_status)) {
        stopEnrichmentPoll();
      }
    } catch {
      /* ignore transient poll errors */
    }
  }, ENRICH_POLL_MS);
}

async function loadAccounts() {
  accountsLoading.value = true;
  try {
    const { data } = await api.get("/inventory/client-account-options");
    accounts.value = Array.isArray(data?.accounts) ? data.accounts : [];
  } catch {
    accounts.value = [];
  } finally {
    accountsLoading.value = false;
  }
}

async function loadSnapshot({ showSpinner = true } = {}) {
  if (showSpinner) loading.value = true;
  try {
    const { data } = await api.get("/inventory/restock-beta");
    applySnapshot(data, { silent: true });
  } catch (e) {
    toast.errorFrom(e, "Could not load restock data.");
  } finally {
    if (showSpinner) loading.value = false;
  }
}

function openUploadModal() {
  uploadFile.value = null;
  uploadModalOpen.value = true;
}

function closeUploadModal(force = false) {
  if (uploadBusy.value && !force) return;
  uploadModalOpen.value = false;
  uploadFile.value = null;
}

function onUploadFileChange(event) {
  uploadFile.value = event.target.files?.[0] ?? null;
}

async function submitUpload() {
  if (!uploadFile.value) {
    toast.error("Choose a CSV file to upload.");
    return;
  }

  uploadBusy.value = true;
  try {
    const formData = new FormData();
    formData.append("file", uploadFile.value);
    const { data } = await api.post("/inventory/restock-beta/import", formData, {
      headers: { "Content-Type": "multipart/form-data" },
    });
    applySnapshot(data);
    toast.success(`Uploaded ${Number(data?.active_row_count ?? data?.row_count ?? 0).toLocaleString()} rows.`);
    closeUploadModal(true);
  } catch (e) {
    toast.errorFrom(e, "Could not upload CSV.");
  } finally {
    uploadBusy.value = false;
  }
}

function closeLineMenu() {
  lineMenuSku.value = null;
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

async function toggleLineMenu(sku, e) {
  e?.stopPropagation?.();
  const key = String(sku || "");
  if (lineMenuSku.value === key) {
    lineMenuSku.value = null;
    return;
  }
  const btn = e?.currentTarget;
  lineMenuSku.value = key;
  await nextTick();
  requestAnimationFrame(() => {
    if (btn instanceof HTMLElement) placeLineMenu(btn);
  });
}

function onDocClickMenus(e) {
  if (!e.target?.closest?.("[data-restock-row-actions]")) {
    lineMenuSku.value = null;
  }
  if (!e.target?.closest?.("[data-toolbar-filter]")) {
    filterMenuOpen.value = false;
  }
}

function resetStatusFilter() {
  selectedStatus.value = "all";
  filterMenuOpen.value = false;
}

function onRowMenuClick(sku, e) {
  e?.stopPropagation?.();
  toggleLineMenu(sku, e);
}

async function removeRowFromMenu(row) {
  if (!row?.sku) return;
  closeLineMenu();
  try {
    const { data } = await api.post("/inventory/restock-beta/status", {
      sku: row.sku,
      status: RESTOCK_STATUS_COMPLETE,
    });
    applySnapshot(data, { silent: true });
    toast.success("Marked complete.");
  } catch (e) {
    toast.errorFrom(e, "Could not update status.");
  }
}

async function openTransferFromMenu(row) {
  if (!row?.sku) return;
  closeLineMenu();
  if (rowStatus(row) === RESTOCK_STATUS_COMPLETE) {
    toast.error("This restock row is already complete.");
    return;
  }
  const accountId = Number(row.client_account_id || 0);
  if (accountId <= 0) {
    toast.error("Account not matched yet. Wait for product matching to finish.");
    return;
  }
  const mode =
    rowStatus(row) === RESTOCK_STATUS_TRANSFER_CART
      ? RESTOCK_STATUS_TRANSFER_CART
      : "pending";
  transferRow.value = row;
  transferProduct.value = null;
  transferMode.value = mode;
  transferFromLocationId.value = "";
  transferForm.destination_mode = "current";
  transferForm.to_location_id = "";
  transferForm.to_location = "";
  transferForm.cart_location = "";
  transferForm.quantity = "";
  transferForm.reason = "Restock";
  transferModalOpen.value = true;
  transferLoading.value = true;
  transferBusy.value = false;
  try {
    // Prefer DB product-detail cache (omit refresh) so the modal opens instantly.
    let { data } = await api.get(`/inventory/products/${encodeURIComponent(row.sku)}`, {
      params: { client_account_id: accountId },
    });
    let product = data?.product ?? null;
    const hasLocations =
      Array.isArray(product?.warehouses) &&
      product.warehouses.some((wh) => Array.isArray(wh?.locations) && wh.locations.length > 0);
    if (!hasLocations) {
      // Soft fallback: one live refresh when cache is empty.
      ({ data } = await api.get(`/inventory/products/${encodeURIComponent(row.sku)}`, {
        params: { client_account_id: accountId, refresh: 1 },
      }));
      product = data?.product ?? null;
    }
    transferProduct.value = product;
    const fromId =
      mode === RESTOCK_STATUS_TRANSFER_CART
        ? defaultCartLocationId(transferProduct.value, row)
        : defaultBackstockLocationId(transferProduct.value, row);
    if (!fromId) {
      transferModalOpen.value = false;
      toast.error(
        mode === RESTOCK_STATUS_TRANSFER_CART
          ? "No transfer cart location found for this SKU."
          : "No backstock location found for this SKU in ShipHero.",
      );
      return;
    }
    transferFromLocationId.value = fromId;
    // Leave QTY blank — user enters qty or clicks Transfer All.
    transferForm.quantity = "";
    const picks = pickLocationsFromProduct(transferProduct.value, row);
    if (picks.length && !splitLocationText(pickLocationText(row)).length) {
      row.pick_location = picks.map(formatLocationWithQty).join(", ");
    }
  } catch (e) {
    transferModalOpen.value = false;
    toast.errorFrom(e, "Could not load product for transfer.");
  } finally {
    transferLoading.value = false;
  }
}

function fillTransferAllQty() {
  transferForm.quantity = String(transferFromLocation.value?.quantity ?? 0);
}

async function submitTransfer() {
  if (!transferRow.value || !transferFromLocation.value) return;
  const qty = parseInt(String(transferForm.quantity || ""), 10);
  if (Number.isNaN(qty) || qty <= 0) {
    toast.error("Enter a valid transfer quantity.");
    return;
  }

  const destMode = String(transferForm.destination_mode || "current");
  const body = {
    sku: transferRow.value.sku,
    warehouse_id: transferFromLocation.value.warehouse_id,
    from_location_id: transferFromLocation.value.location_id,
    quantity: qty,
    reason: transferForm.reason,
    background: 1,
  };
  const accountId = Number(transferRow.value.client_account_id || 0);
  if (accountId > 0) {
    body.client_account_id = accountId;
  }

  let nextStatus = RESTOCK_STATUS_COMPLETE;
  if (transferMode.value === "pending" && destMode === "cart") {
    const cartCode = String(transferForm.cart_location || "").trim();
    if (!cartCode) {
      toast.error("Select a transfer cart location.");
      return;
    }
    const dest = resolveCartDestination(transferProduct.value, transferRow.value, cartCode);
    if (dest?.to_location_id) {
      body.to_location_id = dest.to_location_id;
    } else {
      body.to_location = dest?.to_location || cartCode;
    }
    nextStatus = RESTOCK_STATUS_TRANSFER_CART;
  } else if (destMode === "new") {
    if (!String(transferForm.to_location || "").trim()) {
      toast.error("Enter destination location.");
      return;
    }
    body.to_location = String(transferForm.to_location).trim();
  } else {
    if (!String(transferForm.to_location_id || "").trim()) {
      toast.error("Select a pick location.");
      return;
    }
    body.to_location_id = String(transferForm.to_location_id).trim();
  }

  const row = transferRow.value;
  const previousStatus = rowStatus(row);
  body.restock_previous_status = previousStatus;

  // Close immediately and optimistically update status while ShipHero runs in background.
  transferModalOpen.value = false;
  transferBusy.value = false;
  row.status = nextStatus;
  row.status_label =
    nextStatus === RESTOCK_STATUS_TRANSFER_CART
      ? "Transfer"
      : nextStatus === RESTOCK_STATUS_COMPLETE
        ? "Complete"
        : "Pending";
  toast.success(
    nextStatus === RESTOCK_STATUS_TRANSFER_CART
      ? "Transferred to cart."
      : "Transferred and marked complete.",
  );

  try {
    const { data: statusData } = await api.post("/inventory/restock-beta/status", {
      sku: row.sku,
      status: nextStatus,
    });
    applySnapshot(statusData, { silent: true });
  } catch (e) {
    row.status = previousStatus;
    toast.errorFrom(e, "Could not update restock status.");
    return;
  }

  try {
    await api.post("/inventory/transfer", body);
    // Pick up any async failure rollback / transfer_error toast.
    window.setTimeout(() => {
      loadSnapshot({ showSpinner: false });
    }, 4000);
  } catch (e) {
    try {
      const { data } = await api.post("/inventory/restock-beta/status", {
        sku: row.sku,
        status: previousStatus,
      });
      applySnapshot(data, { silent: true });
    } catch {
      row.status = previousStatus;
    }
    toast.errorFrom(e, "Could not transfer quantity.");
  }
}

async function loadAdjustmentReasons() {
  try {
    const { data } = await api.get("/inventory/adjustment-reasons");
    const reasons = Array.isArray(data?.reasons) ? data.reasons.filter(Boolean) : [];
    if (reasons.length) inventoryReasons.value = reasons;
    const defaultReason = String(data?.default_transfer_reason || "").trim();
    if (defaultReason) transferForm.reason = defaultReason;
  } catch {
    /* keep fallback list */
  }
}

onMounted(() => {
  setCrmPageMeta({
    title: "Save Rack | Inventory | Restocks",
    description: "Inventory needing replenishment.",
  });
  loadAccounts();
  loadSnapshot();
  loadAdjustmentReasons();
  document.addEventListener("click", onDocClickMenus);
});

onUnmounted(() => {
  stopEnrichmentPoll();
  document.removeEventListener("click", onDocClickMenus);
});
</script>

<template>
  <div class="staff-page staff-page--wide">
    <div class="d-flex flex-column flex-md-row align-items-start align-items-md-center gap-3 mb-4">
      <div class="min-w-0 flex-grow-1">
        <h1 class="h4 fw-semibold text-body mb-1">Restocks</h1>
        <p class="text-secondary small mb-0">Inventory Needing Replenishment</p>
      </div>
      <div class="d-flex flex-column align-items-md-end gap-2 flex-shrink-0 ms-md-auto">
        <div class="d-flex flex-wrap align-items-center gap-3 justify-content-md-end">
          <p v-if="lastUploadDateLabel" class="small text-secondary mb-0">
            Last Upload: {{ lastUploadDateLabel }}
          </p>
          <button
            type="button"
            class="btn btn-outline-secondary btn-sm orders-toolbar-outline-btn"
            @click="openUploadModal"
          >
            Upload CSV
          </button>
        </div>
      </div>
    </div>

    <div class="staff-table-card staff-datatable-card staff-datatable-card--white w-100">
      <div
        v-if="showEnrichmentBanner"
        class="user-inv-sync-banner small text-secondary px-3 py-2 border-bottom bg-body-tertiary"
        role="status"
        aria-live="polite"
      >
        Refreshing product details…
      </div>

      <div class="staff-table-toolbar">
        <div class="staff-table-toolbar--row">
          <div class="restock-toolbar-account flex-shrink-0">
            <CrmSearchableSelect
              v-model="selectedAccountId"
              class="staff-toolbar-search staff-toolbar-search--inline"
              appearance="staff"
              aria-label="Client account"
              :options="accountOptions"
              :disabled="accountsLoading || loading"
              placeholder="All accounts"
              search-placeholder="Search accounts…"
              :allow-empty="true"
              empty-label="All accounts"
              button-id="restock-account-trigger"
            />
          </div>
          <input
            v-model="searchQuery"
            type="search"
            class="form-control staff-toolbar-search staff-toolbar-search--inline"
            placeholder="Search SKU, name, or account"
            autocomplete="off"
          />
          <div class="position-relative flex-shrink-0" data-toolbar-filter>
            <button
              type="button"
              class="btn btn-outline-secondary staff-toolbar-btn d-inline-flex align-items-center gap-2"
              :aria-expanded="filterMenuOpen ? 'true' : 'false'"
              @click.stop="filterMenuOpen = !filterMenuOpen"
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
              v-if="filterMenuOpen"
              class="dropdown-menu dropdown-menu-end show shadow border p-0 staff-toolbar-filter-dropdown"
              role="dialog"
              aria-label="Restock filters"
              @click.stop
            >
              <div class="staff-toolbar-filter-dropdown__head">
                <span>Filters</span>
                <button
                  type="button"
                  class="btn btn-link btn-sm text-secondary text-decoration-none p-0"
                  @click="resetStatusFilter"
                >
                  Reset
                </button>
              </div>
              <div class="staff-toolbar-filter-dropdown__body">
                <label class="form-label" for="restock-filter-status">Status</label>
                <select
                  id="restock-filter-status"
                  v-model="selectedStatus"
                  class="form-select staff-datatable-filters__select"
                >
                  <option value="all">All</option>
                  <option :value="RESTOCK_STATUS_PENDING">Pending</option>
                  <option :value="RESTOCK_STATUS_TRANSFER_CART">Transfer</option>
                  <option :value="RESTOCK_STATUS_COMPLETE">Complete</option>
                </select>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="table-responsive staff-table-wrap">
        <table class="table table-hover align-middle mb-0 staff-data-table user-inv-table">
          <thead class="table-light staff-table-head">
            <tr>
              <th class="staff-table-head__th text-center" scope="col">Status</th>
              <th class="staff-table-head__th user-inv-table__text-col" scope="col">Product</th>
              <th class="staff-table-head__th" scope="col">Account</th>
              <th class="staff-table-head__th text-center" scope="col">On Hand</th>
              <th class="staff-table-head__th text-center" scope="col">Allocated</th>
              <th class="staff-table-head__th text-center" scope="col">Pickable QTY</th>
              <th class="staff-table-head__th text-center" scope="col">Backstock</th>
              <th class="staff-table-head__th" scope="col">Backstock Locations</th>
              <th class="staff-table-head__th" scope="col">Pick Location</th>
              <th class="staff-table-head__th staff-actions-col text-center restock-actions-col" scope="col">
                Actions
              </th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="loading">
              <td colspan="10" class="py-5 text-center text-secondary">Loading restock data…</td>
            </tr>
            <tr v-else-if="!rows.length">
              <td colspan="10" class="py-5 text-center text-secondary">Upload a restock CSV to get started.</td>
            </tr>
            <tr v-else-if="!filteredRows.length">
              <td colspan="10" class="py-5 text-center text-secondary">No rows match your search.</td>
            </tr>
            <tr v-for="row in filteredRows" :key="row.sku" class="align-middle">
              <td class="text-center">
                <span
                  class="staff-status-badge text-capitalize"
                  :class="restockStatusBadgeClass(row.status)"
                >
                  {{ row.status_label || restockStatusLabel(row.status) }}
                </span>
              </td>
              <td class="user-inv-table__text-col">
                <div class="restock-product">
                  <a
                    :href="inventoryDetailHref(row)"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="restock-product__thumb-link user-inv-table__image-link"
                    :aria-label="`View ${row.sku || 'product'}`"
                  >
                    <img
                      v-if="row.image_url"
                      :src="row.image_url"
                      alt=""
                      class="user-inventory-thumb"
                      loading="lazy"
                    />
                    <div v-else class="user-inventory-thumb user-inventory-thumb--empty" />
                  </a>
                  <div class="restock-product__text min-w-0">
                    <a
                      :href="inventoryDetailHref(row)"
                      target="_blank"
                      rel="noopener noreferrer"
                      class="restock-product__sku user-inv-table__sku-link"
                    >
                      {{ row.sku || "—" }}
                    </a>
                    <div class="restock-product__name text-secondary small">{{ row.name || "—" }}</div>
                  </div>
                </div>
              </td>
              <td>
                <RouterLink
                  v-if="accountDetailTo(row.client_account_id)"
                  :to="accountDetailTo(row.client_account_id)"
                  class="text-decoration-none"
                >
                  {{ row.account_name }}
                </RouterLink>
                <span v-else class="text-secondary">—</span>
              </td>
              <td class="text-center">{{ formatQty(row.on_hand) }}</td>
              <td class="text-center">{{ formatQty(row.allocated) }}</td>
              <td class="text-center">{{ formatQty(row.pickable_qty) }}</td>
              <td class="text-center">{{ formatQty(row.backstock_qty) }}</td>
              <td class="restock-locations-col">
                <template v-if="splitLocationText(row.backstock_locations).length">
                  <div
                    v-for="(location, index) in splitLocationText(row.backstock_locations)"
                    :key="`${row.sku}-backstock-${index}`"
                    class="restock-loc-row small text-secondary"
                  >
                    {{ location }}
                  </div>
                </template>
                <span v-else class="text-secondary">—</span>
              </td>
              <td class="restock-locations-col">
                <template v-if="splitLocationText(pickLocationText(row)).length">
                  <div
                    v-for="(location, index) in splitLocationText(pickLocationText(row))"
                    :key="`${row.sku}-pick-${index}`"
                    class="restock-loc-row small text-secondary"
                  >
                    {{ location }}
                  </div>
                </template>
                <span v-else class="text-secondary">—</span>
              </td>
              <td class="staff-actions-cell text-center restock-actions-cell" @click.stop>
                <div
                  v-if="rowStatus(row) !== RESTOCK_STATUS_COMPLETE"
                  data-restock-row-actions
                  class="staff-actions-inner staff-actions-inner--single restock-actions-inner justify-content-center"
                >
                  <button
                    type="button"
                    class="staff-action-btn staff-action-btn--more"
                    :class="{ 'is-open': lineMenuSku === row.sku }"
                    aria-haspopup="true"
                    :aria-expanded="lineMenuSku === row.sku ? 'true' : 'false'"
                    aria-label="Row actions"
                    @click.stop="onRowMenuClick(row.sku, $event)"
                  >
                    <CrmIconRowActions variant="horizontal" />
                  </button>
                </div>
                <span v-else class="text-secondary">—</span>
              </td>
            </tr>
          </tbody>
        </table>
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
          v-if="lineMenuRow"
          data-restock-row-actions
          class="staff-row-menu fixed z-[300] overflow-hidden"
          role="menu"
          :style="{ top: `${lineMenuRect.top}px`, left: `${lineMenuRect.left}px` }"
          @click.stop
        >
          <button
            v-if="canShowTransferAction(lineMenuRow)"
            type="button"
            class="staff-row-menu__item"
            role="menuitem"
            @click="openTransferFromMenu(lineMenuRow)"
          >
            Transfer
          </button>
          <button
            type="button"
            class="staff-row-menu__item staff-row-menu__item--danger"
            role="menuitem"
            @click="removeRowFromMenu(lineMenuRow)"
          >
            Remove
          </button>
        </div>
      </Transition>
    </Teleport>

    <InventoryRestockTransferModal
      :open="transferModalOpen"
      :busy="transferBusy"
      :loading="transferLoading"
      :mode="transferMode"
      :from-options="transferFromOptions"
      v-model:from-location-id="transferFromLocationId"
      v-model:destination-mode="transferForm.destination_mode"
      v-model:to-location-id="transferForm.to_location_id"
      v-model:to-location="transferForm.to_location"
      v-model:cart-location="transferForm.cart_location"
      v-model:quantity="transferForm.quantity"
      v-model:reason="transferForm.reason"
      :pick-options="transferPickOptions"
      :reason-options="inventoryReasons"
      @close="transferModalOpen = false"
      @submit="submitTransfer"
      @transfer-all="fillTransferAllQty"
    />

    <Teleport to="body">
      <Transition name="crm-vx-confirm">
        <div
          v-if="uploadModalOpen"
          class="crm-vx-modal-overlay"
          role="dialog"
          aria-modal="true"
          @click.self="closeUploadModal"
        >
          <div class="crm-vx-modal crm-vx-modal--sm" @click.stop>
            <header class="crm-vx-modal__head border-bottom">
              <h2 class="crm-vx-modal__title mb-0">Upload Restock CSV</h2>
            </header>
            <div class="crm-vx-modal__body">
              <label class="form-label" for="restock-upload-file">CSV File</label>
              <input
                id="restock-upload-file"
                type="file"
                class="form-control"
                accept=".csv,text/csv,text/plain"
                @change="onUploadFileChange"
              />
            </div>
            <footer class="crm-vx-modal__footer d-flex gap-2 justify-content-end">
              <button
                type="button"
                class="crm-vx-modal-btn crm-vx-modal-btn--secondary"
                :disabled="uploadBusy"
                @click="closeUploadModal"
              >
                Cancel
              </button>
              <button
                type="button"
                class="crm-vx-modal-btn crm-vx-modal-btn--primary"
                :disabled="uploadBusy"
                @click="submitUpload"
              >
                {{ uploadBusy ? "Uploading…" : "Upload CSV" }}
              </button>
            </footer>
          </div>
        </div>
      </Transition>
    </Teleport>
  </div>
</template>

<style scoped>
.restock-toolbar-account {
  min-width: 12rem;
  max-width: 16rem;
}

.restock-product {
  display: flex;
  align-items: flex-start;
  gap: 0.75rem;
  max-width: min(20rem, 32vw);
}

.restock-product__thumb-link {
  flex-shrink: 0;
}

.restock-product__text {
  flex: 1;
  min-width: 0;
}

.user-inventory-thumb {
  width: 52px;
  height: 52px;
  border-radius: 0.4rem;
  object-fit: cover;
  border: 1px solid rgba(0, 0, 0, 0.08);
  background: #fff;
}

.user-inventory-thumb--empty {
  display: inline-block;
  background: rgba(0, 0, 0, 0.05);
}

.restock-product__sku {
  display: block;
  font-size: 1rem;
  font-weight: 600;
  line-height: 1.35;
  margin-bottom: 0.15rem;
  word-break: break-word;
}

.restock-product__name {
  display: -webkit-box;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
  overflow: hidden;
  line-height: 1.35;
  word-break: break-word;
}

.restock-locations-col {
  min-width: 10rem;
  max-width: min(14rem, 22vw);
}

.restock-loc-row + .restock-loc-row {
  margin-top: 0.25rem;
}

:deep(.table.staff-data-table > thead > tr > th.restock-actions-col),
:deep(.table.staff-data-table > tbody > tr > td.restock-actions-cell) {
  text-align: center !important;
}

:deep(.restock-actions-inner) {
  justify-content: center !important;
  width: 100%;
}

:deep(.user-inv-table__text-col) {
  max-width: min(16rem, 28vw);
}

:deep(.table-responsive.staff-table-wrap) {
  overflow-x: auto;
}
</style>
