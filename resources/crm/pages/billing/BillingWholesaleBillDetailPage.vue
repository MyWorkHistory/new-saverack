<script setup>
import { computed, inject, nextTick, onMounted, onUnmounted, ref, watch } from "vue";
import { useRouter } from "vue-router";
import api from "../../services/api";
import BillingDollarStatIcon from "../../components/billing/BillingDollarStatIcon.vue";
import BillingBillAddToInvoiceDrawer from "../../components/billing/BillingBillAddToInvoiceDrawer.vue";
import BillingBillDetailsCard from "../../components/billing/BillingBillDetailsCard.vue";
import ConfirmModal from "../../components/common/ConfirmModal.vue";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import { useToast } from "../../composables/useToast.js";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { crmIsAdmin } from "../../utils/crmUser.js";
import { formatCents } from "../../utils/formatMoney.js";
import { formatDateUs, formatIsoDate, formatDateTimeUs } from "../../utils/formatUserDates.js";

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

const canUpdate = computed(
  () => userHasPerm("billing_wholesale_bills.update") || userHasPerm("billing.update"),
);
const canDelete = computed(
  () => userHasPerm("billing_wholesale_bills.delete") || userHasPerm("billing.delete"),
);

const loading = ref(true);
const bill = ref(null);
const actionMenuOpen = ref(false);
const actionMenuRect = ref({ top: 0, left: 0 });

const editDateModalOpen = ref(false);
const editDateValue = ref("");
const editDateBusy = ref(false);

const deleteBillModalOpen = ref(false);
const deleteBillBusy = ref(false);

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

const timeFmt = new Intl.DateTimeFormat("en-US", {
  hour: "numeric",
  minute: "2-digit",
});

function timeUs(val) {
  if (!val) return "";
  const d = new Date(val);
  if (Number.isNaN(d.getTime())) return "";
  return timeFmt.format(d);
}

const billDetailFields = computed(() => {
  const b = bill.value;
  if (!b) return [];
  const fields = [
    { icon: "doc", label: "Bill Type", value: "Wholesale Bill" },
    { icon: "calendar", label: "Bill Date", value: formatIsoDate(b.bill_date) },
    {
      icon: "clock",
      label: "Created Date",
      value: formatDateUs(b.created_at) || "—",
      sub: timeUs(b.created_at),
    },
    { icon: "user", label: "Created By", value: b.created_by_name },
    {
      icon: "status",
      label: "Status",
      badge: { label: b.status_label, class: statusBadgeClass(b.status) },
    },
  ];
  if (b.order_number) {
    fields.push({
      icon: "box",
      label: "Order #",
      value: b.order_number,
      link: b.wholesale_order_id
        ? {
            to: {
              name: "wholesale-order-detail",
              params: { id: String(b.wholesale_order_id) },
            },
            label: "View Order",
            targetBlank: true,
          }
        : null,
    });
  }
  return fields;
});

const ACTION_MENU_W = 168;
const ACTION_MENU_H = 120;

function formatHistoryTimestamp(iso) {
  return iso ? formatDateTimeUs(iso) : "—";
}

async function loadBill() {
  loading.value = true;
  try {
    const { data } = await api.get(`/billing/wholesale-bills/${props.id}`);
    bill.value = data;
    setCrmPageMeta({
      title: `Save Rack | Wholesale Bill #${data?.bill_number ?? props.id}`,
      description: "Wholesale bill detail.",
    });
  } catch (e) {
    toast.errorFrom(e, "Could not load wholesale bill.");
    bill.value = null;
  } finally {
    loading.value = false;
  }
}

function statusBadgeClass(status) {
  return status === "invoiced" ? "bg-success-subtle text-success" : "bg-warning-subtle text-warning";
}

function onDocClick(e) {
  if (!e.target?.closest?.("[data-wb-action]")) actionMenuOpen.value = false;
}

