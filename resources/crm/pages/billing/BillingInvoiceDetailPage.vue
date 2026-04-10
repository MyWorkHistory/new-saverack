<script setup>
import { computed, inject, onMounted, onUnmounted, ref } from "vue";
import { useRouter } from "vue-router";
import api, { getApiBaseUrl } from "../../services/api";
import invoiceBrandLogoUrl from "@public/images/logo/logo.svg";
import ConfirmModal from "../../components/common/ConfirmModal.vue";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import { useToast } from "../../composables/useToast";
import { crmIsAdmin } from "../../utils/crmUser";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { formatCents } from "../../utils/formatMoney.js";

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
const invoice = ref(null);

const editDueAt = ref("");
const editLines = ref([]);
const draftSaving = ref(false);

const payModalOpen = ref(false);
const payAmount = ref("");
const payBusy = ref(false);

const voidModalOpen = ref(false);
const voidBusy = ref(false);

const deleteModalOpen = ref(false);
const deleteBusy = ref(false);

const pdfDownloading = ref(false);

const invoiceDateLong = new Intl.DateTimeFormat(undefined, {
  year: "numeric",
  month: "long",
  day: "numeric",
});

function formatInvoiceLongDate(iso) {
  if (!iso) return "—";
  const s = String(iso);
  const d = /^\d{4}-\d{2}-\d{2}$/.test(s) ? new Date(`${s}T12:00:00`) : new Date(s);
  if (Number.isNaN(d.getTime())) return "—";
  return invoiceDateLong.format(d);
}

async function downloadInvoicePdf() {
  if (!invoice.value?.id || pdfDownloading.value) return;
  pdfDownloading.value = true;
  try {
    const token = localStorage.getItem("auth_token");
    const url = `${getApiBaseUrl()}/invoices/${invoice.value.id}/pdf`;
    const res = await fetch(url, {
      headers: {
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
        Accept: "application/pdf",
      },
    });
    if (!res.ok) {
      toast.error("Could not download PDF.");
      return;
    }
    const blob = await res.blob();
    const a = document.createElement("a");
    const baseName = String(invoice.value.invoice_number || "invoice").replace(
      /[^A-Za-z0-9._-]+/g,
      "_",
    );
    a.href = URL.createObjectURL(blob);
    a.download = `${baseName}.pdf`;
    a.rel = "noopener";
    document.body.appendChild(a);
    a.click();
    URL.revokeObjectURL(a.href);
    a.remove();
  } catch {
    toast.error("Could not download PDF.");
  } finally {
    pdfDownloading.value = false;
  }
}

function syncEditFromInvoice() {
  const inv = invoice.value;
  if (!inv || inv.status !== "draft") {
    editDueAt.value = "";
    editLines.value = [];
    return;
  }
  editDueAt.value = inv.due_at ? String(inv.due_at).slice(0, 10) : "";
  const items = inv.items || [];
  editLines.value = items.length
    ? items.map((i) => ({
        description: i.description || "",
        quantity: String(i.quantity ?? 1),
        unit_price: (Number(i.unit_price_cents) / 100).toFixed(2),
      }))
    : [{ description: "", quantity: "1", unit_price: "0" }];
}

async function load() {
  loading.value = true;
  try {
    const { data } = await api.get(`/invoices/${props.id}`);
    invoice.value = data;
    syncEditFromInvoice();
    setCrmPageMeta({
      title: `Save Rack | ${data?.invoice_number || "Invoice"}`,
      description: "Invoice detail.",
    });
  } catch (e) {
    toast.errorFrom(e, "Could not load invoice.");
    invoice.value = null;
  } finally {
    loading.value = false;
  }
}

function dollarsToCents(s) {
  const n = Number.parseFloat(String(s).replace(/,/g, ""));
  if (!Number.isFinite(n)) return 0;
  return Math.round(n * 100);
}

function addDraftLine() {
  editLines.value = [
    ...editLines.value,
    { description: "", quantity: "1", unit_price: "0" },
  ];
}

function removeDraftLine(idx) {
  if (editLines.value.length <= 1) return;
  editLines.value = editLines.value.filter((_, i) => i !== idx);
}

