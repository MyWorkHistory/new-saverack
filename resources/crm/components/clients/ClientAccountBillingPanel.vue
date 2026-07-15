<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from "vue";
import { RouterLink } from "vue-router";
import api from "../../services/api";
import BillingDollarStatIcon from "../../components/billing/BillingDollarStatIcon.vue";
import AccountDetailSectionHead from "./AccountDetailSectionHead.vue";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import { useToast } from "../../composables/useToast.js";
import { formatCents } from "../../utils/formatMoney.js";
import { formatIsoDate } from "../../utils/formatUserDates.js";

const props = defineProps({
  account: { type: Object, required: true },
  accountId: { type: [String, Number], required: true },
  canEdit: { type: Boolean, default: false },
});

const emit = defineEmits(["edit"]);

const toast = useToast();
const loading = ref(true);
const errorMsg = ref("");
const summary = ref({
  open_balance_due_cents: 0,
  processing_total_cents: 0,
  past_due_balance_cents: 0,
  overdue_invoice_count: 0,
  draft_invoice_count: 0,
  paid_mtd_cents: 0,
});
const invoices = ref([]);
const paymentMethods = ref([]);
const paymentMethodsError = ref("");

const addPaymentOpen = ref(false);
const addPaymentMethod = ref("credit_card");
const addPaymentBusy = ref(false);
const replacePaymentMethodId = ref(null);

const pinModalOpen = ref(false);
const pinTarget = ref(null);
const pinValue = ref("");
const pinBusy = ref(false);
const pinError = ref("");
const detailModalOpen = ref(false);
const detailPayload = ref(null);
const deleteBusyId = ref("");

const nf = new Intl.NumberFormat(undefined, { maximumFractionDigits: 0 });

const preferenceRows = computed(() => [
  {
    key: "payment_type",
    label: "Default payment type",
    value: display(props.account.default_payment_type),
    icon: "card",
  },
  {
    key: "payment_terms",
    label: "Payment terms",
    value:
      props.account.payment_terms_days != null
        ? `${Number(props.account.payment_terms_days)} day${Number(props.account.payment_terms_days) === 1 ? "" : "s"}`
        : "1 day",
    icon: "calendar",
  },
  {
    key: "cc_fee",
    label: "Credit card fee",
    value:
      props.account.cc_fee_percent != null
        ? `${Number(props.account.cc_fee_percent).toFixed(2)}%`
        : "—",
    icon: "percent",
  },
  {
    key: "postage",
    label: "Postage",
    value: display(props.account.postage_option_label),
    icon: "truck",
  },
  {
    key: "packaging",
    label: "Packaging",
    value: display(props.account.packaging_option_label),
    icon: "box",
  },
  {
    key: "stripe",
    label: "Stripe customer ID",
    value: display(props.account.stripe_customer_id),
    icon: "id",
  },
]);

function display(val) {
  if (val == null || val === "") return "—";
  return String(val);
}

function lastFourDisplay(row) {
  const last4 = String(row?.last4 || "").trim();
  if (last4) return `**** ${last4}`;
  const label = String(row?.label || "");
  const m = label.match(/\*{0,}\s*(\d{4})\b/);
  return m ? `**** ${m[1]}` : "—";
}

function typeLabel(row) {
  if (row?.type_label) return row.type_label;
  return row?.type === "us_bank_account" ? "ACH Bank" : "Credit Card";
}

function invoicesUrl(status) {
  const q = new URLSearchParams({
    client_account_id: String(props.accountId),
    status,
  });
  return `/admin/billing/invoices?${q.toString()}`;
}

function openInvoicesInNewTab(status) {
  window.open(invoicesUrl(status), "_blank", "noopener,noreferrer");
}

async function loadSummary() {
  const { data } = await api.get("/billing/summary", {
    params: { client_account_id: Number(props.accountId) },
  });
  summary.value = {
    open_balance_due_cents: data?.open_balance_due_cents ?? 0,
    processing_total_cents: data?.processing_total_cents ?? 0,
    past_due_balance_cents: data?.past_due_balance_cents ?? 0,
    overdue_invoice_count: data?.overdue_invoice_count ?? 0,
    draft_invoice_count: data?.draft_invoice_count ?? 0,
    paid_mtd_cents: data?.paid_mtd_cents ?? 0,
  };
}