function placeActionMenu(anchorEl, rectRef, width, height) {
  if (!(anchorEl instanceof HTMLElement)) return;
  const rect = anchorEl.getBoundingClientRect();
  let top = rect.bottom + 4;
  let left = rect.right - width;
  left = Math.max(8, Math.min(left, window.innerWidth - width - 8));
  if (top + height > window.innerHeight - 8) {
    top = Math.max(8, rect.top - height - 4);
  }
  rectRef.value = { top, left };
}

async function toggleActionMenu(event) {
  event?.stopPropagation?.();
  if (actionMenuOpen.value) {
    actionMenuOpen.value = false;
    return;
  }
  const btn = event?.currentTarget;
  actionMenuOpen.value = true;
  await nextTick();
  requestAnimationFrame(() => {
    placeActionMenu(btn, actionMenuRect, ACTION_MENU_W, ACTION_MENU_H);
  });
}

function openEditDateModal() {
  actionMenuOpen.value = false;
  editDateValue.value = bill.value?.bill_date || "";
  editDateModalOpen.value = true;
}

async function saveBillDate() {
  editDateBusy.value = true;
  try {
    const { data } = await api.patch(`/billing/wholesale-bills/${props.id}`, {
      bill_date: editDateValue.value,
    });
    bill.value = data;
    editDateModalOpen.value = false;
    toast.success("Bill date updated.");
  } catch (e) {
    toast.errorFrom(e, "Could not update bill date.");
  } finally {
    editDateBusy.value = false;
  }
}

async function confirmDeleteBill() {
  deleteBillBusy.value = true;
  try {
    await api.delete(`/billing/wholesale-bills/${props.id}`);
    toast.success("Wholesale bill deleted.");
    router.push("/admin/billing/bills");
  } catch (e) {
    toast.errorFrom(e, "Could not delete wholesale bill.");
  } finally {
    deleteBillBusy.value = false;
  }
}

async function openAddToInvoiceModal() {
  selectedInvoiceId.value = "";
  draftInvoices.value = [];
  addToInvoiceModalOpen.value = true;
  try {
    const { data } = await api.get(`/billing/wholesale-bills/${props.id}/draft-invoices`, {
      params: { ensure: 1 },
    });
    draftInvoices.value = Array.isArray(data?.invoices) ? data.invoices : [];
    if (draftInvoices.value.length && !selectedInvoiceId.value) {
      selectedInvoiceId.value = String(draftInvoices.value[0].id);
    }
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
    const { data } = await api.post(`/billing/wholesale-bills/${props.id}/add-to-invoice`, {
      invoice_id: Number(selectedInvoiceId.value),
    });
    bill.value = data;
    addToInvoiceModalOpen.value = false;
    selectedInvoiceId.value = "";
    toast.success("Wholesale bill added to invoice.");
  } catch (e) {
    toast.errorFrom(e, "Could not add wholesale bill to invoice.");
  } finally {
    addToInvoiceBusy.value = false;
  }
}

watch(() => props.id, loadBill);

onMounted(() => {
  document.addEventListener("click", onDocClick);
  loadBill();
});

onUnmounted(() => {
  document.removeEventListener("click", onDocClick);
});
</script>

