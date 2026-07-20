<script setup>
import { computed, inject, nextTick, onMounted, onUnmounted, reactive, ref, watch } from "vue";
import { useRouter } from "vue-router";
import api from "../../services/api";
import BillingCustomBillLineModal from "../../components/billing/BillingCustomBillLineModal.vue";
import BillingBillAddToInvoiceDrawer from "../../components/billing/BillingBillAddToInvoiceDrawer.vue";
import BillingBillDetailsCard from "../../components/billing/BillingBillDetailsCard.vue";
import BillingDollarStatIcon from "../../components/billing/BillingDollarStatIcon.vue";
import ConfirmModal from "../../components/common/ConfirmModal.vue";
import CrmIconRowActions from "../../components/common/CrmIconRowActions.vue";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import { useToast } from "../../composables/useToast.js";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { crmIsAdmin } from "../../utils/crmUser.js";
import { formatCents } from "../../utils/formatMoney.js";
import { formatDateUs, formatIsoDate, formatDateTimeUs } from "../../utils/formatUserDates.js";
import {
  DEFAULT_INVOICE_CATEGORY,
  INVOICE_CATEGORY_OPTIONS,
  invoiceCategoryLabel,
} from "../../constants/invoiceCategoryOptions.js";

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
  () => userHasPerm("billing_custom_bills.update") || userHasPerm("billing.update"),
);
const canDelete = computed(
  () => userHasPerm("billing_custom_bills.delete") || userHasPerm("billing.delete"),
);

const loading = ref(true);
const bill = ref(null);

const actionMenuOpen = ref(false);
const actionMenuRect = ref({ top: 0, left: 0 });
const lineMenuOpenId = ref(null);
const lineMenuPos = ref({ top: 0, left: 0 });

const editDateModalOpen = ref(false);
const editDateValue = ref("");
const editDateBusy = ref(false);

const deleteBillModalOpen = ref(false);
const deleteBillBusy = ref(false);

const addLineModalOpen = ref(false);
const addLineBusy = ref(false);
const addLineError = ref("");

const lineEditModalOpen = ref(false);
const lineEditBusy = ref(false);
const lineEditError = ref("");
const lineEditTarget = ref(null);

function emptyLineForm() {
  return {
    line_type: DEFAULT_INVOICE_CATEGORY,
    name: "",
    quantity: "1",
    unit_price: "0.00",
    sku: "",
  };
}

const addLineForm = reactive(emptyLineForm());
const lineEditForm = reactive(emptyLineForm());

const lineDeleteModalOpen = ref(false);
const lineDeleteBusy = ref(false);
const lineDeleteTarget = ref(null);

const addToInvoiceModalOpen = ref(false);
const addToInvoiceBusy = ref(false);
const draftInvoices = ref([]);
const selectedInvoiceId = ref("");

const reopenBusy = ref(false);

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
    { icon: "doc", label: "Bill Type", value: "Custom" },
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
  if (b.project_pid) {
    fields.push({
      icon: "folder",
      label: "Project #",
      value: b.project_pid,
      link: b.project_id
        ? { to: `/admin/clients/projects/${b.project_id}`, label: "View Project" }
        : null,
    });
  }
  return fields;
});

async function loadBill() {
  loading.value = true;
  try {
    const { data } = await api.get(`/custom-bills/${props.id}`);
    bill.value = data;
    setCrmPageMeta({
      title: `Save Rack | ${data?.name || (data?.bill_number ? `Bill #${data.bill_number}` : `Bill`)}`,
      description: "Bill detail.",
    });
  } catch (e) {
    toast.errorFrom(e, "Could not load bill.");
    bill.value = null;
  } finally {
    loading.value = false;
  }
}

function statusBadgeClass(status) {
  return status === "invoiced" ? "bg-success-subtle text-success" : "bg-warning-subtle text-warning";
}

function unitPriceFromCents(cents) {
  return ((Number(cents) || 0) / 100).toFixed(2);
}

function resetAddLineForm() {
  Object.assign(addLineForm, emptyLineForm());
}

function closeAddLineModal() {
  if (addLineBusy.value) return;
  addLineModalOpen.value = false;
  addLineError.value = "";
  resetAddLineForm();
}

