<script setup>
import { computed, onMounted, onUnmounted, ref } from "vue";
import { useRoute, useRouter } from "vue-router";
import api from "../../services/api";
import ConfirmModal from "../common/ConfirmModal.vue";
import CrmIconRowActions from "../common/CrmIconRowActions.vue";
import CrmLoadingSpinner from "../common/CrmLoadingSpinner.vue";
import CrmRightDrawer from "../common/CrmRightDrawer.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast.js";
import {
  CRM_BTN_PRIMARY,
  CRM_BTN_SECONDARY,
  CRM_DIALOG_FOOTER_CLASS_DRAWER,
} from "../../constants/dialogFooter.js";
import {
  formatLtlMoney,
  ltlStatusBadgeClass,
  LTL_DIRECTIONS,
  LTL_LOAD_OPTIONS,
  LTL_PICKUP_TYPES,
  LTL_SERVICES,
  LTL_STATUSES,
  LTL_TIME_MODES,
} from "../../constants/ltlSections.js";

const props = defineProps({
  mode: { type: String, default: "admin" },
});

const toast = useToast();
const route = useRoute();
const router = useRouter();
const isPortal = computed(() => props.mode === "portal");
const apiBase = computed(() => (isPortal.value ? "/ltl-shipments" : "/admin/ltl-shipments"));
const listRoute = computed(() => (isPortal.value ? "user-ltl-list" : "admin-ltl-list"));
const listLabel = computed(() => (isPortal.value ? "LTL" : "Receiving LTL"));

const loading = ref(true);
const saving = ref(false);
const shipment = ref(null);

const addressOpen = ref(false);
const quoteOpen = ref(false);
const palletOpen = ref(false);
const editingPallet = ref(null);
const requirementsOpen = ref(false);

const addressForm = ref({});
const quoteForm = ref({});
const palletForm = ref({});
const reqForm = ref({ load_requirement: "", pickup_type: "" });
const notesDraft = ref("");

const statusMenuOpen = ref(false);
const palletMenuOpenId = ref(null);
const palletMenuRect = ref({ top: 0, left: 0 });
const deletePalletOpen = ref(false);
const deletePalletBusy = ref(false);
const deletePalletTarget = ref(null);

const canEdit = computed(() => {
  if (!shipment.value) return false;
  if (!isPortal.value) return true;
  return ["draft", "pending"].includes(shipment.value.status);
});

const canGetQuote = computed(() => shipment.value?.status === "draft" && canEdit.value);
const canChangeStatus = computed(() => !isPortal.value);

const loadLabel = computed(() => {
  const v = shipment.value?.load_requirement;
  return LTL_LOAD_OPTIONS.find((o) => o.value === v)?.label || v || "—";
});

const pickupLabel = computed(() => {
  const v = shipment.value?.pickup_type;
  return LTL_PICKUP_TYPES.find((o) => o.value === v)?.label || v || "—";
});

const serviceLabel = computed(() => {
  const v = shipment.value?.quote_service;
  return LTL_SERVICES.find((s) => s.value === v)?.label || v || "—";
});

const timeLabel = computed(() => {
  const s = shipment.value;
  if (!s) return "—";
  if (s.time_mode === "specific") {
    const from = formatDateTime(s.time_from);
    const to = formatDateTime(s.time_to);
    if (from && to) return `${from} → ${to}`;
    return from || to || "—";
  }
  return "As Soon As Possible";
});

const timeFieldLabel = computed(() =>
  shipment.value?.direction === "ship_from_save_rack" ? "Delivery Time" : "Pick Up Time",
);

const cityLine = computed(() => {
  const s = shipment.value;
  if (!s) return "—";
  return [s.city, s.state, s.zip].filter(Boolean).join(", ") || "—";
});

const palletMenuRow = computed(
  () => (shipment.value?.pallets || []).find((p) => p.id === palletMenuOpenId.value) ?? null,
);

function formatDateTime(iso) {
  if (!iso) return "";
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return String(iso).replace("T", " ").slice(0, 16);
  return d.toLocaleString(undefined, {
    month: "short",
    day: "numeric",
    year: "numeric",
    hour: "numeric",
    minute: "2-digit",
  });
}

