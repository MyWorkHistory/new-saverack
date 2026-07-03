<script setup>
import { computed, onMounted, ref } from "vue";
import { useRoute, useRouter } from "vue-router";
import api from "../../services/api";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import ReturnFeesCard from "../../components/admin-returns/ReturnFeesCard.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast.js";
import { formatRmaLabel } from "../../utils/formatReturnDisplay.js";

const route = useRoute();
const router = useRouter();
const toast = useToast();

const loading = ref(true);
const submitBusy = ref(false);
const ret = ref(null);
const formLines = ref([]);
const returnType = ref("direct");
const warehouseNote = ref("");
const reasonOptions = ref({});
const returnFees = ref({});
const defaultReason = ref("unknown");
const selected = ref(new Set());
const selectedReturnBin = ref("");

const returnBinOptions = Array.from({ length: 20 }, (_, i) => i + 1);

const shipheroOrderId = computed(() => String(route.params.shipheroOrderId || ""));
const clientAccountId = computed(() => Number(route.query.client_account_id || 0));

const selectedCount = computed(() => selected.value.size);
const allSelected = computed(() => {
  if (!formLines.value.length) return false;
  return formLines.value.every((_, idx) => selected.value.has(idx));
});

const hasReturnQty = computed(() => formLines.value.some((l) => Number(l.return_qty) > 0));

const canProcess = computed(() => hasReturnQty.value && Boolean(selectedReturnBin.value));

function lineKey(idx) {
  return idx;
}

function toggleAll() {
  if (allSelected.value) {
    selected.value = new Set();
  } else {
    selected.value = new Set(formLines.value.map((_, i) => i));
  }
}

function toggleOne(idx) {
  const next = new Set(selected.value);
  if (next.has(idx)) next.delete(idx);
  else next.add(idx);
  selected.value = next;
}

function returnAllSelected() {
  formLines.value = formLines.value.map((row, idx) => {
    if (!selected.value.has(idx)) return row;
    return { ...row, return_qty: Number(row.order_qty) || 0 };
  });
}

function clampReturnQty(row) {
  const max = Number(row.order_qty) || 0;
  let q = Number(row.return_qty) || 0;
  if (q < 0) q = 0;
  if (q > max) q = max;
  row.return_qty = q;
}

function orderCustomerName(order) {
  const ship = order?.shipping_address || order?.ship_to || {};
  const name = [ship.first_name, ship.last_name].filter(Boolean).join(" ").trim();
  if (name) return name;
  if (ship.company) return String(ship.company);
  return String(order?.email || "").trim();
}

async function copyRma() {
  const num = String(ret.value?.rma_number || "").trim();
  if (!num) return;
  try {
    await navigator.clipboard.writeText(num);
    toast.success("RMA copied.");
  } catch {
    toast.error("Could not copy RMA.");
  }
}

async function openPdf(path, msg) {
  try {
    const { data } = await api.get(path, { responseType: "blob" });
    const blob = new Blob([data], { type: "application/pdf" });
    const url = window.URL.createObjectURL(blob);
    window.open(url, "_blank", "noopener");
    setTimeout(() => window.URL.revokeObjectURL(url), 30000);
  } catch (e) {
    toast.errorFrom(e, msg);
  }
}

function openPackingSlip() {
  if (!ret.value?.id || !hasReturnQty.value) return;
  openPdf(`/returns/${ret.value.id}/packing-slip.pdf`, "Could not open return packing slip.");
}

async function cancelDraft() {
  if (!ret.value?.id) {
    router.push({ name: "admin-process-returns" });
    return;
  }
  try {
    await api.delete(`/returns/${ret.value.id}`);
  } catch {
    /* ignore */
  }
  router.push({ name: "user-return-create-search" });
}