function openAddLineModal() {
  resetAddLineForm();
  addLineError.value = "";
  addLineModalOpen.value = true;
}

function openLineEdit(item) {
  lineEditTarget.value = item;
  Object.assign(lineEditForm, {
    line_type: item.line_type,
    name: item.name,
    quantity: String(item.quantity),
    unit_price: unitPriceFromCents(item.unit_price_cents),
    sku: item.sku || "",
  });
  lineEditError.value = "";
  lineEditModalOpen.value = true;
}

function closeLineEditModal() {
  if (lineEditBusy.value) return;
  lineEditModalOpen.value = false;
  lineEditTarget.value = null;
  lineEditError.value = "";
}

function linePayloadFromForm(form) {
  const payload = {
    line_type: form.line_type,
    name: String(form.name || "").trim(),
    quantity: parseFloat(form.quantity),
    unit_price: parseFloat(form.unit_price) || 0,
  };
  const sku = String(form.sku || "").trim();
  if (sku) payload.sku = sku;
  return payload;
}

async function submitAddLine() {
  const payload = linePayloadFromForm(addLineForm);
  if (!payload.line_type) {
    addLineError.value = "Select a category.";
    return;
  }
  if (!payload.name) {
    addLineError.value = "Service / name is required.";
    return;
  }
  if (!Number.isFinite(payload.quantity) || payload.quantity <= 0) {
    addLineError.value = "Quantity must be greater than zero.";
    return;
  }
  addLineError.value = "";
  addLineBusy.value = true;
  try {
    const { data } = await api.post(`/custom-bills/${props.id}/items`, payload);
    bill.value = data;
    addLineModalOpen.value = false;
    addLineError.value = "";
    resetAddLineForm();
    toast.success("Line added.");
  } catch (e) {
    const d = e?.response?.data;
    addLineError.value =
      d?.message || d?.errors?.name?.[0] || d?.errors?.quantity?.[0] || "";
    toast.errorFrom(e, addLineError.value || "Could not add line.");
  } finally {
    addLineBusy.value = false;
  }
}

async function submitEditLine() {
  if (!lineEditTarget.value) return;
  const payload = linePayloadFromForm(lineEditForm);
  if (!payload.line_type) {
    lineEditError.value = "Select a category.";
    return;
  }
  if (!payload.name) {
    lineEditError.value = "Service / name is required.";
    return;
  }
  if (!Number.isFinite(payload.quantity) || payload.quantity <= 0) {
    lineEditError.value = "Quantity must be greater than zero.";
    return;
  }
  lineEditError.value = "";
  lineEditBusy.value = true;
  try {
    const { data } = await api.put(
      `/custom-bills/${props.id}/items/${lineEditTarget.value.id}`,
      payload,
    );
    bill.value = data;
    lineEditModalOpen.value = false;
    lineEditTarget.value = null;
    lineEditError.value = "";
    toast.success("Line updated.");
  } catch (e) {
    const d = e?.response?.data;
    lineEditError.value =
      d?.message || d?.errors?.name?.[0] || d?.errors?.quantity?.[0] || "";
    toast.errorFrom(e, lineEditError.value || "Could not update line.");
  } finally {
    lineEditBusy.value = false;
  }
}

async function confirmDeleteLine() {
  if (!lineDeleteTarget.value) return;
  lineDeleteBusy.value = true;
  try {
    const { data } = await api.delete(
      `/custom-bills/${props.id}/items/${lineDeleteTarget.value.id}`,
    );
    bill.value = data;
    lineDeleteModalOpen.value = false;
    lineDeleteTarget.value = null;
    toast.success("Line removed.");
  } catch (e) {
    toast.errorFrom(e, "Could not remove line.");
  } finally {
    lineDeleteBusy.value = false;
  }
}

function closeEditDateModal() {
  if (editDateBusy.value) return;
  editDateModalOpen.value = false;
}

function openEditDateModal() {
  actionMenuOpen.value = false;
  editDateValue.value = bill.value?.bill_date || "";
  editDateModalOpen.value = true;
}

function closeAddToInvoiceModal() {
  if (addToInvoiceBusy.value) return;
  addToInvoiceModalOpen.value = false;
}