<template>
  <div class="staff-page staff-page--wide billing-wholesale-bill-detail">
    <nav
      class="staff-user-view__breadcrumb d-flex flex-wrap align-items-center gap-1 mb-3"
      aria-label="Breadcrumb"
    >
      <RouterLink to="/admin/billing/revenue">Billing</RouterLink>
      <span class="text-secondary" aria-hidden="true">/</span>
      <RouterLink to="/admin/billing/bills">Bills</RouterLink>
      <span class="text-secondary" aria-hidden="true">/</span>
      <span class="text-body-secondary">{{ bill?.bill_number ? `#${bill.bill_number}` : "Bill" }}</span>
    </nav>

    <div v-if="loading" class="d-flex justify-content-center py-5">
      <CrmLoadingSpinner message="Loading Wholesale Bill…" />
    </div>

    <template v-else-if="bill">
      <div class="d-flex flex-column flex-md-row align-items-start align-items-md-center gap-3 mb-3">
        <div class="min-w-0 flex-grow-1">
          <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
            <h1 class="h4 mb-0 fw-semibold text-body">Wholesale Bill #{{ bill.bill_number }}</h1>
            <span class="badge rounded-pill fw-medium" :class="statusBadgeClass(bill.status)">
              {{ bill.status_label }}
            </span>
          </div>
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
        <div class="ms-md-auto d-flex flex-wrap align-items-center gap-2">
          <button
            v-if="isOpen && canUpdate"
            type="button"
            class="btn btn-primary btn-sm staff-page-primary fw-semibold"
            @click="openAddToInvoiceModal"
          >
            Add To Invoice
          </button>
          <div
            v-if="canUpdate || canDelete || bill.invoice_id"
            data-wb-action
            class="position-relative"
          >
            <button
              type="button"
              class="btn btn-outline-secondary btn-sm orders-toolbar-outline-btn fw-semibold d-inline-flex align-items-center gap-2"
              :class="{ 'is-open': actionMenuOpen }"
              aria-haspopup="true"
              :aria-expanded="actionMenuOpen ? 'true' : 'false'"
              aria-label="Actions"
              @click.stop="toggleActionMenu"
            >
              <svg
                class="flex-shrink-0"
                width="16"
                height="16"
                fill="none"
                stroke="currentColor"
                stroke-width="1.75"
                viewBox="0 0 24 24"
                aria-hidden="true"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"
                />
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"
                />
              </svg>
              Actions
            </button>
          </div>
        </div>
      </div>

      <div class="row g-4">
        <div class="col-lg-8">
          <div class="staff-table-card staff-datatable-card staff-datatable-card--white p-0 mb-4">
            <div class="billing-bill-preview-head">
              <div class="billing-bill-preview-head__row">
                <div class="min-w-0">
                  <div class="billing-bill-section-label">Bill To</div>
                  <div class="fw-bold text-body fs-6">{{ bill.client_account_name || "—" }}</div>
                </div>
                <div class="text-end">
                  <div class="d-flex align-items-center justify-content-end gap-2">
                    <span class="badge rounded-pill fw-medium" :class="statusBadgeClass(bill.status)">
                      {{ bill.status_label }}
                    </span>
                    <span class="fw-bold text-body">#{{ bill.bill_number }}</span>
                  </div>
                  <div class="small text-secondary mt-1">{{ formatIsoDate(bill.bill_date) }}</div>
                </div>
              </div>
            </div>
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
          <div class="staff-stat-card mb-4 billing-inv-summary-card billing-inv-summary-card--static">
            <p class="staff-stat-card__label">Bill Total</p>
            <p class="staff-stat-card__value">{{ formatCents(bill.total_cents) }}</p>
            <p class="staff-stat-card__sub">{{ billTotalSubtext }}</p>
            <div class="staff-stat-card__icon staff-stat-card__icon--money" aria-hidden="true">
              <BillingDollarStatIcon />
            </div>
          </div>

          <BillingBillDetailsCard class="mb-4" :fields="billDetailFields" />

          <div class="staff-surface p-3 p-md-4">
            <h2 class="h6 fw-semibold mb-3">History</h2>
            <ul v-if="bill.histories?.length" class="list-unstyled mb-0 small billing-wholesale-bill-history">
              <li v-for="h in bill.histories" :key="h.id" class="billing-wholesale-bill-history__item">
                <div class="fw-semibold text-body">
                  {{ h.event_label || h.event_type }}
                  <span v-if="h.message && h.event_type !== 'created'" class="fw-normal text-secondary">
                    — {{ h.message }}
                  </span>
                </div>
                <div v-if="h.event_type === 'created' && h.message" class="text-secondary">{{ h.message }}</div>
                <div v-if="h.event_type === 'invoiced' && bill.invoice_id" class="text-secondary">
                  <RouterLink :to="`/admin/billing/invoices/${bill.invoice_id}`" class="text-decoration-none">
                    View Invoice
                  </RouterLink>
                </div>
                <div class="text-secondary">
                  {{ h.actor_name || "System" }} · {{ formatHistoryTimestamp(h.created_at) }}
                </div>
              </li>
            </ul>
            <p v-else class="text-secondary small mb-0">No history yet.</p>
          </div>
        </div>
      </div>
    </template>

    <Teleport to="body">
      <div
        v-if="actionMenuOpen"
        data-wb-action
        class="staff-row-menu fixed z-[300] overflow-hidden"
        role="menu"
        :style="{ top: `${actionMenuRect.top}px`, left: `${actionMenuRect.left}px` }"
        @click.stop
      >
        <button
          v-if="isOpen && canUpdate"
          type="button"
          class="staff-row-menu__item"
          role="menuitem"
          @click="openEditDateModal"
        >
          Edit
        </button>
        <RouterLink
          v-if="bill?.invoice_id"
          :to="`/admin/billing/invoices/${bill.invoice_id}`"
          class="staff-row-menu__item text-decoration-none text-body"
          role="menuitem"
          @click="actionMenuOpen = false"
        >
          View Invoice
        </RouterLink>
        <button
          v-if="isOpen && canDelete"
          type="button"
          class="staff-row-menu__item staff-row-menu__item--danger"
          role="menuitem"
          @click="
            actionMenuOpen = false;
            deleteBillModalOpen = true;
          "
        >
          Delete
        </button>
      </div>
    </Teleport>

    <ConfirmModal
      v-model:open="deleteBillModalOpen"
      title="Delete Wholesale Bill"
      :message="bill ? `Delete bill #${bill.bill_number}? This cannot be undone.` : ''"
      confirm-label="Delete"
      variant="danger"
      :busy="deleteBillBusy"
      @confirm="confirmDeleteBill"
    />

    <Teleport to="body">
      <Transition name="crm-vx-confirm">
        <div
          v-if="editDateModalOpen"
          class="crm-vx-modal-overlay"
          role="dialog"
          aria-modal="true"
          @click.self="editDateModalOpen = false"
        >
          <div class="crm-vx-modal crm-vx-modal--sm" @click.stop>
            <header class="crm-vx-modal__head">
              <h2 class="crm-vx-modal__title">Edit Bill Date</h2>
            </header>
            <div class="crm-vx-modal__body">
              <label class="form-label" for="wb-edit-date">Bill date</label>
              <input
                id="wb-edit-date"
                v-model="editDateValue"
                type="date"
                class="form-control"
                :disabled="editDateBusy"
              />
            </div>
            <footer class="crm-vx-modal__footer d-flex gap-2 justify-content-end">
              <button
                type="button"
                class="crm-vx-modal-btn crm-vx-modal-btn--secondary"
                :disabled="editDateBusy"
                @click="editDateModalOpen = false"
              >
                Cancel
              </button>
              <button
                type="button"
                class="crm-vx-modal-btn crm-vx-modal-btn--primary"
                :disabled="editDateBusy"
                @click="saveBillDate"
              >
                {{ editDateBusy ? "Saving…" : "Save" }}
              </button>
            </footer>
          </div>
        </div>
      </Transition>
    </Teleport>

    <BillingBillAddToInvoiceDrawer
      v-model:open="addToInvoiceModalOpen"
      v-model:selected-invoice-id="selectedInvoiceId"
      :draft-invoices="draftInvoices"
      :client-account-name="bill?.client_account_name || ''"
      :busy="addToInvoiceBusy"
      @submit="submitAddToInvoice"
    />
  </div>
</template>

<style scoped>
.billing-wholesale-bill-detail :deep(.table-responsive.staff-table-wrap) {
  overflow-x: clip;
  max-width: 100%;
}

.billing-wholesale-bill-detail :deep(.staff-table-wrap .table.staff-data-table) {
  width: 100%;
  min-width: 0;
  table-layout: fixed;
}

.billing-wholesale-bill-history__item + .billing-wholesale-bill-history__item {
  margin-top: 0.75rem;
  padding-top: 0.75rem;
  border-top: 1px solid var(--bs-border-color);
}
</style>