async function saveDraft() {
  if (!invoice.value || invoice.value.status !== "draft") return;
  const items = [];
  for (const line of editLines.value) {
    const desc = (line.description || "").trim();
    if (!desc) continue;
    const qty = Number.parseFloat(line.quantity) || 0;
    const unitCents = dollarsToCents(line.unit_price);
    items.push({
      description: desc,
      quantity: qty,
      unit_price_cents: unitCents,
      line_total_cents: Math.max(0, Math.round(qty * unitCents)),
    });
  }
  draftSaving.value = true;
  try {
    await api.patch(`/invoices/${invoice.value.id}`, {
      due_at: editDueAt.value || null,
      items,
    });
    toast.success("Draft saved.");
    await load();
  } catch (e) {
    toast.errorFrom(e, "Could not save draft.");
  } finally {
    draftSaving.value = false;
  }
}

function statusBadgeClass(status) {
  const s = String(status || "").toLowerCase();
  if (s === "paid") return "bg-success-subtle text-success";
  if (s === "draft") return "bg-secondary-subtle text-secondary";
  if (s === "void") return "bg-dark-subtle text-secondary";
  if (s === "partial") return "bg-info-subtle text-info-emphasis";
  if (s === "sent") return "bg-primary-subtle text-primary-emphasis";
  return "bg-body-secondary text-body-secondary";
}

async function sendInvoice() {
  if (!invoice.value) return;
  try {
    await api.post(`/invoices/${invoice.value.id}/send`);
    toast.success("Invoice sent.");
    await load();
  } catch (e) {
    toast.errorFrom(e, "Could not send invoice.");
  }
}

function openPayModal() {
  if (!invoice.value) return;
  payAmount.value = invoice.value.balance_due_cents
    ? (Number(invoice.value.balance_due_cents) / 100).toFixed(2)
    : "";
  payModalOpen.value = true;
}

function closePayModal() {
  if (payBusy.value) return;
  payModalOpen.value = false;
}

async function confirmPay() {
  if (!invoice.value) return;
  const cents = dollarsToCents(payAmount.value);
  if (cents < 1) {
    toast.error("Enter a valid payment amount.");
    return;
  }
  payBusy.value = true;
  try {
    await api.post(`/invoices/${invoice.value.id}/record-payment`, {
      amount_cents: cents,
    });
    toast.success("Payment recorded.");
    closePayModal();
    await load();
  } catch (e) {
    toast.errorFrom(e, "Could not record payment.");
  } finally {
    payBusy.value = false;
  }
}

function openVoidModal() {
  voidModalOpen.value = true;
}

function closeVoidModal() {
  if (voidBusy.value) return;
  voidModalOpen.value = false;
}

async function confirmVoid() {
  if (!invoice.value) return;
  voidBusy.value = true;
  try {
    await api.post(`/invoices/${invoice.value.id}/void`);
    toast.success("Invoice voided.");
    closeVoidModal();
    await load();
  } catch (e) {
    toast.errorFrom(e, "Could not void invoice.");
  } finally {
    voidBusy.value = false;
  }
}

function openDeleteModal() {
  deleteModalOpen.value = true;
}

function closeDeleteModal() {
  if (deleteBusy.value) return;
  deleteModalOpen.value = false;
}

async function confirmDelete() {
  if (!invoice.value) return;
  deleteBusy.value = true;
  try {
    await api.delete(`/invoices/${invoice.value.id}`);
    toast.success("Invoice deleted.");
    router.replace("/billing/invoices");
  } catch (e) {
    toast.errorFrom(e, "Could not delete invoice.");
  } finally {
    deleteBusy.value = false;
  }
}

onMounted(() => {
  load();
});
</script>

