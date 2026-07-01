<script setup>
import { computed, onMounted, ref, watch } from "vue";
import { RouterLink } from "vue-router";
import api from "../../services/api";
import BillingDollarStatIcon from "../../components/billing/BillingDollarStatIcon.vue";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import { formatCents } from "../../utils/formatMoney.js";

const props = defineProps({
  account: { type: Object, required: true },
  accountId: { type: [String, Number], required: true },
  canEdit: { type: Boolean, default: false },
});

const emit = defineEmits(["edit"]);

const loading = ref(true);
const errorMsg = ref("");
const summary = ref({
  open_balance_due_cents: 0,
  draft_invoice_count: 0,
  paid_mtd_cents: 0,
});
const invoices = ref([]);
const paymentMethods = ref([]);
const paymentMethodsError = ref("");

const nf = new Intl.NumberFormat(undefined, { maximumFractionDigits: 0 });

function display(val) {
  if (val == null || val === "") return "—";
  return String(val);
}

const defaultPaymentMethod = computed(() => {
  const methods = paymentMethods.value;
  if (!Array.isArray(methods) || !methods.length) return null;
  return methods.find((m) => m.is_default) || methods[0];
});

function invoicesUrl(status) {
  const q = new URLSearchParams({
    client_account_id: String(props.accountId),
    status,
  });
  return `/admin/billing/invoices?${q.toString()}`;
}

function openInvoicesBucket(bucket) {
  const status = bucket === "open" ? "open" : bucket === "draft" ? "draft" : "paid";
  window.open(invoicesUrl(status), "_blank", "noopener,noreferrer");
}

