<script setup>
import { computed, onMounted, ref, watch } from "vue";
import { RouterLink } from "vue-router";
import api from "../../services/api";
import BillingBillAddToInvoiceDrawer from "../../components/billing/BillingBillAddToInvoiceDrawer.vue";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import { useToast } from "../../composables/useToast.js";
import { formatCents } from "../../utils/formatMoney.js";
import { formatDateUs, formatDateTimeUs } from "../../utils/formatUserDates.js";

const props = defineProps({ id: { type: String, required: true } });
const toast = useToast();
const loading = ref(true);
const bill = ref(null);
const drawerOpen = ref(false);
const busy = ref(false);
const draftInvoices = ref([]);
const selectedInvoiceId = ref("");
const isOpen = computed(() => bill.value?.status === "open");

async function load() {
  loading.value = true;
  try {
    const { data } = await api.get(`/billing/wholesale-bills/${props.id}`);
    bill.value = data;
  } catch (e) {
    toast.errorFrom(e, "Could not load wholesale bill.");
  } finally {
    loading.value = false;
  }
}

async function openAddToInvoice() {
  drawerOpen.value = true;
  try {
    const { data } = await api.get(`/billing/wholesale-bills/${props.id}/draft-invoices`, { params: { ensure: 1 } });
    draftInvoices.value = data?.invoices || [];
    selectedInvoiceId.value = draftInvoices.value.length ? String(draftInvoices.value[0].id) : "";
  } catch (e) {
    drawerOpen.value = false;
    toast.errorFrom(e, "Could not load draft invoices.");
  }
}

async function addToInvoice() {
  if (!selectedInvoiceId.value) return;
  busy.value = true;
  try {
    const { data } = await api.post(`/billing/wholesale-bills/${props.id}/add-to-invoice`, {
      invoice_id: Number(selectedInvoiceId.value),
    });
    bill.value = data;
    drawerOpen.value = false;
    toast.success("Wholesale bill added to invoice.");
  } catch (e) {
    toast.errorFrom(e, "Could not add wholesale bill to invoice.");
  } finally {
    busy.value = false;
  }
}

watch(() => props.id, load);
onMounted(load);
</script>

<template>
  <div class="staff-page staff-page--wide">
    <nav class="staff-user-view__breadcrumb mb-3">
      <RouterLink to="/admin/billing/bills">Bills</RouterLink>
      <span class="text-secondary mx-1">/</span>
      <span>Wholesale Bill</span>
    </nav>
    <div v-if="loading" class="py-5"><CrmLoadingSpinner message="Loading Wholesale Bill…" /></div>
    <template v-else-if="bill">
      <div class="d-flex align-items-start justify-content-between gap-3 mb-4">
        <div>
          <h1 class="h4 mb-1">Wholesale Bill #{{ bill.bill_number }}</h1>
          <p class="text-secondary mb-0">{{ bill.client_account_name }} · Order #{{ bill.order_number }}</p>
        </div>
        <button v-if="isOpen" type="button" class="btn btn-primary staff-page-primary" @click="openAddToInvoice">
          Add To Invoice
        </button>
      </div>
      <div class="row g-4">
        <div class="col-lg-8">
          <div class="staff-table-card p-4">
            <h2 class="h6 mb-3">Bill Items</h2>
            <div class="table-responsive">
              <table class="table align-middle mb-0">
                <thead><tr><th>Service</th><th class="text-end">Qty</th><th class="text-end">Price</th><th class="text-end">Total</th></tr></thead>
                <tbody>
                  <tr v-for="item in bill.items" :key="item.id">
                    <td>{{ item.name }}</td>
                    <td class="text-end">{{ item.quantity }}</td>
                    <td class="text-end">{{ formatCents(item.unit_price_cents) }}</td>
                    <td class="text-end fw-semibold">{{ formatCents(item.line_total_cents) }}</td>
                  </tr>
                </tbody>
                <tfoot><tr><th colspan="3" class="text-end">Total</th><th class="text-end">{{ formatCents(bill.total_cents) }}</th></tr></tfoot>
              </table>
            </div>
          </div>
        </div>
        <div class="col-lg-4">
          <div class="staff-table-card p-4 mb-4">
            <h2 class="h6 mb-3">Details</h2>
            <p><span class="text-secondary">Status:</span> {{ bill.status_label }}</p>
            <p><span class="text-secondary">Bill Date:</span> {{ formatDateUs(bill.bill_date) }}</p>
            <p class="mb-0">
              <RouterLink :to="`/admin/wholesale-orders/${bill.wholesale_order_id}`">View Wholesale Order</RouterLink>
            </p>
          </div>
          <div class="staff-table-card p-4">
            <h2 class="h6 mb-3">Activity</h2>
            <div v-for="history in bill.histories" :key="history.id" class="border-bottom py-2 small">
              <div>{{ history.message }}</div>
              <div class="text-secondary">{{ history.actor_name }} · {{ formatDateTimeUs(history.created_at) }}</div>
            </div>
          </div>
        </div>
      </div>
    </template>
    <BillingBillAddToInvoiceDrawer
      v-model:open="drawerOpen"
      v-model:selected-invoice-id="selectedInvoiceId"
      :draft-invoices="draftInvoices"
      :client-account-name="bill?.client_account_name || ''"
      :busy="busy"
      @submit="addToInvoice"
    />
  </div>
</template>
