<script setup>
import { computed, inject, nextTick, onMounted, onUnmounted, reactive, ref, watch } from "vue";
import { useRouter } from "vue-router";
import api from "../../services/api";
import BillingDollarStatIcon from "../../components/billing/BillingDollarStatIcon.vue";
import BillingReturnBillLineModal from "../../components/billing/BillingReturnBillLineModal.vue";
import ConfirmModal from "../../components/common/ConfirmModal.vue";
import CrmIconRowActions from "../../components/common/CrmIconRowActions.vue";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import { useToast } from "../../composables/useToast.js";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { crmIsAdmin } from "../../utils/crmUser.js";
import { formatCents } from "../../utils/formatMoney.js";
import { formatIsoDate, formatDateTimeUs } from "../../utils/formatUserDates.js";

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
const canDelete = computed(() => userHasPerm("billing.delete"));

const loading = ref(true);
const bill = ref(null);
const actionMenuOpen = ref(false);
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

const lineDeleteModalOpen = ref(false);
const lineDeleteBusy = ref(false);
const lineDeleteTarget = ref(null);

const addToInvoiceModalOpen = ref(false);
const addToInvoiceBusy = ref(false);
const draftInvoices = ref([]);
const selectedInvoiceId = ref("");
const selectedLineTypes = ref([]);

function emptyLineForm() {
  return { line_type: "", name: "", quantity: "1", unit_price: "0.00" };
}

const addLineForm = reactive(emptyLineForm());
const lineEditForm = reactive(emptyLineForm());

const chargeOptions = computed(() => bill.value?.charge_options || []);

const isOpen = computed(() => bill.value?.status === "open");

const billTotalSubtext = computed(() => {
  if (!bill.value) return "";
  if (bill.value.status === "invoiced" && bill.value.invoice_number) {
    return `On invoice #${bill.value.invoice_number}`;
  }
  return "Sum of line items";
});

const MENU_W = 128;
const MENU_H = 96;

const lineMenuStyle = computed(() => ({
  top: `${lineMenuPos.value.top}px`,
  left: `${lineMenuPos.value.left}px`,
  zIndex: 2200,
}));

function formatHistoryTimestamp(iso) {
  return iso ? formatDateTimeUs(iso) : "—";
}

function unitPriceFromCents(cents) {
  return ((Number(cents) || 0) / 100).toFixed(2);
}

function linePayloadFromForm(form) {
  return {
    line_type: form.line_type,
    name: String(form.name || "").trim(),
    quantity: Number(form.quantity),
    unit_price: Number(form.unit_price),
  };
}

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

function onDocClick(e) {
  if (!e.target?.closest?.("[data-rb-action]")) actionMenuOpen.value = false;
  if (!e.target?.closest?.("[data-rb-line-menu]")) lineMenuOpenId.value = null;
}

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

function openAddLineModal() {
  Object.assign(addLineForm, emptyLineForm());
  const first = chargeOptions.value[0];
  if (first) {
    addLineForm.line_type = first.line_type;
    addLineForm.name = first.display_name;
    addLineForm.unit_price = unitPriceFromCents(first.default_unit_price_cents);
  }
  addLineError.value = "";
  addLineModalOpen.value = true;
}