async function loadSummary() {
  const { data } = await api.get("/billing/summary", {
    params: { client_account_id: Number(props.accountId) },
  });
  summary.value = {
    open_balance_due_cents: data?.open_balance_due_cents ?? 0,
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

function invoiceStatusLabel(status) {
  const s = String(status || "").toLowerCase();
  if (s === "draft") return "Draft";
  if (s === "sent") return "Sent";
  if (s === "partial") return "Partial";
  if (s === "paid") return "Paid";
  if (s === "void") return "Void";
  return status || "—";
}

onMounted(load);
watch(() => props.accountId, load);
</script>

<template>
  <div>
    <div v-if="errorMsg" class="alert alert-danger" role="alert">{{ errorMsg }}</div>

    <div v-if="loading" class="d-flex justify-content-center py-5">
      <CrmLoadingSpinner message="Loading billing…" />
    </div>

    <template v-else>
      <div class="row g-3 mb-4">
        <div class="col-12 col-md-4">
          <button
            type="button"
            class="staff-stat-card h-100 text-start w-100 border-0 billing-summary-stat-btn"
            @click="openInvoicesBucket('open')"
          >
            <p class="staff-stat-card__label">Open Balance Due</p>
            <p class="staff-stat-card__value">
              {{ formatCents(summary.open_balance_due_cents) }}
            </p>
            <p class="staff-stat-card__sub">Sent and partial — unpaid total</p>
            <div class="staff-stat-card__icon staff-stat-card__icon--money" aria-hidden="true">
              <BillingDollarStatIcon />
            </div>
          </button>
        </div>
        <div class="col-12 col-md-4">
          <button
            type="button"
            class="staff-stat-card h-100 text-start w-100 border-0 billing-summary-stat-btn"
            @click="openInvoicesBucket('draft')"
          >
            <p class="staff-stat-card__label">Draft Invoices</p>
            <p class="staff-stat-card__value">{{ nf.format(summary.draft_invoice_count) }}</p>
            <p class="staff-stat-card__sub">Not yet sent</p>
            <div
              class="staff-stat-card__icon bg-secondary-subtle text-secondary"
              aria-hidden="true"
            >
              <svg width="22" height="22" fill="currentColor" viewBox="0 0 24 24">
                <path
                  d="M14 2H6c-1.1 0-1.99.9-1.99 2L4 20c0 1.1.89 2 1.99 2H18c1.1 0 2-.9 2-2V8l-6-6zm2 16H8v-2h8v2zm0-4H8v-2h8v2zm-3-5V3.5L18.5 9H13z"
                />
              </svg>
            </div>
          </button>
        </div>
        <div class="col-12 col-md-4">
          <button
            type="button"
            class="staff-stat-card h-100 text-start w-100 border-0 billing-summary-stat-btn"
            @click="openInvoicesBucket('paid')"
          >
            <p class="staff-stat-card__label">Paid (Month to Date)</p>
            <p class="staff-stat-card__value">{{ formatCents(summary.paid_mtd_cents) }}</p>
            <p class="staff-stat-card__sub">Recorded payments this month</p>
            <div
              class="staff-stat-card__icon bg-success-subtle text-success"
              aria-hidden="true"
            >
              <svg width="22" height="22" fill="currentColor" viewBox="0 0 24 24">
                <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z" />
              </svg>
            </div>
          </button>
        </div>
      </div>

      <div class="row g-4">
        <div class="col-12 col-lg-5">
          <div class="d-flex flex-wrap align-items-start justify-content-between gap-2 mb-3">
            <h3 class="staff-user-section-title mb-0">Billing preferences</h3>
            <button
              v-if="canEdit"
              type="button"
              class="btn btn-sm btn-primary staff-page-primary"
              @click="emit('edit')"
            >
              Edit
            </button>
          </div>
          <div class="row g-3 mb-4">
            <div class="col-12">
              <dl class="mb-0 small">
                <dt class="text-secondary text-uppercase fw-semibold mb-1" style="font-size: 0.65rem">
                  Default payment type
                </dt>
                <dd class="mb-0 fw-semibold text-body">
                  {{ display(account.default_payment_type) }}
                </dd>
              </dl>
            </div>
            <div class="col-12">
              <dl class="mb-0 small">
                <dt class="text-secondary text-uppercase fw-semibold mb-1" style="font-size: 0.65rem">
                  Payment Terms
                </dt>
                <dd class="mb-0 fw-semibold text-body">
                  {{
                    account.payment_terms_days != null
                      ? `${Number(account.payment_terms_days)} day${Number(account.payment_terms_days) === 1 ? "" : "s"}`
                      : "1 day"
                  }}
                </dd>
              </dl>
            </div>
            <div class="col-12">
              <dl class="mb-0 small">
                <dt class="text-secondary text-uppercase fw-semibold mb-1" style="font-size: 0.65rem">
                  Credit card fee
                </dt>
                <dd class="mb-0 fw-semibold text-body">
                  {{
                    account.cc_fee_percent != null
                      ? `${Number(account.cc_fee_percent).toFixed(2)}%`
                      : "—"
                  }}
                </dd>
              </dl>
            </div>
            <div class="col-12">
              <dl class="mb-0 small">
                <dt class="text-secondary text-uppercase fw-semibold mb-1" style="font-size: 0.65rem">
                  Postage
                </dt>
                <dd class="mb-0 fw-semibold text-body">
                  {{ display(account.postage_option_label) }}
                </dd>
              </dl>
            </div>
            <div class="col-12">
              <dl class="mb-0 small">
                <dt class="text-secondary text-uppercase fw-semibold mb-1" style="font-size: 0.65rem">
                  Packaging
                </dt>
                <dd class="mb-0 fw-semibold text-body">
                  {{ display(account.packaging_option_label) }}
                </dd>
              </dl>
            </div>
            <div class="col-12">
              <dl class="mb-0 small">
                <dt class="text-secondary text-uppercase fw-semibold mb-1" style="font-size: 0.65rem">
                  Stripe customer ID
                </dt>
                <dd class="mb-0 fw-semibold text-body text-break">
                  {{ display(account.stripe_customer_id) }}
                </dd>
              </dl>
            </div>
          </div>

          <h4 class="h6 fw-semibold mb-2">Card on file</h4>
          <p v-if="defaultPaymentMethod" class="small mb-0">
            <span class="fw-semibold text-body">{{ defaultPaymentMethod.label }}</span>
            <span v-if="defaultPaymentMethod.is_default" class="text-secondary ms-1">(default)</span>
          </p>
          <p v-else-if="paymentMethodsError" class="small text-secondary mb-0">
            {{ paymentMethodsError }}
          </p>
          <p v-else class="small text-secondary mb-0">No payment method on file.</p>
        </div>

        <div class="col-12 col-lg-7">
          <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
            <h3 class="staff-user-section-title mb-0">Last 5 invoices</h3>
            <RouterLink
              :to="invoicesUrl('all')"
              class="btn btn-sm btn-outline-primary"
              target="_blank"
              rel="noopener noreferrer"
            >
              View All
            </RouterLink>
          </div>
          <div class="table-responsive">
            <table class="table table-sm staff-data-table mb-0">
              <thead>
                <tr>
                  <th scope="col">Invoice</th>
                  <th scope="col">Status</th>
                  <th scope="col" class="text-end">Amount</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="inv in invoices" :key="inv.id">
                  <td>
                    <RouterLink
                      :to="`/admin/billing/invoices/${inv.id}`"
                      class="link-primary text-decoration-none"
                      target="_blank"
                      rel="noopener noreferrer"
                    >
                      {{ inv.invoice_number || `#${inv.id}` }}
                    </RouterLink>
                  </td>
                  <td class="text-capitalize">{{ invoiceStatusLabel(inv.status) }}</td>
                  <td class="text-end">{{ formatCents(inv.total_cents) }}</td>
                </tr>
                <tr v-if="!invoices.length">
                  <td colspan="3" class="text-secondary small">No invoices yet.</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped>
.billing-summary-stat-btn {
  cursor: pointer;
  font: inherit;
  color: inherit;
  transition:
    box-shadow 0.15s ease,
    transform 0.15s ease;
}
.billing-summary-stat-btn:hover {
  box-shadow: 0 0.45rem 1rem rgba(47, 43, 61, 0.1);
  transform: translateY(-1px);
}
</style>
