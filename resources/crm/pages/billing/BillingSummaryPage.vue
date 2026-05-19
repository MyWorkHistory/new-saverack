<script setup>
import { computed, onMounted, ref } from "vue";
import { RouterLink, useRouter } from "vue-router";
import api from "../../services/api";
import BillingDollarStatIcon from "../../components/billing/BillingDollarStatIcon.vue";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { formatCents } from "../../utils/formatMoney.js";

const loading = ref(true);
const errorMsg = ref("");
const summary = ref({
  open_balance_due_cents: 0,
  draft_invoice_count: 0,
  paid_mtd_cents: 0,
  counts_by_status: {},
});

const nf = new Intl.NumberFormat(undefined, { maximumFractionDigits: 0 });
const router = useRouter();

function goToInvoicesBucket(bucket) {
  let status = "all";
  if (bucket === "open") status = "open";
  else if (bucket === "draft") status = "draft";
  else if (bucket === "paid") status = "paid";
  router.push({ path: "/admin/billing/invoices", query: { status } });
}

async function load() {
  loading.value = true;
  errorMsg.value = "";
  try {
    const { data } = await api.get("/billing/summary");
    summary.value = {
      open_balance_due_cents: data?.open_balance_due_cents ?? 0,
      draft_invoice_count: data?.draft_invoice_count ?? 0,
      paid_mtd_cents: data?.paid_mtd_cents ?? 0,
      counts_by_status: data?.counts_by_status ?? {},
    };
  } catch (e) {
    errorMsg.value =
      e.response?.data?.message || "Could not load billing summary.";
  } finally {
    loading.value = false;
  }
}

const totalInvoices = computed(() => {
  const c = summary.value.counts_by_status || {};
  return Object.values(c).reduce((a, b) => a + Number(b || 0), 0);
});

onMounted(() => {
  setCrmPageMeta({
    title: "Save Rack | Billing Summary",
    description: "Billing overview and invoice metrics.",
  });
  load();
});
</script>

<template>
  <div class="staff-page staff-page--wide">
    <div
      class="d-flex flex-column flex-md-row align-items-start align-items-md-center gap-3 mb-4"
    >
      <div class="min-w-0 flex-grow-1">
        <h1 class="h4 mb-1 fw-semibold text-body">Billing Summary</h1>
        <p class="text-secondary small mb-0">
          Open balances and payment activity
        </p>
      </div>
      <RouterLink
        to="/admin/billing/invoices"
        class="btn btn-primary staff-page-primary ms-md-auto flex-shrink-0"
      >
        View Invoices
      </RouterLink>
    </div>

    <div v-if="errorMsg" class="alert alert-danger" role="alert">
      {{ errorMsg }}
    </div>

    <div v-if="loading" class="d-flex justify-content-center py-5">
      <CrmLoadingSpinner message="Loading summary…" />
    </div>

    <template v-else>
      <div class="row g-4 mb-4">
        <div class="col-12 col-sm-6 col-xl-3">
          <button
            type="button"
            class="staff-stat-card h-100 text-start w-100 border-0 billing-summary-stat-btn"
            @click="goToInvoicesBucket('open')"
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
        <div class="col-12 col-sm-6 col-xl-3">
          <button
            type="button"
            class="staff-stat-card h-100 text-start w-100 border-0 billing-summary-stat-btn"
            @click="goToInvoicesBucket('draft')"
          >
            <p class="staff-stat-card__label">Draft Invoices</p>
            <p class="staff-stat-card__value">
              {{ nf.format(summary.draft_invoice_count) }}
            </p>
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
        <div class="col-12 col-sm-6 col-xl-3">
          <button
            type="button"
            class="staff-stat-card h-100 text-start w-100 border-0 billing-summary-stat-btn"
            @click="goToInvoicesBucket('paid')"
          >
            <p class="staff-stat-card__label">Paid (Month to Date)</p>
            <p class="staff-stat-card__value">
              {{ formatCents(summary.paid_mtd_cents) }}
            </p>
            <p class="staff-stat-card__sub">Recorded payments this month</p>
            <div
              class="staff-stat-card__icon bg-success-subtle text-success"
              aria-hidden="true"
            >
              <svg width="22" height="22" fill="currentColor" viewBox="0 0 24 24">
                <path
                  d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"
                />
              </svg>
            </div>
          </button>
        </div>
      </div>

      <div class="staff-table-card staff-datatable-card staff-datatable-card--white p-4">
        <h2 class="h6 fw-semibold mb-3">Invoices by status</h2>
        <p class="text-secondary small mb-0">
          Total invoices:
          <span class="text-body fw-medium">{{ nf.format(totalInvoices) }}</span>
        </p>
        <ul class="list-unstyled small mt-3 mb-0">
          <li
            v-for="(count, st) in summary.counts_by_status"
            :key="st"
            class="d-flex justify-content-between py-1 border-bottom border-light"
          >
            <span class="text-capitalize">{{ st }}</span>
            <span class="fw-medium">{{ nf.format(count) }}</span>
          </li>
          <li v-if="!Object.keys(summary.counts_by_status || {}).length" class="text-secondary">
            No invoices yet.
          </li>
        </ul>
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