async function processReturn() {
  if (!ret.value?.id) return;
  if (!hasReturnQty.value) {
    toast.error("Enter a return quantity for at least one item.");
    return;
  }
  const binNumber = Number(selectedReturnBin.value);
  if (!binNumber || binNumber < 1 || binNumber > 20) {
    toast.error("Select a return bin before processing.");
    return;
  }
  submitBusy.value = true;
  try {
    const lines = formLines.value.map((row) => ({
      shiphero_line_item_id: row.shiphero_line_item_id || null,
      sku: row.sku,
      name: row.name,
      image_url: row.image_url || null,
      order_qty: Number(row.order_qty) || 0,
      return_qty: Number(row.return_qty) || 0,
      return_reason: Number(row.return_qty) > 0 ? row.return_reason || defaultReason.value : null,
      restock: row.restock !== false,
    }));
    const payload = {
      return_type: returnType.value,
      warehouse_private_note: warehouseNote.value.trim() || null,
      return_bin_number: binNumber,
      lines,
    };
    if (returnFees.value.first_item != null) payload.first_item_fee = returnFees.value.first_item;
    if (returnFees.value.additional_item != null) payload.additional_item_fee = returnFees.value.additional_item;
    const { data } = await api.post(`/admin/returns/${ret.value.id}/process-from-draft`, payload);
    toast.success("Return processed.");
    router.push({ name: "admin-process-return-detail", params: { id: String(data.id) } });
  } catch (e) {
    toast.errorFrom(e, "Could not process return.");
  } finally {
    submitBusy.value = false;
  }
}

async function init() {
  if (!clientAccountId.value || !shipheroOrderId.value) {
    loading.value = false;
    return;
  }
  loading.value = true;
  try {
    const orderRes = await api.get(`/orders/${encodeURIComponent(shipheroOrderId.value)}`, {
      params: { client_account_id: clientAccountId.value },
    });
    const order = orderRes.data?.order;
    if (!order) {
      toast.error("Order not found.");
      router.push({ name: "admin-process-returns" });
      return;
    }
    const orderNum = String(order.order_number || "").trim();
    const draftRes = await api.post("/returns/draft", {
      client_account_id: clientAccountId.value,
      shiphero_order_id: shipheroOrderId.value,
      order_number: orderNum,
      customer_name: orderCustomerName(order),
      return_type: returnType.value,
    });
    ret.value = draftRes.data;
    reasonOptions.value = draftRes.data?.admin_return_reasons || draftRes.data?.return_reasons || {};
    defaultReason.value = draftRes.data?.admin_default_return_reason || "unknown";
    returnFees.value = draftRes.data?.return_fees || {};
    returnType.value = draftRes.data?.return_type || "direct";

    const items = Array.isArray(order.items) ? order.items : [];
    formLines.value = items.map((item) => ({
      shiphero_line_item_id: item.id ? String(item.id) : null,
      sku: String(item.sku || ""),
      name: String(item.name || item.product_name || ""),
      image_url: item.image_url || null,
      order_qty: Math.max(0, Math.floor(Number(item.quantity) || 0)),
      return_qty: 0,
      return_reason: defaultReason.value,
      restock: true,
    }));
  } catch (e) {
    toast.errorFrom(e, "Could not start return.");
    router.push({ name: "admin-process-returns" });
  } finally {
    loading.value = false;
  }
}

onMounted(() => {
  setCrmPageMeta({
    title: "Save Rack | Create Return",
    description: "Select items and complete your return.",
  });
  init();
});
</script>

