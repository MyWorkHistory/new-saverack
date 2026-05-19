<script setup>
import { computed, inject, onMounted, onUnmounted, ref, watch } from "vue";
import { useRouter } from "vue-router";
import api from "../../services/api";
import ConfirmModal from "../../components/common/ConfirmModal.vue";
import CrmIconRowActions from "../../components/common/CrmIconRowActions.vue";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import { useToast } from "../../composables/useToast.js";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { crmIsAdmin } from "../../utils/crmUser.js";
import { formatCents } from "../../utils/formatMoney.js";
import { formatIsoDate } from "../../utils/formatUserDates.js";

const LINE_TYPES = [
  "Fulfillment Service",
  "Packaging",
  "Storage",
  "Postage",
  "New Packaging",
  "Packaging Material",
  "Product",
  "Admin",
  "Other",
  "Credit",
];

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

const manageMenuOpen = ref(false);
const lineMenuOpenId = ref(null);
const lineMenuPos = ref({ top: 0, left: 0 });

const editDateModalOpen = ref(false);
const editDateValue = ref("");
const editDateBusy = ref(false);

const deleteBillModalOpen = ref(false);
const deleteBillBusy = ref(false);

const addLineModalOpen = ref(false);
const addLineBusy = ref(false);
const lineForm = ref({
  line_type: LINE_TYPES[0],
  name: "",
  quantity: "1",
  unit_price: "0.00",
  sku: "",
});

const lineEditModalOpen = ref(false);
const lineEditBusy = ref(false);
const lineEditTarget = ref(null);

const lineDeleteModalOpen = ref(false);
const lineDeleteBusy = ref(false);
const lineDeleteTarget = ref(null);

const addToInvoiceModalOpen = ref(false);
const addToInvoiceBusy = ref(false);
const draftInvoices = ref([]);
const selectedInvoiceId = ref("");

const reopenBusy = ref(false);

const isOpen = computed(() => bill.value?.status === "open");

async function loadBill() {
  loading.value = true;
  try {
    const { data } = await api.get(`/custom-bills/${props.id}`);
    bill.value = data;
    setCrmPageMeta({
      title: `Save Rack | Bill #${data?.bill_number ?? props.id}`,
      description: "Custom bill detail.",
    });
  } catch (e) {
    toast.errorFrom(e, "Could not load custom bill.");
    bill.value = null;
  } finally {
    loading.value = false;
  }
}

function statusBadgeClass(status) {
  return status === "invoiced" ? "bg-success-subtle text-success" : "bg-warning-subtle text-warning";
}

function resetLineForm() {
  lineForm.value = {
    line_type: LINE_TYPES[0],
    name: "",
    quantity: "1",
    unit_price: "0.00",
    sku: "",
  };
}

function unitPriceFromCents(cents) {
  return ((Number(cents) || 0) / 100).toFixed(2);
}

function openAddLineModal() {
  resetLineForm();
  addLineModalOpen.value = true;
}

function openLineEdit(item) {
  lineEditTarget.value = item;
  lineForm.value = {
    line_type: item.line_type,
    name: item.name,
    quantity: String(item.quantity),
    unit_price: unitPriceFromCents(item.unit_price_cents),
    sku: item.sku || "",
  };
  lineEditModalOpen.value = true;
}

function linePayloadFromForm() {
  const payload = {
    line_type: lineForm.value.line_type,
    name: String(lineForm.value.name || "").trim(),
    quantity: parseFloat(lineForm.value.quantity),
    unit_price: parseFloat(lineForm.value.unit_price) || 0,
  };
  const sku = String(lineForm.value.sku || "").trim();
  if (sku) payload.sku = sku;
  return payload;
}

async function submitAddLine() {
  const payload = linePayloadFromForm();
  if (!payload.name) {
    toast.error("Name is required.");
    return;
  }
  addLineBusy.value = true;
  try {
    const { data } = await api.post(`/custom-bills/${props.id}/items`, payload);
    bill.value = data;
    addLineModalOpen.value = false;
    toast.success("Line added.");
  } catch (e) {
    toast.errorFrom(e, "Could not add line.");
  } finally {
    addLineBusy.value = false;
  }
}

