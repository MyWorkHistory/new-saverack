<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from "vue";
import { RouterLink, useRoute, useRouter } from "vue-router";
import api from "../../services/api";
import CrmIconRowActions from "../../components/common/CrmIconRowActions.vue";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import ConfirmModal from "../../components/common/ConfirmModal.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast.js";
import { ASN_CARRIER_OPTIONS } from "../../utils/asnCarrierOptions.js";
import { asnTrackingUrl } from "../../utils/asnTrackingUrl.js";
import { formatAsnDisplay, formatAsnHeading } from "../../utils/formatAsnDisplay.js";
import { formatDateUs } from "../../utils/formatUserDates.js";

const toast = useToast();
const route = useRoute();
const router = useRouter();

const loading = ref(true);
const asn = ref(null);
const enrichBusy = ref(false);

const productSearch = ref("");
const lineStatusFilter = ref("");

const receiveDraft = ref({});
const rejectDraft = ref({});
const lineSaveBusy = ref({});

const statusPickerOpen = ref(false);
const statusPickerBusy = ref(false);

const scanOpen = ref(false);
const scanText = ref("");
const scanBusy = ref(false);

const editReceivedOpen = ref(false);
const editReceivedLine = ref(null);
const editReceivedQty = ref(0);
const editReceivedOnHand = ref(0);
const editReceivedBusy = ref(false);

const editRejectedOpen = ref(false);
const editRejectedLine = ref(null);
const editRejectedQty = ref(0);
const editRejectedBusy = ref(false);

const editItemOpen = ref(false);
const editItemLine = ref(null);
const editItemForm = ref({ barcode: "", weight: "", length: "", width: "", height: "" });
const editItemBusy = ref(false);

const trackingDraft = ref([{ carrier: "", tracking_number: "" }]);
const trackingSaveBusy = ref(false);

const reopenBusy = ref(false);

const lineMenuOpenId = ref(null);
const lineMenuPos = ref({ top: 0, left: 0 });
const LINE_MENU_W = 200;
const LINE_MENU_H = 140;

const lineMenuStyle = computed(() => ({
  top: `${lineMenuPos.value.top}px`,
  left: `${lineMenuPos.value.left}px`,
  zIndex: 2200,
}));

const asnId = computed(() => String(route.params.id || ""));
const isDraft = computed(() => String(asn.value?.status || "").toLowerCase() === "draft");
const isNonCompliant = computed(() => String(asn.value?.status || "").toLowerCase() === "non_compliant");

const STATUS_OPTIONS = [
  { value: "draft", label: "Draft" },
  { value: "pending", label: "Pending" },
  { value: "in_progress", label: "In Progress" },
  { value: "completed", label: "Completed" },
  { value: "non_compliant", label: "Non-Compliant" },
];

const filteredLines = computed(() => {
  let lines = asn.value?.lines || [];
  const q = productSearch.value.trim().toLowerCase();
  if (q) {
    lines = lines.filter(
      (l) =>
        String(l.name || "").toLowerCase().includes(q) ||
        String(l.sku || "").toLowerCase().includes(q) ||
        String(l.barcode || "").toLowerCase().includes(q),
    );
  }
  if (lineStatusFilter.value) {
    lines = lines.filter((l) => String(l.line_status || "pending") === lineStatusFilter.value);
  }
  return lines;
});

function statusLabel(s) {
  const x = String(s || "").toLowerCase();
  if (x === "draft") return "Draft";
  if (x === "pending") return "Pending";
  if (x === "in_progress") return "In Progress";
  if (x === "completed") return "Completed";
  if (x === "non_compliant") return "Non-Compliant";
  if (x === "partial") return "Partial";
  return s || "—";
}

function statusBadgeClass(status) {
  const s = String(status || "").toLowerCase();
  if (s === "draft") return "bg-warning-subtle text-warning-emphasis";
  if (s === "pending") return "bg-secondary-subtle text-secondary-emphasis";
  if (s === "in_progress" || s === "partial") return "bg-primary-subtle text-primary-emphasis";
  if (s === "completed") return "bg-success-subtle text-success-emphasis";
  if (s === "non_compliant") return "bg-danger-subtle text-danger-emphasis";
  return "bg-body-secondary text-body-secondary";
}