<template>
  <div v-if="loading" class="staff-page staff-page--wide py-5 user-return-page">
    <CrmLoadingSpinner message="Preparing return…" :center="true" />
  </div>

  <div v-else-if="ret" class="staff-page staff-page--wide user-return-page user-return-detail-page order-detail-page">
    <div class="staff-table-card staff-datatable-card staff-datatable-card--white user-return-page__header-shell mb-4">
      <div class="p-4 pb-3">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
          <div class="min-w-0">
            <h1 class="h4 mb-0 fw-semibold text-body">Create Return</h1>
            <p class="small text-secondary mb-0 mt-2">
              Order {{ ret.order_number || "—" }} · {{ ret.customer_name || "—" }}
            </p>
            <button
              type="button"
              class="btn btn-link btn-sm text-secondary px-0 py-0 mt-1 text-decoration-none"
              @click="router.push({ name: 'admin-process-returns' })"
            >
              &lt; Order
            </button>
          </div>
          <div class="d-flex flex-wrap gap-2 flex-shrink-0 align-items-center">
            <label class="small text-secondary mb-0 fw-medium" for="admin-return-create-bin">Return Bin</label>
            <select
              id="admin-return-create-bin"
              v-model="selectedReturnBin"
              class="form-select form-select-sm"
              style="min-width: 8rem"
              :disabled="submitBusy"
            >
              <option value="">Select bin…</option>
              <option v-for="n in returnBinOptions" :key="n" :value="String(n)">{{ n }}</option>
            </select>
            <button
              type="button"
              class="btn btn-outline-secondary btn-sm fw-semibold orders-toolbar-outline-btn"
              :disabled="!hasReturnQty"
              @click="openPackingSlip"
            >
              Return Packing Slip
            </button>
            <button type="button" class="btn btn-outline-secondary btn-sm fw-semibold" @click="cancelDraft">
              Cancel
            </button>
            <button
              type="button"
              class="btn btn-primary staff-page-primary btn-sm fw-semibold"
              :disabled="submitBusy || !canProcess"
              @click="processReturn"
            >
              {{ submitBusy ? "Processing…" : "Process" }}
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
            <div class="d-flex align-items-center gap-2">
              <label class="small text-secondary mb-0" for="return-type-select">Type</label>
              <select id="return-type-select" v-model="returnType" class="form-select form-select-sm user-return-page__type-select">
                <option value="direct">Direct</option>
                <option value="amazon">Amazon</option>
                <option value="nordstrom">Nordstrom</option>
              </select>
            </div>
          </div>

          <div
            v-if="selectedCount > 0"
            class="staff-bulk-selection-bar d-flex flex-wrap align-items-center gap-2 gap-md-3 px-3 px-md-4 py-3"
          >
            <span class="small staff-bulk-selection-bar__count me-md-1">{{ selectedCount }} selected</span>
            <button
              type="button"
              class="btn btn-primary btn-sm staff-page-primary fw-semibold"
              @click="returnAllSelected"
            >
              Return All Selected
            </button>
          </div>

          <div class="table-responsive staff-table-wrap">
            <table class="table table-hover align-middle mb-0 staff-data-table">
              <thead class="table-light staff-table-head">
                <tr>
                  <th class="staff-table-head__th text-center user-return-page__check-col" scope="col">
                    <input
                      type="checkbox"
                      class="form-check-input staff-table-head__check m-0"
                      :checked="allSelected"
                      :disabled="!formLines.length"
                      aria-label="Select all items"
                      @change="toggleAll"
                    />
                  </th>
                  <th class="staff-table-head__th order-detail-page__items-col" scope="col">Item</th>
                  <th class="staff-table-head__th text-center" scope="col">Order Qty</th>
                  <th class="staff-table-head__th text-center" scope="col">Return Items</th>
                  <th class="staff-table-head__th" scope="col">Reason</th>
                  <th class="staff-table-head__th text-center" scope="col">Restock</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(row, idx) in formLines" :key="lineKey(idx)">
                  <td class="text-center staff-table-cell--tight-check" @click.stop>
                    <input
                      type="checkbox"
                      class="form-check-input staff-table-head__check m-0"
                      :checked="selected.has(idx)"
                      :aria-label="`Select ${row.sku}`"
                      @change="toggleOne(idx)"
                    />
                  </td>
                  <td>
                    <div class="d-flex align-items-center gap-2 order-detail-page__item-cell">
                      <img
                        v-if="row.image_url"
                        :src="row.image_url"
                        alt=""
                        class="order-detail-page__item-thumb rounded border flex-shrink-0"
                        width="48"
                        height="48"
                        loading="lazy"
                      />
                      <div class="min-w-0 order-detail-page__item-copy">
                        <div class="order-detail-page__item-name fw-semibold" :title="row.name || undefined">{{ row.name || "—" }}</div>
                        <div class="order-detail-page__item-sku small text-secondary">{{ row.sku }}</div>
                      </div>
                    </div>
                  </td>
                  <td class="text-center">{{ row.order_qty }}</td>
                  <td class="text-center user-return-page__qty-col">
                    <input
                      v-model.number="row.return_qty"
                      type="number"
                      min="0"
                      :max="row.order_qty"
                      class="form-control form-control-sm text-center"
                      @change="clampReturnQty(row)"
                    />
                  </td>
                  <td class="user-return-page__reason-col">
                    <select v-model="row.return_reason" class="form-select form-select-sm" :disabled="!row.return_qty">
                      <option v-for="(label, key) in reasonOptions" :key="key" :value="key">{{ label }}</option>
                    </select>
                  </td>
                  <td class="text-center">
                    <input
                      v-model="row.restock"
                      type="checkbox"
                      class="form-check-input m-0"
                      :disabled="!row.return_qty"
                      :aria-label="`Restock ${row.sku}`"
                    />
                  </td>
                </tr>
                <tr v-if="!formLines.length">
                  <td colspan="6" class="text-center text-secondary py-4">No line items on this order.</td>
                </tr>
              </tbody>
            </table>
          </div>
          <p class="staff-table-mobile-scroll-cue d-md-none px-3 pb-2 mb-0" aria-hidden="true">
            Scroll sideways or swipe to see all columns.
          </p>
        </div>
      </div>

      <div class="col-lg-4 d-flex flex-column gap-4 user-return-page__side-column">
        <div class="staff-table-card staff-datatable-card staff-datatable-card--white p-4">
          <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
            <h3 class="h6 fw-semibold mb-0">RMA #</h3>
            <button type="button" class="btn btn-sm btn-outline-secondary fw-semibold" @click="copyRma">Copy</button>
          </div>
          <div class="user-return-page__rma-display">{{ ret.rma_number }}</div>
          <p class="small text-secondary mb-0 mt-2">{{ formatRmaLabel(ret.rma_number) }}</p>
        </div>

        <ReturnFeesCard
          :return-id="ret.id"
          :fees="returnFees"
          :editable="true"
          @update:fees="returnFees = $event"
        />

        <div class="staff-table-card staff-datatable-card staff-datatable-card--white p-4">
          <h3 class="h6 fw-semibold mb-3">Private Note</h3>
          <p class="small text-secondary mb-2">Private note to warehouse only</p>
          <textarea
            id="warehouse-private-note"
            v-model="warehouseNote"
            class="form-control form-control-sm mb-0"
            rows="5"
            maxlength="20000"
            placeholder="Notes visible to warehouse staff on this return."
          />
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
.user-return-page__type-select {
  width: 10rem;
  max-width: 100%;
}
.user-return-page__qty-col {
  width: 5.5rem;
}
.user-return-page__reason-col {
  width: 9rem;
  min-width: 0;
}
.user-return-page__check-col {
  width: 2.75rem;
}

.user-return-detail-page :deep(.table-responsive.staff-table-wrap) {
  overflow-x: clip;
  max-width: 100%;
}

.user-return-detail-page :deep(.staff-table-wrap .table.staff-data-table) {
  width: 100%;
  min-width: 0;
  max-width: 100%;
  table-layout: fixed;
}

.user-return-detail-page .order-detail-page__items-col {
  width: 36%;
  min-width: 0;
  vertical-align: middle;
}

.user-return-detail-page .order-detail-page__item-thumb {
  width: 40px;
  height: 40px;
  object-fit: cover;
}

.user-return-detail-page .order-detail-page__item-name {
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
  white-space: normal;
  line-height: 1.3;
  font-size: 0.875rem;
  font-weight: 600;
  color: inherit;
  min-width: 0;
  max-width: 100%;
}

.user-return-detail-page .order-detail-page__item-sku {
  display: block;
  min-width: 0;
  max-width: 100%;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  font-size: 0.8125rem;
  line-height: 1.3;
}
</style>
