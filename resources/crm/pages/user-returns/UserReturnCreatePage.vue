<script setup>
import { computed, inject, onMounted, ref } from "vue";
import { useRoute, useRouter } from "vue-router";
import api from "../../services/api";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast.js";
import { formatRmaLabel } from "../../utils/formatReturnDisplay.js";

const route = useRoute();
const router = useRouter();
const toast = useToast();
const crmUser = inject("crmUser", ref(null));

const loading = ref(true);
const submitBusy = ref(false);
const ret = ref(null);
const formLines = ref([]);
const returnType = ref("direct");
const warehouseNote = ref("");
const reasonOptions = ref({});
const selected = ref(new Set());

const shipheroOrderId = computed(() => String(route.params.shipheroOrderId || ""));
const clientAccountId = computed(() => Number(crmUser.value?.client_account_id || 0));

const accountName = computed(() => String(ret.value?.client_account_company_name || "").trim() || "Save Rack");
const warehouseLines = computed(() => {
  const addr = ret.value?.return_warehouse_address || {};
  return [addr.line1, addr.line2].filter((l) => String(l || "").trim() !== "");
});

const selectedCount = computed(() => selected.value.size);
const allSelected = computed(() => {
  if (!formLines.value.length) return false;
  return formLines.value.every((_, idx) => selected.value.has(idx));
});

const hasReturnQty = computed(() => formLines.value.some((l) => Number(l.return_qty) > 0));

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

function openShippingLabel() {
  if (!ret.value?.id) return;
  openPdf(`/returns/${ret.value.id}/shipping-label.pdf`, "Could not open shipping label.");
}

function openPackingSlip() {
  if (!ret.value?.id || !hasReturnQty.value) return;
  openPdf(`/returns/${ret.value.id}/packing-slip.pdf`, "Could not open return packing slip.");
}

async function cancelDraft() {
  if (!ret.value?.id) {
    router.push({ name: "user-return-create-search" });
    return;
  }
  try {
    await api.delete(`/returns/${ret.value.id}`);
  } catch {
    /* ignore */
  }
  router.push({ name: "user-return-create-search" });
}

