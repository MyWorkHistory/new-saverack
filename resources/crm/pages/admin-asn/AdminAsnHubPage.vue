<script setup>
import { computed, onMounted, ref, watch } from "vue";
import { RouterLink, useRoute, useRouter } from "vue-router";
import api from "../../services/api";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import ConfirmModal from "../../components/common/ConfirmModal.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast.js";
import { ASN_CARRIER_OPTIONS } from "../../utils/asnCarrierOptions.js";
import { asnTrackingUrl } from "../../utils/asnTrackingUrl.js";
import { formatAsnDisplay } from "../../utils/formatAsnDisplay.js";
import { formatDateUs } from "../../utils/formatUserDates.js";

const toast = useToast();
const router = useRouter();
const route = useRoute();

const loading = ref(true);
const summaryLoading = ref(true);
const summary = ref({
  pending: 0,
  in_progress: 0,
  completed: 0,
  non_compliant: 0,
});
const rows = ref([]);
const meta = ref({ current_page: 1, last_page: 1, per_page: 25, total: 0 });

const accountOptions = ref([]);
const accountFilter = ref("");
const statusFilter = ref("");
const search = ref("");
const searchDebounced = ref("");
let searchTimer = null;

const createModalOpen = ref(false);
const createAccountId = ref("");
const createBusy = ref(false);

const nonCompliantOpen = ref(false);
const nonCompliantBusy = ref(false);
const ncAccountId = ref("");
const ncBoxes = ref(0);
const ncPallets = ref(0);
const ncFee = ref("");
const ncTrackings = ref([{ carrier: "", tracking_number: "" }]);

const STAT_CARDS = [
  {
    key: "pending",
    label: "Pending",
    sub: "Pending ASNs",
    status: "pending",
    iconStyle: { background: "#dbeafe", color: "#1e3a8a" },
    iconPath:
      "M3.875 19.125Q3 18.25 3 17H1V6q0-.825.588-1.412T3 4h14v4h3l3 4v5h-2q0 1.25-.875 2.125T18 20t-2.125-.875T15 17H9q0 1.25-.875 2.125T6 20t-2.125-.875m2.838-1.412Q7 17.425 7 17t-.288-.712T6 16t-.712.288T5 17t.288.713T6 18t.713-.288m12 0Q19 17.426 19 17t-.288-.712T18 16t-.712.288T17 17t.288.713T18 18t.713-.288M17 13h4.25L19 10h-2z",
  },
  {
    key: "in_progress",
    label: "In-Progress",
    sub: "Processing ASNs",
    status: "in_progress",
    iconStyle: { background: "#fef3c7", color: "#b45309" },
    iconPath:
      "M8 20h8v-3q0-1.65-1.175-2.825T12 13t-2.825 1.175T8 17zm6.825-10.175Q16 8.65 16 7V4H8v3q0 1.65 1.175 2.825T12 11t2.825-1.175M4 22v-2h2v-3q0-1.525.713-2.863T8.7 12q-1.275-.8-1.987-2.137T6 7V4H4V2h16v2h-2v3q0 1.525-.712 2.863T15.3 12q1.275.8 1.988 2.138T18 17v3h2v2z",
  },
  {
    key: "completed",
    label: "Completed",
    sub: "Completed ASNs",
    status: "completed",
    iconStyle: { background: "#dcfce7", color: "#166534" },
    iconPath: "M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z",
  },
  {
    key: "non_compliant",
    label: "Non-Compliant",
    sub: "Non-Compliant ASNs",
    status: "non_compliant",
    iconStyle: { background: "#fee2e2", color: "#b91c1c" },
    iconPath: "M6.4 19L5 17.6l5.6-5.6L5 6.4 6.4 5l5.6 5.6L17.6 5 19 6.4 13.4 12l5.6 5.6L17.6 19l-5.6-5.6z",
  },
];

watch(search, (v) => {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => {
    searchDebounced.value = v.trim();
    meta.value.current_page = 1;
    loadList();
  }, 300);
});

watch([accountFilter, statusFilter], () => {
  meta.value.current_page = 1;
  loadSummary();
  loadList();
});

function statusLabel(s) {
  const x = String(s || "").toLowerCase();
  if (x === "draft") return "Draft";
  if (x === "pending") return "Pending";
  if (x === "in_progress") return "In Progress";
  if (x === "completed") return "Completed";
  if (x === "non_compliant") return "Non-Compliant";
  return s || "—";
}