async function loadInvoices() {
  const { data } = await api.get("/invoices", {
    params: {
      client_account_id: Number(props.accountId),
      per_page: 5,
      sort_by: "created_at",
      sort_dir: "desc",
    },
  });
  invoices.value = Array.isArray(data?.data) ? data.data : [];
}

async function loadPaymentMethods() {
  paymentMethodsError.value = "";
  try {
    const { data } = await api.get(
      `/client-accounts/${props.accountId}/stripe-payment-methods`,
    );
    paymentMethods.value = Array.isArray(data?.methods) ? data.methods : [];
    if (data?.error) {
      paymentMethodsError.value = data.error;
    }
  } catch {
    paymentMethods.value = [];
    paymentMethodsError.value = "Could not load payment methods.";
  }
}

async function load() {
  loading.value = true;
  errorMsg.value = "";
  try {
    await Promise.all([loadSummary(), loadInvoices(), loadPaymentMethods()]);
  } catch (e) {
    errorMsg.value = e.response?.data?.message || "Could not load billing data.";
  } finally {
    loading.value = false;
  }
}

function legacyStatusKey(row) {
  return String(row?.status_key || row?.status || "").toLowerCase();
}

function displayStatusText(row) {
  const key = String(row?.status_key || row?.status || "").trim().toLowerCase();
  if (key === "payment_failed") return "Failed";
  const label = String(row?.status_label || "").trim();
  if (label.toLowerCase() === "payment failed") return "Failed";
  if (label) return label;
  return String(row?.status || "")
    .replace(/_/g, " ")
    .replace(/\b\w/g, (c) => c.toUpperCase());
}

function statusBadgeClass(status) {
  const s = String(status || "").toLowerCase();
  if (s === "paid") return "bg-success-subtle text-success";
  if (s === "draft") return "bg-secondary-subtle text-secondary";
  if (s === "void") return "bg-dark-subtle text-secondary";
  if (s === "collection") return "bg-warning-subtle text-warning-emphasis";
  if (s === "processing") return "bg-warning-subtle text-warning-emphasis";
  if (s === "payment_failed") return "bg-danger-subtle text-danger-emphasis";
  if (s === "past_due") return "bg-danger-subtle text-danger-emphasis";
  if (s === "open") return "bg-primary-subtle text-primary-emphasis";
  return "bg-body-secondary text-body-secondary";
}

function openAddPaymentModal(replaceId = null, preferredMethod = "credit_card") {
  replacePaymentMethodId.value = replaceId;
  addPaymentMethod.value =
    preferredMethod === "ach" || preferredMethod === "us_bank_account"
      ? "ach"
      : "credit_card";
  addPaymentOpen.value = true;
}

function closeAddPaymentModal() {
  if (!addPaymentBusy.value) {
    addPaymentOpen.value = false;
    replacePaymentMethodId.value = null;
  }
}

async function confirmAddPaymentMethod() {
  if (addPaymentBusy.value) return;
  addPaymentBusy.value = true;
  try {
    const payload = {
      method: addPaymentMethod.value,
    };
    if (replacePaymentMethodId.value) {
      payload.replace_payment_method_id = replacePaymentMethodId.value;
    }
    const { data } = await api.post(
      `/client-accounts/${props.accountId}/payment-method-links`,
      payload,
    );
    const url = String(data?.url || "").trim();
    if (!url) {
      throw new Error("Payment method link missing.");
    }
    addPaymentOpen.value = false;
    replacePaymentMethodId.value = null;
    window.open(url, "_blank", "noopener,noreferrer");
    toast.success("Complete the form in the new tab, then return here.");
  } catch (e) {
    toast.errorFrom(e, "Could not open payment method form.");
  } finally {
    addPaymentBusy.value = false;
  }
}

function openEditPaymentMethod(row) {
  if (!row?.id) return;
  const preferred =
    row.type === "us_bank_account" || row.type_label === "ACH Bank"
      ? "ach"
      : "credit_card";
  openAddPaymentModal(row.id, preferred);
}

