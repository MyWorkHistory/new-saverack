<script setup>
import { computed, inject, onMounted, ref, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import api from "../../services/api";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import ConfirmModal from "../../components/common/ConfirmModal.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast.js";
import { CARRIER_PRESETS } from "../../utils/carrierPresets.js";
import { formatDateUs } from "../../utils/formatUserDates.js";

const SHIP_TO_ADDRESS_LINES = ["3135 Drane Field Rd #20", "Lakeland, FL 33811"];

const toast = useToast();
const route = useRoute();
const router = useRouter();
const crmUser = inject("crmUser", ref(null));

const loading = ref(true);
const asn = ref(null);
const headerBusy = ref(false);
const notesBusy = ref(false);
const trackingBusy = ref(false);
const vendorBusy = ref(false);
const lineBusy = ref(false);

const catalog = ref([]);
const catalogLoading = ref(false);
const catalogFilter = ref("");
const addPanelOpen = ref(false);
const manualSku = ref("");
const manualName = ref("");
const manualQty = ref(1);

const trackingDraft = ref([{ carrier: "", tracking_number: "" }]);
const vendorDraft = ref([{ label: "" }]);

const editLineOpen = ref(false);
const editLine = ref(null);
const editExpected = ref(0);
const editAccepted = ref(0);
const editRejected = ref(0);
const deleteLineOpen = ref(false);
const lineToDelete = ref(null);

const clientAccountId = computed(() => Number(crmUser.value?.client_account_id || 0));
const asnId = computed(() => String(route.params.id || ""));

const filteredCatalog = computed(() => {
  const q = catalogFilter.value.trim().toLowerCase();
  if (!q) return catalog.value;
  return catalog.value.filter(
    (p) =>
      String(p.sku || "")
        .toLowerCase()
        .includes(q) || String(p.name || "").toLowerCase().includes(q),
  );
});

function statusLabel(s) {
  if (s === "in_progress") return "In Progress";
  if (s === "completed") return "Completed";
  return "Pending";
}

function statusBadgeClass(status) {
  const s = String(status || "").toLowerCase();
  if (s === "pending") return "bg-secondary-subtle text-secondary-emphasis";
  if (s === "in_progress") return "bg-primary-subtle text-primary-emphasis";
  if (s === "completed") return "bg-success-subtle text-success-emphasis";
  return "bg-body-secondary text-body-secondary";
}

function syncDraftsFromAsn() {
  if (!asn.value) return;
  const t = (asn.value.trackings || []).length
    ? asn.value.trackings.map((x) => ({ carrier: x.carrier || "", tracking_number: x.tracking_number || "" }))
    : [{ carrier: "", tracking_number: "" }];
  trackingDraft.value = t;
  const v = (asn.value.vendor_lines || []).length
    ? asn.value.vendor_lines.map((x) => ({ label: x.label || "" }))
    : [{ label: "" }];
  vendorDraft.value = v;
}

async function loadAsn() {
  if (!asnId.value || !clientAccountId.value) {
    loading.value = false;
    return;
  }
  loading.value = true;
  try {
    const { data } = await api.get(`/asns/${asnId.value}`);
    asn.value = data;
    syncDraftsFromAsn();
  } catch (e) {
    toast.errorFrom(e, "Could not load ASN.");
    asn.value = null;
  } finally {
    loading.value = false;
  }
}

async function loadCatalog() {
  if (!clientAccountId.value || catalog.value.length) return;
  catalogLoading.value = true;
  try {
    const { data } = await api.get("/inventory/asn-product-catalog", {
      params: { client_account_id: clientAccountId.value },
    });
    catalog.value = data.products || [];
    if (data.truncated) {
      toast.success("Product catalog hit the maximum page cap; some products may be missing from the list.");
    }
  } catch (e) {
    toast.errorFrom(e, "Could not load product catalog.");
  } finally {
    catalogLoading.value = false;
  }
}

watch(addPanelOpen, (open) => {
  if (open) loadCatalog();
});

async function saveHeader() {
  if (!asn.value) return;
  headerBusy.value = true;
  try {
    const { data } = await api.patch(`/asns/${asn.value.id}`, {
      status: asn.value.status,
      date_received: asn.value.date_received ? asn.value.date_received : null,
      total_boxes: asn.value.total_boxes,
      total_pallets: asn.value.total_pallets,
    });
    asn.value = data;
    syncDraftsFromAsn();
    toast.success("ASN details saved.");
  } catch (e) {
    toast.errorFrom(e, "Save failed.");
  } finally {
    headerBusy.value = false;
  }
}

async function saveNotes() {
  if (!asn.value) return;
  notesBusy.value = true;
  try {
    const { data } = await api.patch(`/asns/${asn.value.id}/warehouse-notes`, {
      warehouse_notes: asn.value.warehouse_notes,
    });
    asn.value = data;
    syncDraftsFromAsn();
    toast.success("Warehouse notes saved.");
  } catch (e) {
    toast.errorFrom(e, "Save failed.");
  } finally {
    notesBusy.value = false;
  }
}

async function saveTrackings() {
  if (!asn.value) return;
  trackingBusy.value = true;
  try {
    const { data } = await api.put(`/asns/${asn.value.id}/trackings`, {
      trackings: trackingDraft.value,
    });
    asn.value = data;
    syncDraftsFromAsn();
    toast.success("Tracking saved.");
  } catch (e) {
    toast.errorFrom(e, "Save failed.");
  } finally {
    trackingBusy.value = false;
  }
}

async function saveVendors() {
  if (!asn.value) return;
  vendorBusy.value = true;
  try {
    const { data } = await api.put(`/asns/${asn.value.id}/vendor-lines`, {
      vendor_lines: vendorDraft.value.filter((v) => String(v.label || "").trim() !== ""),
    });
    asn.value = data;
    syncDraftsFromAsn();
    toast.success("Vendor details saved.");
  } catch (e) {
    toast.errorFrom(e, "Save failed.");
  } finally {
    vendorBusy.value = false;
  }
}

function addTrackingRow() {
  trackingDraft.value = [...trackingDraft.value, { carrier: "", tracking_number: "" }];
}

function addVendorRow() {
  vendorDraft.value = [...vendorDraft.value, { label: "" }];
}

function removeVendorRow(i) {
  vendorDraft.value = vendorDraft.value.filter((_, idx) => idx !== i);
  if (vendorDraft.value.length === 0) vendorDraft.value = [{ label: "" }];
}

async function addFromCatalog(p) {
  if (!asn.value) return;
  lineBusy.value = true;
  try {
    await api.post(`/asns/${asn.value.id}/lines`, {
      shiphero_product_id: p.id,
      sku: p.sku,
      name: p.name,
      expected_qty: 1,
    });
    toast.success("Line added.");
    await loadAsn();
  } catch (e) {
    toast.errorFrom(e, "Could not add line.");
  } finally {
    lineBusy.value = false;
  }
}

async function addManualLine() {
  if (!asn.value) return;
  const sku = manualSku.value.trim();
  const name = manualName.value.trim();
  if (!sku || !name) {
    toast.error("Enter SKU and name.");
    return;
  }
  lineBusy.value = true;
  try {
    await api.post(`/asns/${asn.value.id}/lines`, {
      sku,
      name,
      expected_qty: Math.max(0, Number(manualQty.value) || 0),
    });
    manualSku.value = "";
    manualName.value = "";
    manualQty.value = 1;
    toast.success("Line added.");
    await loadAsn();
  } catch (e) {
    toast.errorFrom(e, "Could not add line.");
  } finally {
    lineBusy.value = false;
  }
}

function openEditLine(row) {
  editLine.value = row;
  editExpected.value = row.expected_qty;
  editAccepted.value = row.accepted_qty;
  editRejected.value = row.rejected_qty;
  editLineOpen.value = true;
}

async function saveEditLine() {
  if (!asn.value || !editLine.value) return;
  lineBusy.value = true;
  try {
    await api.patch(`/asns/${asn.value.id}/lines/${editLine.value.id}`, {
      expected_qty: editExpected.value,
      accepted_qty: editAccepted.value,
      rejected_qty: editRejected.value,
    });
    editLineOpen.value = false;
    toast.success("Line updated.");
    await loadAsn();
  } catch (e) {
    toast.errorFrom(e, "Update failed.");
  } finally {
    lineBusy.value = false;
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
    toast.success("Line removed.");
    await loadAsn();
  } catch (e) {
    toast.errorFrom(e, "Delete failed.");
  } finally {
    lineBusy.value = false;
  }
}

function openPrintSlip() {
  const r = router.resolve({
    name: "user-asn-print-packing-slip",
    params: { id: asnId.value },
    query: { client_account_id: String(clientAccountId.value) },
  });
  window.open(r.href, "_blank", "noopener");
}

function openPrintLabel() {
  const r = router.resolve({
    name: "user-asn-print-shipping-label",
    params: { id: asnId.value },
    query: { client_account_id: String(clientAccountId.value) },
  });
  window.open(r.href, "_blank", "noopener");
}

function openPrintBarcode(line) {
  const r = router.resolve({
    name: "user-asn-print-barcode",
    params: { asnId: asnId.value, lineId: String(line.id) },
    query: { client_account_id: String(clientAccountId.value) },
  });
  window.open(r.href, "_blank", "noopener");
}

onMounted(() => {
  setCrmPageMeta({ title: "Save Rack | ASN", description: "ASN detail." });
  loadAsn();
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
              <h1 class="h4 mb-0 fw-semibold text-body">{{ asn.asn_number }}</h1>
              <span class="badge rounded-pill fw-medium" :class="statusBadgeClass(asn.status)">{{
                statusLabel(asn.status)
              }}</span>
            </div>
            <div class="d-flex flex-wrap gap-2 align-items-center mt-2">
              <label class="small text-secondary mb-0" for="asn-detail-status">Status</label>
              <select id="asn-detail-status" v-model="asn.status" class="form-select form-select-sm" style="width: 11rem">
                <option value="pending">Pending</option>
                <option value="in_progress">In Progress</option>
                <option value="completed">Completed</option>
              </select>
            </div>
          </div>
          <div class="d-flex flex-wrap gap-2 flex-shrink-0">
            <button type="button" class="btn btn-outline-secondary btn-sm fw-semibold" @click="openPrintSlip">
              Print Packing Slip
            </button>
            <RouterLink to="/users/asn" class="btn btn-sm btn-outline-secondary fw-semibold">Back To List</RouterLink>
          </div>
        </div>
      </div>
    </div>

    <div class="row g-4">
      <div class="col-lg-8">
        <div class="staff-table-card staff-datatable-card staff-datatable-card--white p-0 mb-4">
          <div class="px-4 py-3 border-bottom d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h2 class="h6 mb-0 fw-semibold">Items</h2>
            <button
              type="button"
              class="btn btn-sm btn-primary staff-page-primary"
              @click="addPanelOpen = !addPanelOpen"
            >
              {{ addPanelOpen ? "Hide Add Panel" : "Add Items" }}
            </button>
          </div>

          <div v-show="addPanelOpen">
            <div v-if="!catalogLoading" class="staff-table-toolbar border-bottom">
              <div class="staff-table-toolbar--row flex-wrap align-items-end gap-2 gap-md-3">
                <input
                  id="asn-catalog-filter"
                  v-model="catalogFilter"
                  type="search"
                  class="form-control staff-toolbar-search staff-toolbar-search--inline"
                  placeholder="Filter catalog by SKU or name"
                  autocomplete="off"
                  aria-label="Filter catalog"
                />
              </div>
            </div>
            <div class="p-4 bg-body-tertiary border-bottom">
              <div v-if="catalogLoading" class="d-flex justify-content-center py-4">
                <CrmLoadingSpinner message="Loading catalog…" />
              </div>
              <template v-else>
                <p class="small fw-semibold mb-2">From catalog</p>
                <div class="catalog-pick-list border rounded bg-white" style="max-height: 220px; overflow: auto">
                  <button
                    v-for="p in filteredCatalog"
                    :key="p.id + p.sku"
                    type="button"
                    class="dropdown-item text-start py-2 border-bottom w-100"
                    :disabled="lineBusy"
                    @click="addFromCatalog(p)"
                  >
                    <span class="fw-semibold">{{ p.sku }}</span>
                    <span class="text-secondary small d-block text-truncate">{{ p.name }}</span>
                  </button>
                  <div v-if="filteredCatalog.length === 0" class="p-3 small text-secondary">No matches.</div>
                </div>
                <hr class="my-3" />
                <p class="small fw-semibold mb-2">Create line manually</p>
                <div class="row g-2 align-items-end">
                  <div class="col-md-3">
                    <label class="form-label small mb-0">SKU</label>
                    <input v-model="manualSku" class="form-control form-control-sm" />
                  </div>
                  <div class="col-md-5">
                    <label class="form-label small mb-0">Name</label>
                    <input v-model="manualName" class="form-control form-control-sm" />
                  </div>
                  <div class="col-md-2">
                    <label class="form-label small mb-0">Qty</label>
                    <input v-model.number="manualQty" type="number" min="0" class="form-control form-control-sm" />
                  </div>
                  <div class="col-md-2">
                    <button
                      type="button"
                      class="btn btn-sm btn-outline-primary w-100 fw-semibold"
                      :disabled="lineBusy"
                      @click="addManualLine"
                    >
                      Add
                    </button>
                  </div>
                </div>
              </template>
            </div>
          </div>

          <div class="table-responsive staff-table-wrap">
            <table class="table table-hover align-middle mb-0 staff-data-table">
              <thead class="table-light staff-table-head">
                <tr>
                  <th class="staff-table-head__th">SKU</th>
                  <th class="staff-table-head__th">Name</th>
                  <th class="staff-table-head__th text-end">Expected</th>
                  <th class="staff-table-head__th text-end">Accepted</th>
                  <th class="staff-table-head__th text-end">Rejected</th>
                  <th class="staff-table-head__th text-end">Actions</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="line in asn.lines" :key="line.id">
                  <td class="fw-medium">{{ line.sku }}</td>
                  <td class="small">{{ line.name }}</td>
                  <td class="text-end">{{ line.expected_qty }}</td>
                  <td class="text-end">{{ line.accepted_qty }}</td>
                  <td class="text-end">{{ line.rejected_qty }}</td>
                  <td class="text-end text-nowrap">
                    <button type="button" class="btn btn-link btn-sm p-0 me-2" @click="openEditLine(line)">Edit</button>
                    <button type="button" class="btn btn-link btn-sm p-0 me-2 text-danger" @click="askDeleteLine(line)">
                      Delete
                    </button>
                    <button type="button" class="btn btn-link btn-sm p-0" @click="openPrintBarcode(line)">
                      Print Barcode
                    </button>
                  </td>
                </tr>
                <tr v-if="!asn.lines?.length">
                  <td colspan="6" class="text-center text-secondary py-4">No lines yet.</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <div class="col-lg-4 d-flex flex-column gap-4 order-detail-page__side-column">
        <div class="staff-table-card staff-datatable-card staff-datatable-card--white p-4">
          <h3 class="h6 fw-semibold mb-3">ASN Details</h3>
          <div class="mb-2">
            <label class="form-label small mb-0">Date Created</label>
            <div class="small text-secondary">{{ formatDateUs(asn.created_at) }}</div>
          </div>
          <div class="mb-2">
            <label class="form-label small mb-0">Date Received</label>
            <input v-model="asn.date_received" type="date" class="form-control form-control-sm" />
          </div>
          <div class="mb-2">
            <label class="form-label small mb-0">Total Boxes</label>
            <input v-model.number="asn.total_boxes" type="number" min="0" class="form-control form-control-sm" />
          </div>
          <div class="mb-3">
            <label class="form-label small mb-0">Total Pallets</label>
            <input v-model.number="asn.total_pallets" type="number" min="0" class="form-control form-control-sm" />
          </div>
          <button
            type="button"
            class="btn btn-primary btn-sm staff-page-primary w-100"
            :disabled="headerBusy"
            @click="saveHeader"
          >
            Save ASN Details
          </button>
        </div>

        <div class="staff-table-card staff-datatable-card staff-datatable-card--white p-4">
          <h3 class="h6 fw-semibold mb-3">Tracking Details</h3>
          <div v-for="(row, idx) in trackingDraft" :key="'t' + idx" class="mb-2">
            <div class="row g-1">
              <div class="col-5">
                <select v-model="row.carrier" class="form-select form-select-sm">
                  <option value="">Carrier</option>
                  <option v-for="c in CARRIER_PRESETS" :key="c" :value="c">{{ c }}</option>
                </select>
              </div>
              <div class="col-7">
                <input v-model="row.tracking_number" class="form-control form-control-sm" placeholder="Tracking #" />
              </div>
            </div>
          </div>
          <button type="button" class="btn btn-link btn-sm px-0 mb-2" @click="addTrackingRow">Add Tracking Row</button>
          <button
            type="button"
            class="btn btn-primary btn-sm staff-page-primary w-100"
            :disabled="trackingBusy"
            @click="saveTrackings"
          >
            Save Tracking
          </button>
        </div>

        <div class="staff-table-card staff-datatable-card staff-datatable-card--white p-4">
          <h3 class="h6 fw-semibold mb-3">Ship To</h3>
          <p class="mb-1 fw-semibold">Save Rack</p>
          <p class="mb-1 small">ASN# {{ asn.asn_number }}</p>
          <p v-for="(ln, i) in SHIP_TO_ADDRESS_LINES" :key="i" class="mb-0 small">{{ ln }}</p>
          <button type="button" class="btn btn-outline-secondary btn-sm mt-3 w-100" @click="openPrintLabel">
            Print Shipping Label
          </button>
        </div>

        <div class="staff-table-card staff-datatable-card staff-datatable-card--white p-4">
          <h3 class="h6 fw-semibold mb-3">Vendor Details</h3>
          <div v-for="(row, idx) in vendorDraft" :key="'v' + idx" class="d-flex gap-1 mb-2">
            <input v-model="row.label" class="form-control form-control-sm" placeholder="Vendor line" />
            <button type="button" class="btn btn-outline-danger btn-sm" @click="removeVendorRow(idx)">Remove</button>
          </div>
          <button type="button" class="btn btn-link btn-sm px-0 mb-2" @click="addVendorRow">Add Vendor Line</button>
          <button
            type="button"
            class="btn btn-primary btn-sm staff-page-primary w-100"
            :disabled="vendorBusy"
            @click="saveVendors"
          >
            Save Vendor Details
          </button>
        </div>

        <div class="staff-table-card staff-datatable-card staff-datatable-card--white p-4">
          <h3 class="h6 fw-semibold mb-3">Warehouse Notes</h3>
          <textarea v-model="asn.warehouse_notes" class="form-control form-control-sm" rows="4" placeholder="Notes for warehouse staff" />
          <button
            type="button"
            class="btn btn-primary btn-sm staff-page-primary w-100 mt-2"
            :disabled="notesBusy"
            @click="saveNotes"
          >
            Save Notes
          </button>
        </div>
      </div>
    </div>

    <div
      v-if="editLineOpen"
      class="modal d-block"
      tabindex="-1"
      style="background: rgba(0, 0, 0, 0.35)"
      @click.self="editLineOpen = false"
    >
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header">
            <h2 class="modal-title h6">Edit Line</h2>
            <button type="button" class="btn-close" aria-label="Close" @click="editLineOpen = false" />
          </div>
          <div class="modal-body">
            <div class="mb-2">
              <label class="form-label small">Expected</label>
              <input v-model.number="editExpected" type="number" min="0" class="form-control form-control-sm" />
            </div>
            <div class="mb-2">
              <label class="form-label small">Accepted</label>
              <input v-model.number="editAccepted" type="number" min="0" class="form-control form-control-sm" />
            </div>
            <div class="mb-0">
              <label class="form-label small">Rejected</label>
              <input v-model.number="editRejected" type="number" min="0" class="form-control form-control-sm" />
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-sm btn-outline-secondary" @click="editLineOpen = false">Cancel</button>
            <button
              type="button"
              class="btn btn-sm btn-primary staff-page-primary"
              :disabled="lineBusy"
              @click="saveEditLine"
            >
              Save
            </button>
          </div>
        </div>
      </div>
    </div>

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
.catalog-pick-list .dropdown-item:hover {
  background: rgba(115, 103, 240, 0.08);
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
</style>
