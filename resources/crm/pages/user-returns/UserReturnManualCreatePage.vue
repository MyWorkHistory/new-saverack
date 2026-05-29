<script setup>
import { computed, inject, onMounted, ref } from "vue";
import { useRoute, useRouter } from "vue-router";
import api from "../../services/api";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast.js";
import { formatRmaLabel } from "../../utils/formatReturnDisplay.js";

const CATALOG_PAGE_SIZE = 50;

const route = useRoute();
const router = useRouter();
const toast = useToast();
const crmUser = inject("crmUser", ref(null));

const orderNumber = ref("");
const customerName = ref("");
const returnType = ref("direct");
const startBusy = ref(false);
const submitBusy = ref(false);
const noteBusy = ref(false);

const ret = ref(null);
const formLines = ref([]);
const reasonOptions = ref({});
const warehouseNote = ref("");

const catalog = ref([]);
const catalogLoading = ref(false);
const catalogLoadingMore = ref(false);
const catalogSearchDraft = ref("");
const catalogSearchCommitted = ref("");
const catalogPageInfo = ref({ has_next_page: false, end_cursor: null });
const catalogQtyByKey = ref({});

const clientAccountId = computed(() => Number(crmUser.value?.client_account_id || 0));
const accountName = computed(() => String(ret.value?.client_account_company_name || "").trim() || "Save Rack");
const warehouseLines = computed(() => {
  const addr = ret.value?.return_warehouse_address || {};
  return [addr.line1, addr.line2].filter((l) => String(l || "").trim() !== "");
});
const hasReturnQty = computed(() => formLines.value.some((l) => Number(l.return_qty) > 0));

function catalogKey(p) {
  return String(p.id || p.sku || "");
}

function catalogQty(p) {
  const k = catalogKey(p);
  const n = Number(catalogQtyByKey.value[k]);
  return Number.isFinite(n) && n > 0 ? n : 1;
}

function setCatalogQty(p, v) {
  const k = catalogKey(p);
  catalogQtyByKey.value = { ...catalogQtyByKey.value, [k]: Math.max(1, Number(v) || 1) };
}

