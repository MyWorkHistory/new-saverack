<script setup>
import { computed, onMounted, ref } from "vue";
import { useRoute, useRouter } from "vue-router";
import api from "../../services/api";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import Modal from "../../components/Modal.vue";
import AsnProductCatalogPanel from "../../components/inventory/AsnProductCatalogPanel.vue";
import ReturnFeesCard from "../../components/admin-returns/ReturnFeesCard.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast.js";
import { formatDateUs } from "../../utils/formatUserDates.js";
import {
  formatRmaLabel,
  processDisplayStatusBadgeClass,
  processDisplayStatusLabel,
  returnStatusBadgeClass,
  returnStatusLabel,
  thirdPartyTypeLabel,
} from "../../utils/formatReturnDisplay.js";

const UNKNOWN_SKU = "Unknown SKU";

const route = useRoute();
const router = useRouter();
const toast = useToast();

const loading = ref(true);
const processing = ref(false);
const lineBusy = ref(false);
const addPanelOpen = ref(false);
const unknownSkuOpen = ref(false);
const unknownSkuQty = ref(1);
const ret = ref(null);
const returnFees = ref({});
const lineRestock = ref({});
const selected = ref(new Set());

const returnId = computed(() => String(route.params.id || ""));

const isPending = computed(() => String(ret.value?.status || "").toLowerCase() === "pending");
const isNonCompliant = computed(() => Boolean(ret.value?.is_non_compliant));
const isThirdParty = computed(() => Boolean(ret.value?.is_third_party));
const isNonCompliantPending = computed(() => isPending.value && isNonCompliant.value);
const isThirdPartyPending = computed(() => isPending.value && isThirdParty.value);
const isStaffManagedPending = computed(() => isNonCompliantPending.value || isThirdPartyPending.value);
const isProcessed = computed(() => {
  const s = String(ret.value?.status || "").toLowerCase();
  return s === "received" || s === "completed";
});

const clientAccountId = computed(() => Number(ret.value?.client_account_id || 0));

const lines = computed(() => (Array.isArray(ret.value?.lines) ? ret.value.lines : []));

const selectedCount = computed(() => selected.value.size);
const allSelected = computed(() => {
  if (!lines.value.length) return false;
  return lines.value.every((line) => selected.value.has(line.id));
});

const canProcess = computed(() => {
  if (!isPending.value) return false;
  if (isStaffManagedPending.value) {
    return lines.value.some((line) => selected.value.has(line.id) && Number(line.return_qty) > 0);
  }
  return selectedCount.value > 0;
});

const statusBadgeClass = computed(() => {
  if (isProcessed.value) return processDisplayStatusBadgeClass("returned");
  if (isThirdPartyPending.value) return processDisplayStatusBadgeClass("third_party_return");
  if (isNonCompliantPending.value) return processDisplayStatusBadgeClass("pending");
  if (isPending.value) return processDisplayStatusBadgeClass("pending");
  return returnStatusBadgeClass(ret.value?.status);
});

const statusLabel = computed(() => {
  if (isProcessed.value) return processDisplayStatusLabel("returned");
  if (isThirdPartyPending.value) return processDisplayStatusLabel("third_party_return");
  if (isPending.value) return processDisplayStatusLabel("pending");
  return returnStatusLabel(ret.value?.status);
});

function syncSelectionFromLines() {
  selected.value = new Set(
    lines.value.filter((line) => Number(line.return_qty) > 0).map((line) => line.id),
  );
}

function applyReturnData(data) {
  ret.value = data;
  returnFees.value = data?.return_fees || {};
  const restockMap = {};
  for (const line of Array.isArray(data?.lines) ? data.lines : []) {
    restockMap[line.id] = line.restock !== false;
  }
  lineRestock.value = restockMap;
  syncSelectionFromLines();
}

function toggleAll() {
  if (allSelected.value) {
    selected.value = new Set();
  } else {
    selected.value = new Set(lines.value.map((l) => l.id));
  }
}

function toggleOne(lineId) {
  const next = new Set(selected.value);
  if (next.has(lineId)) next.delete(lineId);
  else next.add(lineId);
  selected.value = next;
}

async function copyRma() {
  const num = String(ret.value?.rma_number || "").trim();
  if (!num) return;
  try {
    await navigator.clipboard.writeText(num);
    toast.success("RMA number copied.");
  } catch {
    toast.error("Could not copy RMA number.");
  }
}