async function submitReturn() {
  if (!ret.value?.id) return;
  submitBusy.value = true;
  try {
    const lines = formLines.value.map((row) => ({
      shiphero_line_item_id: row.shiphero_line_item_id || null,
      sku: row.sku,
      name: row.name,
      image_url: row.image_url || null,
      order_qty: Number(row.order_qty) || 0,
      return_qty: Number(row.return_qty) || 0,
      return_reason: Number(row.return_qty) > 0 ? row.return_reason || null : null,
    }));
    const { data } = await api.put(`/returns/${ret.value.id}/submit`, {
      return_type: returnType.value,
      warehouse_private_note: warehouseNote.value.trim() || null,
      lines,
    });
    toast.success("Return created.");
    router.push({ name: "user-return-detail", params: { id: String(data.id) } });
  } catch (e) {
    toast.errorFrom(e, "Could not create return.");
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
      router.push({ name: "user-return-create-search" });
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
    reasonOptions.value = draftRes.data?.return_reasons || {};
    returnType.value = draftRes.data?.return_type || "direct";

    const items = Array.isArray(order.items) ? order.items : [];
    formLines.value = items.map((item) => ({
      shiphero_line_item_id: item.id ? String(item.id) : null,
      sku: String(item.sku || ""),
      name: String(item.name || item.product_name || ""),
      image_url: item.image_url || null,
      order_qty: Math.max(0, Math.floor(Number(item.quantity) || 0)),
      return_qty: 0,
      return_reason: "",
    }));
  } catch (e) {
    toast.errorFrom(e, "Could not start return.");
    router.push({ name: "user-return-create-search" });
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
  <div class="staff-page staff-page--wide user-return-create-page order-detail-page">
    <div v-if="loading" class="py-5">
      <CrmLoadingSpinner message="Preparing return…" />
    </div>

    <template v-else-if="ret">
      <div class="staff-table-card staff-datatable-card staff-datatable-card--white mb-4">
        <div class="p-4 pb-3 d-flex flex-wrap justify-content-between align-items-start gap-3">
          <div>
            <h1 class="h4 mb-1 fw-semibold text-body">Create Return</h1>
            <p class="small text-secondary mb-0">Order {{ ret.order_number || "—" }}</p>
          </div>
          <div class="d-flex flex-wrap gap-2">
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
              :disabled="submitBusy"
              @click="submitReturn"
            >
              Create Return
            </button>
          </div>
        </div>
      </div>

      <div class="row g-4">
        <div class="col-lg-8">
          <div class="staff-table-card staff-datatable-card staff-datatable-card--white p-4 mb-4">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
              <span class="small text-secondary fw-semibold text-uppercase">RMA #</span>
              <button type="button" class="btn btn-sm btn-outline-secondary fw-semibold" @click="copyRma">Copy</button>
            </div>
            <div class="user-return-create-page__rma-display">{{ ret.rma_number }}</div>
            <p class="small text-secondary mb-0 mt-2">{{ formatRmaLabel(ret.rma_number) }}</p>
          </div>

          <div class="staff-table-card staff-datatable-card staff-datatable-card--white p-4 mb-4">
            <h2 class="h6 fw-semibold mb-3">Return Address</h2>
            <div class="user-return-create-page__address small">
              <div class="fw-semibold">{{ accountName }}</div>
              <div>{{ formatRmaLabel(ret.rma_number) }}</div>
              <div v-for="(line, i) in warehouseLines" :key="'addr-' + i">{{ line }}</div>
            </div>
            <button
              type="button"
              class="btn btn-outline-secondary btn-sm fw-semibold orders-toolbar-outline-btn mt-3"
              @click="openShippingLabel"
            >
              View Shipping Label
            </button>
          </div>

          <div class="staff-table-card staff-datatable-card staff-datatable-card--white p-0 mb-4">
            <div class="px-4 py-3 border-bottom d-flex flex-wrap justify-content-between align-items-center gap-2">
              <h2 class="h6 mb-0 fw-semibold">Return Items</h2>
              <div class="d-flex align-items-center gap-2">
                <label class="small text-secondary mb-0" for="return-type-select">Type</label>
                <select id="return-type-select" v-model="returnType" class="form-select form-select-sm" style="width: 10rem">
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
                    <th class="staff-table-head__th text-center" style="width: 2.75rem" scope="col">
                      <input
                        type="checkbox"
                        class="form-check-input m-0"
                        :checked="allSelected"
                        :disabled="!formLines.length"
                        aria-label="Select all items"
                        @change="toggleAll"
                      />
                    </th>
                    <th class="staff-table-head__th" scope="col">Item</th>
                    <th class="staff-table-head__th text-center" scope="col">Order Qty</th>
                    <th class="staff-table-head__th text-center" scope="col">Return Items</th>
                    <th class="staff-table-head__th" scope="col">Reason</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="(row, idx) in formLines" :key="lineKey(idx)">
                    <td class="text-center" @click.stop>
                      <input
                        type="checkbox"
                        class="form-check-input m-0"
                        :checked="selected.has(idx)"
                        :aria-label="`Select ${row.sku}`"
                        @change="toggleOne(idx)"
                      />
                    </td>
                    <td>
                      <div class="d-flex align-items-center gap-2">
                        <img
                          v-if="row.image_url"
                          :src="row.image_url"
                          alt=""
                          class="rounded border flex-shrink-0"
                          width="40"
                          height="40"
                          style="object-fit: cover"
                        />
                        <div class="min-w-0">
                          <div class="small fw-semibold text-truncate">{{ row.name || "—" }}</div>
                          <div class="small text-secondary">{{ row.sku }}</div>
                        </div>
                      </div>
                    </td>
                    <td class="text-center">{{ row.order_qty }}</td>
                    <td class="text-center" style="width: 6rem">
                      <input
                        v-model.number="row.return_qty"
                        type="number"
                        min="0"
                        :max="row.order_qty"
                        class="form-control form-control-sm text-center"
                        @change="clampReturnQty(row)"
                      />
                    </td>
                    <td style="min-width: 11rem">
                      <select v-model="row.return_reason" class="form-select form-select-sm" :disabled="!row.return_qty">
                        <option value="">Select reason</option>
                        <option v-for="(label, key) in reasonOptions" :key="key" :value="key">{{ label }}</option>
                      </select>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <div class="col-lg-4">
          <div class="staff-table-card staff-datatable-card staff-datatable-card--white p-4">
            <label for="warehouse-private-note" class="form-label fw-semibold small mb-2">
              Private note to warehouse only
            </label>
            <textarea
              id="warehouse-private-note"
              v-model="warehouseNote"
              class="form-control"
              rows="5"
              maxlength="20000"
              placeholder="Notes visible to warehouse staff on this return."
            />
          </div>
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped>
.user-return-create-page__rma-display {
  font-size: 2.75rem;
  font-weight: 800;
  letter-spacing: 0.06em;
  line-height: 1.1;
}
.user-return-create-page__address {
  line-height: 1.5;
}
</style>