function statusBadgeClass(status) {
  const s = String(status || "").toLowerCase();
  if (s === "draft") return "bg-warning-subtle text-warning-emphasis";
  if (s === "pending") return "bg-secondary-subtle text-secondary-emphasis";
  if (s === "in_progress") return "bg-primary-subtle text-primary-emphasis";
  if (s === "completed") return "bg-success-subtle text-success-emphasis";
  if (s === "non_compliant") return "bg-danger-subtle text-danger-emphasis";
  return "bg-body-secondary text-body-secondary";
}

function summaryParams() {
  const p = {};
  if (accountFilter.value) {
    p.client_account_id = accountFilter.value;
  }
  return p;
}

function listParams() {
  const p = {
    page: meta.value.current_page,
    per_page: meta.value.per_page,
    sort_by: "created_at",
    sort_dir: "desc",
  };
  if (accountFilter.value) {
    p.client_account_id = accountFilter.value;
  }
  if (statusFilter.value) {
    p.status = statusFilter.value;
  }
  if (searchDebounced.value) {
    p.q = searchDebounced.value;
  }
  return p;
}

async function loadSummary() {
  summaryLoading.value = true;
  try {
    const { data } = await api.get("/admin/asns/summary", { params: summaryParams() });
    summary.value = {
      pending: Number(data.pending || 0),
      in_progress: Number(data.in_progress || 0),
      completed: Number(data.completed || 0),
      non_compliant: Number(data.non_compliant || 0),
    };
  } catch (e) {
    toast.errorFrom(e, "Could not load ASN summary.");
  } finally {
    summaryLoading.value = false;
  }
}

async function loadList() {
  loading.value = true;
  try {
    const { data } = await api.get("/admin/asns", { params: listParams() });
    rows.value = data.data || [];
    meta.value = { ...meta.value, ...(data.meta || {}) };
  } catch (e) {
    toast.errorFrom(e, "Could not load ASNs.");
  } finally {
    loading.value = false;
  }
}

async function loadAccounts() {
  try {
    const { data } = await api.get("/inventory/client-account-options");
    accountOptions.value = data.data || data || [];
  } catch {
    accountOptions.value = [];
  }
}

function setStatusCard(status) {
  statusFilter.value = statusFilter.value === status ? "" : status;
}

function openCreateModal() {
  createAccountId.value = accountFilter.value || "";
  createModalOpen.value = true;
}

async function confirmCreate() {
  const id = Number(createAccountId.value);
  if (!id) {
    toast.error("Select an account.");
    return;
  }
  createBusy.value = true;
  try {
    const { data } = await api.post("/asns", { client_account_id: id });
    createModalOpen.value = false;
    toast.success("ASN created.");
    router.push({ name: "admin-asn-detail", params: { id: String(data.id) } });
  } catch (e) {
    toast.errorFrom(e, "Could not create ASN.");
  } finally {
    createBusy.value = false;
  }
}

function openNonCompliant() {
  ncAccountId.value = accountFilter.value || "";
  ncBoxes.value = 0;
  ncPallets.value = 0;
  ncFee.value = "";
  ncTrackings.value = [{ carrier: "", tracking_number: "" }];
  nonCompliantOpen.value = true;
}

function addNcTracking() {
  ncTrackings.value = [...ncTrackings.value, { carrier: "", tracking_number: "" }];
}

async function submitNonCompliant() {
  const id = Number(ncAccountId.value);
  if (!id) {
    toast.error("Select an account.");
    return;
  }
  const trackings = ncTrackings.value
    .map((t) => ({
      carrier: String(t.carrier || "").trim(),
      tracking_number: String(t.tracking_number || "").trim(),
    }))
    .filter((t) => t.tracking_number !== "");
  if (!trackings.length) {
    toast.error("Add at least one tracking number.");
    return;
  }
  nonCompliantBusy.value = true;
  try {
    const fee = ncFee.value === "" ? 0 : Number(ncFee.value);
    const { data } = await api.post("/admin/asns/non-compliant", {
      client_account_id: id,
      total_boxes: Number(ncBoxes.value) || 0,
      total_pallets: Number(ncPallets.value) || 0,
      trackings,
      fee: Number.isFinite(fee) ? fee : 0,
    });
    nonCompliantOpen.value = false;
    toast.success("Non-compliant ASN created.");
    router.push({ name: "admin-asn-detail", params: { id: String(data.id) } });
  } catch (e) {
    toast.errorFrom(e, "Could not create non-compliant ASN.");
  } finally {
    nonCompliantBusy.value = false;
  }
}