async function deletePaymentMethod(row) {
  if (!row?.id || deleteBusyId.value) return;
  const ok = window.confirm(
    `Remove payment method ${lastFourDisplay(row)} (${typeLabel(row)})?`,
  );
  if (!ok) return;
  deleteBusyId.value = row.id;
  try {
    await api.delete(
      `/client-accounts/${props.accountId}/stripe-payment-methods/${row.id}`,
    );
    toast.success("Payment method removed.");
    await loadPaymentMethods();
  } catch (e) {
    toast.errorFrom(e, "Could not remove payment method.");
  } finally {
    deleteBusyId.value = "";
  }
}

function openPinForRow(row) {
  pinTarget.value = row;
  pinValue.value = "";
  pinError.value = "";
  pinModalOpen.value = true;
}

function closePinModal() {
  if (pinBusy.value) return;
  pinModalOpen.value = false;
  pinTarget.value = null;
  pinValue.value = "";
  pinError.value = "";
}

async function submitPin() {
  if (!pinTarget.value?.id || pinBusy.value) return;
  pinBusy.value = true;
  pinError.value = "";
  try {
    const { data } = await api.post(
      `/client-accounts/${props.accountId}/stripe-payment-methods/${pinTarget.value.id}/unlock`,
      { pin: pinValue.value },
    );
    detailPayload.value = data?.payment_method || null;
    pinModalOpen.value = false;
    detailModalOpen.value = true;
  } catch (e) {
    pinError.value =
      e?.response?.data?.message ||
      (e?.response?.status === 403 ? "Incorrect PIN." : "Could not unlock.");
  } finally {
    pinBusy.value = false;
  }
}

function closeDetailModal() {
  detailModalOpen.value = false;
  detailPayload.value = null;
}

function onWindowFocus() {
  if (!loading.value) {
    loadPaymentMethods();
  }
}

function onEsc(e) {
  if (e.key === "Escape" && addPaymentOpen.value) {
    closeAddPaymentModal();
  }
  if (e.key === "Escape" && pinModalOpen.value) {
    closePinModal();
  }
  if (e.key === "Escape" && detailModalOpen.value) {
    closeDetailModal();
  }
}

onMounted(() => {
  load();
  window.addEventListener("focus", onWindowFocus);
  document.addEventListener("keydown", onEsc);
});

onUnmounted(() => {
  window.removeEventListener("focus", onWindowFocus);
  document.removeEventListener("keydown", onEsc);
});

watch(() => props.accountId, load);
</script>

