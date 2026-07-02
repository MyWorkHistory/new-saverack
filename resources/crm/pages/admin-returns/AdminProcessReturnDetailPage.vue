<script setup>
import { computed, onMounted, ref } from "vue";
import { useRoute, useRouter } from "vue-router";
import api from "../../services/api";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
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
} from "../../utils/formatReturnDisplay.js";

const route = useRoute();
const router = useRouter();
const toast = useToast();

const loading = ref(true);
const processing = ref(false);
const ret = ref(null);
const returnFees = ref({});
const lineRestock = ref({});
const selected = ref(new Set());

const returnId = computed(() => String(route.params.id || ""));

const isPending = computed(() => String(ret.value?.status || "").toLowerCase() === "pending");
const isProcessed = computed(
  () => {
    const s = String(ret.value?.status || "").toLowerCase();
    return s === "received" || s === "completed";
  },
);

const lines = computed(() => (Array.isArray(ret.value?.lines) ? ret.value.lines : []));

const selectedCount = computed(() => selected.value.size);
const allSelected = computed(() => {
  if (!lines.value.length) return false;
  return lines.value.every((line) => selected.value.has(line.id));
});

const statusBadgeClass = computed(() => {
  if (isProcessed.value) return processDisplayStatusBadgeClass("returned");
  if (isPending.value) return processDisplayStatusBadgeClass("pending");
  return returnStatusBadgeClass(ret.value?.status);
});

const statusLabel = computed(() => {
  if (isProcessed.value) return processDisplayStatusLabel("returned");
  if (isPending.value) return processDisplayStatusLabel("pending");
  return returnStatusLabel(ret.value?.status);
});

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
  const sku = String(line?.sku || "").trim();
  if (!sku) {
    toast.error("No SKU for this line.");
    return;
  }
  const accountId = Number(ret.value?.client_account_id || 0);
  const params = accountId > 0 ? { client_account_id: accountId } : {};
  openPdf(
    `/inventory/products/${encodeURIComponent(sku)}/barcode-label.pdf`,
    params,
    "Could not print barcode.",
  );
}

const tableColspan = computed(() => (isPending.value ? 7 : 6));

function stripOrderNumberHash(value) {
  return String(value || "").trim().replace(/^#+/, "");
}

const displayOrderNumber = computed(() => {
  const raw = String(ret.value?.order_number || "").trim();
  return raw ? stripOrderNumberHash(raw) : "—";
});

async function load() {
  loading.value = true;
  try {
    const { data } = await api.get(`/returns/${returnId.value}`);
    ret.value = data;
    returnFees.value = data?.return_fees || {};
    const restockMap = {};
    for (const line of Array.isArray(data?.lines) ? data.lines : []) {
      restockMap[line.id] = line.restock !== false;
    }
    lineRestock.value = restockMap;
    selected.value = new Set(
      (Array.isArray(data?.lines) ? data.lines : [])
        .filter((l) => Number(l.return_qty) > 0)
        .map((l) => l.id),
    );
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
    const { data } = await api.post(`/admin/returns/${ret.value.id}/process`, payload);
    ret.value = data;
    returnFees.value = data?.return_fees || returnFees.value;
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
              :disabled="processing || selectedCount === 0"
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
            <span v-if="isPending && selectedCount > 0" class="small text-secondary">
              {{ selectedCount }} selected
            </span>
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
                  <th class="staff-table-head__th text-center" scope="col">Order Qty</th>
                  <th class="staff-table-head__th text-center" scope="col">Return Qty</th>
                  <th class="staff-table-head__th" scope="col">Reason</th>
                  <th class="staff-table-head__th text-center" scope="col">Restock</th>
                  <th class="staff-table-head__th text-center" scope="col">Barcode</th>
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
                  <td class="text-center">{{ line.order_qty }}</td>
                  <td class="text-center">{{ line.return_qty }}</td>
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
            <h3 class="h6 fw-semibold mb-0">Order #</h3>
            <button type="button" class="btn btn-sm btn-outline-secondary fw-semibold" @click="copyOrderNumber">
              Copy
            </button>
          </div>
          <div class="user-return-page__rma-display">{{ displayOrderNumber }}</div>
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
  </div>
</template>

<style scoped>
.user-return-page__rma-display {
  font-size: 2.25rem;
  font-weight: 800;
  letter-spacing: 0.06em;
  line-height: 1.1;
}
.user-return-page__check-col {
  width: 2.75rem;
}
.admin-return-restock-check {
  color: #28c76f;
  display: inline-block;
  vertical-align: middle;
}
</style>