async function saveBillDate() {
  editDateBusy.value = true;
  try {
    const { data } = await api.patch(`/custom-bills/${props.id}`, {
      bill_date: editDateValue.value,
    });
    bill.value = data;
    closeEditDateModal();
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
    await api.delete(`/custom-bills/${props.id}`);
    toast.success("Bill deleted.");
    router.push("/admin/billing/bills");
  } catch (e) {
    toast.errorFrom(e, "Could not delete bill.");
  } finally {
    deleteBillBusy.value = false;
  }
}

async function openAddToInvoiceModal() {
  actionMenuOpen.value = false;
  selectedInvoiceId.value = "";
  draftInvoices.value = [];
  addToInvoiceModalOpen.value = true;
  try {
    const { data } = await api.get(`/custom-bills/${props.id}/draft-invoices`);
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
    const { data } = await api.post(`/custom-bills/${props.id}/add-to-invoice`, {
      invoice_id: Number(selectedInvoiceId.value),
    });
    bill.value = data;
    addToInvoiceModalOpen.value = false;
    selectedInvoiceId.value = "";
    toast.success("Bill lines added to invoice.");
  } catch (e) {
    toast.errorFrom(e, "Could not add bill to invoice.");
  } finally {
    addToInvoiceBusy.value = false;
  }
}

async function markAsOpen() {
  reopenBusy.value = true;
  actionMenuOpen.value = false;
  try {
    const { data } = await api.patch(`/custom-bills/${props.id}/status`, {
      status: "open",
    });
    bill.value = data;
    toast.success("Bill marked as Open.");
  } catch (e) {
    toast.errorFrom(e, "Could not update bill status.");
  } finally {
    reopenBusy.value = false;
  }
}

const MENU_W = 128;
const MENU_H = 96;
const ACTION_MENU_W = 168;
const ACTION_MENU_H = 160;

const lineMenuStyle = computed(() => ({
  top: `${lineMenuPos.value.top}px`,
  left: `${lineMenuPos.value.left}px`,
  zIndex: 2200,
}));

function placeOverlayMenu(anchorEl, setPos) {
  if (!(anchorEl instanceof HTMLElement)) return;
  const rect = anchorEl.getBoundingClientRect();
  let top = rect.bottom + 4;
  let left = rect.right - MENU_W;
  left = Math.max(8, Math.min(left, window.innerWidth - MENU_W - 8));
  if (top + MENU_H > window.innerHeight - 8) {
    top = Math.max(8, rect.top - MENU_H - 4);
  }
  setPos({ top, left });
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
    if (!(btn instanceof HTMLElement)) return;
    const rect = btn.getBoundingClientRect();
    let top = rect.bottom + 4;
    let left = rect.right - ACTION_MENU_W;
    left = Math.max(8, Math.min(left, window.innerWidth - ACTION_MENU_W - 8));
    if (top + ACTION_MENU_H > window.innerHeight - 8) {
      top = Math.max(8, rect.top - ACTION_MENU_H - 4);
    }
    actionMenuRect.value = { top, left };
  });
}

async function toggleLineMenu(lineId, event) {
  event?.stopPropagation?.();
  if (lineMenuOpenId.value === lineId) {
    lineMenuOpenId.value = null;
    return;
  }
  const btn = event?.currentTarget;
  lineMenuOpenId.value = lineId;
  await nextTick();
  requestAnimationFrame(() => {
    placeOverlayMenu(btn, (v) => {
      lineMenuPos.value = v;
    });
  });
}

function openLineEditFromMenu(item) {
  lineMenuOpenId.value = null;
  openLineEdit(item);
}

function openLineDeleteFromMenu(item) {
  lineMenuOpenId.value = null;
  lineDeleteTarget.value = item;
  lineDeleteModalOpen.value = true;
}

function onDocClick(e) {
  if (!e.target?.closest?.("[data-cb-actions]")) {
    actionMenuOpen.value = false;
  }
  if (!e.target?.closest?.("[data-row-actions]")) {
    lineMenuOpenId.value = null;
  }
}