<template>
  <div>
    <div v-if="errorMsg" class="alert alert-danger" role="alert">{{ errorMsg }}</div>

    <div v-if="loading" class="d-flex justify-content-center py-5">
      <CrmLoadingSpinner message="Loading billing…" />
    </div>

    <template v-else>
      <div class="row g-4 mb-4 client-account-billing-summary">
        <div class="col-12 col-sm-6 col-xl-4">
          <div
            class="staff-stat-card billing-inv-summary-card billing-inv-summary-card--static h-100 text-start w-100 client-account-billing-summary-card"
          >
            <p class="staff-stat-card__label">Open Balance Due</p>
            <p class="staff-stat-card__value">
              {{ formatCents(summary.open_balance_due_cents) }}
            </p>
            <p class="staff-stat-card__sub">Open and past due — unpaid total</p>
            <div class="staff-stat-card__icon staff-stat-card__icon--money" aria-hidden="true">
              <BillingDollarStatIcon />
            </div>
            <div class="client-account-billing-summary-card__footer">
              <button
                type="button"
                class="btn btn-link btn-sm client-account-billing-summary-card__link p-0"
                @click="openInvoicesInNewTab('open')"
              >
                View statement &gt;
              </button>
            </div>
          </div>
        </div>
        <div class="col-12 col-sm-6 col-xl-4">
          <div
            class="staff-stat-card billing-inv-summary-card billing-inv-summary-card--static h-100 text-start w-100 client-account-billing-summary-card"
          >
            <p class="staff-stat-card__label">Processing</p>
            <p class="staff-stat-card__value">
              {{ formatCents(summary.processing_total_cents) }}
            </p>
            <p class="staff-stat-card__sub">Payments in progress</p>
            <div
              class="staff-stat-card__icon bg-warning-subtle text-warning-emphasis"
              aria-hidden="true"
            >
              <svg width="22" height="22" fill="currentColor" viewBox="0 0 24 24">
                <path
                  d="M12 4V1L8 5l4 4V6c3.31 0 6 2.69 6 6 0 1.01-.25 1.97-.7 2.8l1.46 1.46A7.93 7.93 0 0 0 20 12c0-4.42-3.58-8-8-8m0 14c-3.31 0-6-2.69-6-6 0-1.01.25-1.97.7-2.8L5.24 7.74A7.93 7.93 0 0 0 4 12c0 4.42 3.58 8 8 8v3l4-4-4-4z"
                />
              </svg>
            </div>
            <div class="client-account-billing-summary-card__footer">
              <button
                type="button"
                class="btn btn-link btn-sm client-account-billing-summary-card__link p-0"
                @click="openInvoicesInNewTab('processing')"
              >
                View processing &gt;
              </button>
            </div>
          </div>
        </div>
        <div class="col-12 col-sm-6 col-xl-4">
          <div
            class="staff-stat-card billing-inv-summary-card billing-inv-summary-card--static h-100 text-start w-100 client-account-billing-summary-card"
          >
            <p class="staff-stat-card__label">Past Due</p>
            <p class="staff-stat-card__value">
              {{ formatCents(summary.past_due_balance_cents) }}
            </p>
            <p class="staff-stat-card__sub">
              {{ nf.format(summary.overdue_invoice_count) }}
              {{ summary.overdue_invoice_count === 1 ? "invoice" : "invoices" }} past due
            </p>
            <div
              class="staff-stat-card__icon bg-danger-subtle text-danger"
              aria-hidden="true"
            >
              <svg width="22" height="22" fill="currentColor" viewBox="0 0 24 24">
                <path
                  d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2m1 15h-2v-2h2zm0-4h-2V7h2z"
                />
              </svg>
            </div>
            <div class="client-account-billing-summary-card__footer">
              <button
                type="button"
                class="btn btn-link btn-sm client-account-billing-summary-card__link p-0"
                @click="openInvoicesInNewTab('past_due')"
              >
                View past due &gt;
              </button>
            </div>
          </div>
        </div>
      </div>

      <div class="row g-4">
        <div class="col-12 col-lg-5">
          <div class="staff-surface p-3 p-md-4 h-100">
            <AccountDetailSectionHead
              title="Billing preferences"
              icon="billing"
              head-class="mb-3"
              :show-edit="canEdit"
              @edit="emit('edit')"
            />

            <div class="client-account-billing-prefs">
              <div
                v-for="row in preferenceRows"
                :key="row.key"
                class="client-account-billing-pref-row"
              >
                <div class="client-account-billing-pref-row__icon" aria-hidden="true">
                  <svg
                    v-if="row.icon === 'card'"
                    width="18"
                    height="18"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.75"
                    viewBox="0 0 24 24"
                  >
                    <rect x="2" y="5" width="20" height="14" rx="2" />
                    <path d="M2 10h20" />
                  </svg>
                  <svg
                    v-else-if="row.icon === 'calendar'"
                    width="18"
                    height="18"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.75"
                    viewBox="0 0 24 24"
                  >
                    <rect x="3" y="4" width="18" height="18" rx="2" />
                    <path d="M16 2v4M8 2v4M3 10h18" />
                  </svg>
                  <svg
                    v-else-if="row.icon === 'percent'"
                    width="18"
                    height="18"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.75"
                    viewBox="0 0 24 24"
                  >
                    <circle cx="7" cy="7" r="2.5" />
                    <circle cx="17" cy="17" r="2.5" />
                    <path d="M19 5L5 19" />
                  </svg>
                  <svg
                    v-else-if="row.icon === 'truck'"
                    width="18"
                    height="18"
                    fill="currentColor"
                    viewBox="0 0 24 24"
                  >
                    <path
                      d="M3.875 19.125Q3 18.25 3 17H1V6q0-.825.588-1.412T3 4h14v4h3l3 4v5h-2q0 1.25-.875 2.125T18 20t-2.125-.875T15 17H9q0 1.25-.875 2.125T6 20t-2.125-.875m2.838-1.412Q7 17.425 7 17t-.288-.712T6 16t-.712.288T5 17t.288.713T6 18t.713-.288m12 0Q19 17.426 19 17t-.288-.712T18 16t-.712.288T17 17t.288.713T18 18t.713-.288M17 13h4.25L19 10h-2z"
                    />
                  </svg>
                  <svg
                    v-else-if="row.icon === 'box'"
                    width="18"
                    height="18"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.75"
                    viewBox="0 0 24 24"
                  >
                    <path d="M12 22V12M12 12L3 7l9-5 9 5-9 5z" />
                    <path d="M3 7v10l9 5 9-5V7" />
                  </svg>
                  <svg
                    v-else
                    width="18"
                    height="18"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.75"
                    viewBox="0 0 24 24"
                  >
                    <rect x="3" y="5" width="18" height="14" rx="2" />
                    <path d="M7 9h4M7 13h6" />
                  </svg>
                </div>
                <div class="client-account-billing-pref-row__body min-w-0">
                  <div class="client-account-billing-pref-row__label">{{ row.label }}</div>
                  <div class="client-account-billing-pref-row__value text-break">{{ row.value }}</div>
                </div>
              </div>
            </div>

            <div class="client-account-billing-card-on-file mt-4 pt-3 border-top">
              <div class="d-flex align-items-center justify-content-between gap-2 mb-3">
                <h4 class="h6 fw-semibold mb-0">Payment Method</h4>
                <button
                  v-if="canEdit"
                  type="button"
                  class="btn btn-sm btn-outline-primary"
                  @click="openAddPaymentModal()"
                >
                  Add Payment Method
                </button>
              </div>

              <div
                v-if="paymentMethods.length"
                class="table-responsive client-account-billing-pm-table"
              >
                <table class="table table-sm align-middle mb-0">
                  <thead>
                    <tr>
                      <th scope="col">Last #</th>
                      <th scope="col">Type</th>
                      <th scope="col" class="text-end">Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="row in paymentMethods" :key="row.id">
                      <td>
                        <button
                          type="button"
                          class="btn btn-link btn-sm p-0 text-decoration-none fw-semibold"
                          @click="openPinForRow(row)"
                        >
                          {{ lastFourDisplay(row) }}
                        </button>
                        <span
                          v-if="row.is_default"
                          class="badge rounded-pill bg-primary-subtle text-primary-emphasis ms-1"
                        >
                          Default
                        </span>
                      </td>
                      <td>{{ typeLabel(row) }}</td>
                      <td class="text-end text-nowrap">
                        <button
                          v-if="canEdit"
                          type="button"
                          class="btn btn-sm btn-outline-primary me-1"
                          @click="openEditPaymentMethod(row)"
                        >
                          Edit
                        </button>
                        <button
                          v-if="canEdit"
                          type="button"
                          class="btn btn-sm btn-outline-danger"
                          :disabled="deleteBusyId === row.id"
                          @click="deletePaymentMethod(row)"
                        >
                          {{ deleteBusyId === row.id ? "…" : "Delete" }}
                        </button>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>

              <div
                v-else
                class="client-account-billing-card-empty text-center"
              >
                <div class="client-account-billing-card-empty__icon mx-auto mb-2" aria-hidden="true">
                  <svg
                    width="28"
                    height="28"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.5"
                    viewBox="0 0 24 24"
                  >
                    <rect x="2" y="5" width="20" height="14" rx="2" />
                    <path d="M2 10h20" />
                  </svg>
                </div>
                <p v-if="paymentMethodsError" class="small text-secondary mb-2">
                  {{ paymentMethodsError }}
                </p>
                <p v-else class="small text-secondary mb-3">No payment method on file.</p>
                <button
                  v-if="canEdit"
                  type="button"
                  class="btn btn-sm btn-outline-primary"
                  @click="openAddPaymentModal()"
                >
                  Add Payment Method
                </button>
              </div>
            </div>
          </div>
        </div>

        <div class="col-12 col-lg-7">
          <AccountDetailSectionHead title="Recent invoices" icon="invoices" head-class="mb-3">
            <template #actions>
              <RouterLink
                :to="invoicesUrl('all')"
                class="btn btn-sm btn-outline-primary"
                target="_blank"
                rel="noopener noreferrer"
              >
                View All
              </RouterLink>
            </template>
          </AccountDetailSectionHead>
          <div class="staff-table-card staff-datatable-card staff-datatable-card--white p-0 h-100 d-flex flex-column">
            <div class="table-responsive staff-table-wrap flex-grow-1">
              <table class="table table-hover align-middle mb-0 staff-data-table">
                <thead class="table-light staff-table-head">
                  <tr>
                    <th class="staff-table-head__th" scope="col">Invoice</th>
                    <th class="staff-table-head__th" scope="col">Status</th>
                    <th class="staff-table-head__th" scope="col">Date</th>
                    <th class="staff-table-head__th text-end" scope="col">Amount</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="inv in invoices" :key="inv.id" class="align-middle">
                    <td class="fw-medium text-body">
                      <RouterLink
                        :to="`/admin/billing/invoices/${inv.id}`"
                        class="text-decoration-none text-body billing-inv-row-link"
                        target="_blank"
                        rel="noopener noreferrer"
                      >
                        {{ inv.invoice_number || `#${inv.id}` }}
                      </RouterLink>
                    </td>
                    <td>
                      <span
                        class="badge rounded-pill fw-medium"
                        :class="statusBadgeClass(legacyStatusKey(inv))"
                      >
                        {{ displayStatusText(inv) }}
                      </span>
                    </td>
                    <td class="staff-table-cell__meta text-nowrap">
                      {{ formatIsoDate(inv.invoice_date) }}
                    </td>
                    <td class="text-end fw-medium">{{ formatCents(inv.total_cents) }}</td>
                  </tr>
                  <tr v-if="!invoices.length">
                    <td colspan="4" class="text-center text-secondary py-5">
                      No invoices yet.
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
            <div class="text-center py-3 border-top">
              <RouterLink
                :to="invoicesUrl('all')"
                class="client-account-billing-history-link small text-decoration-none"
                target="_blank"
                rel="noopener noreferrer"
              >
                Go to billing history &gt;
              </RouterLink>
            </div>
          </div>
        </div>
      </div>
    </template>

    <Teleport to="body">
      <div
        v-if="addPaymentOpen"
        class="modal fade show d-block"
        tabindex="-1"
        role="dialog"
        aria-modal="true"
        aria-labelledby="add-payment-method-title"
      >
        <div class="modal-dialog modal-dialog-centered" role="document">
          <div class="modal-content">
            <div class="modal-header">
              <h2 id="add-payment-method-title" class="modal-title h5 mb-0">Add Payment Method</h2>
              <button
                type="button"
                class="btn-close"
                aria-label="Close"
                :disabled="addPaymentBusy"
                @click="closeAddPaymentModal"
              />
            </div>
            <div class="modal-body">
              <p class="small text-secondary mb-3">
                {{
                  replacePaymentMethodId
                    ? "Choose a payment type, then continue to open the secure form to replace this method."
                    : "Choose a payment type, then continue to the secure form to save it on file."
                }}
              </p>
              <div class="d-flex flex-column gap-2">
                <label class="border rounded p-3 mb-0 d-flex gap-2 align-items-start">
                  <input
                    v-model="addPaymentMethod"
                    type="radio"
                    name="add-payment-method"
                    class="form-check-input mt-1"
                    value="credit_card"
                    :disabled="addPaymentBusy"
                  />
                  <span>
                    <span class="fw-semibold d-block">Credit Card</span>
                    <span class="small text-secondary">Save a card on file for future invoices.</span>
                  </span>
                </label>
                <label class="border rounded p-3 mb-0 d-flex gap-2 align-items-start">
                  <input
                    v-model="addPaymentMethod"
                    type="radio"
                    name="add-payment-method"
                    class="form-check-input mt-1"
                    value="ach"
                    :disabled="addPaymentBusy"
                  />
                  <span>
                    <span class="fw-semibold d-block">ACH / Bank Transfer</span>
                    <span class="small text-secondary">Save a bank account on file for future invoices.</span>
                  </span>
                </label>
              </div>
            </div>
            <div class="modal-footer">
              <button
                type="button"
                class="btn btn-outline-secondary"
                :disabled="addPaymentBusy"
                @click="closeAddPaymentModal"
              >
                Cancel
              </button>
              <button
                type="button"
                class="btn btn-primary staff-page-primary"
                :disabled="addPaymentBusy"
                @click="confirmAddPaymentMethod"
              >
                {{ addPaymentBusy ? "Starting…" : "Continue" }}
              </button>
            </div>
          </div>
        </div>
      </div>
      <div v-if="addPaymentOpen" class="modal-backdrop fade show" @click="closeAddPaymentModal" />
    </Teleport>

    <Teleport to="body">
      <div
        v-if="pinModalOpen"
        class="modal fade show d-block"
        tabindex="-1"
        role="dialog"
        aria-modal="true"
        aria-labelledby="pm-pin-title"
      >
        <div class="modal-dialog modal-dialog-centered modal-sm" role="document">
          <div class="modal-content">
            <div class="modal-header">
              <h2 id="pm-pin-title" class="modal-title h5 mb-0">Enter PIN</h2>
              <button
                type="button"
                class="btn-close"
                aria-label="Close"
                :disabled="pinBusy"
                @click="closePinModal"
              />
            </div>
            <div class="modal-body">
              <p class="small text-secondary mb-3">
                Enter the PIN to view payment method details.
              </p>
              <p v-if="pinError" class="small text-danger mb-2">{{ pinError }}</p>
              <input
                v-model="pinValue"
                type="password"
                inputmode="numeric"
                class="form-control"
                autocomplete="one-time-code"
                placeholder="PIN"
                @keyup.enter="submitPin"
              />
            </div>
            <div class="modal-footer">
              <button
                type="button"
                class="btn btn-outline-primary"
                :disabled="pinBusy"
                @click="closePinModal"
              >
                Cancel
              </button>
              <button
                type="button"
                class="btn btn-primary staff-page-primary"
                :disabled="pinBusy || !pinValue"
                @click="submitPin"
              >
                {{ pinBusy ? "Checking…" : "Unlock" }}
              </button>
            </div>
          </div>
        </div>
      </div>
      <div v-if="pinModalOpen" class="modal-backdrop fade show" @click="closePinModal" />
    </Teleport>

    <Teleport to="body">
      <div
        v-if="detailModalOpen && detailPayload"
        class="modal fade show d-block"
        tabindex="-1"
        role="dialog"
        aria-modal="true"
        aria-labelledby="pm-detail-title"
      >
        <div class="modal-dialog modal-dialog-centered" role="document">
          <div class="modal-content">
            <div class="modal-header">
              <h2 id="pm-detail-title" class="modal-title h5 mb-0">Payment Method</h2>
              <button
                type="button"
                class="btn-close"
                aria-label="Close"
                @click="closeDetailModal"
              />
            </div>
            <div class="modal-body">
              <dl class="row mb-0 small">
                <dt class="col-5 text-secondary">Type</dt>
                <dd class="col-7">{{ detailPayload.type_label || "—" }}</dd>
                <dt class="col-5 text-secondary">Last #</dt>
                <dd class="col-7">**** {{ detailPayload.last4 || "—" }}</dd>
                <dt class="col-5 text-secondary">Name</dt>
                <dd class="col-7">{{ detailPayload.name || "—" }}</dd>
                <template v-if="detailPayload.type === 'card'">
                  <dt class="col-5 text-secondary">Brand</dt>
                  <dd class="col-7 text-uppercase">{{ detailPayload.brand || "—" }}</dd>
                  <dt class="col-5 text-secondary">Expiration</dt>
                  <dd class="col-7">
                    <template v-if="detailPayload.exp_month && detailPayload.exp_year">
                      {{ String(detailPayload.exp_month).padStart(2, "0") }}/{{
                        String(detailPayload.exp_year).slice(-2)
                      }}
                    </template>
                    <template v-else>—</template>
                  </dd>
                </template>
                <template v-else>
                  <dt class="col-5 text-secondary">Bank</dt>
                  <dd class="col-7">{{ detailPayload.bank_name || detailPayload.brand || "—" }}</dd>
                  <dt class="col-5 text-secondary">Account Type</dt>
                  <dd class="col-7 text-capitalize">
                    {{ detailPayload.account_type || "—" }}
                  </dd>
                </template>
                <dt class="col-5 text-secondary">Billing Address</dt>
                <dd class="col-7">
                  <template v-if="detailPayload.address?.line1">
                    {{ detailPayload.address.line1 }}<br />
                    {{ detailPayload.address.city }}
                    {{ detailPayload.address.state }}
                    {{ detailPayload.address.postal_code }}
                  </template>
                  <template v-else>—</template>
                </dd>
              </dl>
            </div>
            <div class="modal-footer">
              <button
                type="button"
                class="btn btn-primary staff-page-primary"
                @click="closeDetailModal"
              >
                Close
              </button>
            </div>
          </div>
        </div>
      </div>
      <div v-if="detailModalOpen" class="modal-backdrop fade show" @click="closeDetailModal" />
    </Teleport>
  </div>
