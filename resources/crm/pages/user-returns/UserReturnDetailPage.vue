<script setup>
import { computed, inject, onMounted, ref } from "vue";
import { useRoute, useRouter } from "vue-router";
import api from "../../services/api";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast.js";
import {
  formatRmaLabel,
  returnStatusBadgeClass,
  returnStatusLabel,
  returnTypeLabel,
} from "../../utils/formatReturnDisplay.js";

const route = useRoute();
const router = useRouter();
const toast = useToast();
const crmUser = inject("crmUser", ref(null));

const loading = ref(true);
const ret = ref(null);
const noteBusy = ref(false);
const statusBusy = ref(false);
const warehouseNote = ref("");

const returnId = computed(() => String(route.params.id || ""));
const accountName = computed(() => String(ret.value?.client_account_company_name || "").trim() || "Save Rack");
const warehouseLines = computed(() => {
  const addr = ret.value?.return_warehouse_address || {};
  return [addr.line1, addr.line2].filter((l) => String(l || "").trim() !== "");
});
const returnedLines = computed(() =>
  (ret.value?.lines || []).filter((l) => Number(l.return_qty) > 0),
);

async function load() {
  loading.value = true;
  try {
    const { data } = await api.get(`/returns/${returnId.value}`);
    ret.value = data;
    warehouseNote.value = String(data.warehouse_private_note || "");
    setCrmPageMeta({
      title: `Save Rack | ${formatRmaLabel(data.rma_number)}`,
      description: "Return detail.",
    });
  } catch (e) {
    toast.errorFrom(e, "Could not load return.");
    router.push({ name: "user-return-orders" });
  } finally {
    loading.value = false;
  }
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
  openPdf(`/returns/${returnId.value}/shipping-label.pdf`, "Could not open shipping label.");
}

function openPackingSlip() {
  openPdf(`/returns/${returnId.value}/packing-slip.pdf`, "Could not open return packing slip.");
}

function openRmaBarcode() {
  openPdf(`/returns/${returnId.value}/rma-barcode.pdf`, "Could not open RMA barcode.");
}

async function saveNote() {
  if (!ret.value?.id) return;
  noteBusy.value = true;
  try {
    const { data } = await api.patch(`/returns/${ret.value.id}/warehouse-note`, {
      warehouse_private_note: warehouseNote.value.trim() || null,
    });
    ret.value = data;
    toast.success("Note saved.");
  } catch (e) {
    toast.errorFrom(e, "Could not save note.");
  } finally {
    noteBusy.value = false;
  }
}

async function updateStatus(next) {
  if (!ret.value?.id || ret.value.status === next) return;
  statusBusy.value = true;
  try {
    const { data } = await api.patch(`/returns/${ret.value.id}`, { status: next });
    ret.value = data;
    toast.success("Status updated.");
  } catch (e) {
    toast.errorFrom(e, "Could not update status.");
  } finally {
    statusBusy.value = false;
  }
}

onMounted(() => {
  setCrmPageMeta({ title: "Save Rack | Return", description: "Return detail." });
  load();
});
</script>

