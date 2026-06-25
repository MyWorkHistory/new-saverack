<script setup>
import { computed, inject, onMounted, onUnmounted, ref, watch } from "vue";
import { useRouter } from "vue-router";
import api from "../../services/api";
import BillingDollarStatIcon from "../../components/billing/BillingDollarStatIcon.vue";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import { useToast } from "../../composables/useToast.js";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { crmIsAdmin } from "../../utils/crmUser.js";
import { formatCents } from "../../utils/formatMoney.js";
import { formatIsoDate } from "../../utils/formatUserDates.js";

const props = defineProps({
  id: { type: String, required: true },
});

const crmUser = inject("crmUser", ref(null));
const toast = useToast();
const router = useRouter();

function userHasPerm(key) {
  const u = crmUser.value;
  if (!u) return false;
  if (crmIsAdmin(u) || u.is_crm_owner) return true;
  return Array.isArray(u.permission_keys) && u.permission_keys.includes(key);
}

const canUpdate = computed(() => userHasPerm("billing.update"));

const loading = ref(true);
const bill = ref(null);
const manageMenuOpen = ref(false);
const addToInvoiceModalOpen = ref(false);
const addToInvoiceBusy = ref(false);
const draftInvoices = ref([]);
const selectedInvoiceId = ref("");

const isOpen = computed(() => bill.value?.status === "open");

const billTotalSubtext = computed(() => {
  if (!bill.value) return "";
  if (bill.value.status === "invoiced" && bill.value.invoice_number) {
    return `On invoice #${bill.value.invoice_number}`;
  }
  return "Sum of line items";
});

async function loadBill() {
  loading.value = true;
  try {
    const { data } = await api.get(`/return-bills/${props.id}`);
    bill.value = data;
    setCrmPageMeta({
      title: `Save Rack | Return Bill #${data?.bill_number ?? props.id}`,
      description: "Return bill detail.",
    });
  } catch (e) {
    toast.errorFrom(e, "Could not load return bill.");
    bill.value = null;
  } finally {
    loading.value = false;
  }
}

function statusBadgeClass(status) {
  return status === "invoiced" ? "bg-success-subtle text-success" : "bg-warning-subtle text-warning";
}

function closeAddToInvoiceModal() {
  if (addToInvoiceBusy.value) return;
  addToInvoiceModalOpen.value = false;
}

async function openAddToInvoiceModal() {
  manageMenuOpen.value = false;
  selectedInvoiceId.value = "";
  draftInvoices.value = [];
  addToInvoiceModalOpen.value = true;
  try {
    const { data } = await api.get(`/return-bills/${props.id}/draft-invoices`);
    draftInvoices.value = Array.isArray(data?.invoices) ? data.invoices : [];
  } catch (e) {
    toast.errorFrom(e, "Could not load draft invoices.");
    addToInvoiceModalOpen.value = false;
  }
}

async function submitAddToInvoice() {
  if (!selectedInvoiceId.value) {
    toast.error("Select a draft invoice.");
    return;
  }
  addToInvoiceBusy.value = true;
  try {
    const { data } = await api.post(`/return-bills/${props.id}/add-to-invoice`, {
      invoice_id: Number(selectedInvoiceId.value),
    });
    bill.value = data;
    addToInvoiceModalOpen.value = false;
    selectedInvoiceId.value = "";
    toast.success("Return bill added to invoice.");
  } catch (e) {
    toast.errorFrom(e, "Could not add return bill to invoice.");
  } finally {
    addToInvoiceBusy.value = false;
  }
}

function onDocClick(e) {
  if (!e.target?.closest?.("[data-rb-manage]")) {
    manageMenuOpen.value = false;
  }
}

watch(
  () => props.id,
  () => {
    loadBill();
  },
);

onMounted(() => {
  document.addEventListener("click", onDocClick);
  loadBill();
});

onUnmounted(() => {
  document.removeEventListener("click", onDocClick);
});
</script>