function lineStatusBadgeClass(status) {
  const s = String(status || "").toLowerCase();
  if (s === "pending") return "bg-secondary-subtle text-secondary-emphasis";
  if (s === "partial") return "bg-primary-subtle text-primary-emphasis";
  if (s === "completed") return "bg-success-subtle text-success-emphasis";
  return "bg-body-secondary text-body-secondary";
}

function specDisplay(val) {
  if (val === null || val === undefined || val === "") return "";
  const n = Number(val);
  if (!Number.isFinite(n) || n <= 0) return "";
  return String(val);
}

function isSpecEditable(val) {
  return specDisplay(val) === "";
}

async function loadAsn() {
  loading.value = true;
  try {
    const { data } = await api.get(`/admin/asns/${asnId.value}`);
    asn.value = data;
    trackingDraft.value =
      (data.trackings || []).length > 0
        ? data.trackings.map((t) => ({
            carrier: t.carrier || "",
            tracking_number: t.tracking_number || "",
          }))
        : [{ carrier: "", tracking_number: "" }];
    const rd = {};
    const rj = {};
    for (const l of data.lines || []) {
      rd[l.id] = "";
      rj[l.id] = "";
    }
    receiveDraft.value = rd;
    rejectDraft.value = rj;
    setCrmPageMeta({
      title: `Save Rack | ${formatAsnDisplay(data.asn_number)}`,
      description: "ASN receiving detail.",
    });
    const needsSpecs = (data.lines || []).some((l) => !l.specs_cached_at);
    if (needsSpecs && !isNonCompliant.value) {
      enrichSpecs(false);
    }
  } catch (e) {
    toast.errorFrom(e, "Could not load ASN.");
  } finally {
    loading.value = false;
  }
}

async function enrichSpecs(force = false) {
  enrichBusy.value = true;
  try {
    const { data } = await api.post(`/admin/asns/${asnId.value}/enrich-specs`, null, {
      params: force ? { force: 1 } : {},
    });
    if (data.asn) {
      asn.value = data.asn;
    }
    toast.success(force ? "Product specs refreshed." : "Product specs loaded.");
  } catch (e) {
    toast.errorFrom(e, "Could not load product specs.");
  } finally {
    enrichBusy.value = false;
  }
}

async function saveReceive(line) {
  const delta = Number(receiveDraft.value[line.id]);
  if (!Number.isFinite(delta) || delta <= 0) {
    toast.error("Enter a received quantity to add.");
    return;
  }
  lineSaveBusy.value = { ...lineSaveBusy.value, [line.id]: true };
  try {
    const { data } = await api.post(`/admin/asns/${asnId.value}/lines/${line.id}/receive`, { delta });
    asn.value = data.asn;
    receiveDraft.value = { ...receiveDraft.value, [line.id]: "" };
    toast.success("Received quantity saved.");
  } catch (e) {
    toast.errorFrom(e, "Could not save received quantity.");
  } finally {
    lineSaveBusy.value = { ...lineSaveBusy.value, [line.id]: false };
  }
}

async function saveReject(line) {
  const qty = Number(rejectDraft.value[line.id]);
  if (!Number.isFinite(qty) || qty < 0) {
    toast.error("Enter rejected quantity.");
    return;
  }
  lineSaveBusy.value = { ...lineSaveBusy.value, [line.id]: true };
  try {
    const { data } = await api.post(`/admin/asns/${asnId.value}/lines/${line.id}/reject-override`, {
      rejected_qty: qty,
    });
    asn.value = data.asn;
    rejectDraft.value = { ...rejectDraft.value, [line.id]: "" };
    toast.success("Rejected quantity saved.");
  } catch (e) {
    toast.errorFrom(e, "Could not save rejected quantity.");
  } finally {
    lineSaveBusy.value = { ...lineSaveBusy.value, [line.id]: false };
  }
}

async function openEditReceived(line) {
  editReceivedLine.value = line;
  editReceivedQty.value = line.accepted_qty;
  editReceivedBusy.value = true;
  editReceivedOpen.value = true;
  try {
    const { data } = await api.get(
      `/admin/asns/${asnId.value}/lines/${line.id}/receiving-on-hand`,
    );
    editReceivedOnHand.value = data.receiving_on_hand ?? 0;
    editReceivedQty.value = data.accepted_qty ?? line.accepted_qty;
  } catch (e) {
    toast.errorFrom(e, "Could not load Receiving on-hand.");
  } finally {
    editReceivedBusy.value = false;
  }
}