<template>
  <div class="staff-page staff-page--wide user-return-detail-page order-detail-page">
    <div v-if="loading" class="py-5">
      <CrmLoadingSpinner message="Loading return…" />
    </div>

    <template v-else-if="ret">
      <div class="staff-table-card staff-datatable-card staff-datatable-card--white mb-4">
        <div class="p-4 pb-3 d-flex flex-wrap justify-content-between align-items-start gap-3">
          <div class="min-w-0">
            <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
              <h1 class="h4 mb-0 fw-semibold text-body">Order {{ ret.order_number || "—" }}</h1>
              <span class="badge rounded-pill fw-medium" :class="returnStatusBadgeClass(ret.status)">
                {{ returnStatusLabel(ret.status) }}
              </span>
            </div>
            <p class="small text-secondary mb-0">{{ ret.customer_name || "—" }} · {{ returnTypeLabel(ret.return_type) }}</p>
            <button
              type="button"
              class="btn btn-link btn-sm text-secondary px-0 py-0 mt-1 text-decoration-none"
              @click="router.push({ name: 'user-return-orders' })"
            >
              &lt; Returned Orders
            </button>
          </div>
          <div class="d-flex flex-wrap gap-2 align-items-center">
            <select
              class="form-select form-select-sm"
              style="width: 10rem"
              :value="ret.status"
              :disabled="statusBusy"
              @change="updateStatus($event.target.value)"
            >
              <option value="pending">Pending</option>
              <option value="received">Received</option>
              <option value="completed">Completed</option>
            </select>
            <button
              type="button"
              class="btn btn-outline-secondary btn-sm fw-semibold orders-toolbar-outline-btn"
              @click="openPackingSlip"
            >
              Return Packing Slip
            </button>
            <button
              type="button"
              class="btn btn-outline-secondary btn-sm fw-semibold orders-toolbar-outline-btn"
              @click="openShippingLabel"
            >
              View Shipping Label
            </button>
            <button
              type="button"
              class="btn btn-outline-secondary btn-sm fw-semibold orders-toolbar-outline-btn"
              @click="openRmaBarcode"
            >
              RMA Barcode
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
            <div class="user-return-detail-page__rma-display">{{ ret.rma_number }}</div>
          </div>

          <div class="staff-table-card staff-datatable-card staff-datatable-card--white p-4 mb-4">
            <h2 class="h6 fw-semibold mb-3">Return Address</h2>
            <div class="small user-return-detail-page__address">
              <div class="fw-semibold">{{ accountName }}</div>
              <div>{{ formatRmaLabel(ret.rma_number) }}</div>
              <div v-for="(line, i) in warehouseLines" :key="'addr-' + i">{{ line }}</div>
            </div>
          </div>

          <div class="staff-table-card staff-datatable-card staff-datatable-card--white p-0 mb-4">
            <div class="px-4 py-3 border-bottom">
              <h2 class="h6 mb-0 fw-semibold">Returned Items</h2>
            </div>
            <div class="table-responsive staff-table-wrap">
              <table class="table table-hover align-middle mb-0 staff-data-table">
                <thead class="table-light staff-table-head">
                  <tr>
                    <th class="staff-table-head__th" scope="col">Item</th>
                    <th class="staff-table-head__th text-center" scope="col">Return Qty</th>
                    <th class="staff-table-head__th" scope="col">Reason</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-if="!returnedLines.length">
                    <td colspan="3" class="text-center text-secondary py-4">No items on this return.</td>
                  </tr>
                  <tr v-for="line in returnedLines" :key="line.id">
                    <td>
                      <div class="d-flex align-items-center gap-2">
                        <img
                          v-if="line.image_url"
                          :src="line.image_url"
                          alt=""
                          class="rounded border flex-shrink-0"
                          width="40"
                          height="40"
                          style="object-fit: cover"
                        />
                        <div class="min-w-0">
                          <div class="small fw-semibold">{{ line.name }}</div>
                          <div class="small text-secondary">{{ line.sku }}</div>
                        </div>
                      </div>
                    </td>
                    <td class="text-center">{{ line.return_qty }}</td>
                    <td class="small">{{ line.return_reason_label || "—" }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <div class="col-lg-4">
          <div class="staff-table-card staff-datatable-card staff-datatable-card--white p-4">
            <label for="return-detail-note" class="form-label fw-semibold small mb-2">
              Private note to warehouse only
            </label>
            <textarea
              id="return-detail-note"
              v-model="warehouseNote"
              class="form-control mb-2"
              rows="5"
              maxlength="20000"
            />
            <button
              type="button"
              class="btn btn-primary btn-sm staff-page-primary fw-semibold"
              :disabled="noteBusy"
              @click="saveNote"
            >
              Save Note
            </button>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped>
.user-return-detail-page__rma-display {
  font-size: 2.75rem;
  font-weight: 800;
  letter-spacing: 0.06em;
}
.user-return-detail-page__address {
  line-height: 1.5;
}
</style>