<template>
  <div class="staff-page staff-page--wide billing-return-bill-detail">
    <nav
      class="staff-user-view__breadcrumb d-flex flex-wrap align-items-center gap-1 mb-3"
      aria-label="Breadcrumb"
    >
      <RouterLink to="/admin/billing/summary">Billing</RouterLink>
      <span class="text-secondary" aria-hidden="true">/</span>
      <RouterLink to="/admin/billing/return-bills">Returns Bills</RouterLink>
      <span class="text-secondary" aria-hidden="true">/</span>
      <span class="text-body-secondary">{{ bill?.bill_number ? `#${bill.bill_number}` : "Bill" }}</span>
    </nav>

    <div v-if="loading" class="d-flex justify-content-center py-5">
      <CrmLoadingSpinner message="Loading return bill…" />
    </div>

    <template v-else-if="bill">
      <div class="d-flex flex-column flex-md-row align-items-start align-items-md-center gap-3 mb-4">
        <div class="min-w-0 flex-grow-1">
          <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
            <h1 class="h4 mb-0 fw-semibold text-body">Return Bill #{{ bill.bill_number }}</h1>
            <span class="badge rounded-pill fw-medium" :class="statusBadgeClass(bill.status)">
              {{ bill.status_label }}
            </span>
          </div>
          <p class="text-secondary small mb-0">
            {{ bill.client_account_name || "—" }} · {{ formatIsoDate(bill.bill_date) }}
          </p>
          <p v-if="bill.rma_number" class="small mb-0 mt-1 text-secondary">
            RMA {{ bill.rma_number }}
            <span v-if="bill.order_number"> · Order #{{ bill.order_number }}</span>
            <RouterLink
              v-if="bill.client_account_return_id"
              :to="{ name: 'admin-process-return-detail', params: { id: String(bill.client_account_return_id) } }"
              class="ms-2 text-decoration-none"
            >
              View Return
            </RouterLink>
          </p>
          <p v-if="bill.invoice_id" class="small mb-0 mt-1">
            On invoice
            <RouterLink
              :to="`/admin/billing/invoices/${bill.invoice_id}`"
              class="fw-semibold text-decoration-none"
            >
              #{{ bill.invoice_number }}
            </RouterLink>
          </p>
        </div>
        <div v-if="isOpen && canUpdate" class="ms-md-auto position-relative" data-rb-manage>
          <button
            type="button"
            class="btn btn-outline-secondary btn-sm d-inline-flex align-items-center gap-2"
            @click.stop="manageMenuOpen = !manageMenuOpen"
          >
            Manage
          </button>
          <div
            v-if="manageMenuOpen"
            class="staff-row-menu staff-toolbar-bulk-dropdown dropdown-menu show shadow position-absolute end-0 mt-1 p-0 overflow-hidden"
            style="min-width: 12rem"
          >
            <button type="button" class="dropdown-item" @click="openAddToInvoiceModal">Add To Invoice</button>
          </div>
        </div>
      </div>

      <div class="row g-4">
        <div class="col-lg-8">
          <div class="staff-table-card staff-datatable-card staff-datatable-card--white p-0 mb-4">
            <div class="px-4 py-3 border-bottom">
              <h2 class="h6 mb-0 fw-semibold">Line Items</h2>
            </div>
            <div class="table-responsive staff-table-wrap">
              <table class="table table-hover align-middle mb-0 staff-data-table">
                <thead class="table-light staff-table-head">
                  <tr>
                    <th class="staff-table-head__th">Service / Name</th>
                    <th class="staff-table-head__th text-end">Qty</th>
                    <th class="staff-table-head__th text-end">Price</th>
                    <th class="staff-table-head__th text-end">Total</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-if="!bill.items?.length">
                    <td colspan="4" class="text-center text-secondary py-4">No line items.</td>
                  </tr>
                  <tr v-for="item in bill.items" :key="item.id">
                    <td class="fw-medium">{{ item.name }}</td>
                    <td class="text-end text-nowrap">{{ item.quantity }}</td>
                    <td class="text-end">{{ formatCents(item.unit_price_cents) }}</td>
                    <td class="text-end fw-semibold">{{ formatCents(item.line_total_cents) }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <div class="col-lg-4">
          <div class="staff-stat-card billing-inv-summary-card billing-inv-summary-card--static">
            <p class="staff-stat-card__label">Bill Total</p>
            <p class="staff-stat-card__value">{{ formatCents(bill.total_cents) }}</p>
            <p class="staff-stat-card__sub">{{ billTotalSubtext }}</p>
            <div class="staff-stat-card__icon staff-stat-card__icon--money" aria-hidden="true">
              <BillingDollarStatIcon />
            </div>
          </div>
        </div>
      </div>
    </template>

    <Teleport to="body">
      <Transition name="crm-vx-confirm">
        <div
          v-if="addToInvoiceModalOpen"
          class="crm-vx-modal-overlay"
          role="dialog"
          aria-modal="true"
          @click.self="closeAddToInvoiceModal"
        >
          <div class="crm-vx-modal crm-vx-modal--sm" @click.stop>
            <header class="crm-vx-modal__head">
              <h2 class="crm-vx-modal__title">Add To Invoice</h2>
            </header>
            <div class="crm-vx-modal__body text-start">
              <label class="form-label" for="rb-draft-invoice">Draft invoice</label>
              <select
                id="rb-draft-invoice"
                v-model="selectedInvoiceId"
                class="form-select"
                :disabled="addToInvoiceBusy"
              >
                <option value="">Select invoice…</option>
                <option v-for="inv in draftInvoices" :key="inv.id" :value="String(inv.id)">
                  #{{ inv.invoice_number }} · {{ formatCents(inv.total_cents) }}
                </option>
              </select>
              <p v-if="!draftInvoices.length" class="small text-secondary mt-2 mb-0">
                No draft invoices for this account.
              </p>
            </div>
            <footer class="crm-vx-modal__footer d-flex gap-2 justify-content-end">
              <button
                type="button"
                class="crm-vx-modal-btn crm-vx-modal-btn--secondary"
                :disabled="addToInvoiceBusy"
                @click="closeAddToInvoiceModal"
              >
                Cancel
              </button>
              <button
                type="button"
                class="crm-vx-modal-btn crm-vx-modal-btn--primary"
                :disabled="addToInvoiceBusy || !selectedInvoiceId"
                @click="submitAddToInvoice"
              >
                {{ addToInvoiceBusy ? "Adding…" : "Add To Invoice" }}
              </button>
            </footer>
          </div>
        </div>
      </Transition>
    </Teleport>
  </div>
</template>
