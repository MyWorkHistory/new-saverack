<script setup>
import { Transition, computed, inject, nextTick, onMounted, onUnmounted, reactive, ref } from "vue";
import { RouterLink, useRouter } from "vue-router";
import api from "../../services/api";
import CrmIconRowActions from "../../components/common/CrmIconRowActions.vue";
import CrmSearchableSelect from "../../components/common/CrmSearchableSelect.vue";
import InventoryTransferQtyModal from "../../components/inventory/InventoryTransferQtyModal.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast.js";
import { formatDateTimeUs, formatIsoDate } from "../../utils/formatUserDates.js";

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
const transferRow = ref(null);
const transferProduct = ref(null);
const transferFromLocation = ref(null);
const transferForm = reactive({
  transfer_type: "current",
  to_location_id: "",
  to_location: "",
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
  return Array.isArray(u.permission_keys) && u.permission_keys.includes("inventory.update");
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

const uploadedAtLabel = computed(() => {
  const raw = meta.value.uploaded_at;
  if (!raw) return null;
  const d = new Date(raw);
  if (Number.isNaN(d.getTime())) return null;
  return formatDateTimeUs(d);
});

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

const transferDestinationOptions = computed(() => {
  const source = transferFromLocation.value;
  if (!source) return [];
  const whId = String(source.warehouse_id || "");
  const fromId = String(source.location_id || "");
  return flattenProductLocations(transferProduct.value).filter(
    (loc) => String(loc.warehouse_id || "") === whId && String(loc.location_id || "") !== fromId,
  );
});

const filteredRows = computed(() => {
  const accountId = Number(selectedAccountId.value || 0);
  const q = searchQuery.value.trim().toLowerCase();
  return rows.value.filter((row) => {
    if (accountId > 0 && Number(row?.client_account_id || 0) !== accountId) {
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

function splitBackstockLocations(text) {
  const raw = String(text || "").trim();
  if (!raw) return [];
  return raw
    .split(/\s*,\s*/)
    .map((part) => part.trim())
    .filter(Boolean);
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

function flattenProductLocations(product) {
  const out = [];
  const warehouses = Array.isArray(product?.warehouses) ? product.warehouses : [];
  warehouses.forEach((wh) => {
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
}

function findFromLocationForRow(product, row) {
  const locs = flattenProductLocations(product).filter((loc) => loc.pickable === false);
  const names = splitBackstockLocations(row?.backstock_locations).map(parseBackstockLocationName);
  for (const name of names) {
    const lower = name.toLowerCase();
    if (!lower) continue;
    const match = locs.find((loc) => {
      const locName = String(loc.location_name || loc.location_id || "").toLowerCase();
      return locName === lower || locName.includes(lower) || lower.includes(locName);
    });
    if (match) return match;
  }
  return locs[0] ?? null;
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
}

async function removeRowFromMenu(row) {
  if (!row?.sku) return;
  closeLineMenu();
  try {
    const { data } = await api.post("/inventory/restock-beta/complete", { sku: row.sku });
    applySnapshot(data, { silent: true });
    toast.success("Removed from restock list.");
  } catch (e) {
    toast.errorFrom(e, "Could not remove row.");
  }
}

async function openTransferFromMenu(row) {
  if (!row?.sku) return;
  closeLineMenu();
  const accountId = Number(row.client_account_id || 0);
  if (accountId <= 0) {
    toast.error("Account not matched yet. Wait for product matching to finish.");
    return;
  }
  transferRow.value = row;
  transferProduct.value = null;
  transferFromLocation.value = null;
  transferForm.transfer_type = "current";
  transferForm.to_location_id = "";
  transferForm.to_location = "";
  transferForm.quantity = "";
  transferForm.reason = "Restock";
  transferModalOpen.value = true;
  transferBusy.value = true;
  try {
    const { data } = await api.get(`/inventory/products/${encodeURIComponent(row.sku)}`, {
      params: { client_account_id: accountId },
    });
    transferProduct.value = data?.product ?? null;
    const fromLoc = findFromLocationForRow(transferProduct.value, row);
    if (!fromLoc) {
      transferModalOpen.value = false;
      toast.error("No backstock location found for this SKU in ShipHero.");
      return;
    }
    transferFromLocation.value = fromLoc;
  } catch (e) {
    transferModalOpen.value = false;
    toast.errorFrom(e, "Could not load product for transfer.");
  } finally {
    transferBusy.value = false;
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
    const sku = transferRow.value.sku;
    transferModalOpen.value = false;
    const { data } = await api.post("/inventory/restock-beta/complete", { sku });
    applySnapshot(data, { silent: true });
    toast.success("Transferred and removed from restock list.");
  } catch (e) {
    toast.errorFrom(e, "Could not transfer quantity.");
  } finally {
    transferBusy.value = false;
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
    title: "Save Rack | Inventory | Restock",
    description: "Upload a restock CSV and review SKUs that need replenishment.",
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
        <h1 class="h4 fw-semibold text-body mb-1">Restock</h1>
        <p class="text-secondary small mb-0">
          Upload a restock CSV to review SKUs with backstock and replenishment needs.
        </p>
        <p v-if="uploadedAtLabel" class="text-secondary small mb-0 mt-1">
          Last upload: {{ uploadedAtLabel }}
          <span v-if="meta.original_filename"> ({{ meta.original_filename }})</span>
        </p>
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
        Matching products to accounts…
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
        </div>
      </div>

      <div class="table-responsive staff-table-wrap">
        <table class="table table-hover align-middle mb-0 staff-data-table user-inv-table">
          <thead class="table-light staff-table-head">
            <tr>
              <th class="staff-table-head__th text-center user-inv-table__image-col" scope="col">Image</th>
              <th class="staff-table-head__th user-inv-table__text-col" scope="col">Product</th>
              <th class="staff-table-head__th" scope="col">Account</th>
              <th class="staff-table-head__th text-center" scope="col">On Hand</th>
              <th class="staff-table-head__th text-center" scope="col">Allocated</th>
              <th class="staff-table-head__th text-center" scope="col">Pickable QTY</th>
              <th class="staff-table-head__th text-center" scope="col">Backstock</th>
              <th class="staff-table-head__th text-center" scope="col">Restock Needed</th>
              <th class="staff-table-head__th" scope="col">Backstock Locations</th>
              <th class="staff-table-head__th text-center" scope="col">Actions</th>
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
              <td class="text-center user-inv-table__image-col">
                <a
                  :href="inventoryDetailHref(row)"
                  target="_blank"
                  rel="noopener noreferrer"
                  class="user-inv-table__image-link"
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
              </td>
              <td class="user-inv-table__text-col">
                <div class="restock-product">
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
              </td>
              <td>
                <RouterLink
                  v-if="accountDetailTo(row.client_account_id)"
                  :to="accountDetailTo(row.client_account_id)"
                  class="text-decoration-none"
                >
                  {{ row.account_name }}
                </RouterLink>
                <span v-else-if="isEnriching && !row.account_name" class="text-secondary small">Matching…</span>
                <span v-else class="text-secondary">—</span>
              </td>
              <td class="text-center">{{ formatQty(row.on_hand) }}</td>
              <td class="text-center">{{ formatQty(row.allocated) }}</td>
              <td class="text-center">{{ formatQty(row.pickable_qty) }}</td>
              <td class="text-center">{{ formatQty(row.backstock_qty) }}</td>
              <td class="text-center">{{ formatQty(row.restock_needed) }}</td>
              <td class="restock-locations-col">
                <template v-if="splitBackstockLocations(row.backstock_locations).length">
                  <div
                    v-for="(location, index) in splitBackstockLocations(row.backstock_locations)"
                    :key="`${row.sku}-${index}`"
                    class="restock-loc-row small text-secondary"
                  >
                    {{ location }}
                  </div>
                </template>
                <span v-else class="text-secondary">—</span>
              </td>
              <td class="text-center" @click.stop>
                <CrmIconRowActions
                  :class="{ 'is-open': lineMenuSku === row.sku }"
                  :aria-expanded="lineMenuSku === row.sku ? 'true' : 'false'"
                  aria-label="Row actions"
                  @click="toggleLineMenu(row.sku, $event)"
                />
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
            v-if="canTransfer"
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

    <InventoryTransferQtyModal
      :open="transferModalOpen"
      :busy="transferBusy"
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

.restock-product__sku {
  display: block;
  font-size: 1rem;
  font-weight: 600;
  line-height: 1.35;
  margin-bottom: 0.15rem;
}

.restock-product__name {
  white-space: normal;
  word-break: break-word;
  line-height: 1.35;
}

.restock-locations-col {
  min-width: 10rem;
  max-width: min(18rem, 28vw);
}

.restock-loc-row + .restock-loc-row {
  margin-top: 0.25rem;
}
</style>