<template>
  <div class="staff-page staff-page--wide billing-invoice-detail">
    <div v-if="loading" class="d-flex justify-content-center py-5">
      <CrmLoadingSpinner message="Loading invoice…" />
    </div>

    <template v-else-if="invoice">
      <div
        class="d-flex flex-column flex-lg-row flex-wrap align-items-stretch align-items-lg-center gap-3 mb-4"
      >
        <div class="d-flex flex-wrap align-items-center gap-2">
          <button
            type="button"
            class="btn btn-outline-secondary btn-sm"
            @click="router.push('/billing/invoices')"
          >
            ← Invoices
          </button>
          <button
            type="button"
            class="btn btn-outline-primary btn-sm"
            :disabled="pdfDownloading"
            @click="downloadInvoicePdf"
          >
            {{ pdfDownloading ? "Downloading…" : "Download PDF" }}
          </button>
        </div>
        <div class="d-flex flex-wrap gap-2 ms-lg-auto">
          <button
            v-if="canUpdate && invoice.status === 'draft'"
            type="button"
            class="btn btn-primary staff-page-primary btn-sm"
            @click="sendInvoice"
          >
            Send Invoice
          </button>
          <button
            v-if="
              canUpdate &&
              invoice.status !== 'draft' &&
              invoice.status !== 'paid' &&
              invoice.status !== 'void' &&
              invoice.balance_due_cents > 0
            "
            type="button"
            class="btn btn-outline-primary btn-sm"
            @click="openPayModal"
          >
            Record Payment
          </button>
          <button
            v-if="canUpdate && invoice.status !== 'draft' && invoice.status !== 'void'"
            type="button"
            class="btn btn-outline-danger btn-sm"
            @click="openVoidModal"
          >
            Void
          </button>
          <button
            v-if="canDelete && invoice.status === 'draft'"
            type="button"
            class="btn btn-outline-danger btn-sm"
            @click="openDeleteModal"
          >
            Delete Draft
          </button>
        </div>
      </div>

      <div class="row g-4">
        <div class="col-lg-8">
          <div
            class="staff-table-card staff-datatable-card staff-datatable-card--white p-4 billing-inv-preview"
          >
            <div class="billing-inv-preview-head border-bottom border-light pb-4 mb-4">
              <div class="row g-4 align-items-start">
                <div class="col-md-6 d-flex gap-3 align-items-start min-w-0">
                  <img
                    :src="invoiceBrandLogoUrl"
                    alt=""
                    class="billing-inv-logo flex-shrink-0"
                    width="48"
                    height="48"
                  />
                  <div class="min-w-0">
                    <div class="text-uppercase small fw-semibold text-secondary billing-inv-brand-kicker">
                      Save Rack
                    </div>
                    <div class="small text-secondary">Fulfillment billing</div>
                    <div class="mt-3 small">
                      <span class="text-secondary">Invoice to</span>
                      <div class="fw-semibold text-body mt-1">
                        {{ invoice.client_company_name || "—" }}
                      </div>
                    </div>
                  </div>
                </div>
                <div class="col-md-6 text-md-end min-w-0">
                  <h1 class="h4 fw-bold text-body mb-3">
                    {{ invoice.invoice_number }}
                  </h1>
                  <div class="small billing-inv-meta-list">
                    <div class="mb-1">
                      <span class="text-secondary">Issue date</span>
                      <span class="fw-medium ms-1">{{
                        formatInvoiceLongDate(invoice.issued_at)
                      }}</span>
                    </div>
                    <div class="mb-1">
                      <span class="text-secondary">Due date</span>
                      <span class="fw-medium ms-1">
                        {{
                          invoice.status === "draft" && canUpdate
                            ? editDueAt
                              ? formatInvoiceLongDate(editDueAt)
                              : "—"
                            : formatInvoiceLongDate(invoice.due_at)
                        }}
                      </span>
                    </div>
                    <div v-if="invoice.payment_terms" class="mb-1">
                      <span class="text-secondary">Terms</span>
                      <span class="fw-medium ms-1">{{ invoice.payment_terms }}</span>
                    </div>
                    <div v-if="invoice.po_number" class="mb-1">
                      <span class="text-secondary">PO number</span>
                      <span class="fw-medium ms-1">{{ invoice.po_number }}</span>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <div class="d-flex flex-wrap align-items-center gap-2 mb-4">
              <span
                class="badge rounded-pill text-capitalize fw-medium"
                :class="statusBadgeClass(invoice.status)"
              >
                {{ invoice.status }}
              </span>
              <span
                v-if="invoice.is_overdue"
                class="badge rounded-pill bg-danger-subtle text-danger-emphasis"
              >
                Overdue
              </span>
            </div>

            <h2 class="h6 fw-semibold mb-3">Line Items</h2>

            <template v-if="invoice.status === 'draft' && canUpdate">
              <div class="row g-3 mb-3">
                <div class="col-md-4">
                  <label class="form-label small" for="inv-detail-due">Due date</label>
                  <input
                    id="inv-detail-due"
                    v-model="editDueAt"
                    type="date"
                    class="form-control form-control-sm"
                    :disabled="draftSaving"
                  />
                </div>
              </div>
              <div class="d-flex justify-content-end mb-2">
                <button
                  type="button"
                  class="btn btn-sm btn-outline-secondary"
                  :disabled="draftSaving"
                  @click="addDraftLine"
                >
                  Add Line
                </button>
              </div>
              <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                  <thead>
                    <tr class="text-secondary small">
                      <th>Description</th>
                      <th class="text-end" style="width: 6rem">Qty</th>
                      <th class="text-end" style="width: 7rem">Unit</th>
                      <th class="text-end" style="width: 7rem">Line total</th>
                      <th style="width: 3rem" />
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="(line, idx) in editLines" :key="idx">
                      <td>
                        <input
                          v-model="line.description"
                          type="text"
                          class="form-control form-control-sm"
                          placeholder="Description"
                          :disabled="draftSaving"
                        />
                      </td>
                      <td class="text-end">
                        <input
                          v-model="line.quantity"
                          type="text"
                          class="form-control form-control-sm text-end"
                          :disabled="draftSaving"
                        />
                      </td>
                      <td class="text-end">
                        <input
                          v-model="line.unit_price"
                          type="text"
                          class="form-control form-control-sm text-end"
                          placeholder="0.00"
                          :disabled="draftSaving"
                        />
                      </td>
                      <td class="text-end small text-secondary">
                        {{
                          formatCents(
                            Math.max(
                              0,
                              Math.round(
                                (Number.parseFloat(line.quantity) || 0) *
                                  dollarsToCents(line.unit_price),
                              ),
                            ),
                            invoice.currency,
                          )
                        }}
                      </td>
                      <td class="text-end">
                        <button
                          type="button"
                          class="btn btn-link btn-sm text-danger p-0"
                          :disabled="draftSaving || editLines.length <= 1"
                          @click="removeDraftLine(idx)"
                        >
                          Remove
                        </button>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
              <div class="mt-3">
                <button
                  type="button"
                  class="btn btn-primary staff-page-primary btn-sm"
                  :disabled="draftSaving"
                  @click="saveDraft"
                >
                  {{ draftSaving ? "Saving…" : "Save Draft" }}
                </button>
              </div>
            </template>

            <template v-else>
              <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                  <thead>
                    <tr class="text-secondary small">
                      <th>Description</th>
                      <th class="text-end">Qty</th>
                      <th class="text-end">Unit</th>
                      <th class="text-end">Line total</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="item in invoice.items" :key="item.id">
                      <td>{{ item.description }}</td>
                      <td class="text-end">{{ item.quantity }}</td>
                      <td class="text-end">
                        {{ formatCents(item.unit_price_cents, invoice.currency) }}
                      </td>
                      <td class="text-end fw-medium">
                        {{ formatCents(item.line_total_cents, invoice.currency) }}
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </template>
            <div class="d-flex justify-content-end mt-3">
              <div class="text-end small" style="min-width: 12rem">
                <div class="d-flex justify-content-between">
                  <span class="text-secondary">Subtotal</span>
                  <span>{{ formatCents(invoice.subtotal_cents, invoice.currency) }}</span>
                </div>
                <div class="d-flex justify-content-between">
                  <span class="text-secondary">Tax</span>
                  <span>{{ formatCents(invoice.tax_cents, invoice.currency) }}</span>
                </div>
                <div class="d-flex justify-content-between fw-semibold mt-1">
                  <span>Total</span>
                  <span>{{ formatCents(invoice.total_cents, invoice.currency) }}</span>
                </div>
                <div class="d-flex justify-content-between">
                  <span class="text-secondary">Paid</span>
                  <span>{{ formatCents(invoice.amount_paid_cents, invoice.currency) }}</span>
                </div>
                <div class="d-flex justify-content-between fw-semibold text-primary">
                  <span>Balance due</span>
                  <span>{{ formatCents(invoice.balance_due_cents, invoice.currency) }}</span>
                </div>
              </div>
            </div>
            <template v-if="invoice.customer_notes || invoice.internal_notes">
              <hr />
              <p v-if="invoice.customer_notes" class="small mb-2">
                <span class="text-secondary">Customer notes:</span>
                {{ invoice.customer_notes }}
              </p>
              <p v-if="invoice.internal_notes" class="small mb-0">
                <span class="text-secondary">Internal notes:</span>
                {{ invoice.internal_notes }}
              </p>
            </template>
          </div>
        </div>
        <div class="col-lg-4">
          <div class="staff-surface p-4">
            <h2 class="h6 fw-semibold mb-3">Activity</h2>
            <ul class="list-unstyled small mb-0">
              <li
                v-for="h in invoice.histories"
                :key="h.id"
                class="border-bottom border-light py-2"
              >
                <div class="fw-medium text-capitalize">{{ h.action.replace(/_/g, " ") }}</div>
                <div class="text-secondary">
                  {{ h.user?.name || "System" }} ·
                  {{ new Date(h.created_at).toLocaleString() }}
                </div>
              </li>
              <li v-if="!invoice.histories?.length" class="text-secondary py-2">No history.</li>
            </ul>
          </div>
        </div>
      </div>
    </template>

    <div v-else class="alert alert-warning">Invoice not found.</div>

    <Teleport to="body">
      <Transition name="crm-vx-confirm">
        <div
          v-if="payModalOpen"
          class="crm-vx-modal-overlay"
          role="dialog"
          aria-modal="true"
          @click.self="closePayModal"
        >
          <div class="crm-vx-modal crm-vx-modal--sm" @click.stop>
            <header class="crm-vx-modal__head">
              <h2 class="crm-vx-modal__title">Record Payment</h2>
            </header>
            <div class="crm-vx-modal__body">
              <label class="form-label">Amount (USD)</label>
              <input v-model="payAmount" type="text" class="form-control" />
            </div>
            <footer class="crm-vx-modal__footer d-flex gap-2 justify-content-end">
              <button
                type="button"
                class="crm-vx-modal-btn crm-vx-modal-btn--secondary"
                :disabled="payBusy"
                @click="closePayModal"
              >
                Cancel
              </button>
              <button
                type="button"
                class="crm-vx-modal-btn crm-vx-modal-btn--primary"
                :disabled="payBusy"
                @click="confirmPay"
              >
                {{ payBusy ? "Saving…" : "Record" }}
              </button>
            </footer>
          </div>
        </div>
      </Transition>
    </Teleport>

    <ConfirmModal
      :open="voidModalOpen"
      title="Void Invoice?"
      subtitle="This invoice cannot be collected after voiding."
      message="Void this invoice?"
      confirm-label="Void"
      cancel-label="Cancel"
      :busy="voidBusy"
      danger
      @close="closeVoidModal"
      @confirm="confirmVoid"
    />

    <ConfirmModal
      :open="deleteModalOpen"
      title="Delete Draft?"
      :message="
        invoice ? `Delete ${invoice.invoice_number}? This cannot be undone.` : ''
      "
      confirm-label="Delete"
      cancel-label="Cancel"
      :busy="deleteBusy"
      danger
      @close="closeDeleteModal"
      @confirm="confirmDelete"
    />
  </div>
</template>

<style scoped>
.billing-inv-preview {
  box-shadow: 0 0.125rem 0.5rem rgba(15, 23, 42, 0.06);
}
.billing-inv-logo {
  object-fit: contain;
}
.billing-inv-brand-kicker {
  letter-spacing: 0.06em;
  font-size: 0.7rem;
}
.billing-inv-meta-list .text-secondary {
  min-width: 5.5rem;
  display: inline-block;
}
@media (min-width: 768px) {
  .billing-inv-meta-list .text-secondary {
    text-align: right;
    margin-right: 0.35rem;
  }
}
</style>