function applyShipment(data) {
  shipment.value = data;
  if (!data) return;
  reqForm.value = {
    load_requirement: data.load_requirement || "",
    pickup_type: data.pickup_type || "",
  };
  notesDraft.value = data.notes || "";
  setCrmPageMeta({
    title: `Save Rack | ${data.number}`,
    description: "LTL shipment detail.",
  });
}

async function load() {
  loading.value = true;
  try {
    const { data } = await api.get(`${apiBase.value}/${route.params.id}`);
    applyShipment(data?.shipment || null);
  } catch (e) {
    toast.errorFrom(e, "Could not load LTL.");
    shipment.value = null;
  } finally {
    loading.value = false;
  }
}

function openAddressEdit() {
  const s = shipment.value;
  addressForm.value = {
    direction: s.direction,
    company_name: s.company_name || "",
    address_line1: s.address_line1 || "",
    address_line2: s.address_line2 || "",
    city: s.city || "",
    state: s.state || "",
    zip: s.zip || "",
    contact_name: s.contact_name || "",
    contact_email: s.contact_email || "",
    contact_phone: s.contact_phone || "",
    time_mode: s.time_mode || "asap",
    time_from: s.time_from ? s.time_from.slice(0, 16) : "",
    time_to: s.time_to ? s.time_to.slice(0, 16) : "",
  };
  addressOpen.value = true;
}

function openRequirementsEdit() {
  reqForm.value = {
    load_requirement: shipment.value.load_requirement || "",
    pickup_type: shipment.value.pickup_type || "",
  };
  requirementsOpen.value = true;
}

function openQuoteEdit() {
  const s = shipment.value;
  quoteForm.value = {
    quote_amount: s.quote_amount_cents != null ? (Number(s.quote_amount_cents) / 100).toFixed(2) : "",
    quote_carrier: s.quote_carrier || "",
    quote_transit_time: s.quote_transit_time || "",
    quote_service: s.quote_service || "standard_ltl",
    tracking_number: s.tracking_number || "",
  };
  quoteOpen.value = true;
}

function openAddPallet() {
  editingPallet.value = null;
  palletForm.value = { commodity: "", length_in: 48, width_in: 40, height_in: 60, weight_lbs: "" };
  palletOpen.value = true;
}

function openEditPallet(p) {
  editingPallet.value = p;
  palletForm.value = {
    commodity: p.commodity || "",
    length_in: p.length_in,
    width_in: p.width_in,
    height_in: p.height_in,
    weight_lbs: p.weight_lbs,
  };
  palletMenuOpenId.value = null;
  palletOpen.value = true;
}

async function patch(body, successMsg = "Saved.") {
  saving.value = true;
  try {
    const { data } = await api.patch(`${apiBase.value}/${shipment.value.id}`, body);
    applyShipment(data.shipment);
    toast.success(successMsg);
    return true;
  } catch (e) {
    toast.errorFrom(e, "Could not save.");
    return false;
  } finally {
    saving.value = false;
  }
}

async function saveAddress() {
  const f = addressForm.value;
  const body = {
    direction: f.direction,
    company_name: f.company_name,
    address_line1: f.address_line1,
    address_line2: f.address_line2 || null,
    city: f.city,
    state: f.state,
    zip: f.zip,
    contact_name: f.contact_name,
    contact_email: f.contact_email || null,
    contact_phone: f.contact_phone,
    time_mode: f.time_mode,
    time_from: f.time_mode === "specific" && f.time_from ? f.time_from : null,
    time_to: f.time_mode === "specific" && f.time_to ? f.time_to : null,
  };
  if (await patch(body, "Address saved.")) addressOpen.value = false;
}

async function saveRequirements() {
  if (
    await patch(
      {
        load_requirement: reqForm.value.load_requirement || null,
        pickup_type: reqForm.value.pickup_type || null,
      },
      "Requirements saved.",
    )
  ) {
    requirementsOpen.value = false;
  }
}

async function saveNotes() {
  await patch({ notes: notesDraft.value || null }, "Notes saved.");
}

async function saveQuote() {
  const amount = quoteForm.value.quote_amount;
  const cents = amount === "" || amount == null ? null : Math.round(Number(amount) * 100);
  const body = {
    quote_amount_cents: Number.isFinite(cents) ? cents : null,
    quote_carrier: quoteForm.value.quote_carrier || null,
    quote_transit_time: quoteForm.value.quote_transit_time || null,
    quote_service: quoteForm.value.quote_service || "standard_ltl",
    tracking_number: quoteForm.value.tracking_number || null,
  };
  if (await patch(body, "Quote saved.")) quoteOpen.value = false;
}

