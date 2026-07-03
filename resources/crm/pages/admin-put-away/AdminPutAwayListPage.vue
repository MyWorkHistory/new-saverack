<script setup>
import { computed, onMounted, reactive, ref } from "vue";
import { RouterLink, useRouter } from "vue-router";
import api from "../../services/api";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import CrmSearchableSelect from "../../components/common/CrmSearchableSelect.vue";
import InventoryTransferQtyModal from "../../components/inventory/InventoryTransferQtyModal.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast.js";
import { formatDateTimeUs } from "../../utils/formatUserDates.js";

const LIST_PAGE_SIZE = 20;
const RECEIVING_LOCATION_NAME = "Receiving";
const PUT_AWAY_REASON = "Inbound Receiving Adjustments";

const toast = useToast();
const router = useRouter();

const rows = ref([]);
const loading = ref(false);
const loadingMore = ref(false);
const accountsLoading = ref(false);
const accounts = ref([]);
const selectedAccountId = ref("");
const searchDraft = ref("");
const searchCommitted = ref("");
const searchSkipNext = ref(0);
const pageInfo = ref({ has_next_page: false, end_cursor: null });
const meta = ref({ computed_at: null, row_count: 0, source: null, status: null });

const transferModalOpen = ref(false);
const transferLoading = ref(false);
const transferBusy = ref(false);
const transferRow = ref(null);
const transferPutAwayRow = ref(null);
const transferProduct = ref(null);
const transferFromLocation = ref(null);
const transferForm = reactive({
  transfer_type: "current",
  to_location_id: "",
  to_location: "",
  quantity: "",
  reason: PUT_AWAY_REASON,
});
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
const defaultTransferReason = ref(PUT_AWAY_REASON);

const accountOptions = computed(() =>
  (accounts.value || []).map((a) => ({
    id: a.id,
    name: a.company_name || `Account #${a.id}`,
    email: a.has_shiphero_customer ? "" : "(No ShipHero)",
  })),
);

const accountId = computed(() => Number(selectedAccountId.value || 0));
const hasAccountFilter = computed(() => accountId.value > 0);

const accountNameById = computed(() => {
  const map = new Map();
  for (const account of accounts.value || []) {
    map.set(account.id, account.company_name || `Account #${account.id}`);
  }
  return map;
});

function accountLabelForRow(row) {
  const id = Number(row?.client_account_id || 0);
  if (id <= 0) return "—";
  return accountNameById.value.get(id) || `Account #${id}`;
}

function accountDetailTo(accountIdValue) {
  const id = Number(accountIdValue || 0);
  return id > 0 ? { name: "client-account-detail", params: { id: String(id) } } : null;
}

function locationLabel(value) {
  const text = String(value || "").trim();
  return text || "—";
}

function hasBarcodeValue(barcode) {
  return String(barcode || "").trim() !== "";
}

function barcodeLineLabel(barcode) {
  return hasBarcodeValue(barcode) ? `Barcode: ${barcode}` : "Barcode: —";
}

const lastUpdatedLabel = computed(() => {
  const raw = meta.value?.computed_at;
  if (!raw) return null;
  const d = new Date(raw);
  if (Number.isNaN(d.getTime())) return null;
  return formatDateTimeUs(d);
});

const emptyTableMessage = computed(() => {
  if (hasAccountFilter.value) {
    return "No products in Receiving for this account. Receive inventory on an ASN to add SKUs here.";
  }
  return "No products in Receiving. Receive inventory on an ASN to add SKUs here.";
});