</template>

<style scoped>
.client-account-billing-summary :deep(.billing-inv-summary-card .staff-stat-card__icon) {
  top: 50%;
  right: 1.125rem;
  transform: translateY(-50%);
  width: 3rem;
  height: 3rem;
}

.client-account-billing-summary :deep(.billing-inv-summary-card .staff-stat-card__icon svg) {
  width: 1.5rem;
  height: 1.5rem;
}

.client-account-billing-summary :deep(.billing-inv-summary-card .staff-stat-card__icon--money) {
  width: 3rem;
  height: 3rem;
}

.client-account-billing-summary :deep(.billing-inv-summary-card .staff-stat-card__icon--money svg) {
  width: 1.35rem;
  height: 1.35rem;
}

.client-account-billing-summary-card {
  display: flex;
  flex-direction: column;
  padding-bottom: 0.75rem;
}

.client-account-billing-summary-card__footer {
  margin-top: auto;
  padding-top: 0.75rem;
}

.client-account-billing-summary-card__link {
  color: var(--bs-primary);
  text-decoration: none;
  font-weight: 500;
}

.client-account-billing-summary-card__link:hover {
  text-decoration: underline;
}

.client-account-billing-prefs {
  display: flex;
  flex-direction: column;
  gap: 0.875rem;
}