async function savePallet() {
  saving.value = true;
  try {
    const body = { ...palletForm.value };
    if (editingPallet.value) {
      const { data } = await api.patch(
        `${apiBase.value}/${shipment.value.id}/pallets/${editingPallet.value.id}`,
        body,
      );
      applyShipment(data.shipment);
    } else {
      const { data } = await api.post(`${apiBase.value}/${shipment.value.id}/pallets`, body);
      applyShipment(data.shipment);
    }
    palletOpen.value = false;
    toast.success("Pallet saved.");
  } catch (e) {
    toast.errorFrom(e, "Could not save pallet.");
  } finally {
    saving.value = false;
  }
}

function askRemovePallet(p) {
  deletePalletTarget.value = p;
  palletMenuOpenId.value = null;
  deletePalletOpen.value = true;
}

async function confirmRemovePallet() {
  if (!deletePalletTarget.value) return;
  deletePalletBusy.value = true;
  try {
    const { data } = await api.delete(
      `${apiBase.value}/${shipment.value.id}/pallets/${deletePalletTarget.value.id}`,
    );
    applyShipment(data.shipment);
    toast.success("Pallet removed.");
    deletePalletOpen.value = false;
    deletePalletTarget.value = null;
  } catch (e) {
    toast.errorFrom(e, "Could not remove pallet.");
  } finally {
    deletePalletBusy.value = false;
  }
}

async function onGetQuote() {
  saving.value = true;
  try {
    const { data } = await api.post(`${apiBase.value}/${shipment.value.id}/request-quote`);
    applyShipment(data.shipment);
    toast.success("Quote requested — status is Pending.");
  } catch (e) {
    const msgs = e.response?.data?.errors?.quote;
    if (Array.isArray(msgs) && msgs.length) {
      toast.error(msgs.join(" "));
    } else {
      toast.errorFrom(e, "Could not request quote.");
    }
  } finally {
    saving.value = false;
  }
}

async function setStatus(status) {
  statusMenuOpen.value = false;
  if (!canChangeStatus.value || !status || status === shipment.value?.status) return;
  saving.value = true;
  try {
    const { data } = await api.patch(`${apiBase.value}/${shipment.value.id}/status`, { status });
    applyShipment(data.shipment);
    toast.success("Status updated.");
  } catch (e) {
    toast.errorFrom(e, "Could not update status.");
  } finally {
    saving.value = false;
  }
}

function togglePalletMenu(p, event) {
  const btn = event?.currentTarget;
  if (!(btn instanceof HTMLElement)) return;
  if (palletMenuOpenId.value === p.id) {
    palletMenuOpenId.value = null;
    return;
  }
  const rect = btn.getBoundingClientRect();
  const menuWidth = 160;
  palletMenuRect.value = {
    top: rect.bottom + 4,
    left: Math.max(8, Math.min(rect.right - menuWidth, window.innerWidth - menuWidth - 8)),
  };
  palletMenuOpenId.value = p.id;
}

function onDocClick(e) {
  const t = e.target;
  if (!(t instanceof Element)) return;
  if (!t.closest("[data-ltl-status-menu]")) statusMenuOpen.value = false;
  if (!t.closest("[data-row-actions]")) palletMenuOpenId.value = null;
}

function onWindowCloseMenus() {
  statusMenuOpen.value = false;
  palletMenuOpenId.value = null;
}

onMounted(() => {
  load();
  document.addEventListener("click", onDocClick);
  window.addEventListener("scroll", onWindowCloseMenus, true);
  window.addEventListener("resize", onWindowCloseMenus);
});

onUnmounted(() => {
  document.removeEventListener("click", onDocClick);
  window.removeEventListener("scroll", onWindowCloseMenus, true);
  window.removeEventListener("resize", onWindowCloseMenus);
});
</script>