async function confirmEditReceived() {
  if (!editReceivedLine.value) return;
  editReceivedBusy.value = true;
  try {
    const { data } = await api.post(
      `/admin/asns/${asnId.value}/lines/${editReceivedLine.value.id}/receive-override`,
      { accepted_qty: Number(editReceivedQty.value) || 0 },
    );
    asn.value = data.asn;
    editReceivedOpen.value = false;
    toast.success("Received quantity updated.");
  } catch (e) {
    toast.errorFrom(e, "Could not update received quantity.");
  } finally {
    editReceivedBusy.value = false;
  }
}

function openEditRejected(line) {
  editRejectedLine.value = line;
  editRejectedQty.value = line.rejected_qty;
  editRejectedOpen.value = true;
}

async function confirmEditRejected() {
  if (!editRejectedLine.value) return;
  editRejectedBusy.value = true;
  try {
    const { data } = await api.post(
      `/admin/asns/${asnId.value}/lines/${editRejectedLine.value.id}/reject-override`,
      { rejected_qty: Number(editRejectedQty.value) || 0 },
    );
    asn.value = data.asn;
    editRejectedOpen.value = false;
    toast.success("Rejected quantity updated.");
  } catch (e) {
    toast.errorFrom(e, "Could not update rejected quantity.");
  } finally {
    editRejectedBusy.value = false;
  }
}

function openEditItem(line) {
  editItemLine.value = line;
  editItemForm.value = {
    barcode: line.barcode || "",
    weight: line.weight || "",
    length: line.length || "",
    width: line.width || "",
    height: line.height || "",
  };
  editItemOpen.value = true;
}

async function confirmEditItem() {
  if (!editItemLine.value) return;
  editItemBusy.value = true;
  try {
    const { data } = await api.patch(
      `/admin/asns/${asnId.value}/lines/${editItemLine.value.id}/specs`,
      editItemForm.value,
    );
    const lines = (asn.value.lines || []).map((l) => (l.id === data.id ? { ...l, ...data } : l));
    asn.value = { ...asn.value, lines };
    editItemOpen.value = false;
    toast.success("Item updated.");
  } catch (e) {
    toast.errorFrom(e, "Could not save item.");
  } finally {
    editItemBusy.value = false;
  }
}

async function openPdf(path, fallbackMessage) {
  try {
    const { data } = await api.get(path, { responseType: "blob" });
    const blob = new Blob([data], { type: "application/pdf" });
    const url = window.URL.createObjectURL(blob);
    window.open(url, "_blank", "noopener");
    setTimeout(() => window.URL.revokeObjectURL(url), 30000);
  } catch (e) {
    toast.errorFrom(e, fallbackMessage);
  }
}

function printBarcode(line) {
  openPdf(`/asns/${asnId.value}/lines/${line.id}/barcode.pdf`, "Could not open barcode PDF.");
}

async function submitScan() {
  scanBusy.value = true;
  try {
    const { data } = await api.post(`/admin/asns/${asnId.value}/scan-barcodes`, {
      barcodes: scanText.value,
    });
    asn.value = data.asn;
    const unmatched = data.unmatched || [];
    if (unmatched.length) {
      toast.error(`No match for: ${unmatched.slice(0, 3).join(", ")}${unmatched.length > 3 ? "…" : ""}`);
    } else {
      toast.success(`Processed ${data.matched || 0} item(s).`);
    }
    scanOpen.value = false;
    scanText.value = "";
  } catch (e) {
    toast.errorFrom(e, "Could not process barcodes.");
  } finally {
    scanBusy.value = false;
  }
}

async function setAsnStatus(status) {
  statusPickerBusy.value = true;
  try {
    const { data } = await api.patch(`/admin/asns/${asnId.value}/status`, { status });
    asn.value = data;
    statusPickerOpen.value = false;
    toast.success("Status updated.");
  } catch (e) {
    toast.errorFrom(e, "Could not update status.");
  } finally {
    statusPickerBusy.value = false;
  }
}