async function copyOrderNumber() {
  const num = displayOrderNumber.value;
  if (!num || num === "—") return;
  try {
    await navigator.clipboard.writeText(num);
    toast.success("Order number copied.");
  } catch {
    toast.error("Could not copy order number.");
  }
}

async function openPdf(path, params, msg) {
  try {
    const { data } = await api.get(path, {
      params,
      responseType: "blob",
    });
    const blob = new Blob([data], { type: "application/pdf" });
    const url = window.URL.createObjectURL(blob);
    window.open(url, "_blank", "noopener");
    setTimeout(() => window.URL.revokeObjectURL(url), 30000);
  } catch (e) {
    toast.errorFrom(e, msg);
  }
}

function printLineBarcode(line) {
  if (!ret.value?.id || !line?.id) return;
  openPdf(
    `/admin/returns/${ret.value.id}/lines/${line.id}/barcode.pdf`,
    {},
    "Could not print barcode.",
  );
}

const tableColspan = computed(() => {
  let cols = isPending.value ? 7 : 6;
  if (isStaffManagedPending.value) cols += 1;
  return cols;
});

function stripOrderNumberHash(value) {
  return String(value || "").trim().replace(/^#+/, "");
}

const displayOrderNumber = computed(() => {
  const raw = String(ret.value?.order_number || "").trim();
  return raw ? stripOrderNumberHash(raw) : "—";
});

function buildLinePayload(product, quantity) {
  const sku = String(product?.sku || "").trim();
  const name = String(product?.name || product?.product_name || sku).trim();
  const imageUrl = product?.image_url || product?.thumbnail || product?.small_image || null;
  const shipheroLineItemId = product?.id != null ? String(product.id) : null;

  return {
    sku,
    name,
    image_url: imageUrl,
    shiphero_line_item_id: shipheroLineItemId,
    return_qty: Math.max(1, Math.floor(Number(quantity) || 0)),
  };
}

async function addFromCatalog({ product, quantity }) {
  if (!ret.value?.id || !isStaffManagedPending.value) return;
  const payload = buildLinePayload(product, quantity);
  if (!payload.sku) {
    toast.error("This product has no SKU.");
    return;
  }
  lineBusy.value = true;
  try {
    const { data } = await api.post(`/admin/returns/${ret.value.id}/lines`, payload);
    applyReturnData(data);
    toast.success("Product added.");
  } catch (e) {
    toast.errorFrom(e, "Could not add product.");
  } finally {
    lineBusy.value = false;
  }
}

async function saveLineReturnQty(line, rawQty) {
  if (!ret.value?.id || !isStaffManagedPending.value || !line?.id) return;
  const qty = Math.max(1, Number(rawQty) || 1);
  if (qty === Number(line.return_qty)) return;
  lineBusy.value = true;
  try {
    const { data } = await api.patch(`/admin/returns/${ret.value.id}/lines/${line.id}`, {
      return_qty: qty,
    });
    applyReturnData(data);
  } catch (e) {
    toast.errorFrom(e, "Could not update return quantity.");
    await load();
  } finally {
    lineBusy.value = false;
  }
}

async function removeLine(line) {
  if (!ret.value?.id || !isStaffManagedPending.value || !line?.id) return;
  lineBusy.value = true;
  try {
    const { data } = await api.delete(`/admin/returns/${ret.value.id}/lines/${line.id}`);
    applyReturnData(data);
    toast.success("Line removed.");
  } catch (e) {
    toast.errorFrom(e, "Could not remove line.");
  } finally {
    lineBusy.value = false;
  }
}

function openUnknownSkuModal() {
  unknownSkuQty.value = 1;
  unknownSkuOpen.value = true;
}

function closeUnknownSkuModal() {
  if (lineBusy.value) return;
  unknownSkuOpen.value = false;
}

async function submitUnknownSku() {
  if (!ret.value?.id || !isStaffManagedPending.value) return;
  const qty = Math.max(1, Number(unknownSkuQty.value) || 1);
  lineBusy.value = true;
  try {
    const { data } = await api.post(`/admin/returns/${ret.value.id}/lines`, {
      sku: UNKNOWN_SKU,
      name: UNKNOWN_SKU,
      return_qty: qty,
    });
    applyReturnData(data);
    unknownSkuOpen.value = false;
    toast.success("Unknown SKU line added.");
  } catch (e) {
    toast.errorFrom(e, "Could not add Unknown SKU line.");
  } finally {
    lineBusy.value = false;
  }
}

async function load() {
  loading.value = true;
  try {
    const { data } = await api.get(`/returns/${returnId.value}`);
    applyReturnData(data);
    setCrmPageMeta({
      title: `Save Rack | ${formatRmaLabel(data.rma_number) || "Process Return"}`,
      description: "Process a pending return.",
    });
  } catch (e) {
    toast.errorFrom(e, "Could not load return.");
    router.push({ name: "admin-process-returns" });
  } finally {
    loading.value = false;
  }
}

async function processReturn() {
  if (!ret.value?.id || !isPending.value) return;
  const lineIds = [...selected.value];
  if (!lineIds.length) {
    toast.error("Select at least one item to process.");
    return;
  }
  processing.value = true;
  try {
    const restockByLineId = {};
    for (const lineId of lineIds) {
      restockByLineId[lineId] = lineRestock.value[lineId] !== false;
    }
    const payload = { line_ids: lineIds, restock_by_line_id: restockByLineId };
    if (returnFees.value.first_item != null) payload.first_item_fee = returnFees.value.first_item;
    if (returnFees.value.additional_item != null) payload.additional_item_fee = returnFees.value.additional_item;
    if (returnFees.value.non_compliant != null) payload.non_compliant_fee = returnFees.value.non_compliant;
    const { data } = await api.post(`/admin/returns/${ret.value.id}/process`, payload);
    applyReturnData(data);
    toast.success("Return processed.");
  } catch (e) {
    toast.errorFrom(e, "Could not process return.");
  } finally {
    processing.value = false;
  }
}

onMounted(load);
</script>

<template>
  <div v-if="loading" class="staff-page staff-page--wide py-5 admin-returns-page">
    <CrmLoadingSpinner message="Loading return…" :center="true" />
  </div>

  <div
    v-else-if="ret"
    class="staff-page staff-page--wide admin-returns-page admin-returns-detail-page order-detail-page"
  >
    <div class="staff-table-card staff-datatable-card staff-datatable-card--white user-return-page__header-shell mb-4">
      <div class="p-4 pb-3">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
          <div class="min-w-0">
            <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
              <h1 class="h4 mb-0 fw-semibold text-body">
                {{ formatRmaLabel(ret.rma_number) || "Process Return" }}
              </h1>
              <span
                v-if="isThirdPartyPending"
                class="badge rounded-pill fw-medium"
                :class="processDisplayStatusBadgeClass('third_party_return')"
              >
                {{ processDisplayStatusLabel("third_party_return") }}
              </span>
              <span
                v-if="isNonCompliantPending"
                class="badge rounded-pill fw-medium"
                :class="processDisplayStatusBadgeClass('non_compliant_return')"
              >
                {{ processDisplayStatusLabel("non_compliant_return") }}
              </span>
              <span class="badge rounded-pill fw-medium" :class="statusBadgeClass">
                {{ statusLabel }}
              </span>
            </div>
            <button
              type="button"
              class="btn btn-link btn-sm text-secondary px-0 py-0 mt-2 text-decoration-none"
              @click="router.push({ name: 'admin-process-returns' })"
            >
              &lt; Process Returns
            </button>
          </div>
          <div v-if="isPending" class="d-flex flex-wrap gap-2 flex-shrink-0 align-items-center">
            <button
              type="button"
              class="btn btn-primary staff-page-primary btn-sm fw-semibold"
              :disabled="processing || !canProcess"
              @click="processReturn"
            >
              {{ processing ? "Processing…" : "Process Return" }}
            </button>
          </div>
        </div>
      </div>
    </div>

    <div class="row g-4">
      <div class="col-lg-8">
        <div class="staff-table-card staff-datatable-card staff-datatable-card--white p-0 mb-4">
          <div class="px-4 py-3 border-bottom d-flex flex-wrap justify-content-between align-items-center gap-2">
            <h2 class="h6 mb-0 fw-semibold">Return Items</h2>
            <div v-if="isStaffManagedPending" class="d-flex flex-wrap gap-2 align-items-center">
              <button
                type="button"
                class="btn btn-sm btn-outline-secondary fw-semibold orders-toolbar-outline-btn"
                :disabled="lineBusy"
                @click="openUnknownSkuModal"
              >
                Unknown SKU
              </button>
              <button
                type="button"
                class="btn btn-sm btn-primary staff-page-primary"
                :disabled="lineBusy"
                @click="addPanelOpen = !addPanelOpen"
              >
                {{ addPanelOpen ? "Hide Add Products" : "Add Products" }}
              </button>
            </div>
            <span v-else-if="isPending && selectedCount > 0" class="small text-secondary">
              {{ selectedCount }} selected
            </span>
          </div>

          <div v-if="isStaffManagedPending && addPanelOpen" class="border-bottom">
            <AsnProductCatalogPanel
              :client-account-id="clientAccountId"
              :active="addPanelOpen"
              :busy="lineBusy"
              qty-label="Return Qty"
              search-input-id="admin-return-nc-catalog-search"
              @add="addFromCatalog"
            />
          </div>

          <div class="table-responsive staff-table-wrap">
            <table class="table table-hover align-middle mb-0 staff-data-table">
              <thead class="table-light staff-table-head">
                <tr>
                  <th
                    v-if="isPending"
                    class="staff-table-head__th text-center user-return-page__check-col"
                    scope="col"
                  >
                    <input
                      type="checkbox"
                      class="form-check-input staff-table-head__check m-0"
                      :checked="allSelected"
                      :disabled="!lines.length"
                      aria-label="Select all items"
                      @change="toggleAll"
                    />
                  </th>
                  <th class="staff-table-head__th order-detail-page__items-col" scope="col">Item</th>
                  <th v-if="!isStaffManagedPending" class="staff-table-head__th text-center" scope="col">Order Qty</th>
                  <th class="staff-table-head__th text-center" scope="col">Return Qty</th>
                  <th class="staff-table-head__th" scope="col">Reason</th>
                  <th class="staff-table-head__th text-center" scope="col">Restock</th>
                  <th class="staff-table-head__th text-center" scope="col">Barcode</th>
                  <th v-if="isStaffManagedPending" class="staff-table-head__th text-center" scope="col">Actions</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="line in lines" :key="line.id">
                  <td v-if="isPending" class="text-center staff-table-cell--tight-check" @click.stop>
                    <input
                      type="checkbox"
                      class="form-check-input staff-table-head__check m-0"
                      :checked="selected.has(line.id)"
                      :aria-label="`Select ${line.sku}`"
                      @change="toggleOne(line.id)"
                    />
                  </td>
                  <td>
                    <div class="d-flex align-items-center gap-2 order-detail-page__item-cell">
                      <img
                        v-if="line.image_url"
                        :src="line.image_url"
                        alt=""
                        class="order-detail-page__item-thumb rounded border flex-shrink-0"
                        width="48"
                        height="48"
                        loading="lazy"
                      />
                      <div class="min-w-0 order-detail-page__item-copy">
                        <div class="order-detail-page__item-name fw-semibold text-truncate">{{ line.name || "—" }}</div>
                        <div class="order-detail-page__item-sku small text-secondary">{{ line.sku }}</div>
                      </div>
                    </div>
                  </td>
                  <td v-if="!isStaffManagedPending" class="text-center">{{ line.order_qty }}</td>
                  <td class="text-center">
                    <input
                      v-if="isStaffManagedPending"
                      type="number"
                      min="1"
                      class="form-control form-control-sm text-center mx-auto admin-return-nc-qty-input"
                      :value="line.return_qty"
                      :disabled="lineBusy"
                      :aria-label="`Return quantity for ${line.sku}`"
                      @change="saveLineReturnQty(line, $event.target.value)"
                    />
                    <span v-else>{{ line.return_qty }}</span>
                  </td>
                  <td>{{ line.return_reason_label || line.return_reason || "—" }}</td>
                  <td class="text-center">
                    <input
                      v-if="isPending"
                      v-model="lineRestock[line.id]"
                      type="checkbox"
                      class="form-check-input m-0"
                      :disabled="!selected.has(line.id)"
                      :aria-label="`Restock ${line.sku}`"
                    />
                    <svg
                      v-else-if="line.restock"
                      class="admin-return-restock-check"
                      width="20"
                      height="20"
                      viewBox="0 0 24 24"
                      fill="currentColor"
                      aria-label="Restock"
                      role="img"
                    >
                      <path d="M9 16.17 4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z" />
                    </svg>
                    <span v-else class="text-secondary">—</span>
                  </td>
                  <td class="text-center">
                    <button
                      type="button"
                      class="btn btn-outline-secondary btn-sm fw-semibold orders-toolbar-outline-btn"
                      @click="printLineBarcode(line)"
                    >
                      Print
                    </button>
                  </td>
                  <td v-if="isStaffManagedPending" class="text-center">
                    <button
                      type="button"
                      class="btn btn-link btn-sm text-danger text-decoration-none p-0"
                      :disabled="lineBusy"
                      @click="removeLine(line)"
                    >
                      Remove
                    </button>
                  </td>
                </tr>
                <tr v-if="!lines.length">
                  <td :colspan="tableColspan" class="text-center text-secondary py-4">No return items.</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <div class="col-lg-4 d-flex flex-column gap-4 user-return-page__side-column">
        <div class="staff-table-card staff-datatable-card staff-datatable-card--white p-4">
          <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
            <h3 class="h6 fw-semibold mb-0">RMA #</h3>
            <button
              type="button"
              class="btn btn-sm btn-outline-secondary fw-semibold"
              :disabled="!ret.rma_number"
              @click="copyRma"
            >
              Copy
            </button>
          </div>
          <div class="user-return-page__rma-display">{{ ret.rma_number || "—" }}</div>
          <div class="mt-3 pt-3 border-top">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-1">
              <h3 class="h6 fw-semibold mb-0">Order #</h3>
              <button
                v-if="displayOrderNumber !== '—'"
                type="button"
                class="btn btn-sm btn-outline-secondary fw-semibold"
                @click="copyOrderNumber"
              >
                Copy
              </button>
            </div>
            <div class="user-return-page__order-display">{{ displayOrderNumber }}</div>
          </div>
        </div>

        <div
          v-if="isThirdParty"
          class="staff-table-card staff-datatable-card staff-datatable-card--white p-4"
        >
          <h3 class="h6 fw-semibold mb-3">3rd Party Return</h3>
          <dl class="small mb-0">
            <dt class="text-secondary fw-normal">3rd Party</dt>
            <dd class="mb-0">{{ thirdPartyTypeLabel(ret) }}</dd>
          </dl>
        </div>

        <div
          v-if="isNonCompliant"
          class="staff-table-card staff-datatable-card staff-datatable-card--white p-4"
        >
          <h3 class="h6 fw-semibold mb-3">Non-Compliant Return</h3>
          <dl class="small mb-0">
            <dt class="text-secondary fw-normal">Reason</dt>
            <dd class="mb-2">{{ ret.non_compliant_reason_label || "—" }}</dd>
            <dt class="text-secondary fw-normal">Declared Items</dt>
            <dd class="mb-0">{{ ret.non_compliant_declared_items ?? "—" }}</dd>
          </dl>
        </div>

        <ReturnFeesCard
          :return-id="ret.id"
          :fees="returnFees"
          :editable="isPending"
          :return-bill-id="ret.return_bill_id"
          @update:fees="returnFees = $event"
        />

        <div v-if="ret.warehouse_private_note" class="staff-table-card staff-datatable-card staff-datatable-card--white p-4">
          <h3 class="h6 fw-semibold mb-3">Private Note</h3>
          <p class="small mb-0 text-secondary" style="white-space: pre-wrap">{{ ret.warehouse_private_note }}</p>
        </div>

        <div v-if="ret.processed_at" class="staff-table-card staff-datatable-card staff-datatable-card--white p-4">
          <h3 class="h6 fw-semibold mb-2">Processed</h3>
          <p class="small text-secondary mb-0">{{ formatDateUs(ret.processed_at) }}</p>
        </div>
      </div>
    </div>

    <Modal :open="unknownSkuOpen" title="Unknown SKU" @close="closeUnknownSkuModal">
      <label class="form-label" for="admin-return-unknown-sku-qty">Return Qty</label>
      <input
        id="admin-return-unknown-sku-qty"
        v-model.number="unknownSkuQty"
        type="number"
        min="1"
        class="form-control mb-3"
        :disabled="lineBusy"
      />
      <div class="d-flex justify-content-end gap-2">
        <button type="button" class="btn btn-outline-secondary" :disabled="lineBusy" @click="closeUnknownSkuModal">
          Cancel
        </button>
        <button type="button" class="btn btn-primary staff-page-primary" :disabled="lineBusy" @click="submitUnknownSku">
          {{ lineBusy ? "Adding…" : "Add" }}
        </button>
      </div>
    </Modal>
  </div>
</template>

<style scoped>
.user-return-page__rma-display {
  font-size: 2.25rem;
  font-weight: 800;
  letter-spacing: 0.06em;
  line-height: 1.1;
}
.user-return-page__order-display {
  font-size: 1.5rem;
  font-weight: 700;
  letter-spacing: 0.04em;
  line-height: 1.2;
}
.user-return-page__check-col {
  width: 2.75rem;
}
.admin-return-restock-check {
  color: #28c76f;
  display: inline-block;
  vertical-align: middle;
}
.admin-return-nc-qty-input {
  max-width: 5rem;
}
</style>