.client-account-billing-pref-row {
  display: flex;
  align-items: flex-start;
  gap: 0.75rem;
}

.client-account-billing-pref-row__icon {
  flex-shrink: 0;
  width: 2.25rem;
  height: 2.25rem;
  border-radius: 0.5rem;
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--bs-secondary-bg);
  color: var(--bs-secondary-color);
}

.client-account-billing-pref-row__label {
  font-size: 0.8125rem;
  color: var(--bs-secondary-color);
  margin-bottom: 0.125rem;
}

.client-account-billing-pref-row__value {
  font-size: 0.9375rem;
  font-weight: 600;
  color: var(--bs-body-color);
}

.client-account-billing-card-empty {
  border: 1px dashed var(--bs-border-color);
  border-radius: 0.5rem;
  padding: 1.5rem 1rem;
  background: var(--bs-body-bg);
}

.client-account-billing-card-empty__icon {
  width: 3rem;
  height: 3rem;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--bs-secondary-bg);
  color: var(--bs-secondary-color);
}

.client-account-billing-pm-table {
  border: 1px solid var(--bs-border-color);
  border-radius: 0.5rem;
  overflow: hidden;
}

.client-account-billing-pm-table thead th {
  font-size: 0.7rem;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--bs-secondary-color);
  background: var(--bs-secondary-bg);
}

.client-account-billing-card-filled {
  padding: 0.75rem 1rem;
  border: 1px solid var(--bs-border-color);
  border-radius: 0.5rem;
  background: var(--bs-body-bg);
}

.client-account-billing-history-link {
  color: var(--bs-primary);
  font-weight: 500;
}

.client-account-billing-history-link:hover {
  text-decoration: underline !important;
}

.billing-inv-row-link:hover {
  text-decoration: underline !important;
}
</style>