async function saveTracking() {
  trackingSaveBusy.value = true;
  try {
    const { data } = await api.put(`/asns/${asnId.value}/trackings`, { trackings: trackingDraft.value });
    asn.value = { ...asn.value, trackings: data.trackings };
    toast.success("Tracking saved.");
  } catch (e) {
    toast.errorFrom(e, "Could not save tracking.");
  } finally {
    trackingSaveBusy.value = false;
  }
}

async function reopenForEdit() {
  reopenBusy.value = true;
  try {
    const { data } = await api.post(`/asns/${asnId.value}/reopen-for-edit`);
    asn.value = { ...asn.value, ...data };
    toast.success("ASN reopened for editing.");
  } catch (e) {
    toast.errorFrom(e, "Could not reopen ASN.");
  } finally {
    reopenBusy.value = false;
  }
}

function placeLineMenuFromButton(btn) {
  const r = btn.getBoundingClientRect();
  let top = r.bottom + 4;
  let left = r.right - LINE_MENU_W;
  left = Math.max(8, Math.min(left, window.innerWidth - LINE_MENU_W - 8));
  if (top + LINE_MENU_H > window.innerHeight - 8) {
    top = Math.max(8, r.top - LINE_MENU_H - 4);
  }
  lineMenuPos.value = { top, left };
}

async function toggleLineMenu(lineId, e) {
  e?.stopPropagation?.();
  if (lineMenuOpenId.value === lineId) {
    lineMenuOpenId.value = null;
    return;
  }
  const btn = e?.currentTarget;
  lineMenuOpenId.value = lineId;
  await nextTick();
  requestAnimationFrame(() => {
    if (btn instanceof HTMLElement) placeLineMenuFromButton(btn);
  });
}

function closeLineMenu() {
  lineMenuOpenId.value = null;
}

function onDocClickLineMenu(e) {
  if (!e.target?.closest?.("[data-row-actions]")) {
    lineMenuOpenId.value = null;
  }
}

watch(asnId, () => loadAsn());

onMounted(() => {
  loadAsn();
  document.addEventListener("click", onDocClickLineMenu);
});

onUnmounted(() => {
  document.removeEventListener("click", onDocClickLineMenu);
});
</script>