function lineRowKey(line, idx) {
  return `${line.sku}-${idx}`;
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

async function loadCatalogRows(reset) {
  if (!clientAccountId.value) return;
  if (reset) {
    catalogLoading.value = true;
    catalog.value = [];
    catalogPageInfo.value = { has_next_page: false, end_cursor: null };
  } else {
    catalogLoadingMore.value = true;
  }
  try {
    const params = {
      client_account_id: clientAccountId.value,
      first: CATALOG_PAGE_SIZE,
    };
    const q = catalogSearchCommitted.value.trim();
    if (q) params.query = q;
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
    const dest = reset ? [] : [...catalog.value];
    const seen = new Set(dest.map((p) => catalogKey(p)));
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
  }
}

function commitCatalogSearch() {
  catalogSearchCommitted.value = catalogSearchDraft.value.trim();
  loadCatalogRows(true);
}

function addFromCatalog(p) {
  const sku = String(p.sku || "").trim();
  if (!sku) return;
  const qty = catalogQty(p);
  const existing = formLines.value.find((l) => String(l.sku) === sku);
  if (existing) {
    existing.return_qty = Number(existing.return_qty || 0) + qty;
    return;
  }
  formLines.value = [
    ...formLines.value,
    {
      sku,
      name: String(p.name || sku),
      image_url: p.image_url || null,
      return_qty: qty,
      return_reason: "",
    },
  ];
}

function removeLine(idx) {
  formLines.value = formLines.value.filter((_, i) => i !== idx);
}

async function startManualReturn() {
  const num = orderNumber.value.trim().replace(/^#+/, "");
  const name = customerName.value.trim();
  if (!num) {
    toast.error("Enter an order number.");
    return;
  }
  if (!name) {
    toast.error("Enter a recipient name.");
    return;
  }
  if (!clientAccountId.value) return;
  startBusy.value = true;
  try {
    const { data } = await api.post("/returns/draft", {
      client_account_id: clientAccountId.value,
      manual: true,
      order_number: num,
      customer_name: name,
      return_type: returnType.value,
    });
    ret.value = data;
    reasonOptions.value = data?.return_reasons || {};
    warehouseNote.value = String(data.warehouse_private_note || "");
    returnType.value = data?.return_type || returnType.value;
    toast.success("Manual return started. Add products from the catalog.");
    loadCatalogRows(true);
  } catch (e) {
    toast.errorFrom(e, "Could not start manual return.");
  } finally {
    startBusy.value = false;
  }
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

async function cancelDraft() {
  if (ret.value?.id) {
    try {
      await api.delete(`/returns/${ret.value.id}`);
    } catch {
      /* ignore */
    }
  }
  router.push({ name: "user-return-create-search" });
}

async function submitReturn() {
  if (!ret.value?.id) return;
  submitBusy.value = true;
  try {
    const lines = formLines.value.map((row) => ({
      sku: row.sku,
      name: row.name,
      image_url: row.image_url || null,
      order_qty: Number(row.return_qty) || 0,
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

onMounted(() => {
  setCrmPageMeta({
    title: "Save Rack | Manual Return",
    description: "Create a return without a ShipHero order.",
  });
  const qNum = String(route.query.order_number || "").trim().replace(/^#+/, "");
  if (qNum) orderNumber.value = qNum;
});
</script>

<template>
  <div class="staff-page staff-page--wide user-return-page user-return-detail-page order-detail-page">
    <div class="d-flex flex-wrap align-items-end justify-content-between gap-3 mb-4">
      <div>
        <h1 class="h4 mb-1 fw-semibold text-body">Manual Return</h1>
        <p class="text-secondary small mb-0">
          Enter order details and add return items from your product catalog.
        </p>
      </div>
      <button
        type="button"
        class="btn btn-outline-secondary btn-sm fw-semibold orders-toolbar-outline-btn"
        @click="router.push({ name: 'user-return-create-search' })"
      >
        Back to Search
      </button>
    </div>

    <div
      v-if="!ret"
      class="staff-table-card staff-datatable-card staff-datatable-card--white p-4 mb-4"
    >
      <h2 class="h6 fw-semibold mb-3">Order Details</h2>
      <div class="row g-3">
        <div class="col-md-4">
          <label class="form-label small" for="manual-order-number">Order Number</label>
          <input
            id="manual-order-number"
            v-model="orderNumber"
            type="text"
            class="form-control"
            autocomplete="off"
          />
        </div>
        <div class="col-md-4">
          <label class="form-label small" for="manual-recipient">Recipient Name</label>
          <input
            id="manual-recipient"
            v-model="customerName"
            type="text"
            class="form-control"
            autocomplete="name"
          />
        </div>
        <div class="col-md-4">
          <label class="form-label small" for="manual-return-type">Return Type</label>
          <select id="manual-return-type" v-model="returnType" class="form-select">
            <option value="direct">Direct</option>
            <option value="amazon">Amazon</option>
            <option value="nordstrom">Nordstrom</option>
          </select>
        </div>
      </div>
      <div class="mt-3">
        <button
          type="button"
          class="btn btn-primary staff-page-primary fw-semibold"
          :disabled="startBusy"
          @click="startManualReturn"
        >
          {{ startBusy ? "Starting…" : "Start Manual Return" }}
        </button>
      </div>
    </div>

    <template v-else>
      <div class="staff-table-card staff-datatable-card staff-datatable-card--white user-return-page__header-shell mb-4">
        <div class="p-4 pb-3">
          <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
            <div class="min-w-0">
              <h2 class="h5 mb-0 fw-semibold text-body">Order {{ ret.order_number || "—" }}</h2>
              <p class="small text-secondary mb-0 mt-2">{{ ret.customer_name || "—" }}</p>
            </div>
            <div class="d-flex flex-wrap gap-2 flex-shrink-0">
              <button
                type="button"
                class="btn btn-outline-secondary btn-sm fw-semibold orders-toolbar-outline-btn"
                :disabled="!hasReturnQty"
                @click="openPackingSlip"
              >
                Return Packing Slip
              </button>
              <button
                type="button"
                class="btn btn-outline-secondary btn-sm fw-semibold"
                @click="openShippingLabel"
              >
                View Shipping Label
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
      </div>

      <div class="row g-4">
        <div class="col-lg-8">
          <div class="staff-table-card staff-datatable-card staff-datatable-card--white p-0 mb-4">
            <div class="px-4 py-3 border-bottom">
              <h2 class="h6 mb-0 fw-semibold">Add Products</h2>
            </div>
            <div class="staff-table-toolbar border-bottom">
              <div class="staff-table-toolbar--row flex-wrap align-items-end gap-2 gap-md-3">
                <input
                  v-model.trim="catalogSearchDraft"
                  type="search"
                  class="form-control staff-toolbar-search staff-toolbar-search--inline"
                  placeholder="Search by SKU or name"
                  autocomplete="off"
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
              </div>
            </div>
            <div class="p-4 bg-body-tertiary border-bottom">
              <div v-if="catalogLoading" class="d-flex justify-content-center py-4">
                <CrmLoadingSpinner message="Loading products…" />
              </div>
              <template v-else>
                <div class="user-return-manual-catalog border rounded bg-white">
                  <div
                    v-for="p in catalog"
                    :key="catalogKey(p)"
                    class="user-return-manual-catalog__row d-flex align-items-center gap-2 border-bottom py-2 px-2"
                  >
                    <img
                      v-if="p.image_url"
                      :src="p.image_url"
                      alt=""
                      class="user-return-manual-catalog__thumb"
                      loading="lazy"
                    />
                    <div v-else class="user-return-manual-catalog__thumb user-return-manual-catalog__thumb--empty" />
                    <div class="min-w-0 flex-grow-1">
                      <div class="fw-semibold small text-truncate">{{ p.sku }}</div>
                      <div class="text-secondary small text-truncate">{{ p.name }}</div>
                    </div>
                    <input
                      type="number"
                      min="1"
                      class="form-control form-control-sm user-return-manual-catalog__qty"
                      :value="catalogQty(p)"
                      @input="setCatalogQty(p, $event.target.value)"
                    />
                    <button
                      type="button"
                      class="btn btn-sm btn-primary staff-page-primary"
                      @click="addFromCatalog(p)"
                    >
                      Add
                    </button>
                  </div>
                  <div v-if="catalog.length === 0" class="p-3 small text-secondary">No products found.</div>
                </div>
                <div v-if="catalogPageInfo.has_next_page" class="d-flex justify-content-center mt-3">
                  <button
                    type="button"
                    class="btn btn-sm btn-outline-secondary fw-semibold"
                    :disabled="catalogLoadingMore"
                    @click="loadCatalogRows(false)"
                  >
                    {{ catalogLoadingMore ? "Loading…" : "Load 50 More" }}
                  </button>
                </div>
              </template>
            </div>
          </div>

          <div class="staff-table-card staff-datatable-card staff-datatable-card--white p-0 mb-4">
            <div class="px-4 py-3 border-bottom">
              <h2 class="h6 mb-0 fw-semibold">Return Items</h2>
            </div>
            <div class="table-responsive staff-table-wrap">
              <table class="table table-hover align-middle mb-0 staff-data-table">
                <thead class="table-light staff-table-head">
                  <tr>
                    <th class="staff-table-head__th order-detail-page__items-col" scope="col">Product</th>
                    <th class="staff-table-head__th text-center" scope="col">Return Qty</th>
                    <th class="staff-table-head__th" scope="col">Reason</th>
                    <th class="staff-table-head__th text-center" scope="col" style="width: 5rem">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-if="!formLines.length">
                    <td colspan="4" class="text-center text-secondary py-4">
                      Add products from the catalog above.
                    </td>
                  </tr>
                  <tr v-for="(line, idx) in formLines" :key="lineRowKey(line, idx)">
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
                          <div class="order-detail-page__item-name fw-semibold">{{ line.name }}</div>
                          <div class="order-detail-page__item-sku small text-secondary">{{ line.sku }}</div>
                        </div>
                      </div>
                    </td>
                    <td class="text-center user-return-page__qty-col">
                      <input
                        v-model.number="line.return_qty"
                        type="number"
                        min="1"
                        class="form-control form-control-sm text-center"
                      />
                    </td>
                    <td class="user-return-page__reason-col">
                      <select v-model="line.return_reason" class="form-select form-select-sm">
                        <option value="">Select reason</option>
                        <option v-for="(label, key) in reasonOptions" :key="key" :value="key">{{ label }}</option>
                      </select>
                    </td>
                    <td class="text-center">
                      <button
                        type="button"
                        class="btn btn-link btn-sm text-danger px-1"
                        @click="removeLine(idx)"
                      >
                        Remove
                      </button>
                    </td>
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
              <button type="button" class="btn btn-sm btn-outline-secondary fw-semibold" @click="copyRma">Copy</button>
            </div>
            <div class="user-return-page__rma-display">{{ ret.rma_number }}</div>
            <p class="small text-secondary mb-0 mt-2">{{ formatRmaLabel(ret.rma_number) }}</p>
          </div>

          <div class="staff-table-card staff-datatable-card staff-datatable-card--white p-4">
            <h3 class="h6 fw-semibold mb-3">Return Address</h3>
            <div class="user-return-page__address-block small">
              <p class="mb-1 fw-semibold text-body">{{ accountName }}</p>
              <p class="mb-1 fw-semibold text-body">{{ formatRmaLabel(ret.rma_number) }}</p>
              <p v-for="(line, i) in warehouseLines" :key="'addr-' + i" class="mb-0 text-secondary">{{ line }}</p>
            </div>
            <button
              type="button"
              class="btn btn-outline-secondary btn-sm fw-semibold orders-toolbar-outline-btn mt-3 w-100"
              @click="openShippingLabel"
            >
              View Shipping Label
            </button>
          </div>

          <div class="staff-table-card staff-datatable-card staff-datatable-card--white p-4">
            <h3 class="h6 fw-semibold mb-3">Private Note</h3>
            <p class="small text-secondary mb-2">Private note to warehouse only</p>
            <textarea
              v-model="warehouseNote"
              class="form-control form-control-sm mb-3"
              rows="5"
              maxlength="20000"
            />
            <button
              type="button"
              class="btn btn-primary btn-sm staff-page-primary fw-semibold w-100"
              :disabled="noteBusy"
              @click="saveNote"
            >
              {{ noteBusy ? "Saving…" : "Save Note" }}
            </button>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped>
.user-return-page__rma-display {
  font-size: 2.25rem;
  font-weight: 800;
  letter-spacing: 0.06em;
  line-height: 1.1;
}
.user-return-page__qty-col {
  width: 6.5rem;
}
.user-return-page__reason-col {
  min-width: 11rem;
}
.user-return-page__address-block {
  line-height: 1.5;
}
.user-return-manual-catalog {
  max-height: 280px;
  overflow: auto;
}
.user-return-manual-catalog__thumb {
  width: 40px;
  height: 40px;
  border-radius: 0.35rem;
  object-fit: cover;
  border: 1px solid rgba(0, 0, 0, 0.08);
  flex-shrink: 0;
}
.user-return-manual-catalog__thumb--empty {
  display: block;
  background: rgba(0, 0, 0, 0.05);
}
.user-return-manual-catalog__qty {
  width: 4.5rem;
}
</style>