async function submitEditLine() {
  if (!lineEditTarget.value) return;
  const payload = linePayloadFromForm();
  if (!payload.name) {
    toast.error("Name is required.");
    return;
  }
  lineEditBusy.value = true;
  try {
    const { data } = await api.put(
      `/custom-bills/${props.id}/items/${lineEditTarget.value.id}`,
      payload,
    );
    bill.value = data;
    lineEditModalOpen.value = false;
    lineEditTarget.value = null;
    toast.success("Line updated.");
  } catch (e) {
    toast.errorFrom(e, "Could not update line.");
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

function openEditDateModal() {
  editDateValue.value = bill.value?.bill_date || "";
  editDateModalOpen.value = true;
}

async function saveBillDate() {
  editDateBusy.value = true;
  try {
    const { data } = await api.patch(`/custom-bills/${props.id}`, {
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
    await api.delete(`/custom-bills/${props.id}`);
    toast.success("Custom bill deleted.");
    router.push("/admin/billing/custom-bills");
  } catch (e) {
    toast.errorFrom(e, "Could not delete custom bill.");
  } finally {
    deleteBillBusy.value = false;
  }
}

async function openAddToInvoiceModal() {
  manageMenuOpen.value = false;
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
    toast.success("Bill lines added to invoice.");
  } catch (e) {
    toast.errorFrom(e, "Could not add bill to invoice.");
  } finally {
    addToInvoiceBusy.value = false;
  }
}

async function markAsOpen() {
  reopenBusy.value = true;
  manageMenuOpen.value = false;
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

function openLineMenu(item, ev) {
  const btn = ev?.currentTarget;
  if (!btn || !(btn instanceof HTMLElement)) return;
  const rect = btn.getBoundingClientRect();
  lineMenuPos.value = { top: rect.bottom + 4, left: Math.max(8, rect.right - 160) };
  lineMenuOpenId.value = item.id;
}

function closeLineMenu() {
  lineMenuOpenId.value = null;
}

function onDocClick(e) {
  if (!e.target?.closest?.("[data-cb-manage]")) {
    manageMenuOpen.value = false;
  }
  if (!e.target?.closest?.("[data-cb-line-menu]")) {
    closeLineMenu();
  }
}

function formatHistoryTimestamp(iso) {
  if (!iso) return "—";
  try {
    return new Date(iso).toLocaleString();
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
  <div class="staff-page staff-page--wide">
    <nav
      class="staff-user-view__breadcrumb d-flex flex-wrap align-items-center gap-1 mb-3"
      aria-label="Breadcrumb"
    >
      <RouterLink to="/admin/billing/summary">Billing</RouterLink>
      <span class="text-secondary" aria-hidden="true">/</span>
      <RouterLink to="/admin/billing/custom-bills">Custom Bills</RouterLink>
      <span class="text-secondary" aria-hidden="true">/</span>
      <span class="text-body-secondary">{{ bill?.bill_number ? `#${bill.bill_number}` : "Bill" }}</span>
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
            <h1 class="h4 mb-0 fw-semibold text-body">Bill #{{ bill.bill_number }}</h1>
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
        <div class="ms-md-auto position-relative" data-cb-manage>
          <button
            type="button"
            class="btn btn-outline-secondary btn-sm d-inline-flex align-items-center gap-2"
            @click.stop="manageMenuOpen = !manageMenuOpen"
          >
            Manage
            <svg
              class="flex-shrink-0"
              width="14"
              height="14"
              fill="none"
              viewBox="0 0 24 24"
              stroke="currentColor"
              stroke-width="2"
              aria-hidden="true"
            >
              <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
            </svg>
          </button>
          <div
            v-if="manageMenuOpen"
            class="staff-row-menu dropdown-menu show shadow position-absolute end-0 mt-1"
            style="min-width: 12rem"
          >
            <template v-if="isOpen && canUpdate">
              <button type="button" class="dropdown-item" @click="openEditDateModal">
                Edit Bill Date
              </button>
              <button type="button" class="dropdown-item" @click="openAddToInvoiceModal">
                Add To Invoice
              </button>
            </template>
            <RouterLink
              v-if="bill.invoice_id"
              :to="`/admin/billing/invoices/${bill.invoice_id}`"
              class="dropdown-item"
              @click="manageMenuOpen = false"
            >
              View Invoice
            </RouterLink>
            <button
              v-if="!isOpen && canUpdate"
              type="button"
              class="dropdown-item"
              :disabled="reopenBusy"
              @click="markAsOpen"
            >
              {{ reopenBusy ? "Updating…" : "Mark As Open" }}
            </button>
            <button
              v-if="isOpen && canDelete"
              type="button"
              class="dropdown-item text-danger"
              @click="
                manageMenuOpen = false;
                deleteBillModalOpen = true;
              "
            >
              Delete Bill
            </button>
          </div>
        </div>
      </div>

      <div class="row g-4">
        <div class="col-lg-8">
          <div class="staff-table-card staff-datatable-card staff-datatable-card--white p-3 p-md-4">
            <div
              class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3"
            >
              <h2 class="h6 fw-semibold mb-0">Line Items</h2>
              <button
                v-if="isOpen && canUpdate"
                type="button"
                class="btn btn-sm btn-primary staff-page-primary"
                @click="openAddLineModal"
              >
                Add To Bill
              </button>
            </div>
            <div class="table-responsive">
              <table class="table table-hover staff-table mb-0">
                <thead class="staff-table-head">
                  <tr>
                    <th scope="col">Type</th>
                    <th scope="col">Service / Name</th>
                    <th scope="col" class="text-end">QTY</th>
                    <th scope="col" class="text-end">Price</th>
                    <th scope="col" class="text-end">Total</th>
                    <th v-if="isOpen && canUpdate" scope="col" class="text-end">Action</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-if="!bill.items?.length">
                    <td :colspan="isOpen && canUpdate ? 6 : 5" class="text-center text-secondary py-4">
                      No line items yet.
                    </td>
                  </tr>
                  <tr v-for="item in bill.items" :key="item.id">
                    <td>{{ item.line_type }}</td>
                    <td>{{ item.name }}</td>
                    <td class="text-end">{{ item.quantity }}</td>
                    <td class="text-end">{{ formatCents(item.unit_price_cents) }}</td>
                    <td class="text-end">{{ formatCents(item.line_total_cents) }}</td>
                    <td v-if="isOpen && canUpdate" class="text-end" data-cb-line-menu>
                      <CrmIconRowActions
                        variant="horizontal"
                        @click="openLineMenu(item, $event)"
                      />
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <div class="col-lg-4">
          <div class="staff-surface p-3 p-md-4 mb-4">
            <h2 class="h6 fw-semibold mb-3">Bill Amount</h2>
            <div class="d-flex justify-content-between align-items-center">
              <span class="text-secondary">Total</span>
              <span class="fs-5 fw-semibold">{{ formatCents(bill.total_cents) }}</span>
            </div>
          </div>

          <div class="staff-surface p-3 p-md-4">
            <h2 class="h6 fw-semibold mb-3">History</h2>
            <ul v-if="bill.histories?.length" class="list-unstyled mb-0 small">
              <li
                v-for="h in bill.histories"
                :key="h.id"
                class="border-bottom py-2 last:border-0"
              >
                <div class="fw-medium">{{ h.message }}</div>
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
        v-if="lineMenuOpenId"
        class="staff-row-menu dropdown-menu show shadow"
        :style="{ position: 'fixed', top: `${lineMenuPos.top}px`, left: `${lineMenuPos.left}px` }"
        data-cb-line-menu
      >
        <button
          type="button"
          class="dropdown-item"
          @click="
            closeLineMenu();
            openLineEdit(bill.items.find((i) => i.id === lineMenuOpenId));
          "
        >
          Edit
        </button>
        <button
          type="button"
          class="dropdown-item text-danger"
          @click="
            lineDeleteTarget = bill.items.find((i) => i.id === lineMenuOpenId);
            closeLineMenu();
            lineDeleteModalOpen = true;
          "
        >
          Delete
        </button>
      </div>
    </Teleport>

  <!-- Edit date -->
  <Teleport to="body">
    <div
      v-if="editDateModalOpen"
      class="crm-vx-modal-overlay"
      role="dialog"
      aria-modal="true"
    >
      <div class="crm-vx-modal-backdrop" aria-hidden="true" @click="editDateModalOpen = false" />
      <div class="crm-vx-modal crm-vx-modal--sm">
        <header class="crm-vx-modal__head">
          <h2 class="crm-vx-modal__title">Edit Bill Date</h2>
        </header>
        <div class="crm-vx-modal__body">
          <label class="form-label" for="cb-edit-date">Bill Date</label>
          <input
            id="cb-edit-date"
            v-model="editDateValue"
            type="date"
            class="form-control"
            :disabled="editDateBusy"
          />
        </div>
        <footer class="crm-vx-modal__foot d-flex justify-content-end gap-2">
          <button
            type="button"
            class="btn btn-outline-secondary"
            :disabled="editDateBusy"
            @click="editDateModalOpen = false"
          >
            Cancel
          </button>
          <button
            type="button"
            class="btn btn-primary staff-page-primary"
            :disabled="editDateBusy"
            @click="saveBillDate"
          >
            {{ editDateBusy ? "Saving…" : "Save" }}
          </button>
        </footer>
      </div>
    </div>
  </Teleport>

  <!-- Line add/edit modal -->
  <Teleport to="body">
    <div
      v-if="addLineModalOpen || lineEditModalOpen"
      class="crm-vx-modal-overlay"
      role="dialog"
      aria-modal="true"
    >
      <div
        class="crm-vx-modal-backdrop"
        aria-hidden="true"
        @click="
          addLineModalOpen = false;
          lineEditModalOpen = false;
        "
      />
      <div class="crm-vx-modal crm-vx-modal--sm">
        <header class="crm-vx-modal__head">
          <h2 class="crm-vx-modal__title">
            {{ lineEditModalOpen ? "Edit Line" : "Add To Bill" }}
          </h2>
        </header>
        <div class="crm-vx-modal__body">
          <label class="form-label">Type</label>
          <select v-model="lineForm.line_type" class="form-select mb-3">
            <option v-for="t in LINE_TYPES" :key="t" :value="t">{{ t }}</option>
          </select>
          <label class="form-label">Service / Name</label>
          <input v-model="lineForm.name" type="text" class="form-control mb-3" />
          <div class="row g-2 mb-3">
            <div class="col-6">
              <label class="form-label">QTY</label>
              <input
                v-model="lineForm.quantity"
                type="number"
                min="0.0001"
                step="any"
                class="form-control"
              />
            </div>
            <div class="col-6">
              <label class="form-label">Unit Price</label>
              <input
                v-model="lineForm.unit_price"
                type="number"
                step="0.01"
                class="form-control"
              />
            </div>
          </div>
          <label class="form-label">SKU (optional)</label>
          <input v-model="lineForm.sku" type="text" class="form-control" />
        </div>
        <footer class="crm-vx-modal__foot d-flex justify-content-end gap-2">
          <button
            type="button"
            class="btn btn-outline-secondary"
            :disabled="addLineBusy || lineEditBusy"
            @click="
              addLineModalOpen = false;
              lineEditModalOpen = false;
            "
          >
            Cancel
          </button>
          <button
            type="button"
            class="btn btn-primary staff-page-primary"
            :disabled="addLineBusy || lineEditBusy"
            @click="lineEditModalOpen ? submitEditLine() : submitAddLine()"
          >
            {{
              addLineBusy || lineEditBusy
                ? "Saving…"
                : lineEditModalOpen
                  ? "Save"
                  : "Add Line"
            }}
          </button>
        </footer>
      </div>
    </div>
  </Teleport>

  <!-- Add to invoice -->
  <Teleport to="body">
    <div
      v-if="addToInvoiceModalOpen"
      class="crm-vx-modal-overlay"
      role="dialog"
      aria-modal="true"
    >
      <div
        class="crm-vx-modal-backdrop"
        aria-hidden="true"
        @click="addToInvoiceModalOpen = false"
      />
      <div class="crm-vx-modal">
        <header class="crm-vx-modal__head">
          <h2 class="crm-vx-modal__title">Add To Invoice</h2>
          <p class="crm-vx-modal__subtitle mb-0">
            Select a draft invoice for {{ bill.client_account_name }}.
          </p>
        </header>
        <div class="crm-vx-modal__body">
          <p v-if="!draftInvoices.length" class="text-secondary small mb-0">
            No draft invoices for this account. Create a draft invoice first.
          </p>
          <div v-else class="list-group list-group-flush border rounded">
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
              />
              <span class="fw-semibold">Invoice #{{ inv.invoice_number }}</span>
              <span class="ms-auto text-secondary">{{ formatCents(inv.total_cents) }}</span>
            </label>
          </div>
        </div>
        <footer class="crm-vx-modal__foot d-flex justify-content-end gap-2">
          <button
            type="button"
            class="btn btn-outline-secondary"
            :disabled="addToInvoiceBusy"
            @click="addToInvoiceModalOpen = false"
          >
            Cancel
          </button>
          <button
            type="button"
            class="btn btn-primary staff-page-primary"
            :disabled="addToInvoiceBusy || !draftInvoices.length"
            @click="submitAddToInvoice"
          >
            {{ addToInvoiceBusy ? "Processing…" : "Process" }}
          </button>
        </footer>
      </div>
    </div>
  </Teleport>

    <ConfirmModal
      v-model:open="deleteBillModalOpen"
      title="Delete Custom Bill"
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