<template>
  <div class="staff-page">
    <div class="mb-3">
      <RouterLink :to="{ name: 'admin-asn-hub' }" class="text-decoration-none small">
        ← ASN list
      </RouterLink>
    </div>

    <div v-if="loading" class="d-flex justify-content-center py-5">
      <CrmLoadingSpinner message="Loading ASN…" />
    </div>

    <template v-else-if="asn">
      <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-4">
        <div>
          <h1 class="staff-page-title mb-2">{{ formatAsnHeading(asn.asn_number) }}</h1>
          <p class="mb-1 text-body-secondary">
            <strong>{{ asn.client_account_company_name }}</strong>
          </p>
          <p class="mb-0 small text-body-secondary">
            Created {{ formatDateUs(asn.created_at) }}
            <span v-if="asn.processed_at"> · Processed {{ formatDateUs(asn.processed_at) }}</span>
          </p>
          <div class="mt-2 position-relative d-inline-block">
            <button
              type="button"
              class="badge rounded-pill border-0"
              :class="statusBadgeClass(asn.status)"
              @click="statusPickerOpen = !statusPickerOpen"
            >
              {{ statusLabel(asn.status) }} ▾
            </button>
            <div
              v-if="statusPickerOpen"
              class="dropdown-menu show position-absolute start-0 mt-1"
              style="z-index: 1050"
            >
              <button
                v-for="opt in STATUS_OPTIONS"
                :key="opt.value"
                type="button"
                class="dropdown-item"
                :disabled="statusPickerBusy"
                @click="setAsnStatus(opt.value)"
              >
                {{ opt.label }}
              </button>
            </div>
          </div>
        </div>
        <div class="d-flex flex-wrap gap-2 align-items-center">
          <div class="dropdown">
            <button
              type="button"
              class="btn btn-outline-secondary dropdown-toggle"
              data-bs-toggle="dropdown"
            >
              Action
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
              <li>
                <button type="button" class="dropdown-item" @click="scanOpen = true">Scan Items</button>
              </li>
            </ul>
          </div>
          <div v-if="String(asn.status) === 'pending'" class="dropdown">
            <button
              type="button"
              class="btn btn-outline-secondary dropdown-toggle"
              :disabled="reopenBusy"
              data-bs-toggle="dropdown"
            >
              Manage
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
              <li>
                <button type="button" class="dropdown-item" @click="reopenForEdit">Edit</button>
              </li>
            </ul>
          </div>
          <RouterLink :to="{ name: 'admin-asn-hub' }" class="btn btn-outline-secondary">
            Back to List
          </RouterLink>
        </div>
      </div>

      <div class="row g-4">
        <div class="col-lg-8">
          <div v-if="isDraft" class="alert alert-info mb-4">
            This ASN is in draft. Add products and mark ready using the same flow as the client portal, or
            reopen after pending via Manage → Edit.
            <RouterLink
              class="ms-1"
              :to="{ name: 'user-asn-detail', params: { id: asnId }, query: { client_account_id: asn.client_account_id } }"
              target="_blank"
            >
              Open client view
            </RouterLink>
          </div>

          <div v-if="!isNonCompliant" class="card border-0 shadow-sm mb-4">
            <div class="card-body">
              <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                <h2 class="h5 mb-0">Products</h2>
                <div class="d-flex flex-wrap gap-2 align-items-center">
                  <input
                    v-model="productSearch"
                    type="search"
                    class="form-control form-control-sm"
                    style="width: 10rem"
                    placeholder="Name or SKU"
                  />
                  <select v-model="lineStatusFilter" class="form-select form-select-sm" style="width: 8rem">
                    <option value="">All status</option>
                    <option value="pending">Pending</option>
                    <option value="partial">Partial</option>
                    <option value="completed">Completed</option>
                  </select>
                  <button
                    type="button"
                    class="btn btn-link btn-sm p-0"
                    :disabled="enrichBusy"
                    @click="enrichSpecs(true)"
                  >
                    {{ enrichBusy ? "Refreshing…" : "Refresh specs" }}
                  </button>
                </div>
              </div>

              <div class="table-responsive">
                <table class="table staff-data-table mb-0">
                  <thead>
                    <tr>
                      <th>Status</th>
                      <th>Product</th>
                      <th>Specs</th>
                      <th class="text-end">Expected</th>
                      <th class="text-end">Received</th>
                      <th class="text-end">Rejected</th>
                      <th class="text-end">Action</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-if="filteredLines.length === 0">
                      <td colspan="7" class="text-center text-body-secondary py-4">No products.</td>
                    </tr>
                    <tr v-for="line in filteredLines" :key="line.id">
                      <td>
                        <span class="badge rounded-pill" :class="lineStatusBadgeClass(line.line_status)">
                          {{ statusLabel(line.line_status) }}
                        </span>
                      </td>
                      <td>
                        <div class="d-flex gap-2 align-items-start">
                          <div
                            class="rounded bg-body-secondary flex-shrink-0"
                            style="width: 40px; height: 40px"
                          >
                            <img
                              v-if="line.image_url"
                              :src="line.image_url"
                              alt=""
                              class="rounded w-100 h-100 object-fit-cover"
                            />
                          </div>
                          <div>
                            <div class="fw-semibold small">{{ line.name }}</div>
                            <div class="text-body-secondary small">{{ line.sku }}</div>
                            <button
                              v-if="isSpecEditable(line.barcode)"
                              type="button"
                              class="btn btn-link btn-sm p-0 text-danger"
                              @click="openEditItem(line)"
                            >
                              Add barcode
                            </button>
                            <button
                              v-else
                              type="button"
                              class="btn btn-link btn-sm p-0"
                              @click="openEditItem(line)"
                            >
                              {{ line.barcode }}
                            </button>
                          </div>
                        </div>
                      </td>
                      <td class="small">
                        <div>
                          <span v-if="specDisplay(line.weight)">Weight: {{ line.weight }} lbs</span>
                          <button
                            v-else
                            type="button"
                            class="btn btn-link btn-sm p-0 text-danger"
                            @click="openEditItem(line)"
                          >
                            Weight
                          </button>
                        </div>
                        <div class="text-body-secondary">
                          <template v-if="specDisplay(line.length)">L: {{ line.length }}</template>
                          <button
                            v-else
                            type="button"
                            class="btn btn-link btn-sm p-0 text-danger"
                            @click="openEditItem(line)"
                          >
                            L
                          </button>
                          <template v-if="specDisplay(line.width)"> W: {{ line.width }}</template>
                          <button
                            v-else
                            type="button"
                            class="btn btn-link btn-sm p-0 text-danger"
                            @click="openEditItem(line)"
                          >
                            W
                          </button>
                          <template v-if="specDisplay(line.height)"> H: {{ line.height }}</template>
                          <button
                            v-else
                            type="button"
                            class="btn btn-link btn-sm p-0 text-danger"
                            @click="openEditItem(line)"
                          >
                            H
                          </button>
                        </div>
                      </td>
                      <td class="text-end">{{ line.expected_qty }}</td>
                      <td class="text-end">
                        <template v-if="!isDraft">
                          <input
                            v-model="receiveDraft[line.id]"
                            type="number"
                            min="0"
                            class="form-control form-control-sm d-inline-block text-end"
                            style="width: 4.5rem"
                            placeholder="0"
                          />
                          <div class="small text-body-secondary mt-1">{{ line.accepted_qty }} saved</div>
                        </template>
                        <span v-else>{{ line.accepted_qty }}</span>
                      </td>
                      <td class="text-end">
                        <template v-if="!isDraft">
                          <input
                            v-model="rejectDraft[line.id]"
                            type="number"
                            min="0"
                            class="form-control form-control-sm d-inline-block text-end"
                            style="width: 4.5rem"
                          />
                          <div class="small text-body-secondary mt-1">{{ line.rejected_qty }} saved</div>
                          <button
                            type="button"
                            class="btn btn-sm btn-outline-secondary mt-1"
                            :disabled="lineSaveBusy[line.id]"
                            @click="saveReject(line)"
                          >
                            Set Rejected
                          </button>
                        </template>
                        <span v-else>{{ line.rejected_qty }}</span>
                      </td>
                      <td class="text-end">
                        <template v-if="!isDraft">
                          <button
                            type="button"
                            class="btn btn-sm btn-primary mb-1"
                            :disabled="lineSaveBusy[line.id]"
                            @click="saveReceive(line)"
                          >
                            Save
                          </button>
                          <div data-row-actions class="position-relative d-inline-block">
                            <button
                              type="button"
                              class="staff-action-btn staff-action-btn--more"
                              aria-label="Line actions"
                              @click.stop="toggleLineMenu(line.id, $event)"
                            >
                              <CrmIconRowActions variant="horizontal" />
                            </button>
                            <div
                              v-if="lineMenuOpenId === line.id"
                              data-row-actions
                              class="staff-row-menu overflow-hidden"
                              role="menu"
                              :style="lineMenuStyle"
                              @click.stop
                            >
                              <button
                                type="button"
                                class="staff-row-menu__item"
                                role="menuitem"
                                @click="
                                  closeLineMenu();
                                  openEditReceived(line);
                                "
                              >
                                Edit Received
                              </button>
                              <button
                                type="button"
                                class="staff-row-menu__item"
                                role="menuitem"
                                @click="
                                  closeLineMenu();
                                  openEditRejected(line);
                                "
                              >
                                Edit Rejected
                              </button>
                              <button
                                type="button"
                                class="staff-row-menu__item"
                                role="menuitem"
                                @click="
                                  closeLineMenu();
                                  printBarcode(line);
                                "
                              >
                                Print Barcode
                              </button>
                            </div>
                          </div>
                        </template>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
              <div class="text-end mt-2">
                <button
                  type="button"
                  class="btn btn-link btn-sm"
                  :disabled="enrichBusy"
                  @click="enrichSpecs(true)"
                >
                  Refresh specs
                </button>
              </div>
            </div>
          </div>
        </div>

        <div class="col-lg-4">
          <div class="card border-0 shadow-sm mb-4">
            <div class="card-body">
              <h2 class="h6 mb-3">Tracking Details</h2>
              <div v-for="(t, i) in trackingDraft" :key="i" class="mb-2">
                <select v-model="t.carrier" class="form-select form-select-sm mb-1">
                  <option value="">Carrier</option>
                  <option v-for="c in ASN_CARRIER_OPTIONS" :key="c" :value="c">{{ c }}</option>
                </select>
                <input
                  v-model="t.tracking_number"
                  type="text"
                  class="form-control form-control-sm"
                  placeholder="Tracking #"
                />
              </div>
              <button
                type="button"
                class="btn btn-link btn-sm p-0 mb-2"
                @click="trackingDraft.push({ carrier: '', tracking_number: '' })"
              >
                Add Row
              </button>
              <button
                type="button"
                class="btn btn-primary btn-sm w-100"
                :disabled="trackingSaveBusy"
                @click="saveTracking"
              >
                Save Tracking
              </button>
            </div>
          </div>

          <div class="card border-0 shadow-sm mb-4">
            <div class="card-body">
              <h2 class="h6 mb-2">Note from Client</h2>
              <p class="mb-0 small text-body-secondary" style="white-space: pre-wrap">
                {{ asn.warehouse_notes || "—" }}
              </p>
            </div>
          </div>

          <div v-if="asn.custom_bill_id" class="card border-0 shadow-sm">
            <div class="card-body">
              <h2 class="h6 mb-2">Non-Compliant Fee</h2>
              <p class="mb-1">${{ Number(asn.non_compliant_fee || 0).toFixed(2) }}</p>
              <RouterLink
                :to="{ name: 'billing-custom-bill-detail', params: { id: String(asn.custom_bill_id) } }"
                class="small"
              >
                View custom bill
              </RouterLink>
            </div>
          </div>
        </div>
      </div>
    </template>

    <ConfirmModal
      :open="scanOpen"
      title="Scan Items"
      confirm-label="Save"
      :busy="scanBusy"
      @close="scanOpen = false"
      @confirm="submitScan"
    >
      <label class="form-label">Enter barcodes line by line</label>
      <textarea v-model="scanText" class="form-control font-monospace" rows="10" />
    </ConfirmModal>

    <ConfirmModal
      :open="editReceivedOpen"
      title="Edit Received"
      confirm-label="Save"
      :busy="editReceivedBusy"
      @close="editReceivedOpen = false"
      @confirm="confirmEditReceived"
    >
      <p class="small text-body-secondary">
        On-hand in Receiving: <strong>{{ editReceivedOnHand }}</strong>
      </p>
      <label class="form-label">New QTY</label>
      <input v-model.number="editReceivedQty" type="number" min="0" class="form-control" />
    </ConfirmModal>

    <ConfirmModal
      :open="editRejectedOpen"
      title="Edit Rejected"
      confirm-label="Save"
      :busy="editRejectedBusy"
      @close="editRejectedOpen = false"
      @confirm="confirmEditRejected"
    >
      <label class="form-label">Rejected QTY</label>
      <input v-model.number="editRejectedQty" type="number" min="0" class="form-control" />
    </ConfirmModal>

    <ConfirmModal
      :open="editItemOpen"
      title="Edit Item"
      confirm-label="Save"
      :busy="editItemBusy"
      @close="editItemOpen = false"
      @confirm="confirmEditItem"
    >
      <div v-if="editItemLine" class="mb-3">
        <div class="fw-semibold">{{ editItemLine.name }}</div>
        <div class="small text-body-secondary">{{ editItemLine.sku }}</div>
      </div>
      <p class="small fw-semibold text-body-secondary">Information</p>
      <label class="form-label">Barcode</label>
      <input v-model="editItemForm.barcode" type="text" class="form-control mb-3" />
      <p class="small fw-semibold text-body-secondary">Dimensions &amp; weight</p>
      <div class="row g-2">
        <div class="col-3">
          <label class="form-label small">Length</label>
          <input v-model="editItemForm.length" type="number" step="0.01" min="0" class="form-control" />
        </div>
        <div class="col-3">
          <label class="form-label small">Width</label>
          <input v-model="editItemForm.width" type="number" step="0.01" min="0" class="form-control" />
        </div>
        <div class="col-3">
          <label class="form-label small">Height</label>
          <input v-model="editItemForm.height" type="number" step="0.01" min="0" class="form-control" />
        </div>
        <div class="col-3">
          <label class="form-label small">Weight</label>
          <input v-model="editItemForm.weight" type="number" step="0.01" min="0" class="form-control" />
        </div>
      </div>
    </ConfirmModal>
  </div>
</template>