const transferAllLocations = computed(() => {
  const out = [];
  const p = transferProduct.value;
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

const transferDestinationOptions = computed(() => {
  const source = transferFromLocation.value;
  if (!source) return [];
  const whId = String(source.warehouse_id || "");
  const fromId = String(source.location_id || "");
  return transferAllLocations.value.filter(
    (loc) => String(loc.warehouse_id || "") === whId && String(loc.location_id || "") !== fromId,
  );
});

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

function receivingLocationFromResponses(putAwayRow, product) {
  const receivingQty = Math.max(
    Number(putAwayRow?.receiving_qty ?? 0),
    receivingQtyFromProduct(product),
  );
  const rowLoc = putAwayRow?.receiving_location;
  if (rowLoc?.location_id && rowLoc?.warehouse_id) {
    return {
      location_id: String(rowLoc.location_id),
      location_name: String(rowLoc.location_name || RECEIVING_LOCATION_NAME),
      warehouse_id: String(rowLoc.warehouse_id),
      quantity: receivingQty,
    };
  }

  const p = product;
  if (!p?.warehouses) return null;
  for (const wh of p.warehouses) {
    for (const loc of wh.locations || []) {
      if (!isReceivingLocation(loc)) continue;
      const locId = String(loc.location_id || "").trim();
      if (!locId) continue;
      return {
        ...loc,
        warehouse_id: wh.warehouse_id,
        warehouse_name: wh.warehouse_name,
        quantity: receivingQty,
      };
    }
  }
  return null;
}

function defaultTransferDestinationId(source) {
  if (!source) return "";
  const whId = String(source.warehouse_id || "");
  const fromId = String(source.location_id || "");
  const candidates = transferAllLocations.value.filter(
    (loc) =>
      String(loc.warehouse_id || "") === whId
      && String(loc.location_id || "") !== fromId
      && !isReceivingLocation(loc),
  );
  if (!candidates.length) return "";

  const pickableWithStock = candidates
    .filter((loc) => loc.pickable === true && Number(loc.quantity || 0) > 0)
    .sort((a, b) => Number(b.quantity || 0) - Number(a.quantity || 0));
  if (pickableWithStock.length > 0) {
    return String(pickableWithStock[0].location_id || "");
  }

  const pickable = candidates
    .filter((loc) => loc.pickable === true)
    .sort((a, b) =>
      String(a.location_name || a.location_id || "").localeCompare(
        String(b.location_name || b.location_id || ""),
      ),
    );
  if (pickable.length > 0) {
    return String(pickable[0].location_id || "");
  }

  const sorted = [...candidates].sort((a, b) => Number(b.quantity || 0) - Number(a.quantity || 0));
  return String(sorted[0].location_id || "");
}

function applyDefaultTransferDestination() {
  const destId = defaultTransferDestinationId(transferFromLocation.value);
  if (destId) {
    transferForm.transfer_type = "current";
    transferForm.to_location_id = destId;
    transferForm.to_location = "";
    return;
  }
  transferForm.transfer_type = "new";
  transferForm.to_location_id = "";
  transferForm.to_location = "";
}

function applyListPayload(data, append = false) {
  const nextRows = Array.isArray(data?.rows) ? data.rows : [];
  rows.value = append ? [...rows.value, ...nextRows] : nextRows;
  pageInfo.value = data?.page_info || { has_next_page: false, end_cursor: null };
  meta.value = data?.meta || meta.value;
  if (typeof data?.page_info?.next_search_skip === "number") {
    searchSkipNext.value = Number(data.page_info.next_search_skip);
  } else if (!append) {
    searchSkipNext.value = 0;
  }
}

function putAwayDetailTo(row) {
  const sku = String(row?.sku || "").trim();
  const clientAccountId = Number(row?.client_account_id || accountId.value || 0);
  if (!sku || clientAccountId <= 0) {
    return { name: "admin-put-away" };
  }
  return {
    name: "admin-put-away-detail",
    params: { sku },
    query: { client_account_id: String(clientAccountId) },
  };
}

function putAwayDetailHref(row) {
  const sku = String(row?.sku || "").trim();
  const clientAccountId = Number(row?.client_account_id || accountId.value || 0);
  if (!sku || clientAccountId <= 0) return "";
  return router.resolve(putAwayDetailTo(row)).href;
}

function openPutAwayInNewTab(row, event) {
  event?.preventDefault?.();
  const href = putAwayDetailHref(row);
  if (!href) return;
  window.open(href, "_blank", "noopener,noreferrer");
}

function canTransferRow(row) {
  return Number(row?.client_account_id || 0) > 0 && Number(row?.receiving_qty ?? 0) > 0;
}

async function loadAccounts() {
  accountsLoading.value = true;
  try {
    const { data } = await api.get("/inventory/client-account-options");
    accounts.value = Array.isArray(data?.accounts) ? data.accounts : [];
  } catch (e) {
    toast.errorFrom(e, "Could not load account list.");
  } finally {
    accountsLoading.value = false;
  }
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

async function fetchPage(append) {
  const params = {
    first: LIST_PAGE_SIZE,
  };
  if (hasAccountFilter.value) {
    params.client_account_id = accountId.value;
  }
  const q = searchCommitted.value.trim();
  if (q) {
    params.query = q;
  } else if (append && pageInfo.value?.end_cursor) {
    params.after = pageInfo.value.end_cursor;
  }
  const { data } = await api.get("/admin/put-away", { params });
  applyListPayload(data, append);
}

async function loadRows(reset = false) {
  if (reset) {
    loading.value = true;
    pageInfo.value = { has_next_page: false, end_cursor: null };
    searchSkipNext.value = 0;
  } else {
    loadingMore.value = true;
  }
  try {
    await fetchPage(!reset);
  } catch (e) {
    toast.errorFrom(e, "Could not load put away list.");
  } finally {
    loading.value = false;
    loadingMore.value = false;
  }
}

async function loadMore() {
  if (!pageInfo.value?.has_next_page || loadingMore.value || loading.value) return;
  await loadRows(false);
}

function commitSearch() {
  searchCommitted.value = searchDraft.value.trim();
  searchSkipNext.value = 0;
  loadRows(true);
}

function onAccountChange() {
  searchDraft.value = "";
  searchCommitted.value = "";
  searchSkipNext.value = 0;
  rows.value = [];
  loadRows(true);
}

async function openTransfer(row) {
  if (!canTransferRow(row)) {
    toast.error("No quantity in Receiving to transfer.");
    return;
  }
  const accountIdForRow = Number(row.client_account_id || 0);
  transferRow.value = row;
  transferPutAwayRow.value = null;
  transferProduct.value = null;
  transferFromLocation.value = null;
  transferForm.transfer_type = "current";
  transferForm.to_location_id = "";
  transferForm.to_location = "";
  transferForm.quantity = "";
  transferForm.reason = defaultTransferReason.value;
  transferModalOpen.value = true;
  transferLoading.value = true;
  transferBusy.value = false;
  try {
    const sku = encodeURIComponent(String(row.sku || "").trim());
    const [putAwayRes, productRes] = await Promise.all([
      api.get(`/admin/put-away/products/${sku}`, {
        params: { client_account_id: accountIdForRow },
      }),
      api.get(`/inventory/products/${sku}`, {
        params: { client_account_id: accountIdForRow },
      }),
    ]);
    transferPutAwayRow.value = putAwayRes.data?.row ?? null;
    transferProduct.value = productRes.data?.product ?? null;
    const fromLoc = receivingLocationFromResponses(transferPutAwayRow.value, transferProduct.value);
    if (!fromLoc) {
      transferModalOpen.value = false;
      toast.error("Receiving location not found for this SKU.");
      return;
    }
    transferFromLocation.value = fromLoc;
    applyDefaultTransferDestination();
    transferForm.quantity = String(fromLoc.quantity || row.receiving_qty || 0);
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
  const maxQty = Number(transferFromLocation.value.quantity || 0);
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
  transferBusy.value = true;
  try {
    const body = {
      sku: transferRow.value.sku,
      warehouse_id: transferFromLocation.value.warehouse_id,
      from_location_id: transferFromLocation.value.location_id,
      quantity: qty,
      reason: transferForm.reason,
      client_account_id: Number(transferRow.value.client_account_id || 0),
    };
    if (transferForm.transfer_type === "current") {
      body.to_location_id = String(transferForm.to_location_id).trim();
    } else {
      body.to_location = transferForm.to_location.trim();
    }
    await api.post("/inventory/transfer", body);
    transferModalOpen.value = false;
    toast.success("Quantity transferred.");
    await loadRows(true);
  } catch (e) {
    toast.errorFrom(e, "Could not transfer quantity.");
  } finally {
    transferBusy.value = false;
  }
}

onMounted(async () => {
  setCrmPageMeta({
    title: "Save Rack | Put Away",
    description: "Move inventory from Receiving to warehouse locations.",
  });
  await loadAccounts();
  await loadAdjustmentReasons();
  await loadRows(true);
});
</script>

<template>
  <div class="staff-page staff-page--wide admin-put-away-list-page">
    <div class="d-flex flex-column flex-md-row align-items-start align-items-md-center gap-3 mb-4">
      <div class="min-w-0 flex-grow-1">
        <h1 class="h4 mb-1 fw-bold text-body">Put Away</h1>
        <p class="text-secondary small mb-0">
          Products received into Receiving via ASN appear here. Optionally filter by account.
        </p>
      </div>
      <div class="d-flex align-items-center gap-2 flex-shrink-0 ms-md-auto">
        <p v-if="lastUpdatedLabel" class="small text-secondary mb-0">
          Last Updated: {{ lastUpdatedLabel }}
        </p>
      </div>
    </div>

    <div class="staff-table-card staff-datatable-card staff-datatable-card--white w-100 put-away-list-toolbar">
      <div class="staff-table-toolbar">
        <div class="staff-table-toolbar--row inventory-toolbar-row">
          <div class="inventory-toolbar-account flex-shrink-0">
            <CrmSearchableSelect
              v-model="selectedAccountId"
              class="staff-toolbar-search staff-toolbar-search--inline"
              appearance="staff"
              aria-label="Client account"
              :options="accountOptions"
              :disabled="accountsLoading"
              placeholder="All accounts"
              search-placeholder="Search accounts…"
              :allow-empty="true"
              button-id="put-away-list-account-trigger"
              @update:model-value="onAccountChange"
            />
          </div>
          <div class="user-inv-search-wrap flex-shrink-0">
            <div class="input-group orders-toolbar-search-group">
              <input
                v-model.trim="searchDraft"
                type="search"
                class="form-control"
                placeholder="Search by name, SKU, or barcode"
                autocomplete="off"
                enterkeyhint="search"
                aria-label="Search by name, SKU, or barcode"
                :disabled="loading"
                @keydown.enter.prevent="commitSearch"
              />
              <button
                type="button"
                class="btn btn-primary staff-page-primary orders-toolbar-search-btn"
                :disabled="loading"
                @click="commitSearch"
              >
                Search
              </button>
            </div>
          </div>
        </div>
      </div>

      <div v-if="loading && !rows.length" class="text-center py-5">
        <CrmLoadingSpinner label="Loading put away list…" />
      </div>

      <div v-else class="table-responsive staff-table-wrap">
        <table
          class="table table-hover align-middle mb-0 staff-data-table user-inv-table put-away-list-table"
          :class="{ 'put-away-list-table--syncing': loading || loadingMore }"
        >
          <thead class="table-light staff-table-head">
            <tr>
              <th class="staff-table-head__th order-detail-page__items-col" scope="col">Product</th>
              <th class="staff-table-head__th put-away-list-table__account-col" scope="col">Account</th>
              <th class="staff-table-head__th put-away-list-table__location-col" scope="col">Pickable Location</th>
              <th class="staff-table-head__th put-away-list-table__location-col" scope="col">Backstock Location</th>
              <th class="staff-table-head__th user-inv-table__num-col" scope="col">Receiving</th>
              <th class="staff-table-head__th user-inv-table__num-col" scope="col">Pickable</th>
              <th class="staff-table-head__th user-inv-table__num-col" scope="col">Non-Pickable</th>
              <th class="staff-table-head__th user-inv-table__num-col" scope="col">On-Hand</th>
              <th class="staff-table-head__th user-inv-table__num-col" scope="col">Backorder</th>
              <th class="staff-table-head__th text-center put-away-list-table__transfer-col" scope="col">Transfer</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="!rows.length">
              <td colspan="10" class="text-center text-secondary py-4">
                {{ emptyTableMessage }}
              </td>
            </tr>
            <tr v-for="row in rows" :key="`${row.client_account_id}-${row.sku}`">
              <td class="order-detail-page__items-col">
                <div class="order-detail-page__item-cell">
                  <a
                    v-if="putAwayDetailHref(row)"
                    :href="putAwayDetailHref(row)"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="asn-line-thumb-link text-decoration-none"
                    :aria-label="row.sku ? `View put away for SKU ${row.sku} in new tab` : undefined"
                    @click="openPutAwayInNewTab(row, $event)"
                  >
                    <img
                      v-if="row.image_url"
                      :src="row.image_url"
                      alt=""
                      class="asn-line-thumb asn-line-thumb--lg"
                      loading="lazy"
                    />
                    <div v-else class="asn-line-thumb asn-line-thumb--lg asn-line-thumb--empty" aria-hidden="true" />
                  </a>
                  <template v-else>
                    <img
                      v-if="row.image_url"
                      :src="row.image_url"
                      alt=""
                      class="asn-line-thumb asn-line-thumb--lg"
                      loading="lazy"
                    />
                    <div v-else class="asn-line-thumb asn-line-thumb--lg asn-line-thumb--empty" aria-hidden="true" />
                  </template>
                  <div class="order-detail-page__item-copy min-w-0">
                    <a
                      v-if="putAwayDetailHref(row)"
                      :href="putAwayDetailHref(row)"
                      target="_blank"
                      rel="noopener noreferrer"
                      class="order-detail-page__item-sku-title text-decoration-none put-away-list-table__sku-link"
                      :title="row.sku || undefined"
                      @click="openPutAwayInNewTab(row, $event)"
                    >
                      {{ row.sku || "—" }}
                    </a>
                    <div v-else class="order-detail-page__item-sku-title" :title="row.sku || undefined">
                      {{ row.sku || "—" }}
                    </div>
                    <div class="order-detail-page__item-name-sub" :title="row.name || undefined">
                      {{ row.name || "—" }}
                    </div>
                    <div class="order-detail-page__item-meta">
                      {{ barcodeLineLabel(row.barcode) }}
                    </div>
                  </div>
                </div>
              </td>
              <td class="put-away-list-table__account-col small">
                <RouterLink
                  v-if="accountDetailTo(row.client_account_id)"
                  :to="accountDetailTo(row.client_account_id)"
                  class="put-away-list-table__account-link"
                >
                  {{ accountLabelForRow(row) }}
                </RouterLink>
                <span v-else>—</span>
              </td>
              <td class="put-away-list-table__location-col small text-secondary">
                {{ locationLabel(row.pick_location) }}
              </td>
              <td class="put-away-list-table__location-col small text-secondary">
                {{ locationLabel(row.backstock_location) }}
              </td>
              <td class="user-inv-table__num-col text-center">{{ Number(row.receiving_qty ?? 0).toLocaleString() }}</td>
              <td class="user-inv-table__num-col text-center">{{ Number(row.pickable_qty ?? 0).toLocaleString() }}</td>
              <td class="user-inv-table__num-col text-center">{{ Number(row.non_pickable_qty ?? 0).toLocaleString() }}</td>
              <td class="user-inv-table__num-col text-center">{{ Number(row.on_hand ?? 0).toLocaleString() }}</td>
              <td class="user-inv-table__num-col text-center">{{ Number(row.backorder ?? 0).toLocaleString() }}</td>
              <td class="text-center put-away-list-table__transfer-col">
                <button
                  type="button"
                  class="btn btn-primary btn-sm staff-page-primary"
                  :disabled="!canTransferRow(row) || transferLoading || transferBusy"
                  @click="openTransfer(row)"
                >
                  Transfer
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="pageInfo.has_next_page" class="p-3 border-top text-center">
        <button
          type="button"
          class="btn btn-outline-secondary btn-sm orders-toolbar-outline-btn"
          :disabled="loadingMore || loading"
          @click="loadMore"
        >
          {{ loadingMore ? "Loading…" : "Load 20 More" }}
        </button>
      </div>
    </div>

    <InventoryTransferQtyModal
      :open="transferModalOpen"
      :busy="transferBusy"
      :loading="transferLoading"
      :from-location="transferFromLocation"
      v-model:transfer-type="transferForm.transfer_type"
      v-model:to-location-id="transferForm.to_location_id"
      v-model:to-location="transferForm.to_location"
      v-model:quantity="transferForm.quantity"
      v-model:reason="transferForm.reason"
      :destination-options="transferDestinationOptions"
      :reason-options="inventoryReasons"
      @close="transferModalOpen = false"
      @submit="submitTransfer"
      @transfer-all="fillTransferAllQty"
    />
  </div>
</template>

<style scoped>
.put-away-list-toolbar .inventory-toolbar-account {
  flex: 0 0 auto;
  width: min(280px, 100%);
}

.put-away-list-table {
  table-layout: fixed;
  width: 100%;
}

.put-away-list-table--syncing {
  opacity: 0.55;
  pointer-events: none;
}

.put-away-list-table .user-inv-table__num-col {
  min-width: 3.75rem;
  width: 3.75rem;
  padding-left: 0.2rem;
  padding-right: 0.2rem;
  font-size: 0.8125rem;
}

.put-away-list-table .put-away-list-table__transfer-col {
  width: 5.5rem;
  min-width: 5.5rem;
  padding-left: 0.25rem;
  padding-right: 0.25rem;
}

.put-away-list-table .put-away-list-table__account-col {
  width: 7rem;
  min-width: 5.5rem;
  vertical-align: middle;
}

.put-away-list-table .put-away-list-table__location-col {
  width: 6.5rem;
  min-width: 5rem;
  vertical-align: middle;
  word-break: break-word;
}

.put-away-list-table__account-link {
  color: #2563eb;
  text-decoration: none;
  font-weight: 500;
}

.put-away-list-table__account-link:hover {
  color: #1d4ed8;
  text-decoration: underline;
}

.put-away-list-table__sku-link {
  color: #2563eb;
}

.put-away-list-table__sku-link:hover {
  color: #1d4ed8;
  text-decoration: underline;
}

.admin-put-away-list-page .asn-line-thumb {
  width: 64px;
  height: 64px;
  border-radius: 0.4rem;
  object-fit: cover;
  border: 1px solid rgba(0, 0, 0, 0.08);
  background: #fff;
  flex-shrink: 0;
}

.admin-put-away-list-page .asn-line-thumb--lg {
  width: 72px;
  height: 72px;
}

.admin-put-away-list-page .asn-line-thumb--empty {
  display: block;
  background: rgba(0, 0, 0, 0.05);
}

.admin-put-away-list-page .order-detail-page__item-cell {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  min-width: 0;
}

.admin-put-away-list-page .order-detail-page__items-col {
  width: 28%;
  min-width: 14rem;
  vertical-align: middle;
}

.admin-put-away-list-page .order-detail-page__item-sku-title {
  font-size: 1rem;
  font-weight: 700;
  line-height: 1.35;
  color: var(--bs-body-color);
  word-break: break-word;
  margin-bottom: 0.2rem;
}

.admin-put-away-list-page .order-detail-page__item-name-sub {
  font-size: 0.8125rem;
  line-height: 1.4;
  color: var(--bs-secondary-color);
  word-break: break-word;
  margin-bottom: 0.35rem;
}

.admin-put-away-list-page .order-detail-page__item-meta {
  font-size: 0.8125rem;
  line-height: 1.4;
  color: var(--bs-secondary-color);
}

.admin-put-away-list-page .asn-line-thumb-link {
  flex-shrink: 0;
  line-height: 0;
}

.admin-put-away-list-page .asn-line-thumb-link:hover .asn-line-thumb {
  opacity: 0.88;
}
</style>