function trackingLink(row) {
  return asnTrackingUrl(row.tracking_carrier, row.tracking_display);
}

function goPage(page) {
  meta.value.current_page = page;
  loadList();
}

const statCardValue = (key) => summary.value[key] ?? 0;

onMounted(async () => {
  setCrmPageMeta({ title: "Save Rack | ASN", description: "Receiving advance shipping notices." });
  if (route.query.status) {
    statusFilter.value = String(route.query.status);
  }
  await loadAccounts();
  await Promise.all([loadSummary(), loadList()]);
});
</script>

<template>
  <div class="staff-page">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
      <div>
        <h1 class="staff-page-title mb-1">ASN</h1>
        <p class="staff-page-subtitle mb-0 text-body-secondary">Receiving — advance shipping notices</p>
      </div>
      <div class="d-flex flex-wrap gap-2">
        <button type="button" class="btn btn-primary staff-page-primary" @click="openCreateModal">
          Create ASN
        </button>
        <button type="button" class="btn btn-outline-secondary" @click="openNonCompliant">
          Non-Compliant ASN
        </button>
      </div>
    </div>

    <div v-if="summaryLoading" class="d-flex justify-content-center py-4 mb-4">
      <CrmLoadingSpinner message="Loading summary…" />
    </div>
    <div v-else class="row g-4 mb-4">
      <div v-for="card in STAT_CARDS" :key="card.key" class="col-12 col-sm-6 col-xl-3">
        <button
          type="button"
          class="staff-stat-card h-100 text-start w-100 border-0"
          :class="{ 'ring-2 ring-primary': statusFilter === card.status }"
          @click="setStatusCard(card.status)"
        >
          <p class="staff-stat-card__label">{{ card.label }}</p>
          <p class="staff-stat-card__value">{{ statCardValue(card.key) }}</p>
          <p class="staff-stat-card__sub">{{ card.sub }}</p>
          <div class="staff-stat-card__icon" :style="card.iconStyle" aria-hidden="true">
            <svg width="22" height="22" fill="currentColor" viewBox="0 0 24 24">
              <path :d="card.iconPath" />
            </svg>
          </div>
        </button>
      </div>
    </div>

    <div class="d-flex flex-wrap align-items-end gap-3 mb-3">
      <div style="min-width: 12rem">
        <label class="form-label small text-body-secondary mb-1">Account</label>
        <select v-model="accountFilter" class="form-select form-select-sm">
          <option value="">All accounts</option>
          <option v-for="a in accountOptions" :key="a.id" :value="String(a.id)">
            {{ a.company_name || a.label || `Account #${a.id}` }}
          </option>
        </select>
      </div>
      <div class="flex-grow-1" style="max-width: 22rem">
        <label class="form-label small text-body-secondary mb-1">Search</label>
        <input
          v-model="search"
          type="search"
          class="form-control form-control-sm"
          placeholder="ASN # or Tracking #"
          autocomplete="off"
        />
      </div>
    </div>

    <div class="card border-0 shadow-sm">
      <div class="table-responsive">
        <table class="table table-hover staff-data-table mb-0">
          <thead>
            <tr>
              <th>Status</th>
              <th>ASN #</th>
              <th>Account</th>
              <th class="text-end">Expected QTY</th>
              <th class="text-end">Received QTY</th>
              <th class="text-end">Boxes</th>
              <th>Tracking #</th>
              <th class="text-end">Action</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="loading">
              <td colspan="8" class="text-center py-5">
                <CrmLoadingSpinner message="Loading ASNs…" />
              </td>
            </tr>
            <tr v-else-if="rows.length === 0">
              <td colspan="8" class="text-center text-body-secondary py-5">No ASNs found.</td>
            </tr>
            <tr v-for="row in rows" v-else :key="row.id">
              <td>
                <span class="badge rounded-pill" :class="statusBadgeClass(row.status)">
                  {{ statusLabel(row.status) }}
                </span>
              </td>
              <td>
                <RouterLink
                  :to="{ name: 'admin-asn-detail', params: { id: String(row.id) } }"
                  class="fw-semibold text-decoration-none"
                >
                  {{ formatAsnDisplay(row.asn_number) }}
                </RouterLink>
              </td>
              <td>{{ row.client_account_company_name || "—" }}</td>
              <td class="text-end">{{ row.expected_qty }}</td>
              <td class="text-end">{{ row.accepted_qty }}</td>
              <td class="text-end">{{ row.total_boxes }}</td>
              <td>
                <a
                  v-if="trackingLink(row)"
                  :href="trackingLink(row)"
                  target="_blank"
                  rel="noopener noreferrer"
                  class="text-decoration-none"
                  @click.stop
                >
                  {{ row.tracking_display || "—" }}
                </a>
                <span v-else>{{ row.tracking_display || "—" }}</span>
              </td>
              <td class="text-end">
                <RouterLink
                  :to="{ name: 'admin-asn-detail', params: { id: String(row.id) } }"
                  class="btn btn-sm btn-outline-primary"
                >
                  View
                </RouterLink>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <div
        v-if="meta.last_page > 1"
        class="d-flex justify-content-between align-items-center px-3 py-2 border-top"
      >
        <span class="small text-body-secondary">Page {{ meta.current_page }} of {{ meta.last_page }}</span>
        <div class="btn-group btn-group-sm">
          <button
            type="button"
            class="btn btn-outline-secondary"
            :disabled="meta.current_page <= 1"
            @click="goPage(meta.current_page - 1)"
          >
            Previous
          </button>
          <button
            type="button"
            class="btn btn-outline-secondary"
            :disabled="meta.current_page >= meta.last_page"
            @click="goPage(meta.current_page + 1)"
          >
            Next
          </button>
        </div>
      </div>
    </div>

    <ConfirmModal
      :open="createModalOpen"
      title="Create ASN"
      confirm-label="Continue"
      :busy="createBusy"
      @close="createModalOpen = false"
      @confirm="confirmCreate"
    >
      <label class="form-label">Account</label>
      <select v-model="createAccountId" class="form-select">
        <option value="">Select account…</option>
        <option v-for="a in accountOptions" :key="a.id" :value="String(a.id)">
          {{ a.company_name || a.label || `Account #${a.id}` }}
        </option>
      </select>
    </ConfirmModal>

    <ConfirmModal
      :open="nonCompliantOpen"
      title="Non-Compliant ASN"
      confirm-label="Create"
      :busy="nonCompliantBusy"
      @close="nonCompliantOpen = false"
      @confirm="submitNonCompliant"
    >
      <div class="row g-3">
        <div class="col-12">
          <label class="form-label">Account</label>
          <select v-model="ncAccountId" class="form-select">
            <option value="">Select account…</option>
            <option v-for="a in accountOptions" :key="a.id" :value="String(a.id)">
              {{ a.company_name || a.label || `Account #${a.id}` }}
            </option>
          </select>
        </div>
        <div class="col-6">
          <label class="form-label">Boxes</label>
          <input v-model.number="ncBoxes" type="number" min="0" class="form-control" />
        </div>
        <div class="col-6">
          <label class="form-label">Pallets</label>
          <input v-model.number="ncPallets" type="number" min="0" class="form-control" />
        </div>
        <div class="col-12">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <label class="form-label mb-0">Tracking</label>
            <button type="button" class="btn btn-link btn-sm p-0" @click="addNcTracking">Add Row</button>
          </div>
          <div v-for="(t, i) in ncTrackings" :key="i" class="row g-2 mb-2">
            <div class="col-5">
              <select v-model="t.carrier" class="form-select form-select-sm">
                <option value="">Carrier</option>
                <option v-for="c in ASN_CARRIER_OPTIONS" :key="c" :value="c">{{ c }}</option>
              </select>
            </div>
            <div class="col-7">
              <input
                v-model="t.tracking_number"
                type="text"
                class="form-control form-control-sm"
                placeholder="Tracking #"
              />
            </div>
          </div>
        </div>
        <div class="col-12">
          <label class="form-label">Non-Compliant Fee</label>
          <input v-model="ncFee" type="number" min="0" step="0.01" class="form-control" placeholder="0.00" />
          <p class="form-text mb-0">If greater than zero, a custom bill line is created automatically.</p>
        </div>
      </div>
    </ConfirmModal>
  </div>
</template>