function openLineEdit(item) {
  lineEditTarget.value = item;
  lineEditForm.line_type = item.line_type;
  lineEditForm.name = item.name;
  lineEditForm.quantity = String(item.quantity);
  lineEditForm.unit_price = unitPriceFromCents(item.unit_price_cents);
  lineEditError.value = "";
  lineEditModalOpen.value = true;
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

async function submitAddLine() {
  const payload = linePayloadFromForm(addLineForm);
  if (!payload.line_type) {
    addLineError.value = "Select a charge type.";
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
    const { data } = await api.post(`/return-bills/${props.id}/items`, payload);
    bill.value = data;
    addLineModalOpen.value = false;
    toast.success("Line added.");
  } catch (e) {
    const d = e?.response?.data;
    addLineError.value = d?.message || d?.errors?.quantity?.[0] || "";
    toast.errorFrom(e, addLineError.value || "Could not add line.");
  } finally {
    addLineBusy.value = false;
  }
}

async function submitEditLine() {
  if (!lineEditTarget.value) return;
  const payload = linePayloadFromForm(lineEditForm);
  if (!payload.line_type) {
    lineEditError.value = "Select a charge type.";
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
      `/return-bills/${props.id}/items/${lineEditTarget.value.id}`,
      payload,
    );
    bill.value = data;
    lineEditModalOpen.value = false;
    lineEditTarget.value = null;
    toast.success("Line updated.");
  } catch (e) {
    const d = e?.response?.data;
    lineEditError.value = d?.message || d?.errors?.quantity?.[0] || "";
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
      `/return-bills/${props.id}/items/${lineDeleteTarget.value.id}`,
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

function openEditDateModal() {
  actionMenuOpen.value = false;
  editDateValue.value = bill.value?.bill_date || "";
  editDateModalOpen.value = true;
}

async function saveBillDate() {
  editDateBusy.value = true;
  try {
    const { data } = await api.patch(`/return-bills/${props.id}`, {
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
    await api.delete(`/return-bills/${props.id}`);
    toast.success("Return bill deleted.");
    router.push("/admin/billing/return-bills");
  } catch (e) {
    toast.errorFrom(e, "Could not delete return bill.");
  } finally {
    deleteBillBusy.value = false;
  }
}

function initAddToInvoiceSelections() {
  const onBill = new Set((bill.value?.items || []).map((i) => i.line_type));
  selectedLineTypes.value = chargeOptions.value
    .filter((opt) => onBill.has(opt.line_type))
    .map((opt) => opt.line_type);
  if (!selectedLineTypes.value.length) {
    selectedLineTypes.value = chargeOptions.value.map((opt) => opt.line_type);
  }
}

function toggleLineTypeSelection(lineType) {
  const set = new Set(selectedLineTypes.value);
  if (set.has(lineType)) set.delete(lineType);
  else set.add(lineType);
  selectedLineTypes.value = [...set];
}

function closeAddToInvoiceModal() {
  if (addToInvoiceBusy.value) return;
  addToInvoiceModalOpen.value = false;
}

async function openAddToInvoiceModal() {
  selectedInvoiceId.value = "";
  draftInvoices.value = [];
  initAddToInvoiceSelections();
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
  if (!selectedLineTypes.value.length) {
    toast.error("Select at least one charge type.");
    return;
  }
  addToInvoiceBusy.value = true;
  try {
    const { data } = await api.post(`/return-bills/${props.id}/add-to-invoice`, {
      invoice_id: Number(selectedInvoiceId.value),
      line_types: selectedLineTypes.value,
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
      <div class="d-flex flex-column flex-md-row align-items-start align-items-md-center gap-3 mb-3">
        <div class="min-w-0 flex-grow-1">
          <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
            <h1 class="h4 mb-0 fw-semibold text-body">Return Bill #{{ bill.bill_number }}</h1>
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
        <div
          v-if="isOpen && canUpdate"
          class="ms-md-auto d-flex flex-wrap align-items-center gap-2"
        >
          <button type="button" class="btn btn-primary btn-sm" @click="openAddToInvoiceModal">
            Add To Invoice
          </button>
          <div class="position-relative" data-rb-action>
            <button
              type="button"
              class="btn btn-outline-secondary btn-sm d-inline-flex align-items-center gap-2"
              @click.stop="actionMenuOpen = !actionMenuOpen"
            >
              Action
              <svg class="flex-shrink-0" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
              </svg>
            </button>
            <div
              v-if="actionMenuOpen"
              class="staff-row-menu staff-toolbar-bulk-dropdown dropdown-menu show shadow position-absolute end-0 mt-1 p-0 overflow-hidden"
              style="min-width: 12rem"
            >
              <button type="button" class="dropdown-item" @click="openEditDateModal">Edit Bill</button>
              <button
                v-if="canDelete"
                type="button"
                class="dropdown-item text-danger"
                @click="actionMenuOpen = false; deleteBillModalOpen = true"
              >
                Delete
              </button>
              <RouterLink
                v-if="bill.invoice_id"
                :to="`/admin/billing/invoices/${bill.invoice_id}`"
                class="dropdown-item text-decoration-none"
                @click="actionMenuOpen = false"
              >
                View Invoice
              </RouterLink>
            </div>
          </div>
        </div>
      </div>

      <div class="row g-4">
        <div class="col-lg-8">
          <div class="staff-table-card staff-datatable-card staff-datatable-card--white p-0 mb-4">
            <div class="px-4 py-3 border-bottom d-flex align-items-center justify-content-between gap-2">
              <h2 class="h6 mb-0 fw-semibold">Line Items</h2>
              <button
                v-if="isOpen && canUpdate"
                type="button"
                class="btn btn-outline-secondary btn-sm"
                @click="openAddLineModal"
              >
                Add Line
              </button>
            </div>
            <div class="table-responsive staff-table-wrap">
              <table class="table table-hover align-middle mb-0 staff-data-table">
                <thead class="table-light staff-table-head">
                  <tr>
                    <th class="staff-table-head__th">Service / Name</th>
                    <th class="staff-table-head__th text-end">Qty</th>
                    <th class="staff-table-head__th text-end">Price</th>
                    <th class="staff-table-head__th text-end">Total</th>
                    <th v-if="isOpen && canUpdate" class="staff-table-head__th text-end" style="width: 3rem" />
                  </tr>
                </thead>
                <tbody>
                  <tr v-if="!bill.items?.length">
                    <td :colspan="isOpen && canUpdate ? 5 : 4" class="text-center text-secondary py-4">
                      No line items.
                    </td>
                  </tr>
                  <tr v-for="item in bill.items" :key="item.id">
                    <td class="fw-medium">{{ item.name }}</td>
                    <td class="text-end text-nowrap">{{ item.quantity }}</td>
                    <td class="text-end">{{ formatCents(item.unit_price_cents) }}</td>
                    <td class="text-end fw-semibold">{{ formatCents(item.line_total_cents) }}</td>
                    <td v-if="isOpen && canUpdate" class="text-end">
                      <CrmIconRowActions data-rb-line-menu @click="toggleLineMenu(item.id, $event)" />
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
            <p class="staff-stat-card__value">{{ formatCents(bill.total_cents) }}</p>
            <p class="staff-stat-card__sub">{{ billTotalSubtext }}</p>
            <div class="staff-stat-card__icon staff-stat-card__icon--money" aria-hidden="true">
              <BillingDollarStatIcon />
            </div>
          </div>

          <div class="staff-surface p-3 p-md-4 mb-4">
            <dl class="billing-custom-bill-info small mb-0">
              <div class="billing-custom-bill-info__row">
                <dt class="text-secondary">Created by</dt>
                <dd class="mb-0 text-body">{{ bill.created_by_name || "—" }}</dd>
              </div>
              <div class="billing-custom-bill-info__row">
                <dt class="text-secondary">Account</dt>
                <dd class="mb-0 text-body">{{ bill.client_account_name || "—" }}</dd>
              </div>
              <div class="billing-custom-bill-info__row">
                <dt class="text-secondary">Date</dt>
                <dd class="mb-0 text-body">{{ formatIsoDate(bill.bill_date) }}</dd>
              </div>
              <div v-if="bill.rma_number" class="billing-custom-bill-info__row">
                <dt class="text-secondary">RMA</dt>
                <dd class="mb-0 text-body">
                  {{ bill.rma_number }}
                  <span v-if="bill.order_number"> · Order #{{ bill.order_number }}</span>
                  <RouterLink
                    v-if="bill.client_account_return_id"
                    :to="{ name: 'admin-process-return-detail', params: { id: String(bill.client_account_return_id) } }"
                    class="ms-2 text-decoration-none"
                  >
                    View Return
                  </RouterLink>
                </dd>
              </div>
            </dl>
          </div>

          <div class="staff-surface p-3 p-md-4">
            <h2 class="h6 fw-semibold mb-3">History</h2>
            <ul v-if="bill.histories?.length" class="list-unstyled mb-0 small billing-custom-bill-history">
              <li v-for="h in bill.histories" :key="h.id" class="billing-custom-bill-history__item">
                <div class="fw-semibold text-body">
                  {{ h.event_label || h.event_type }}
                  <span v-if="h.message && h.event_type !== 'created'" class="fw-normal text-secondary">
                    — {{ h.message }}
                  </span>
                </div>
                <div v-if="h.event_type === 'created' && h.message" class="text-secondary">{{ h.message }}</div>
                <div v-if="h.event_type === 'invoiced' && h.invoice_id" class="text-secondary">
                  <RouterLink :to="`/admin/billing/invoices/${h.invoice_id}`" class="text-decoration-none">
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
        v-if="lineMenuOpenId"
        data-rb-line-menu
        class="staff-row-menu staff-toolbar-bulk-dropdown dropdown-menu show shadow p-0 overflow-hidden"
        :style="lineMenuStyle"
      >
        <button type="button" class="dropdown-item" @click="openLineEditFromMenu(bill.items.find((i) => i.id === lineMenuOpenId))">
          Edit
        </button>
        <button
          type="button"
          class="dropdown-item text-danger"
          @click="openLineDeleteFromMenu(bill.items.find((i) => i.id === lineMenuOpenId))"
        >
          Delete
        </button>
      </div>
    </Teleport>

    <BillingReturnBillLineModal
      v-model:open="addLineModalOpen"
      v-model:line-type="addLineForm.line_type"
      v-model:name="addLineForm.name"
      v-model:quantity="addLineForm.quantity"
      v-model:unit-price="addLineForm.unit_price"
      title="Add Line"
      submit-label="Add Line"
      :charge-options="chargeOptions"
      :busy="addLineBusy"
      :error-msg="addLineError"
      @submit="submitAddLine"
    />

    <BillingReturnBillLineModal
      v-model:open="lineEditModalOpen"
      v-model:line-type="lineEditForm.line_type"
      v-model:name="lineEditForm.name"
      v-model:quantity="lineEditForm.quantity"
      v-model:unit-price="lineEditForm.unit_price"
      title="Edit Line Item"
      submit-label="Save"
      :charge-options="chargeOptions"
      :busy="lineEditBusy"
      :error-msg="lineEditError"
      @submit="submitEditLine"
    />

    <ConfirmModal
      v-model:open="lineDeleteModalOpen"
      title="Delete Line Item"
      message="Remove this line from the bill?"
      confirm-label="Delete"
      variant="danger"
      :busy="lineDeleteBusy"
      @confirm="confirmDeleteLine"
    />

    <ConfirmModal
      v-model:open="deleteBillModalOpen"
      title="Delete Return Bill"
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
              <h2 class="crm-vx-modal__title">Edit Bill</h2>
            </header>
            <div class="crm-vx-modal__body">
              <label class="form-label" for="rb-edit-date">Bill date</label>
              <input id="rb-edit-date" v-model="editDateValue" type="date" class="form-control" :disabled="editDateBusy" />
            </div>
            <footer class="crm-vx-modal__footer d-flex gap-2 justify-content-end">
              <button type="button" class="crm-vx-modal-btn crm-vx-modal-btn--secondary" :disabled="editDateBusy" @click="editDateModalOpen = false">
                Cancel
              </button>
              <button type="button" class="crm-vx-modal-btn crm-vx-modal-btn--primary" :disabled="editDateBusy" @click="saveBillDate">
                {{ editDateBusy ? "Saving…" : "Save" }}
              </button>
            </footer>
          </div>
        </div>
      </Transition>
    </Teleport>

    <Teleport to="body">
      <Transition name="crm-vx-confirm">
        <div
          v-if="addToInvoiceModalOpen"
          class="crm-vx-modal-overlay"
          role="dialog"
          aria-modal="true"
          @click.self="closeAddToInvoiceModal"
        >
          <div class="crm-vx-modal crm-vx-modal--md" @click.stop>
            <header class="crm-vx-modal__head">
              <h2 class="crm-vx-modal__title">Add To Invoice</h2>
              <p class="crm-vx-modal__subtitle mb-0">
                Select a draft invoice and charge types for {{ bill?.client_account_name }}.
              </p>
            </header>
            <div class="crm-vx-modal__body text-start">
              <p v-if="!draftInvoices.length" class="small text-secondary mb-3">
                No draft invoices for this account.
              </p>
              <div v-else class="list-group list-group-flush border rounded mb-4">
                <label
                  v-for="inv in draftInvoices"
                  :key="inv.id"
                  class="list-group-item list-group-item-action d-flex align-items-center gap-2 mb-0 cursor-pointer"
                >
                  <input
                    v-model="selectedInvoiceId"
                    type="radio"
                    class="form-check-input mt-0"
                    :value="String(inv.id)"
                    :disabled="addToInvoiceBusy"
                  />
                  <span class="fw-semibold">Invoice #{{ inv.invoice_number }}</span>
                  <span class="ms-auto text-secondary">{{ formatCents(inv.total_cents) }}</span>
                </label>
              </div>

              <p class="small fw-semibold mb-2">Charge types</p>
              <div class="d-flex flex-column gap-2">
                <label
                  v-for="opt in chargeOptions"
                  :key="opt.line_type"
                  class="d-flex align-items-center gap-2 mb-0 small"
                >
                  <input
                    type="checkbox"
                    class="form-check-input mt-0"
                    :checked="selectedLineTypes.includes(opt.line_type)"
                    :disabled="addToInvoiceBusy"
                    @change="toggleLineTypeSelection(opt.line_type)"
                  />
                  <span>{{ opt.display_name }}</span>
                  <span class="ms-auto text-secondary">{{ formatCents(opt.default_unit_price_cents) }} default</span>
                </label>
              </div>
            </div>
            <footer class="crm-vx-modal__footer d-flex gap-2 justify-content-end">
              <button type="button" class="crm-vx-modal-btn crm-vx-modal-btn--secondary" :disabled="addToInvoiceBusy" @click="closeAddToInvoiceModal">
                Cancel
              </button>
              <button
                type="button"
                class="crm-vx-modal-btn crm-vx-modal-btn--primary"
                :disabled="addToInvoiceBusy || !draftInvoices.length || !selectedInvoiceId || !selectedLineTypes.length"
                @click="submitAddToInvoice"
              >
                {{ addToInvoiceBusy ? "Processing…" : "Add To Invoice" }}
              </button>
            </footer>
          </div>
        </div>
      </Transition>
    </Teleport>
  </div>
</template>

<style scoped>
.billing-custom-bill-info {
  display: grid;
  gap: 0.5rem 1rem;
  grid-template-columns: minmax(6rem, auto) 1fr;
}
.billing-custom-bill-info__row {
  display: contents;
}
.billing-custom-bill-info__row dt {
  margin: 0;
}
.billing-custom-bill-info__row dd {
  margin: 0;
}
.billing-custom-bill-history__item + .billing-custom-bill-history__item {
  margin-top: 0.75rem;
  padding-top: 0.75rem;
  border-top: 1px solid var(--bs-border-color);
}
</style>