function formatHistoryTimestamp(iso) {
  if (!iso) return "—";
  try {
    return formatDateTimeUs(new Date(iso));
  } catch {
    return iso;
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
  <div class="staff-page staff-page--wide billing-custom-bill-detail">
    <nav
      class="staff-user-view__breadcrumb d-flex flex-wrap align-items-center gap-1 mb-3"
      aria-label="Breadcrumb"
    >
      <RouterLink to="/admin/billing/summary">Billing</RouterLink>
      <span class="text-secondary" aria-hidden="true">/</span>
      <RouterLink to="/admin/billing/bills">Bills</RouterLink>
      <span class="text-secondary" aria-hidden="true">/</span>
      <span class="text-body-secondary">{{ bill?.display_name || (bill?.bill_number ? `#${bill.bill_number}` : "Bill") }}</span>
    </nav>

    <div v-if="loading" class="d-flex justify-content-center py-5">
      <CrmLoadingSpinner message="Loading bill…" />
    </div>

    <template v-else-if="bill">
      <div
        class="d-flex flex-column flex-md-row align-items-start align-items-md-center gap-3 mb-4"
      >
        <div class="min-w-0 flex-grow-1">
          <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
            <h1 class="h4 mb-0 fw-semibold text-body">
              {{ bill.name || `Bill #${bill.bill_number}` }}
            </h1>
            <span class="badge rounded-pill fw-medium" :class="statusBadgeClass(bill.status)">
              {{ bill.status_label }}
            </span>
          </div>
          <p class="text-secondary small mb-0">
            {{ bill.client_account_name || "—" }} · {{ formatIsoDate(bill.bill_date) }}
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
            data-cb-actions
            class="position-relative"
          >
            <button
              type="button"
              class="btn btn-outline-secondary btn-sm orders-toolbar-outline-btn fw-semibold d-inline-flex align-items-center gap-2"
              :class="{ 'is-open': actionMenuOpen }"
              :aria-expanded="actionMenuOpen ? 'true' : 'false'"
              aria-haspopup="true"
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
            <div
              class="px-4 py-3 border-bottom d-flex justify-content-between align-items-center flex-wrap gap-2"
            >
              <h2 class="h6 mb-0 fw-semibold">Line Items</h2>
              <button
                v-if="isOpen && canUpdate"
                type="button"
                class="btn btn-sm btn-primary staff-page-primary"
                @click="openAddLineModal"
              >
                Add To Bill
              </button>
            </div>
            <div class="table-responsive staff-table-wrap">
              <table class="table table-hover align-middle mb-0 staff-data-table">
                <thead class="table-light staff-table-head">
                  <tr>
                    <th class="staff-table-head__th">Category</th>
                    <th class="staff-table-head__th">Service / Name</th>
                    <th class="staff-table-head__th text-end">Qty</th>
                    <th class="staff-table-head__th text-end">Price</th>
                    <th class="staff-table-head__th text-end">Total</th>
                    <th
                      v-if="isOpen && canUpdate"
                      class="staff-table-head__th text-center billing-custom-bill-lines-actions-col"
                    >
                      Actions
                    </th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-if="!bill.items?.length">
                    <td :colspan="isOpen && canUpdate ? 6 : 5" class="text-center text-secondary py-4">
                      No line items yet.
                    </td>
                  </tr>
                  <tr v-for="item in bill.items" :key="item.id">
                    <td>{{ invoiceCategoryLabel(item.line_type) }}</td>
                    <td class="fw-medium">{{ item.name }}</td>
                    <td class="text-end text-nowrap">{{ item.quantity }}</td>
                    <td class="text-end">{{ formatCents(item.unit_price_cents) }}</td>
                    <td class="text-end fw-semibold">{{ formatCents(item.line_total_cents) }}</td>
                    <td
                      v-if="isOpen && canUpdate"
                      class="text-center align-middle billing-custom-bill-lines-actions-cell"
                      @click.stop
                    >
                      <div data-row-actions class="position-relative d-inline-block">
                        <button
                          type="button"
                          class="staff-action-btn staff-action-btn--more"
                          :class="{ 'is-open': lineMenuOpenId === item.id }"
                          :aria-expanded="lineMenuOpenId === item.id ? 'true' : 'false'"
                          aria-haspopup="true"
                          aria-label="Line item actions"
                          @click.stop="toggleLineMenu(item.id, $event)"
                        >
                          <CrmIconRowActions variant="horizontal" />
                        </button>
                        <div
                          v-if="lineMenuOpenId === item.id"
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
                            @click="openLineEditFromMenu(item)"
                          >
                            Edit
                          </button>
                          <button
                            type="button"
                            class="staff-row-menu__item staff-row-menu__item--danger"
                            role="menuitem"
                            @click="openLineDeleteFromMenu(item)"
                          >
                            Delete
                          </button>
                        </div>
                      </div>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <div class="col-lg-4">
          <div class="staff-stat-card mb-4 billing-inv-summary-card billing-inv-summary-card--static">
            <p class="staff-stat-card__label">Bill Total</p>
            <p class="staff-stat-card__value">
              {{ formatCents(bill.total_cents) }}
            </p>
            <p class="staff-stat-card__sub">{{ billTotalSubtext }}</p>
            <div class="staff-stat-card__icon staff-stat-card__icon--money" aria-hidden="true">
              <BillingDollarStatIcon />
            </div>
          </div>

          <BillingBillDetailsCard class="mb-4" :fields="billDetailFields" />

          <div class="staff-surface p-3 p-md-4">
            <h2 class="h6 fw-semibold mb-3">History</h2>
            <ul v-if="bill.histories?.length" class="list-unstyled mb-0 small billing-custom-bill-history">
              <li
                v-for="h in bill.histories"
                :key="h.id"
                class="billing-custom-bill-history__item"
              >
                <div class="fw-semibold text-body">
                  {{ h.event_label || h.event_type }}
                  <span v-if="h.message && h.event_type !== 'created'" class="fw-normal text-secondary">
                    — {{ h.message }}
                  </span>
                </div>
                <div v-if="h.event_type === 'created' && h.message" class="text-secondary">
                  {{ h.message }}
                </div>
                <div v-if="h.event_type === 'invoiced' && h.invoice_id" class="text-secondary">
                  <RouterLink
                    :to="`/admin/billing/invoices/${h.invoice_id}`"
                    class="text-decoration-none"
                  >
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

    <p v-else class="text-secondary">Bill not found.</p>

    <Teleport to="body">
      <div
        v-if="actionMenuOpen && bill"
        data-cb-actions
        class="staff-row-menu fixed z-[300] overflow-hidden"
        role="menu"
        :style="{ top: `${actionMenuRect.top}px`, left: `${actionMenuRect.left}px`, position: 'fixed', zIndex: 2200 }"
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
          v-if="bill.invoice_id"
          :to="`/admin/billing/invoices/${bill.invoice_id}`"
          class="staff-row-menu__item text-decoration-none text-body"
          role="menuitem"
          @click="actionMenuOpen = false"
        >
          View Invoice
        </RouterLink>
        <button
          v-if="!isOpen && canUpdate"
          type="button"
          class="staff-row-menu__item"
          role="menuitem"
          :disabled="reopenBusy"
          @click="markAsOpen"
        >
          {{ reopenBusy ? "Updating…" : "Mark As Open" }}
        </button>
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

    <BillingCustomBillLineModal
      v-model:open="addLineModalOpen"
      v-model:category="addLineForm.line_type"
      v-model:name="addLineForm.name"
      v-model:quantity="addLineForm.quantity"
      v-model:unit-price="addLineForm.unit_price"
      v-model:sku="addLineForm.sku"
      title="Add To Bill"
      submit-label="Add Line"
      :category-options="INVOICE_CATEGORY_OPTIONS"
      :busy="addLineBusy"
      :error-msg="addLineError"
      @submit="submitAddLine"
    />

    <BillingCustomBillLineModal
      v-model:open="lineEditModalOpen"
      v-model:category="lineEditForm.line_type"
      v-model:name="lineEditForm.name"
      v-model:quantity="lineEditForm.quantity"
      v-model:unit-price="lineEditForm.unit_price"
      v-model:sku="lineEditForm.sku"
      title="Edit Line Item"
      submit-label="Save"
      :category-options="INVOICE_CATEGORY_OPTIONS"
      :busy="lineEditBusy"
      :error-msg="lineEditError"
      @submit="submitEditLine"
    />

  <!-- Edit date -->
  <Teleport to="body">
    <Transition name="crm-vx-confirm">
      <div
        v-if="editDateModalOpen"
        class="crm-vx-modal-overlay"
        role="dialog"
        aria-modal="true"
        @click.self="closeEditDateModal"
      >
        <div class="crm-vx-modal crm-vx-modal--sm" @click.stop>
          <button
            type="button"
            class="crm-vx-modal__close"
            aria-label="Close"
            :disabled="editDateBusy"
            @click="closeEditDateModal"
          >
            <svg
              width="20"
              height="20"
              fill="none"
              viewBox="0 0 24 24"
              stroke="currentColor"
              stroke-width="1.75"
              aria-hidden="true"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="M6 18L18 6M6 6l12 12"
              />
            </svg>
          </button>
          <header class="crm-vx-modal__head">
            <h2 class="crm-vx-modal__title">Edit Bill Date</h2>
          </header>
          <div class="crm-vx-modal__body">
            <form id="cb-edit-date-form" @submit.prevent="saveBillDate">
              <label class="form-label" for="cb-edit-date">Bill Date</label>
              <input
                id="cb-edit-date"
                v-model="editDateValue"
                type="date"
                class="form-control mb-0"
                :disabled="editDateBusy"
                required
              />
            </form>
          </div>
          <footer class="crm-vx-modal__footer d-flex gap-2 justify-content-end">
            <button
              type="button"
              class="crm-vx-modal-btn crm-vx-modal-btn--secondary"
              :disabled="editDateBusy"
              @click="closeEditDateModal"
            >
              Cancel
            </button>
            <button
              type="submit"
              form="cb-edit-date-form"
              class="crm-vx-modal-btn crm-vx-modal-btn--primary"
              :disabled="editDateBusy"
            >
              {{ editDateBusy ? "Saving…" : "Save" }}
            </button>
          </footer>
        </div>
      </div>
    </Transition>
  </Teleport>

  <BillingBillAddToInvoiceDrawer
    v-if="bill"
    v-model:open="addToInvoiceModalOpen"
    v-model:selected-invoice-id="selectedInvoiceId"
    :draft-invoices="draftInvoices"
    :client-account-name="bill.client_account_name || ''"
    :busy="addToInvoiceBusy"
    submit-label="Process"
    @submit="submitAddToInvoice"
  />

    <ConfirmModal
      v-model:open="deleteBillModalOpen"
      title="Delete Bill"
      :message="bill ? `Delete bill #${bill.bill_number}? This cannot be undone.` : ''"
      confirm-label="Delete"
      variant="danger"
      :busy="deleteBillBusy"
      @confirm="confirmDeleteBill"
    />

    <ConfirmModal
      v-model:open="lineDeleteModalOpen"
      title="Remove Line"
      message="Remove this line from the bill?"
      confirm-label="Remove"
      variant="danger"
      :busy="lineDeleteBusy"
      @confirm="confirmDeleteLine"
    />
  </div>
</template>

<style scoped>
.billing-custom-bill-detail :deep(.table-responsive.staff-table-wrap) {
  overflow-x: clip;
  max-width: 100%;
}

.billing-custom-bill-detail :deep(.staff-table-wrap .table.staff-data-table) {
  width: 100%;
  min-width: 0;
  table-layout: fixed;
}

.billing-custom-bill-detail :deep(.table.staff-data-table > thead > tr > th.billing-custom-bill-lines-actions-col),
.billing-custom-bill-detail :deep(.table.staff-data-table > tbody > tr > td.billing-custom-bill-lines-actions-cell) {
  text-align: center !important;
  width: 7rem;
  min-width: 7rem;
  max-width: 7rem;
}

.billing-custom-bill-history__item {
  padding: 0.65rem 0;
  border-bottom: 1px solid var(--vx-nav-border, rgba(0, 0, 0, 0.08));
}

.billing-custom-bill-history__item:last-child {
  border-bottom: 0;
  padding-bottom: 0;
}

.crm-vx-confirm-enter-active,
.crm-vx-confirm-leave-active {
  transition: opacity 0.2s ease;
}
.crm-vx-confirm-enter-from,
.crm-vx-confirm-leave-to {
  opacity: 0;
}
</style>