<template>
  <div v-if="loading" class="staff-page staff-page--wide py-5">
    <CrmLoadingSpinner message="Loading LTL…" :center="true" />
  </div>

  <div v-else-if="!shipment" class="staff-page staff-page--wide py-5 text-secondary">
    LTL not found.
    <button type="button" class="btn btn-link btn-sm d-block px-0 mt-2" @click="router.push({ name: listRoute })">
      Back to LTL
    </button>
  </div>

  <div
    v-else
    class="staff-page staff-page--wide order-detail-page asn-detail-page ltl-detail-page"
  >
    <header class="asn-detail-page__hero staff-table-card staff-datatable-card staff-datatable-card--white p-4">
      <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
        <div class="asn-detail-page__hero-title-row min-w-0">
          <span class="asn-detail-page__hero-doc-icon" aria-hidden="true">
            <svg width="22" height="22" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="2"
                d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"
              />
            </svg>
          </span>
          <div class="min-w-0">
            <div class="d-flex flex-wrap align-items-center gap-2">
              <h1 class="h4 mb-0 fw-bold text-body">{{ shipment.number }}</h1>
              <span :class="ltlStatusBadgeClass(shipment.status)">{{ shipment.status_label }}</span>
            </div>
            <p class="small text-secondary mb-0 mt-2">
              <template v-if="!isPortal && shipment.account_name">
                {{ shipment.account_name }} ·
              </template>
              {{ shipment.direction_label }}
            </p>
            <button
              type="button"
              class="btn btn-link btn-sm text-secondary px-0 py-0 mt-2 text-decoration-none"
              @click="router.push({ name: listRoute })"
            >
              &lt; {{ listLabel }}
            </button>
          </div>
        </div>

        <div class="d-flex flex-wrap gap-2 flex-shrink-0 align-items-center asn-detail-page__hero-actions">
          <button
            v-if="canGetQuote"
            type="button"
            class="btn btn-primary staff-page-primary fw-semibold"
            :disabled="saving"
            @click="onGetQuote"
          >
            Get Quote
          </button>
          <div v-if="canChangeStatus" class="position-relative" data-ltl-status-menu>
            <button
              type="button"
              class="btn btn-outline-secondary fw-semibold orders-toolbar-outline-btn d-inline-flex align-items-center gap-2"
              :disabled="saving"
              :aria-expanded="statusMenuOpen"
              @click.stop="statusMenuOpen = !statusMenuOpen"
            >
              Update Status
              <span aria-hidden="true">▾</span>
            </button>
            <div
              v-if="statusMenuOpen"
              class="dropdown-menu dropdown-menu-end show shadow border py-1 mt-1"
              style="min-width: 11rem"
              role="menu"
              @click.stop
            >
              <button
                v-for="(meta, key) in LTL_STATUSES"
                :key="key"
                type="button"
                class="dropdown-item"
                :disabled="shipment.status === key || saving"
                role="menuitem"
                @click="setStatus(key)"
              >
                {{ meta.label }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </header>

    <div class="row g-4">
      <div class="col-lg-8">
        <div class="staff-table-card staff-datatable-card staff-datatable-card--white p-0 mb-4">
          <div class="px-4 py-3 border-bottom d-flex justify-content-between align-items-center flex-wrap gap-2 asn-detail-page__section-head">
            <div class="d-flex align-items-center gap-2 min-w-0">
              <span class="asn-detail-page__section-icon asn-detail-page__section-icon--products" aria-hidden="true">
                <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"
                  />
                </svg>
              </span>
              <h2 class="h6 mb-0 fw-semibold">Pallets in Shipment</h2>
              <span class="badge text-bg-light border text-secondary fw-medium">
                {{ (shipment.pallets || []).length }}
              </span>
            </div>
            <button
              v-if="canEdit"
              type="button"
              class="btn btn-sm btn-primary staff-page-primary fw-semibold"
              @click="openAddPallet"
            >
              Add Pallet
            </button>
          </div>

          <div class="table-responsive staff-table-wrap">
            <table class="table table-hover align-middle mb-0 staff-data-table">
              <thead class="table-light staff-table-head">
                <tr>
                  <th class="staff-table-head__th text-center" scope="col" style="width: 3rem">#</th>
                  <th class="staff-table-head__th" scope="col">Commodity</th>
                  <th class="staff-table-head__th text-center" scope="col">L (in)</th>
                  <th class="staff-table-head__th text-center" scope="col">W (in)</th>
                  <th class="staff-table-head__th text-center" scope="col">H (in)</th>
                  <th class="staff-table-head__th text-center" scope="col">Weight (lbs)</th>
                  <th
                    v-if="canEdit"
                    class="staff-table-head__th staff-actions-col text-center"
                    scope="col"
                  >
                    Action
                  </th>
                </tr>
              </thead>
              <tbody>
                <tr v-if="!(shipment.pallets || []).length">
                  <td :colspan="canEdit ? 7 : 6" class="text-center text-secondary py-5">
                    No pallets yet.{{ canEdit ? " Use Add Pallet to get started." : "" }}
                  </td>
                </tr>
                <tr v-for="(p, idx) in shipment.pallets" :key="p.id" class="align-middle">
                  <td class="text-center text-secondary">{{ idx + 1 }}</td>
                  <td class="fw-semibold">{{ p.commodity || "—" }}</td>
                  <td class="text-center">{{ p.length_in ?? "—" }}</td>
                  <td class="text-center">{{ p.width_in ?? "—" }}</td>
                  <td class="text-center">{{ p.height_in ?? "—" }}</td>
                  <td class="text-center">{{ p.weight_lbs ?? "—" }}</td>
                  <td v-if="canEdit" class="staff-actions-cell text-center" @click.stop>
                    <div
                      data-row-actions
                      class="staff-actions-inner staff-actions-inner--single justify-content-center"
                    >
                      <button
                        type="button"
                        class="staff-action-btn staff-action-btn--more"
                        :class="{ 'is-open': palletMenuOpenId === p.id }"
                        :aria-expanded="palletMenuOpenId === p.id"
                        aria-haspopup="true"
                        aria-label="Pallet Actions"
                        @click="(e) => togglePalletMenu(p, e)"
                      >
                        <CrmIconRowActions variant="horizontal" />
                      </button>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <p class="staff-table-mobile-scroll-cue d-md-none px-3 pb-2 mb-0" aria-hidden="true">
            Scroll sideways or swipe to see all columns.
          </p>
        </div>

        <div class="staff-table-card staff-datatable-card staff-datatable-card--white p-4">
          <div class="d-flex align-items-center gap-2 mb-3 asn-detail-page__section-head">
            <span class="asn-detail-page__section-icon asn-detail-page__section-icon--note" aria-hidden="true">
              <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  stroke-width="2"
                  d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"
                />
              </svg>
            </span>
            <div class="min-w-0">
              <h2 class="h6 mb-0 fw-semibold">Notes</h2>
              <p class="small text-secondary mb-0">Optional note for this shipment</p>
            </div>
          </div>
          <textarea
            v-model="notesDraft"
            class="form-control mb-3"
            rows="4"
            placeholder="Add a note…"
            :disabled="!canEdit || saving"
          />
          <button
            v-if="canEdit"
            type="button"
            class="btn btn-primary btn-sm staff-page-primary fw-semibold"
            :disabled="saving"
            @click="saveNotes"
          >
            {{ saving ? "Saving…" : "Save Notes" }}
          </button>
        </div>
      </div>

      <div class="col-lg-4 d-flex flex-column gap-4 order-detail-page__side-column">
        <div class="staff-table-card staff-datatable-card staff-datatable-card--white p-4">
          <div class="d-flex justify-content-between align-items-start gap-2 mb-3">
            <div class="d-flex align-items-center gap-2 asn-detail-page__section-head min-w-0">
              <span class="asn-detail-page__section-icon asn-detail-page__section-icon--tracking" aria-hidden="true">
                <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"
                  />
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"
                  />
                </svg>
              </span>
              <h3 class="h6 fw-semibold mb-0">{{ shipment.address_card_title }}</h3>
            </div>
            <button
              v-if="canEdit"
              type="button"
              class="btn btn-sm btn-outline-secondary fw-semibold orders-toolbar-outline-btn flex-shrink-0"
              @click="openAddressEdit"
            >
              Edit
            </button>
          </div>

          <p class="small text-secondary mb-2">{{ shipment.direction_label }}</p>
          <p class="fw-semibold text-body mb-1">{{ shipment.company_name || "—" }}</p>
          <div class="small text-secondary mb-3 ltl-detail-page__address-block">
            <p class="mb-0">{{ shipment.address_line1 || "—" }}</p>
            <p v-if="shipment.address_line2" class="mb-0">{{ shipment.address_line2 }}</p>
            <p class="mb-0">{{ cityLine }}</p>
          </div>

          <dl class="ltl-detail-page__meta mb-0">
            <div class="ltl-detail-page__meta-row">
              <dt>Contact Name</dt>
              <dd>{{ shipment.contact_name || "—" }}</dd>
            </div>
            <div class="ltl-detail-page__meta-row">
              <dt>Email</dt>
              <dd>{{ shipment.contact_email || "—" }}</dd>
            </div>
            <div class="ltl-detail-page__meta-row">
              <dt>Phone</dt>
              <dd>{{ shipment.contact_phone || "—" }}</dd>
            </div>
            <div class="ltl-detail-page__meta-row">
              <dt>{{ timeFieldLabel }}</dt>
              <dd>{{ timeLabel }}</dd>
            </div>
          </dl>
        </div>

        <div class="staff-table-card staff-datatable-card staff-datatable-card--white p-4">
          <div class="d-flex justify-content-between align-items-start gap-2 mb-3">
            <div class="d-flex align-items-center gap-2 asn-detail-page__section-head min-w-0">
              <span class="asn-detail-page__section-icon asn-detail-page__section-icon--info" aria-hidden="true">
                <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"
                  />
                </svg>
              </span>
              <h3 class="h6 fw-semibold mb-0">Requirements</h3>
            </div>
            <button
              v-if="canEdit"
              type="button"
              class="btn btn-sm btn-outline-secondary fw-semibold orders-toolbar-outline-btn flex-shrink-0"
              @click="openRequirementsEdit"
            >
              Edit
            </button>
          </div>
          <dl class="ltl-detail-page__meta mb-0">
            <div class="ltl-detail-page__meta-row">
              <dt>Load</dt>
              <dd>{{ loadLabel }}</dd>
            </div>
            <div class="ltl-detail-page__meta-row">
              <dt>Pickup Type</dt>
              <dd>{{ pickupLabel }}</dd>
            </div>
          </dl>
        </div>

        <div class="staff-table-card staff-datatable-card staff-datatable-card--white p-4">
          <div class="d-flex justify-content-between align-items-start gap-2 mb-3">
            <div class="d-flex align-items-center gap-2 asn-detail-page__section-head min-w-0">
              <span class="asn-detail-page__section-icon asn-detail-page__section-icon--fees" aria-hidden="true">
                <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
                  />
                </svg>
              </span>
              <h3 class="h6 fw-semibold mb-0">Quote</h3>
            </div>
            <button
              v-if="!isPortal"
              type="button"
              class="btn btn-sm btn-outline-secondary fw-semibold orders-toolbar-outline-btn flex-shrink-0"
              @click="openQuoteEdit"
            >
              Edit
            </button>
          </div>
          <p class="ltl-detail-page__quote-amount mb-3">
            {{ formatLtlMoney(shipment.quote_amount_cents) }}
          </p>
          <dl class="ltl-detail-page__meta mb-0">
            <div class="ltl-detail-page__meta-row">
              <dt>Carrier</dt>
              <dd>{{ shipment.quote_carrier || "—" }}</dd>
            </div>
            <div class="ltl-detail-page__meta-row">
              <dt>Transit Time</dt>
              <dd>{{ shipment.quote_transit_time || "—" }}</dd>
            </div>
            <div class="ltl-detail-page__meta-row">
              <dt>Service</dt>
              <dd>{{ serviceLabel }}</dd>
            </div>
            <div v-if="shipment.tracking_number" class="ltl-detail-page__meta-row">
              <dt>Tracking</dt>
              <dd>{{ shipment.tracking_number }}</dd>
            </div>
          </dl>
        </div>
      </div>
    </div>

    <Teleport to="body">
      <div
        v-if="palletMenuRow"
        data-row-actions
        class="staff-row-menu fixed z-[300] overflow-hidden"
        role="menu"
        :style="{
          top: `${palletMenuRect.top}px`,
          left: `${palletMenuRect.left}px`,
        }"
        @click.stop
      >
        <button
          type="button"
          class="staff-row-menu__item"
          role="menuitem"
          @click="openEditPallet(palletMenuRow)"
        >
          Edit
        </button>
        <hr class="staff-row-menu__divider" />
        <button
          type="button"
          class="staff-row-menu__item staff-row-menu__item--danger"
          role="menuitem"
          @click="askRemovePallet(palletMenuRow)"
        >
          Remove
        </button>
      </div>
    </Teleport>

    <ConfirmModal
      :open="deletePalletOpen"
      title="Remove Pallet"
      :message="
        deletePalletTarget
          ? `Remove pallet${deletePalletTarget.commodity ? ` “${deletePalletTarget.commodity}”` : ''} from this shipment?`
          : ''
      "
      confirm-label="Remove"
      :busy="deletePalletBusy"
      @close="deletePalletOpen = false"
      @confirm="confirmRemovePallet"
    />

    <CrmRightDrawer
      :open="addressOpen"
      title="Edit Address"
      :busy="saving"
      form-id="ltl-address-form"
      @update:open="addressOpen = $event"
      @submit="saveAddress"
    >
      <div class="d-flex flex-column gap-3">
        <div>
          <label class="form-label">Location</label>
          <select v-model="addressForm.direction" class="form-select">
            <option v-for="d in LTL_DIRECTIONS" :key="d.value" :value="d.value">{{ d.label }}</option>
          </select>
        </div>
        <div>
          <label class="form-label">Company Name</label>
          <input v-model="addressForm.company_name" class="form-control" />
        </div>
        <div>
          <label class="form-label">Address</label>
          <input v-model="addressForm.address_line1" class="form-control" />
        </div>
        <div class="row g-2">
          <div class="col-5">
            <label class="form-label">City</label>
            <input v-model="addressForm.city" class="form-control" />
          </div>
          <div class="col-3">
            <label class="form-label">State</label>
            <input v-model="addressForm.state" class="form-control" />
          </div>
          <div class="col-4">
            <label class="form-label">Zip</label>
            <input v-model="addressForm.zip" class="form-control" />
          </div>
        </div>
        <div>
          <label class="form-label">Contact Name</label>
          <input v-model="addressForm.contact_name" class="form-control" />
        </div>
        <div>
          <label class="form-label">Email</label>
          <input v-model="addressForm.contact_email" type="email" class="form-control" />
        </div>
        <div>
          <label class="form-label">Phone</label>
          <input v-model="addressForm.contact_phone" class="form-control" />
        </div>
        <div>
          <label class="form-label">{{
            addressForm.direction === "ship_from_save_rack" ? "Delivery Time" : "Pick Up Time"
          }}</label>
          <select v-model="addressForm.time_mode" class="form-select">
            <option v-for="t in LTL_TIME_MODES" :key="t.value" :value="t.value">{{ t.label }}</option>
          </select>
        </div>
        <template v-if="addressForm.time_mode === 'specific'">
          <div>
            <label class="form-label">From</label>
            <input v-model="addressForm.time_from" type="datetime-local" class="form-control" />
          </div>
          <div>
            <label class="form-label">To</label>
            <input v-model="addressForm.time_to" type="datetime-local" class="form-control" />
          </div>
        </template>
      </div>
      <template #footer>
        <footer :class="CRM_DIALOG_FOOTER_CLASS_DRAWER">
          <button type="button" :class="CRM_BTN_SECONDARY" @click="addressOpen = false">Cancel</button>
          <button type="submit" form="ltl-address-form" :class="CRM_BTN_PRIMARY" :disabled="saving">
            Save
          </button>
        </footer>
      </template>
    </CrmRightDrawer>

    <CrmRightDrawer
      :open="requirementsOpen"
      title="Edit Requirements"
      :busy="saving"
      form-id="ltl-requirements-form"
      @update:open="requirementsOpen = $event"
      @submit="saveRequirements"
    >
      <div class="d-flex flex-column gap-3">
        <div>
          <label class="form-label">Load</label>
          <select v-model="reqForm.load_requirement" class="form-select">
            <option value="">Select…</option>
            <option v-for="o in LTL_LOAD_OPTIONS" :key="o.value" :value="o.value">{{ o.label }}</option>
          </select>
        </div>
        <div>
          <label class="form-label">Pickup Type</label>
          <select v-model="reqForm.pickup_type" class="form-select">
            <option value="">Select…</option>
            <option v-for="o in LTL_PICKUP_TYPES" :key="o.value" :value="o.value">{{ o.label }}</option>
          </select>
        </div>
      </div>
      <template #footer>
        <footer :class="CRM_DIALOG_FOOTER_CLASS_DRAWER">
          <button type="button" :class="CRM_BTN_SECONDARY" @click="requirementsOpen = false">Cancel</button>
          <button type="submit" form="ltl-requirements-form" :class="CRM_BTN_PRIMARY" :disabled="saving">
            Save
          </button>
        </footer>
      </template>
    </CrmRightDrawer>

    <CrmRightDrawer
      :open="quoteOpen"
      title="Edit Quote"
      :busy="saving"
      form-id="ltl-quote-form"
      @update:open="quoteOpen = $event"
      @submit="saveQuote"
    >
      <div class="d-flex flex-column gap-3">
        <div>
          <label class="form-label">Amount</label>
          <input v-model="quoteForm.quote_amount" type="number" step="0.01" min="0" class="form-control" />
        </div>
        <div>
          <label class="form-label">Carrier Name</label>
          <input v-model="quoteForm.quote_carrier" class="form-control" />
        </div>
        <div>
          <label class="form-label">Transit Time</label>
          <input
            v-model="quoteForm.quote_transit_time"
            class="form-control"
            placeholder="e.g. 3 – 5 Days"
          />
        </div>
        <div>
          <label class="form-label">Service</label>
          <select v-model="quoteForm.quote_service" class="form-select">
            <option v-for="s in LTL_SERVICES" :key="s.value" :value="s.value">{{ s.label }}</option>
          </select>
        </div>
        <div>
          <label class="form-label">Tracking Number</label>
          <input v-model="quoteForm.tracking_number" class="form-control" />
        </div>
      </div>
      <template #footer>
        <footer :class="CRM_DIALOG_FOOTER_CLASS_DRAWER">
          <button type="button" :class="CRM_BTN_SECONDARY" @click="quoteOpen = false">Cancel</button>
          <button type="submit" form="ltl-quote-form" :class="CRM_BTN_PRIMARY" :disabled="saving">
            Save
          </button>
        </footer>
      </template>
    </CrmRightDrawer>

    <CrmRightDrawer
      :open="palletOpen"
      :title="editingPallet ? 'Edit Pallet' : 'Add Pallet'"
      :busy="saving"
      form-id="ltl-pallet-form"
      @update:open="palletOpen = $event"
      @submit="savePallet"
    >
      <div class="d-flex flex-column gap-3">
        <div>
          <label class="form-label">Commodity</label>
          <input v-model="palletForm.commodity" class="form-control" />
        </div>
        <div class="row g-2">
          <div class="col-4">
            <label class="form-label">Length (in)</label>
            <input v-model="palletForm.length_in" type="number" class="form-control" />
          </div>
          <div class="col-4">
            <label class="form-label">Width (in)</label>
            <input v-model="palletForm.width_in" type="number" class="form-control" />
          </div>
          <div class="col-4">
            <label class="form-label">Height (in)</label>
            <input v-model="palletForm.height_in" type="number" class="form-control" />
          </div>
        </div>
        <div>
          <label class="form-label">Weight (lbs)</label>
          <input v-model="palletForm.weight_lbs" type="number" class="form-control" />
        </div>
      </div>
      <template #footer>
        <footer :class="CRM_DIALOG_FOOTER_CLASS_DRAWER">
          <button type="button" :class="CRM_BTN_SECONDARY" @click="palletOpen = false">Cancel</button>
          <button type="submit" form="ltl-pallet-form" :class="CRM_BTN_PRIMARY" :disabled="saving">
            Save
          </button>
        </footer>
      </template>
    </CrmRightDrawer>
  </div>
</template>

<style scoped>
@import "../../styles/asn-detail-page.scss";

.ltl-detail-page__address-block {
  line-height: 1.5;
}

.ltl-detail-page__meta {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  margin: 0;
}

.ltl-detail-page__meta-row {
  display: grid;
  grid-template-columns: 7.5rem minmax(0, 1fr);
  gap: 0.5rem 0.75rem;
  align-items: start;
}

.ltl-detail-page__meta-row dt {
  margin: 0;
  font-size: 0.8125rem;
  color: var(--bs-secondary-color);
  font-weight: 500;
}

.ltl-detail-page__meta-row dd {
  margin: 0;
  font-size: 0.875rem;
  font-weight: 600;
  color: var(--bs-body-color);
  word-break: break-word;
}

.ltl-detail-page__quote-amount {
  font-size: 1.75rem;
  font-weight: 800;
  line-height: 1.15;
  letter-spacing: -0.02em;
  color: #16a34a;
  margin: 0;
}
</style>
