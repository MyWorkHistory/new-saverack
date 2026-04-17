<script setup>
import { computed, inject, onMounted, ref, watch } from "vue";

import { useRouter } from "vue-router";
import api from "../../services/api";
import { BRAND_MARK_SRC } from "../../utils/brandAssets.js";
import ConfirmModal from "../../components/common/ConfirmModal.vue";
import CrmIconRowActions from "../../components/common/CrmIconRowActions.vue";
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
const draftEditMode = ref(false);

const payModalOpen = ref(false);
const payAmount = ref("");
const payType = ref("ACH");
const payDate = ref("");
const payNotes = ref("");
const payBusy = ref(false);
const payContextBusy = ref(false);
const payFundsCents = ref(0);
const payOpenBalanceCents = ref(0);
const payPastDueBalanceCents = ref(0);
const payPendingBalanceCents = ref(0);
const payRows = ref([]);
const paySelectedInvoiceIds = ref([]);
const payFilterStatus = ref("all");

const voidModalOpen = ref(false);
const voidBusy = ref(false);

const deleteModalOpen = ref(false);
const deleteBusy = ref(false);

const pdfDownloading = ref(false);
const copyLinkBusy = ref(false);
const selectedTableRowId = ref("");
const sendEmailBusy = ref(false);
const sendEmailModalOpen = ref(false);
const sendEmailMessage = ref("");
const sendEmailRecipients = ref([]);
const sendWhatsappBusy = ref(false);
const sendWhatsappModalOpen = ref(false);
const sendWhatsappType = ref("send_invoice");
const sendWhatsappMessage = ref("");
const lineMenuOpenId = ref(null);
const groupMenuOpenId = ref(null);
const lineEditModalOpen = ref(false);
const lineEditBusy = ref(false);
const lineDeleteModalOpen = ref(false);
const lineDeleteBusy = ref(false);
const lineEditTarget = ref(null);
const lineEditForm = ref({
  description: "",
  display_name: "",
  category: "",
  subtype: "",
  sku: "",
  service_code: "",
  quantity: "1.0",
  unit: "",
  unit_price: "0.00",
});
const addItemModalOpen = ref(false);
const addItemBusy = ref(false);
const addItemForm = ref({
  description: "",
  display_name: "",
  category: "",
  subtype: "",
  sku: "",
  service_code: "",
  quantity: "1.0",
  unit: "",
  unit_price: "0.00",
});
const ccFeeModalOpen = ref(false);
const ccFeeBusy = ref(false);
const ccFeeLabel = ref("Credit Card Fee");
const ccFeeAmount = ref("");
const groupEditModalOpen = ref(false);
const groupEditBusy = ref(false);
const groupDeleteModalOpen = ref(false);
const groupDeleteBusy = ref(false);
const groupEditTarget = ref(null);
const groupEditLines = ref([]);

const invoiceLogoSrc = computed(() => BRAND_MARK_SRC());
const activityCardRef = ref(null);

const canPayInvoice = computed(
  () =>
    !!invoice.value &&
    canUpdate.value &&
    invoice.value.status !== "draft" &&
    invoice.value.status !== "paid" &&
    invoice.value.status !== "void" &&
    Number(invoice.value.balance_due_cents) > 0,
);

const canAddCharge = computed(
  () => !!invoice.value && canUpdate.value && invoice.value.status !== "void",
);

const canVoidInvoice = computed(
  () =>
    !!invoice.value &&
    canUpdate.value &&
    invoice.value.status !== "draft" &&
    invoice.value.status !== "void",
);

const canShareInvoice = computed(() => !!invoice.value && invoice.value.status !== "void");

const canSendWhatsapp = computed(
  () => !!invoice.value && canUpdate.value && invoice.value.status !== "void",
);

const payCanAddFunds = computed(() => {
  return (
    !!payDate.value &&
    !!String(payType.value || "").trim() &&
    dollarsToCents(payAmount.value) > 0
  );
});

const payCanSubmit = computed(
  () => payFundsCents.value > 0 && paySelectedInvoiceIds.value.length > 0,
);

const payFilteredRows = computed(() => {
  if (payFilterStatus.value === "past_due") {
    return payRows.value.filter((row) => row.is_overdue);
  }
  if (payFilterStatus.value === "pending") {
    return [];
  }
  if (payFilterStatus.value === "open") {
    return payRows.value.filter((row) => !row.is_overdue);
  }
  return payRows.value;
});

const paySelectedBalanceCents = computed(() =>
  payRows.value
    .filter((row) => paySelectedInvoiceIds.value.includes(row.id))
    .reduce((sum, row) => sum + Number(row.balance_cents || 0), 0),
);

const payAvailablePreviewCents = computed(
  () => payFundsCents.value - paySelectedBalanceCents.value,
);

const emailRecipientOptionList = computed(() =>
  Array.isArray(invoice.value?.email_recipient_options) ? invoice.value.email_recipient_options : [],
);

const allEmailRecipientsSelected = computed(() => {
  const opts = emailRecipientOptionList.value;
  if (!opts.length) return false;
  return opts.every((e) => sendEmailRecipients.value.includes(e));
});

const someEmailRecipientsSelected = computed(() => sendEmailRecipients.value.length > 0);

function formatQtyDisplay(v) {
  const n = Number(v);
  if (!Number.isFinite(n)) return "0.000";
  if (Math.abs(n) < 1) return n.toFixed(3);
  return Number.isInteger(n) ? String(n) : n.toFixed(2);
}

const invoiceTableRows = computed(() => invoice.value?.presentation?.rows || []);

const selectedTableRow = computed(() => {
  if (!selectedTableRowId.value) return null;
  return invoiceTableRows.value.find((r) => r.id === selectedTableRowId.value) || null;
});

const selectedTableRowDetails = computed(() => selectedTableRow.value?.details || []);

function openTableRow(row) {
  selectedTableRowId.value = row.id;
  lineMenuOpenId.value = null;
  groupMenuOpenId.value = null;
}

function closeTableRow() {
  selectedTableRowId.value = "";
  lineMenuOpenId.value = null;
  groupMenuOpenId.value = null;
}

function jumpToHistory() {
  activityCardRef.value?.scrollIntoView({ behavior: "smooth", block: "start" });
}

function payDueInLabel(days) {
  const n = Number(days);
  if (!Number.isFinite(n)) return "—";
  const abs = Math.abs(n);
  if (abs === 0) return "0 days";
  return `${abs} day${abs === 1 ? "" : "s"}`;
}

function isPastDueByLogic(inv) {
  if (!inv) return false;
  const dueIn = Number(inv.due_in);
  if (Number.isFinite(dueIn) && dueIn < 0) return true;
  const dueAt = inv.due_at ? new Date(String(inv.due_at)) : null;
  if (!dueAt || Number.isNaN(dueAt.getTime())) return false;
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  dueAt.setHours(0, 0, 0, 0);
  return dueAt < today;
}

const statusDisplayText = computed(() => {
  const inv = invoice.value;
  if (!inv) return "";
  const raw = String(inv.status || "").toLowerCase();
  if (
    (raw === "open" || raw === "sent" || raw === "partial") &&
    isPastDueByLogic(inv)
  ) {
    return "Past Due";
  }
  return String(inv.status || "")
    .replace(/_/g, " ")
    .replace(/\b\w/g, (c) => c.toUpperCase());
});

const invoiceDateRangeLabel = computed(() => {
  const inv = invoice.value;
  if (!inv) return "—";
  if (inv.invoice_date_label && String(inv.invoice_date_label).trim() !== "") {
    return String(inv.invoice_date_label);
  }
  const from = inv.invoice_date_from;
  const to = inv.invoice_date_to;
  if (from && to) return `${formatInvoiceShortDate(from)} - ${formatInvoiceShortDate(to)}`;
  if (from) return formatInvoiceShortDate(from);
  if (to) return formatInvoiceShortDate(to);
  return formatInvoiceShortDate(inv.invoice_date || inv.issued_at);
});

const invoiceDateShort = new Intl.DateTimeFormat("en-US", {
  year: "numeric",
  month: "2-digit",
  day: "2-digit",
});

function formatInvoiceShortDate(iso) {
  if (!iso) return "—";
  const s = String(iso);
  const d = /^\d{4}-\d{2}-\d{2}$/.test(s) ? new Date(`${s}T12:00:00`) : new Date(s);
  if (Number.isNaN(d.getTime())) return "—";
  return invoiceDateShort.format(d);
}

function formatHistoryTimestamp(iso) {
  if (!iso) return "—";
  const value = new Date(String(iso));
  if (Number.isNaN(value.getTime())) return "—";
  const date = invoiceDateShort.format(value);
  let hour = value.getHours();
  const minute = String(value.getMinutes()).padStart(2, "0");
  const second = String(value.getSeconds()).padStart(2, "0");
  const meridiem = hour >= 12 ? "PM" : "AM";
  hour = hour % 12 || 12;
  return `${date} ${hour}:${minute}:${second} ${meridiem}`;
}

function formatQtyOneDecimal(v) {
  const n = Number(v);
  if (!Number.isFinite(n)) return "0.0";
  return n.toFixed(1);
}

async function downloadInvoicePdf() {
  if (!invoice.value?.id || pdfDownloading.value) return;
  pdfDownloading.value = true;
  try {
    const res = await api.get(`/invoices/${invoice.value.id}/pdf`, {
      responseType: "blob",
      headers: { Accept: "application/pdf" },
    });
    const blob = res.data;
    const a = document.createElement("a");
    const baseName = String(
      `Fulfillment Summary - Invoice #${invoice.value.invoice_number || "invoice"}`,
    ).replace(
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

async function copyCustomerLink() {
  if (!invoice.value?.id || copyLinkBusy.value) return;
  copyLinkBusy.value = true;
  try {
    let url = invoice.value.customer_view_url;
    if (!url) {
      const { data } = await api.post(`/invoices/${invoice.value.id}/share-link`);
      url = data?.customer_view_url;
      if (url) {
        invoice.value = {
          ...invoice.value,
          customer_view_url: data.customer_view_url,
          customer_pdf_url: data.customer_pdf_url,
        };
      }
    }
    if (!url) {
      toast.error("Could not create customer link.");
      return;
    }
    try {
      await navigator.clipboard.writeText(url);
    } catch {
      toast.error("Could not access clipboard.");
      return;
    }
    toast.success("Customer link copied.");
  } catch (e) {
    toast.errorFrom(e, "Could not copy customer link.");
  } finally {
    copyLinkBusy.value = false;
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
        description: i.display_name || i.description || "",
        sku: i.sku != null && String(i.sku) !== "" ? String(i.sku) : "",
        quantity: formatQtyOneDecimal(i.quantity ?? 1),
        unit_price: (Number(i.unit_price_cents) / 100).toFixed(2),
        category: i.category || null,
        subtype: i.subtype || null,
        group_key: i.group_key || null,
        display_name: i.display_name || null,
        service_code: i.service_code || null,
        unit: i.unit || null,
        metadata: i.metadata || null,
      }))
    : [
        {
          description: "",
          sku: "",
          quantity: "1.0",
          unit_price: "0.00",
        },
      ];
}

async function load() {
  loading.value = true;
  try {
    const { data } = await api.get(`/invoices/${props.id}`);
    invoice.value = data;
    if (data?.status !== "draft") {
      draftEditMode.value = false;
    }
    if (selectedTableRowId.value) {
      const stillExists = invoiceTableRows.value.some((r) => r.id === selectedTableRowId.value);
      if (!stillExists) selectedTableRowId.value = "";
    }
    lineMenuOpenId.value = null;
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
    { description: "", sku: "", quantity: "1.0", unit_price: "0.00" },
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
    const qty = Number.parseFloat(String(line.quantity).replace(/,/g, "")) || 0;
    const unitCents = dollarsToCents(line.unit_price);
    const skuTrim = (line.sku || "").trim();
    items.push({
      description: desc,
      sku: skuTrim || null,
      category: line.category || null,
      subtype: line.subtype || null,
      group_key: line.group_key || null,
      display_name: line.display_name || desc,
      service_code: line.service_code || null,
      quantity: qty,
      unit: line.unit || null,
      unit_price_cents: unitCents,
      line_total_cents: Math.max(0, Math.round(qty * unitCents)),
      metadata: line.metadata || null,
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
  if (s === "past due") return "bg-danger-subtle text-danger-emphasis";
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

function openSendEmailModal() {
  sendEmailMessage.value = "";
  sendEmailRecipients.value = [...emailRecipientOptionList.value];
  sendEmailModalOpen.value = true;
}

function selectAllEmailRecipients() {
  sendEmailRecipients.value = [...emailRecipientOptionList.value];
}

function unselectAllEmailRecipients() {
  sendEmailRecipients.value = [];
}

function closeSendEmailModal() {
  if (sendEmailBusy.value) return;
  sendEmailModalOpen.value = false;
}

async function confirmSendEmail() {
  if (!invoice.value) return;
  if (!sendEmailRecipients.value.length) {
    toast.error("Select at least one email recipient.");
    return;
  }
  sendEmailBusy.value = true;
  try {
    const { data } = await api.post(`/invoices/${invoice.value.id}/email`, {
      message: sendEmailMessage.value || null,
      recipients: sendEmailRecipients.value,
    });
    const toCount = Array.isArray(data?.recipients) ? data.recipients.length : 0;
    toast.success(toCount ? `Email sent to ${toCount} recipient(s).` : "Email sent.");
    closeSendEmailModal();
    await load();
  } catch (e) {
    toast.errorFrom(e, "Could not send email.");
  } finally {
    sendEmailBusy.value = false;
  }
}

function buildWhatsappDefaultMessage(type) {
  const inv = invoice.value;
  if (!inv) return "";
  const link = inv.customer_view_url || "";
  const range = invoiceDateRangeLabel.value;
  const balance = formatCents(inv.balance_due_cents || 0, inv.currency || "USD");
  if (type === "invoice_reminder") {
    return `Invoice reminder: ${inv.invoice_number} (${range}). Balance due ${balance}. ${link}`.trim();
  }
  if (type === "send_storage_invoice") {
    return `Hi! Here is your storage invoice: ${link}\nLet me know if you have any questions-thanks!`.trim();
  }
  return `Hi! Here is your invoice for ${range}: ${link}\nLet me know if you have any questions-thanks!`.trim();
}

async function ensureShareLinkForMessaging() {
  if (!invoice.value?.id || invoice.value.customer_view_url) return;
  const { data } = await api.post(`/invoices/${invoice.value.id}/share-link`);
  if (data?.customer_view_url) {
    invoice.value = {
      ...invoice.value,
      customer_view_url: data.customer_view_url,
      customer_pdf_url: data.customer_pdf_url,
    };
  }
}

function openSendWhatsappModal() {
  sendWhatsappType.value = "send_invoice";
  sendWhatsappMessage.value = buildWhatsappDefaultMessage(sendWhatsappType.value);
  sendWhatsappModalOpen.value = true;
  ensureShareLinkForMessaging()
    .then(() => {
      sendWhatsappMessage.value = buildWhatsappDefaultMessage(sendWhatsappType.value);
    })
    .catch(() => {});
}

function closeSendWhatsappModal() {
  if (sendWhatsappBusy.value) return;
  sendWhatsappModalOpen.value = false;
}

async function confirmSendWhatsapp() {
  if (!invoice.value) return;
  sendWhatsappBusy.value = true;
  try {
    if (!sendWhatsappMessage.value.trim()) {
      sendWhatsappMessage.value = buildWhatsappDefaultMessage(sendWhatsappType.value);
    }
    await api.post(`/invoices/${invoice.value.id}/whatsapp`, {
      type: sendWhatsappType.value,
      message: sendWhatsappMessage.value || null,
    });
    toast.success("WhatsApp message sent.");
    closeSendWhatsappModal();
    await load();
  } catch (e) {
    toast.errorFrom(e, "Could not send via WhatsApp.");
  } finally {
    sendWhatsappBusy.value = false;
  }
}

watch(sendWhatsappType, (value) => {
  if (!sendWhatsappModalOpen.value) return;
  sendWhatsappMessage.value = buildWhatsappDefaultMessage(value);
});

function toggleLineMenu(lineId) {
  lineMenuOpenId.value = lineMenuOpenId.value === lineId ? null : lineId;
}

function toggleGroupMenu(rowId) {
  groupMenuOpenId.value = groupMenuOpenId.value === rowId ? null : rowId;
}

function openGroupEditModal(row) {
  groupMenuOpenId.value = null;
  groupEditTarget.value = row;
  groupEditLines.value = Array.isArray(row.details)
    ? row.details.map((line) => ({
        description: line.description || line.name || "",
        display_name: line.display_name || line.name || "",
        category: line.category || "",
        subtype: line.subtype || "",
        sku: line.sku || "",
        service_code: line.service_code || "",
        quantity: formatQtyOneDecimal(line.qty ?? 1),
        unit: line.unit || "",
        unit_price: (Number(line.price_cents || 0) / 100).toFixed(2),
        metadata: line.metadata && typeof line.metadata === "object" ? { ...line.metadata } : {},
      }))
    : [];
  groupEditModalOpen.value = true;
}

function closeGroupEditModal() {
  if (groupEditBusy.value) return;
  groupEditModalOpen.value = false;
  groupEditTarget.value = null;
}

function addGroupEditLine() {
  groupEditLines.value = [
    ...groupEditLines.value,
    {
      description: "",
      display_name: "",
      category: "",
      subtype: "",
      sku: "",
      service_code: "",
      quantity: "1.0",
      unit: "",
      unit_price: "0.00",
      metadata: {},
    },
  ];
}

function removeGroupEditLine(idx) {
  groupEditLines.value = groupEditLines.value.filter((_, i) => i !== idx);
}

async function confirmGroupEdit() {
  if (!invoice.value || !groupEditTarget.value?.line_group_key) return;
  groupEditBusy.value = true;
  try {
    const payloadItems = groupEditLines.value.map((line) => {
      const qty = Number.parseFloat(String(line.quantity).replace(/,/g, "")) || 0;
      const unitCents = dollarsToCents(line.unit_price);
      return {
        description: line.description || line.display_name || "Item",
        display_name: line.display_name || line.description || "Item",
        category: line.category || null,
        subtype: line.subtype || null,
        sku: line.sku || null,
        service_code: line.service_code || null,
        quantity: qty,
        unit: line.unit || null,
        unit_price_cents: unitCents,
        line_total_cents: Math.max(0, Math.round(qty * unitCents)),
        metadata: line.metadata || null,
      };
    });
    await api.put(
      `/invoices/${invoice.value.id}/line-groups/${encodeURIComponent(groupEditTarget.value.line_group_key)}`,
      { items: payloadItems },
    );
    toast.success("Grouped line items updated.");
    closeGroupEditModal();
    await load();
  } catch (e) {
    toast.errorFrom(e, "Could not update grouped line items.");
  } finally {
    groupEditBusy.value = false;
  }
}

function openGroupDeleteModal(row) {
  groupMenuOpenId.value = null;
  groupEditTarget.value = row;
  groupDeleteModalOpen.value = true;
}

function closeGroupDeleteModal() {
  if (groupDeleteBusy.value) return;
  groupDeleteModalOpen.value = false;
}

async function confirmGroupDelete() {
  if (!invoice.value || !groupEditTarget.value?.line_group_key) return;
  groupDeleteBusy.value = true;
  try {
    await api.delete(
      `/invoices/${invoice.value.id}/line-groups/${encodeURIComponent(groupEditTarget.value.line_group_key)}`,
    );
    toast.success("Grouped line items deleted.");
    closeGroupDeleteModal();
    await load();
  } catch (e) {
    toast.errorFrom(e, "Could not delete grouped line items.");
  } finally {
    groupDeleteBusy.value = false;
  }
}

function openLineEditModal(line) {
  lineMenuOpenId.value = null;
  lineEditTarget.value = line;
  lineEditForm.value = {
    description: line.description || line.name || "",
    display_name: line.display_name || line.name || "",
    category: line.category || "",
    subtype: line.subtype || "",
    sku: line.sku || "",
    service_code: line.service_code || "",
    quantity: formatQtyOneDecimal(line.qty ?? 1),
    unit: line.unit || "",
    unit_price: (Number(line.price_cents || 0) / 100).toFixed(2),
  };
  lineEditModalOpen.value = true;
}

function closeLineEditModal() {
  if (lineEditBusy.value) return;
  lineEditModalOpen.value = false;
  lineEditTarget.value = null;
}

async function confirmLineEdit() {
  if (!invoice.value || !lineEditTarget.value) return;
  lineEditBusy.value = true;
  try {
    const qty = Number.parseFloat(String(lineEditForm.value.quantity).replace(/,/g, "")) || 0;
    const unitCents = dollarsToCents(lineEditForm.value.unit_price);
    await api.put(`/invoices/${invoice.value.id}/items/${lineEditTarget.value.id}`, {
      description: lineEditForm.value.description || lineEditTarget.value.name,
      display_name: lineEditForm.value.display_name || lineEditForm.value.description,
      category: lineEditForm.value.category || null,
      subtype: lineEditForm.value.subtype || null,
      group_key: lineEditTarget.value.group_key || null,
      sku: lineEditForm.value.sku || null,
      service_code: lineEditForm.value.service_code || null,
      quantity: qty,
      unit: lineEditForm.value.unit || null,
      unit_price_cents: unitCents,
      line_total_cents: Math.max(0, Math.round(qty * unitCents)),
      metadata: lineEditTarget.value.metadata || null,
    });
    toast.success("Line item updated.");
    closeLineEditModal();
    await load();
  } catch (e) {
    toast.errorFrom(e, "Could not update line item.");
  } finally {
    lineEditBusy.value = false;
  }
}

function openLineDeleteModal(line) {
  lineMenuOpenId.value = null;
  lineEditTarget.value = line;
  lineDeleteModalOpen.value = true;
}

function closeLineDeleteModal() {
  if (lineDeleteBusy.value) return;
  lineDeleteModalOpen.value = false;
}

async function confirmLineDelete() {
  if (!invoice.value || !lineEditTarget.value) return;
  lineDeleteBusy.value = true;
  try {
    await api.delete(`/invoices/${invoice.value.id}/items/${lineEditTarget.value.id}`);
    toast.success("Line item deleted.");
    closeLineDeleteModal();
    await load();
  } catch (e) {
    toast.errorFrom(e, "Could not delete line item.");
  } finally {
    lineDeleteBusy.value = false;
  }
}

function openAddItemModal() {
  addItemForm.value = {
    description: "",
    display_name: "",
    category: "",
    subtype: "",
    sku: "",
    service_code: "",
    quantity: "1.0",
    unit: "",
    unit_price: "0.00",
  };
  addItemModalOpen.value = true;
}

function closeAddItemModal() {
  if (addItemBusy.value) return;
  addItemModalOpen.value = false;
}

async function confirmAddItem() {
  if (!invoice.value) return;
  addItemBusy.value = true;
  try {
    const qty = Number.parseFloat(String(addItemForm.value.quantity).replace(/,/g, "")) || 0;
    const unitCents = dollarsToCents(addItemForm.value.unit_price);
    await api.post(`/invoices/${invoice.value.id}/add-item`, {
      description: addItemForm.value.description,
      display_name: addItemForm.value.display_name || addItemForm.value.description,
      category: addItemForm.value.category || null,
      subtype: addItemForm.value.subtype || null,
      sku: addItemForm.value.sku || null,
      service_code: addItemForm.value.service_code || null,
      quantity: qty,
      unit: addItemForm.value.unit || null,
      unit_price_cents: unitCents,
      line_total_cents: Math.max(0, Math.round(qty * unitCents)),
    });
    toast.success("Item added to invoice.");
    closeAddItemModal();
    await load();
  } catch (e) {
    toast.errorFrom(e, "Could not add item.");
  } finally {
    addItemBusy.value = false;
  }
}

function openCcFeeModal() {
  ccFeeLabel.value = "Credit Card Fee";
  ccFeeAmount.value = "";
  ccFeeModalOpen.value = true;
}

function closeCcFeeModal() {
  if (ccFeeBusy.value) return;
  ccFeeModalOpen.value = false;
}

async function confirmCcFee() {
  if (!invoice.value) return;
  const amountCents = dollarsToCents(ccFeeAmount.value);
  if (amountCents < 1) {
    toast.error("Enter a valid fee amount.");
    return;
  }
  ccFeeBusy.value = true;
  try {
    await api.post(`/invoices/${invoice.value.id}/add-cc-fee`, {
      amount_cents: amountCents,
      label: ccFeeLabel.value || "Credit Card Fee",
    });
    toast.success("CC fee added.");
    closeCcFeeModal();
    await load();
  } catch (e) {
    toast.errorFrom(e, "Could not add CC fee.");
  } finally {
    ccFeeBusy.value = false;
  }
}

function closePayModal() {
  if (payBusy.value || payContextBusy.value) return;
  payModalOpen.value = false;
}

async function openPayModal() {
  if (!invoice.value) return;
  payAmount.value = "";
  payType.value = "";
  payDate.value = new Date().toISOString().slice(0, 10);
  payNotes.value = "";
  payFundsCents.value = 0;
  payOpenBalanceCents.value = 0;
  payPastDueBalanceCents.value = 0;
  payPendingBalanceCents.value = 0;
  payRows.value = [];
  paySelectedInvoiceIds.value = [];
  payFilterStatus.value = "all";
  payModalOpen.value = true;
  payContextBusy.value = true;
  try {
    const { data } = await api.get(`/invoices/${invoice.value.id}/pay-context`);
    payRows.value = Array.isArray(data?.rows) ? data.rows : [];
    payOpenBalanceCents.value = Number(data?.open_balance_cents || 0);
    payPastDueBalanceCents.value = Number(data?.past_due_balance_cents || 0);
    payPendingBalanceCents.value = Number(data?.pending_balance_cents || 0);
  } catch (e) {
    toast.errorFrom(e, "Could not load payment info.");
    payModalOpen.value = false;
  } finally {
    payContextBusy.value = false;
  }
}

function addFundsToPayPool() {
  if (!payCanAddFunds.value) {
    toast.error("Enter payment date, type, and amount first.");
    return;
  }
  payFundsCents.value += dollarsToCents(payAmount.value);
  payAmount.value = "";
}

function togglePayInvoiceSelection(invoiceId) {
  const id = Number(invoiceId);
  if (!Number.isFinite(id)) return;
  if (paySelectedInvoiceIds.value.includes(id)) {
    paySelectedInvoiceIds.value = paySelectedInvoiceIds.value.filter((value) => value !== id);
    return;
  }
  paySelectedInvoiceIds.value = [...paySelectedInvoiceIds.value, id];
}

async function confirmPay() {
  if (!invoice.value) return;
  if (!payCanSubmit.value) {
    toast.error("Add funds and select at least one invoice.");
    return;
  }
  payBusy.value = true;
  try {
    const { data } = await api.post(`/invoices/${invoice.value.id}/pay-allocate`, {
      amount_cents: payFundsCents.value,
      invoice_ids: paySelectedInvoiceIds.value,
      payment_type: payType.value || null,
      payment_date: payDate.value || null,
      notes: payNotes.value || null,
    });
    const allocations = Array.isArray(data?.allocations) ? data.allocations.length : 0;
    toast.success(allocations > 0 ? "Payment allocated." : "No payment applied.");
    closePayModal();
    await load();
  } catch (e) {
    toast.errorFrom(e, "Could not allocate payment.");
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
          <button
            v-if="invoice.status !== 'void'"
            type="button"
            class="btn btn-outline-secondary btn-sm"
            :disabled="copyLinkBusy"
            @click="copyCustomerLink"
          >
            {{ copyLinkBusy ? "Working…" : "Copy Customer Link" }}
          </button>
        </div>
      </div>

      <div class="row g-4">
        <div class="col-lg-8">
          <div
            class="staff-table-card staff-datatable-card staff-datatable-card--white p-4 p-md-5 billing-inv-preview billing-inv-vuexy"
          >
            <div class="billing-inv-preview-head border-bottom pb-4 mb-4">
              <div class="row g-4 align-items-start">
                <div class="col-lg-6 d-flex gap-3 align-items-start min-w-0">
                  <img
                    :src="invoiceLogoSrc"
                    alt=""
                    class="billing-inv-logo flex-shrink-0 rounded-1"
                    width="44"
                    height="44"
                  />
                  <div class="min-w-0">
                    <div class="fw-bold text-body fs-5 mb-1">Save Rack</div>
                    <div class="small text-secondary lh-sm billing-inv-issuer-lines">
                      <div>Fulfillment billing</div>
                      <div class="mt-2">United States</div>
                    </div>
                  </div>
                </div>
                <div class="col-lg-6 text-lg-end min-w-0">
                  <div class="text-secondary small text-uppercase fw-semibold billing-inv-invoice-label">
                    Invoice
                  </div>
                  <h1 class="h3 fw-bold text-body mb-3">
                    {{ invoice.invoice_number }}
                  </h1>
                  <div class="small billing-inv-meta-list">
                    <div class="mb-1">
                      <span class="text-secondary">Invoice Date</span>
                      <span class="fw-medium ms-1">{{ invoiceDateRangeLabel }}</span>
                    </div>
                    <div class="mb-1">
                      <span class="text-secondary">Date due</span>
                      <span class="fw-medium ms-1">
                        {{
                          invoice.status === "draft" && canUpdate
                            ? editDueAt
                              ? formatInvoiceShortDate(editDueAt)
                              : "—"
                            : formatInvoiceShortDate(invoice.due_at)
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

            <div class="row g-4 mb-4">
              <div class="col-md-6">
                <div class="billing-inv-section-label">Invoice to</div>
                <div class="fw-semibold text-body">
                  {{ invoice.client_company_name || "—" }}
                </div>
              </div>
              <div class="col-md-6 text-md-end">
                <div class="billing-inv-section-label">Bill to</div>
                <div class="fw-semibold text-body fs-5">
                  Total due:
                  {{ formatCents(invoice.balance_due_cents, invoice.currency) }}
                </div>
                <div class="small text-secondary mt-2 billing-inv-billto-note">
                  Bank / wire instructions can be added when billing goes live.
                </div>
              </div>
            </div>

            <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
              <span
                class="badge rounded-pill text-capitalize fw-medium"
                :class="statusBadgeClass(statusDisplayText)"
              >
                {{ statusDisplayText }}
              </span>
            </div>

            <h2 class="h6 fw-semibold mb-3">Line items</h2>
            <template v-if="invoice.status === 'draft' && canUpdate && false">
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
              <div class="table-responsive billing-inv-items-wrap">
                <table class="table table-sm align-middle mb-0 billing-inv-items-table">
                  <thead>
                    <tr>
                      <th>Item</th>
                      <th>Description</th>
                      <th class="text-end">Cost</th>
                      <th class="text-end" style="width: 5.5rem">Qty</th>
                      <th class="text-end">Price</th>
                      <th style="width: 3.25rem" />
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="(line, idx) in editLines" :key="idx">
                      <td>
                        <input
                          v-model="line.description"
                          type="text"
                          class="form-control form-control-sm"
                          placeholder="Item"
                          :disabled="draftSaving"
                        />
                      </td>
                      <td>
                        <input
                          v-model="line.sku"
                          type="text"
                          class="form-control form-control-sm"
                          placeholder="SKU or note"
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
                      <td class="text-end">
                        <input
                          v-model="line.quantity"
                          type="text"
                          inputmode="decimal"
                          class="form-control form-control-sm text-end"
                          :disabled="draftSaving"
                          @blur="line.quantity = formatQtyOneDecimal(line.quantity)"
                        />
                      </td>
                      <td class="text-end small text-secondary">
                        {{
                          formatCents(
                            Math.max(
                              0,
                              Math.round(
                                (Number.parseFloat(String(line.quantity).replace(/,/g, "")) ||
                                  0) * dollarsToCents(line.unit_price),
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
              <div v-if="!selectedTableRow" class="table-responsive billing-inv-items-wrap">
                <table class="table table-sm align-middle mb-0 billing-inv-items-table">
                  <thead>
                    <tr>
                      <th>Service</th>
                      <th>Category</th>
                      <th class="text-end">Qty</th>
                      <th class="text-end">Price</th>
                      <th class="text-end">Total</th>
                      <th v-if="canUpdate && invoice.status !== 'void'" class="text-end">Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    <template v-for="row in invoiceTableRows" :key="row.id">
                      <tr
                        class="billing-inv-cat-row"
                        :role="row.details?.length ? 'button' : null"
                        :tabindex="row.details?.length ? 0 : null"
                        @click="row.details?.length ? openTableRow(row) : null"
                        @keydown.enter.prevent="row.details?.length ? openTableRow(row) : null"
                        @keydown.space.prevent="row.details?.length ? openTableRow(row) : null"
                      >
                        <td class="fw-semibold">{{ row.name }}</td>
                        <td>{{ row.type }}</td>
                        <td class="text-end text-nowrap">
                          {{ formatQtyDisplay(row.qty) }}
                        </td>
                        <td class="text-end">
                          {{ formatCents(row.price_cents, invoice.currency) }}
                        </td>
                        <td class="text-end fw-semibold">
                          {{ formatCents(row.total_cents, invoice.currency) }}
                        </td>
                        <td v-if="canUpdate && invoice.status !== 'void'" class="text-end" @click.stop>
                          <div class="position-relative d-inline-block">
                            <button
                              v-if="row.line_group_key"
                              type="button"
                              class="staff-action-btn staff-action-btn--more"
                              :class="{ 'is-open': groupMenuOpenId === row.id }"
                              :aria-expanded="groupMenuOpenId === row.id"
                              aria-haspopup="true"
                              aria-label="Grouped row actions"
                              @click.stop="toggleGroupMenu(row.id)"
                            >
                              <CrmIconRowActions variant="horizontal" />
                            </button>
                            <div v-if="groupMenuOpenId === row.id" class="billing-inline-menu billing-inline-menu--row" role="menu">
                              <button type="button" class="staff-row-menu__item" role="menuitem" @click="openGroupEditModal(row)">
                                Edit
                              </button>
                              <button type="button" class="staff-row-menu__item staff-row-menu__item--danger" role="menuitem" @click="openGroupDeleteModal(row)">
                                Delete
                              </button>
                            </div>
                          </div>
                        </td>
                      </tr>
                    </template>
                    <tr v-if="!invoiceTableRows.length">
                      <td :colspan="canUpdate && invoice.status !== 'void' ? 6 : 5" class="text-center text-secondary py-3">
                        No line items.
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>

              <div v-if="selectedTableRow" class="billing-inv-drill mt-3">
                <div class="d-flex align-items-center justify-content-between mb-2">
                  <h3 class="h6 fw-semibold mb-0">
                    {{ selectedTableRow.name }} — Detail
                  </h3>
                  <button
                    type="button"
                    class="btn btn-sm btn-outline-secondary"
                    @click="closeTableRow"
                  >
                    Back to Invoice Items
                  </button>
                </div>
                <div class="table-responsive">
                  <table class="table table-sm align-middle mb-0 billing-inv-items-table">
                    <thead>
                      <tr>
                        <th>Service</th>
                        <th>Category</th>
                        <th class="text-end">Qty</th>
                        <th class="text-end">Price</th>
                        <th class="text-end">Total</th>
                        <th>Order #</th>
                        <th
                          v-if="invoice.status !== 'void' && canUpdate"
                          class="text-end"
                          style="width: 3.5rem"
                        >
                          Actions
                        </th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr v-for="row in selectedTableRowDetails" :key="row.id" class="billing-inv-line-detail">
                        <td class="fw-medium">{{ row.name }}</td>
                        <td>{{ row.type }}</td>
                        <td class="text-end text-nowrap">{{ formatQtyDisplay(row.qty) }}</td>
                        <td class="text-end">{{ formatCents(row.price_cents, invoice.currency) }}</td>
                        <td class="text-end">{{ formatCents(row.total_cents, invoice.currency) }}</td>
                        <td>{{ row.order_number || "—" }}</td>
                        <td v-if="invoice.status !== 'void' && canUpdate" class="text-end">
                          <div class="position-relative d-inline-block">
                            <button
                              type="button"
                              class="staff-action-btn staff-action-btn--more"
                              :class="{ 'is-open': lineMenuOpenId === row.id }"
                              :aria-expanded="lineMenuOpenId === row.id"
                              aria-haspopup="true"
                              aria-label="Line item actions"
                              @click.stop="toggleLineMenu(row.id)"
                            >
                              <CrmIconRowActions variant="horizontal" />
                            </button>
                            <div v-if="lineMenuOpenId === row.id" class="billing-inline-menu billing-inline-menu--row" role="menu">
                              <button type="button" class="staff-row-menu__item" role="menuitem" @click="openLineEditModal(row)">
                                Edit
                              </button>
                              <button
                                type="button"
                                class="staff-row-menu__item staff-row-menu__item--danger"
                                role="menuitem"
                                @click="openLineDeleteModal(row)"
                              >
                                Delete
                              </button>
                            </div>
                          </div>
                        </td>
                      </tr>
                      <tr v-if="!selectedTableRowDetails.length">
                        <td :colspan="invoice.status !== 'void' && canUpdate ? 7 : 6" class="text-center text-secondary py-3">
                          No line items.
                        </td>
                      </tr>
                    </tbody>
                  </table>
                </div>
              </div>
            </template>

            <div class="row align-items-end mt-4 pt-3 border-top">
              <div class="col-md-6 small text-secondary mb-3 mb-md-0">
                <div class="fw-medium text-body mb-1">Thanks for your business</div>
                <div>Questions? Reply to your Save Rack account contact.</div>
              </div>
              <div class="col-md-6">
                <div class="billing-inv-totals ms-md-auto">
                  <div class="d-flex justify-content-between small">
                    <span class="text-secondary">Subtotal</span>
                    <span>{{ formatCents(invoice.subtotal_cents, invoice.currency) }}</span>
                  </div>
                  <div class="d-flex justify-content-between small">
                    <span class="text-secondary">Tax</span>
                    <span>{{ formatCents(invoice.tax_cents, invoice.currency) }}</span>
                  </div>
                  <div class="d-flex justify-content-between fw-semibold mt-1">
                    <span>Total</span>
                    <span>{{ formatCents(invoice.total_cents, invoice.currency) }}</span>
                  </div>
                  <div class="d-flex justify-content-between small">
                    <span class="text-secondary">Paid</span>
                    <span>{{ formatCents(invoice.amount_paid_cents, invoice.currency) }}</span>
                  </div>
                  <div
                    class="d-flex justify-content-between fw-semibold text-primary pt-2 mt-2 border-top"
                  >
                    <span>Balance due</span>
                    <span>{{ formatCents(invoice.balance_due_cents, invoice.currency) }}</span>
                  </div>
                </div>
              </div>
            </div>

            <template v-if="invoice.customer_notes || invoice.internal_notes">
              <hr class="my-4" />
              <p v-if="invoice.customer_notes" class="small mb-2">
                <span class="fw-semibold">Note:</span>
                {{ invoice.customer_notes }}
              </p>
              <p v-if="invoice.internal_notes" class="small mb-0 text-secondary">
                <span class="fw-medium">Internal:</span>
                {{ invoice.internal_notes }}
              </p>
            </template>
          </div>
        </div>
        <div class="col-lg-4">
          <div class="staff-surface p-4 mb-4">
            <div class="billing-inv-action-stack">
              <button
                v-if="canPayInvoice"
                type="button"
                class="billing-inv-action-btn"
                @click="openPayModal"
              >
                Pay Invoice
              </button>
              <button
                v-if="canAddCharge"
                type="button"
                class="billing-inv-action-btn"
                @click="openAddItemModal"
              >
                Add To Invoice
              </button>
              <button
                v-if="canAddCharge"
                type="button"
                class="billing-inv-action-btn"
                @click="openCcFeeModal"
              >
                Add CC Fee
              </button>
              <button
                v-if="canVoidInvoice"
                type="button"
                class="billing-inv-action-btn billing-inv-action-btn--danger"
                @click="openVoidModal"
              >
                Void Invoice
              </button>
              <button
                v-if="canShareInvoice"
                type="button"
                class="billing-inv-action-btn"
                :disabled="copyLinkBusy"
                @click="copyCustomerLink"
              >
                {{ copyLinkBusy ? "Sharing..." : "Share Invoice" }}
              </button>
              <button
                v-if="canSendWhatsapp"
                type="button"
                class="billing-inv-action-btn"
                @click="openSendWhatsappModal"
              >
                Send To Whatsapp
              </button>
              <button
                type="button"
                class="billing-inv-action-btn"
                @click="jumpToHistory"
              >
                History
              </button>
              <button
                v-if="canUpdate && invoice.status === 'draft'"
                type="button"
                class="billing-inv-action-btn billing-inv-action-btn--primary"
                @click="openSendEmailModal"
              >
                Send Invoice
              </button>
              <button
                v-if="canUpdate && invoice.status === 'draft'"
                type="button"
                class="billing-inv-action-btn"
                @click="sendInvoice"
              >
                Mark As Sent
              </button>
              <button
                v-if="canDelete && invoice.status === 'draft'"
                type="button"
                class="billing-inv-action-btn billing-inv-action-btn--danger"
                @click="openDeleteModal"
              >
                Delete Draft
              </button>
            </div>
          </div>

          <div ref="activityCardRef" class="staff-surface p-4">
            <h2 class="h6 fw-semibold mb-3">History</h2>
            <ul class="list-unstyled small mb-0">
              <li
                v-for="h in invoice.histories"
                :key="h.id"
                class="border-bottom border-light py-2"
              >
                <div class="fw-medium text-capitalize">{{ h.action.replace(/_/g, " ") }}</div>
                <div v-if="h.message" class="text-body">{{ h.message }}</div>
                <div class="text-secondary">
                  {{ h.user?.name || "System" }} ·
                  {{ formatHistoryTimestamp(h.created_at) }}
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
          v-if="groupEditModalOpen"
          class="crm-vx-modal-overlay"
          role="dialog"
          aria-modal="true"
          @click.self="closeGroupEditModal"
        >
          <div class="crm-vx-modal" @click.stop>
            <header class="crm-vx-modal__head">
              <h2 class="crm-vx-modal__title">Edit Grouped Line Items</h2>
            </header>
            <div class="crm-vx-modal__body">
              <div class="d-flex justify-content-end mb-2">
                <button type="button" class="btn btn-sm btn-outline-secondary" :disabled="groupEditBusy" @click="addGroupEditLine">
                  Add Line
                </button>
              </div>
              <div class="table-responsive">
                <table class="table table-sm align-middle mb-0 billing-inv-items-table">
                  <thead>
                    <tr>
                      <th>Service</th>
                      <th>Category</th>
                      <th>Order #</th>
                      <th class="text-end">Qty</th>
                      <th class="text-end">Price</th>
                      <th class="text-end">Total</th>
                      <th />
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="(line, idx) in groupEditLines" :key="idx">
                      <td><input v-model="line.display_name" type="text" class="form-control form-control-sm" /></td>
                      <td><input v-model="line.category" type="text" class="form-control form-control-sm" /></td>
                      <td><input v-model="line.metadata.order_number" type="text" class="form-control form-control-sm" /></td>
                      <td><input v-model="line.quantity" type="text" class="form-control form-control-sm text-end" /></td>
                      <td><input v-model="line.unit_price" type="text" class="form-control form-control-sm text-end" /></td>
                      <td class="text-end">{{ formatCents(Math.max(0, Math.round((Number.parseFloat(String(line.quantity).replace(/,/g, "")) || 0) * dollarsToCents(line.unit_price))), invoice.currency) }}</td>
                      <td class="text-end"><button type="button" class="btn btn-link btn-sm text-danger p-0" @click="removeGroupEditLine(idx)">Delete</button></td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
            <footer class="crm-vx-modal__footer d-flex gap-2 justify-content-end">
              <button type="button" class="crm-vx-modal-btn crm-vx-modal-btn--secondary" :disabled="groupEditBusy" @click="closeGroupEditModal">Cancel</button>
              <button type="button" class="crm-vx-modal-btn crm-vx-modal-btn--primary" :disabled="groupEditBusy" @click="confirmGroupEdit">{{ groupEditBusy ? "Saving…" : "Save" }}</button>
            </footer>
          </div>
        </div>
      </Transition>
    </Teleport>

    <Teleport to="body">
      <Transition name="crm-vx-confirm">
        <div
          v-if="sendEmailModalOpen"
          class="crm-vx-modal-overlay"
          role="dialog"
          aria-modal="true"
          @click.self="closeSendEmailModal"
        >
          <div class="crm-vx-modal crm-vx-modal--sm" @click.stop>
            <header class="crm-vx-modal__head">
              <h2 class="crm-vx-modal__title">Send Email</h2>
            </header>
            <div class="crm-vx-modal__body">
              <label class="billing-pay-label">Recipients</label>
              <div v-if="emailRecipientOptionList.length" class="billing-send-email-recipients mb-3">
                <div class="billing-send-email-recipients__toolbar">
                  <span class="billing-send-email-recipients__count text-secondary">
                    {{ sendEmailRecipients.length }} of {{ emailRecipientOptionList.length }} selected
                  </span>
                  <div class="billing-send-email-recipients__actions">
                    <button
                      type="button"
                      class="billing-send-email-recipients__action"
                      :disabled="sendEmailBusy || allEmailRecipientsSelected"
                      @click="selectAllEmailRecipients"
                    >
                      Select All
                    </button>
                    <button
                      type="button"
                      class="billing-send-email-recipients__action"
                      :disabled="sendEmailBusy || !someEmailRecipientsSelected"
                      @click="unselectAllEmailRecipients"
                    >
                      Unselect All
                    </button>
                  </div>
                </div>
                <div class="billing-send-email-recipients__list">
                  <label
                    v-for="email in emailRecipientOptionList"
                    :key="email"
                    class="billing-send-email-recipients__row"
                  >
                    <input
                      v-model="sendEmailRecipients"
                      class="billing-send-email-recipients__check"
                      type="checkbox"
                      :value="email"
                    />
                    <span class="billing-send-email-recipients__email">{{ email }}</span>
                  </label>
                </div>
              </div>
              <div v-else class="alert alert-warning py-2 small">
                No customer email options found.
              </div>
              <label class="billing-pay-label">Optional message</label>
              <textarea v-model="sendEmailMessage" rows="4" class="form-control billing-send-email-message" />
            </div>
            <footer class="crm-vx-modal__footer d-flex gap-2 justify-content-end">
              <button
                type="button"
                class="crm-vx-modal-btn crm-vx-modal-btn--secondary"
                :disabled="sendEmailBusy"
                @click="closeSendEmailModal"
              >
                Cancel
              </button>
              <button
                type="button"
                class="crm-vx-modal-btn crm-vx-modal-btn--primary"
                :disabled="sendEmailBusy"
                @click="confirmSendEmail"
              >
                {{ sendEmailBusy ? "Sending…" : "Send Email" }}
              </button>
            </footer>
          </div>
        </div>
      </Transition>
    </Teleport>

    <Teleport to="body">
      <Transition name="crm-vx-confirm">
        <div
          v-if="sendWhatsappModalOpen"
          class="crm-vx-modal-overlay"
          role="dialog"
          aria-modal="true"
          @click.self="closeSendWhatsappModal"
        >
          <div class="crm-vx-modal crm-vx-modal--sm" @click.stop>
            <header class="crm-vx-modal__head">
              <h2 class="crm-vx-modal__title">Send via WhatsApp</h2>
            </header>
            <div class="crm-vx-modal__body">
              <label class="form-label">Message type</label>
              <select v-model="sendWhatsappType" class="form-select mb-3">
                <option value="send_invoice">Send Invoice</option>
                <option value="invoice_reminder">Invoice Reminder</option>
                <option value="send_storage_invoice">Send Storage Invoice</option>
              </select>
              <label class="form-label">Optional custom message</label>
              <textarea
                v-model="sendWhatsappMessage"
                rows="4"
                class="form-control"
              />
            </div>
            <footer class="crm-vx-modal__footer d-flex gap-2 justify-content-end">
              <button
                type="button"
                class="crm-vx-modal-btn crm-vx-modal-btn--secondary"
                :disabled="sendWhatsappBusy"
                @click="closeSendWhatsappModal"
              >
                Cancel
              </button>
              <button
                type="button"
                class="crm-vx-modal-btn crm-vx-modal-btn--primary"
                :disabled="sendWhatsappBusy"
                @click="confirmSendWhatsapp"
              >
                {{ sendWhatsappBusy ? "Sending…" : "Send via WhatsApp" }}
              </button>
            </footer>
          </div>
        </div>
      </Transition>
    </Teleport>

    <Teleport to="body">
      <Transition name="crm-vx-confirm">
        <div
          v-if="lineEditModalOpen"
          class="crm-vx-modal-overlay"
          role="dialog"
          aria-modal="true"
          @click.self="closeLineEditModal"
        >
          <div class="crm-vx-modal crm-vx-modal--sm" @click.stop>
            <header class="crm-vx-modal__head">
              <h2 class="crm-vx-modal__title">Edit Line Item</h2>
            </header>
            <div class="crm-vx-modal__body">
              <label class="form-label">Service</label>
              <input v-model="lineEditForm.display_name" type="text" class="form-control mb-2" />
              <label class="form-label">Description</label>
              <textarea v-model="lineEditForm.description" rows="2" class="form-control mb-2" />
              <div class="row g-2">
                <div class="col-6">
                  <label class="form-label">Qty</label>
                  <input v-model="lineEditForm.quantity" type="text" class="form-control text-end" />
                </div>
                <div class="col-6">
                  <label class="form-label">Price</label>
                  <input v-model="lineEditForm.unit_price" type="text" class="form-control text-end" />
                </div>
              </div>
            </div>
            <footer class="crm-vx-modal__footer d-flex gap-2 justify-content-end">
              <button
                type="button"
                class="crm-vx-modal-btn crm-vx-modal-btn--secondary"
                :disabled="lineEditBusy"
                @click="closeLineEditModal"
              >
                Cancel
              </button>
              <button
                type="button"
                class="crm-vx-modal-btn crm-vx-modal-btn--primary"
                :disabled="lineEditBusy"
                @click="confirmLineEdit"
              >
                {{ lineEditBusy ? "Saving…" : "Save" }}
              </button>
            </footer>
          </div>
        </div>
      </Transition>
    </Teleport>

    <Teleport to="body">
      <Transition name="crm-vx-confirm">
        <div
          v-if="addItemModalOpen"
          class="crm-vx-modal-overlay"
          role="dialog"
          aria-modal="true"
          @click.self="closeAddItemModal"
        >
          <div class="crm-vx-modal crm-vx-modal--sm" @click.stop>
            <header class="crm-vx-modal__head">
              <h2 class="crm-vx-modal__title">Add To Invoice</h2>
            </header>
            <div class="crm-vx-modal__body">
              <label class="form-label">Service</label>
              <input v-model="addItemForm.display_name" type="text" class="form-control mb-2" />
              <label class="form-label">Description</label>
              <textarea v-model="addItemForm.description" rows="2" class="form-control mb-2" />
              <div class="row g-2">
                <div class="col-6">
                  <label class="form-label">Qty</label>
                  <input v-model="addItemForm.quantity" type="text" class="form-control text-end" />
                </div>
                <div class="col-6">
                  <label class="form-label">Price</label>
                  <input v-model="addItemForm.unit_price" type="text" class="form-control text-end" />
                </div>
              </div>
            </div>
            <footer class="crm-vx-modal__footer d-flex gap-2 justify-content-end">
              <button type="button" class="crm-vx-modal-btn crm-vx-modal-btn--secondary" :disabled="addItemBusy" @click="closeAddItemModal">
                Cancel
              </button>
              <button type="button" class="crm-vx-modal-btn crm-vx-modal-btn--primary" :disabled="addItemBusy" @click="confirmAddItem">
                {{ addItemBusy ? "Saving…" : "Add" }}
              </button>
            </footer>
          </div>
        </div>
      </Transition>
    </Teleport>

    <Teleport to="body">
      <Transition name="crm-vx-confirm">
        <div
          v-if="ccFeeModalOpen"
          class="crm-vx-modal-overlay"
          role="dialog"
          aria-modal="true"
          @click.self="closeCcFeeModal"
        >
          <div class="crm-vx-modal crm-vx-modal--sm" @click.stop>
            <header class="crm-vx-modal__head">
              <h2 class="crm-vx-modal__title">Add CC Fee</h2>
            </header>
            <div class="crm-vx-modal__body">
              <label class="form-label">Fee Label</label>
              <input v-model="ccFeeLabel" type="text" class="form-control mb-2" />
              <label class="form-label">Amount</label>
              <input v-model="ccFeeAmount" type="text" class="form-control text-end" />
            </div>
            <footer class="crm-vx-modal__footer d-flex gap-2 justify-content-end">
              <button type="button" class="crm-vx-modal-btn crm-vx-modal-btn--secondary" :disabled="ccFeeBusy" @click="closeCcFeeModal">
                Cancel
              </button>
              <button type="button" class="crm-vx-modal-btn crm-vx-modal-btn--primary" :disabled="ccFeeBusy" @click="confirmCcFee">
                {{ ccFeeBusy ? "Saving…" : "Add Fee" }}
              </button>
            </footer>
          </div>
        </div>
      </Transition>
    </Teleport>

    <Teleport to="body">
      <Transition name="crm-vx-confirm">
        <div
          v-if="payModalOpen"
          class="crm-vx-modal-overlay"
          role="dialog"
          aria-modal="true"
          @click.self="closePayModal"
        >
          <div class="crm-vx-modal billing-pay-modal" @click.stop>
            <header class="crm-vx-modal__head">
              <h2 class="crm-vx-modal__title">Pay Invoice</h2>
            </header>
            <div class="crm-vx-modal__body">
              <div v-if="payContextBusy" class="py-4">
                <CrmLoadingSpinner message="Loading payment info…" />
              </div>
              <div v-else class="row g-4">
                <div class="col-lg-8">
                  <div class="billing-pay-form-grid">
                    <div class="billing-pay-field">
                      <label class="billing-pay-label">Account *</label>
                      <div class="billing-pay-value">
                        {{ invoice?.client_company_name || "—" }}
                      </div>
                    </div>
                    <div class="billing-pay-field">
                      <label class="billing-pay-label" for="billing-pay-date">Payment Date *</label>
                      <input
                        id="billing-pay-date"
                        v-model="payDate"
                        type="date"
                        class="form-control"
                      />
                    </div>
                    <div class="billing-pay-field">
                      <label class="billing-pay-label" for="billing-pay-type">Payment Type *</label>
                      <select id="billing-pay-type" v-model="payType" class="form-select">
                        <option value="">Select Payment Type</option>
                        <option value="ACH">ACH</option>
                        <option value="Wire">Wire</option>
                        <option value="Check">Check</option>
                        <option value="Credit Card">Credit Card</option>
                        <option value="Paypal">Paypal</option>
                        <option value="Varies">Varies</option>
                      </select>
                    </div>
                    <div class="billing-pay-field">
                      <label class="billing-pay-label" for="billing-pay-amount">Amount *</label>
                      <input
                        id="billing-pay-amount"
                        v-model="payAmount"
                        type="text"
                        inputmode="decimal"
                        class="form-control text-end"
                        placeholder="0.00"
                      />
                    </div>
                    <div class="billing-pay-field billing-pay-field--full">
                      <label class="billing-pay-label" for="billing-pay-notes">Notes</label>
                      <textarea
                        id="billing-pay-notes"
                        v-model="payNotes"
                        rows="2"
                        class="form-control"
                      />
                    </div>
                  </div>

                  <div class="d-flex justify-content-end mt-3 mb-3">
                    <button
                      type="button"
                      class="btn btn-success"
                      :disabled="!payCanAddFunds"
                      @click="addFundsToPayPool"
                    >
                      Add Funds
                    </button>
                  </div>

                  <div class="table-responsive">
                    <table class="table table-sm align-middle billing-pay-table mb-0">
                      <thead>
                        <tr>
                          <th class="text-center" style="width: 5rem">Select</th>
                          <th class="text-center" style="width: 6rem">Type</th>
                          <th class="text-center" style="width: 8rem">Status</th>
                          <th class="text-center">Invoice #</th>
                          <th class="text-center">Due In</th>
                          <th class="text-center">Due Date</th>
                          <th class="text-end">Balance</th>
                        </tr>
                      </thead>
                      <tbody>
                        <tr v-for="row in payFilteredRows" :key="row.id">
                          <td class="text-center">
                            <input
                              type="checkbox"
                              :checked="paySelectedInvoiceIds.includes(row.id)"
                              @change="togglePayInvoiceSelection(row.id)"
                            />
                          </td>
                          <td class="text-center">
                            <span class="badge bg-info-subtle text-info-emphasis">Beta</span>
                          </td>
                          <td class="text-center">
                            <span
                              class="badge rounded-pill"
                              :class="row.is_overdue ? 'bg-danger-subtle text-danger-emphasis' : 'bg-primary-subtle text-primary-emphasis'"
                            >
                              {{ row.is_overdue ? "Past Due" : "Open" }}
                            </span>
                          </td>
                          <td class="text-center fw-medium">{{ row.invoice_number }}</td>
                          <td class="text-center">{{ payDueInLabel(row.due_in) }}</td>
                          <td class="text-center">{{ formatInvoiceShortDate(row.due_date) }}</td>
                          <td class="text-end">{{ formatCents(row.balance_cents, invoice?.currency || 'USD') }}</td>
                        </tr>
                        <tr v-if="!payFilteredRows.length">
                          <td colspan="7" class="text-center text-secondary py-4">
                            No invoices in this view.
                          </td>
                        </tr>
                      </tbody>
                    </table>
                  </div>
                </div>

                <div class="col-lg-4">
                  <div class="billing-pay-stat-stack">
                    <button
                      type="button"
                      class="billing-pay-stat billing-pay-stat--blue"
                      :class="{ 'is-active': payFilterStatus === 'all' }"
                      @click="payFilterStatus = 'all'"
                    >
                      <div class="billing-pay-stat__value">
                        {{ formatCents(payAvailablePreviewCents, invoice?.currency || 'USD') }}
                      </div>
                      <div class="billing-pay-stat__label">Available Funds</div>
                    </button>
                    <button
                      type="button"
                      class="billing-pay-stat billing-pay-stat--green"
                      :class="{ 'is-active': payFilterStatus === 'open' }"
                      @click="payFilterStatus = 'open'"
                    >
                      <div class="billing-pay-stat__value">
                        {{ formatCents(payOpenBalanceCents, invoice?.currency || 'USD') }}
                      </div>
                      <div class="billing-pay-stat__label">Open Balance</div>
                    </button>
                    <button
                      type="button"
                      class="billing-pay-stat billing-pay-stat--red"
                      :class="{ 'is-active': payFilterStatus === 'past_due' }"
                      @click="payFilterStatus = 'past_due'"
                    >
                      <div class="billing-pay-stat__value">
                        {{ formatCents(payPastDueBalanceCents, invoice?.currency || 'USD') }}
                      </div>
                      <div class="billing-pay-stat__label">Past Due Balance</div>
                    </button>
                    <button
                      type="button"
                      class="billing-pay-stat billing-pay-stat--orange"
                      :class="{ 'is-active': payFilterStatus === 'pending' }"
                      @click="payFilterStatus = 'pending'"
                    >
                      <div class="billing-pay-stat__value">
                        {{ formatCents(payPendingBalanceCents, invoice?.currency || 'USD') }}
                      </div>
                      <div class="billing-pay-stat__label">Pending Balance</div>
                    </button>
                  </div>
                </div>
              </div>
            </div>
            <footer class="crm-vx-modal__footer d-flex gap-2 justify-content-end">
              <button
                type="button"
                class="crm-vx-modal-btn crm-vx-modal-btn--secondary"
                :disabled="payBusy || payContextBusy"
                @click="closePayModal"
              >
                Cancel
              </button>
              <button
                type="button"
                class="crm-vx-modal-btn crm-vx-modal-btn--primary"
                :disabled="payBusy || payContextBusy || !payCanSubmit"
                @click="confirmPay"
              >
                {{ payBusy ? "Paying…" : "Pay Invoice" }}
              </button>
            </footer>
          </div>
        </div>
      </Transition>
    </Teleport>

    <ConfirmModal
      :open="groupDeleteModalOpen"
      title="Delete Group?"
      :message="groupEditTarget ? `Delete grouped items for ${groupEditTarget.name}? This cannot be undone.` : ''"
      confirm-label="Delete"
      cancel-label="Cancel"
      :busy="groupDeleteBusy"
      danger
      @close="closeGroupDeleteModal"
      @confirm="confirmGroupDelete"
    />

    <ConfirmModal
      :open="lineDeleteModalOpen"
      title="Delete Line Item?"
      :message="lineEditTarget ? `Delete ${lineEditTarget.name}? This cannot be undone.` : ''"
      confirm-label="Delete"
      cancel-label="Cancel"
      :busy="lineDeleteBusy"
      danger
      @close="closeLineDeleteModal"
      @confirm="confirmLineDelete"
    />

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
  border-radius: 0.375rem;
}
.billing-inv-vuexy {
  border: 1px solid rgba(47, 43, 61, 0.08);
}
.billing-inv-logo {
  object-fit: contain;
}
.billing-inv-invoice-label {
  letter-spacing: 0.06em;
  font-size: 0.7rem;
  margin-bottom: 0.25rem;
}
.billing-inv-section-label {
  font-size: 0.7rem;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: var(--bs-secondary-color, #6c757d);
  font-weight: 600;
  margin-bottom: 0.35rem;
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
.billing-inv-items-table thead th {
  font-size: 0.7rem;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--bs-secondary-color, #6c757d);
  font-weight: 600;
  border-bottom: 1px solid #e8e7ed;
  padding-top: 0.65rem;
  padding-bottom: 0.65rem;
  white-space: nowrap;
}
.billing-inv-items-table tbody td {
  border-bottom: 1px solid #f1f0f4;
  vertical-align: middle;
}
.billing-inv-totals {
  max-width: 15rem;
}
.billing-inv-action-stack {
  display: grid;
  gap: 0.75rem;
}
.billing-inv-action-btn {
  width: 100%;
  border: 1px solid rgba(47, 43, 61, 0.12);
  border-radius: 0.5rem;
  background: var(--bs-body-bg, #fff);
  color: var(--bs-body-color, #2f2b3d);
  padding: 0.8rem 0.95rem;
  text-align: left;
  font-weight: 500;
  transition:
    background-color 0.15s ease,
    border-color 0.15s ease,
    color 0.15s ease,
    box-shadow 0.15s ease;
}
.billing-inv-action-btn:hover:not(:disabled) {
  background: rgba(115, 103, 240, 0.06);
  border-color: rgba(115, 103, 240, 0.26);
}
.billing-inv-action-btn:disabled {
  opacity: 0.65;
  cursor: not-allowed;
}
.billing-inv-action-btn--primary {
  background: rgba(115, 103, 240, 0.1);
  border-color: rgba(115, 103, 240, 0.28);
  color: #5e50ee;
}
.billing-inv-action-btn--danger {
  color: #ea5455;
}
.billing-inv-col-expand {
  width: 2rem;
  vertical-align: middle;
}
.billing-inv-cat-row {
  cursor: pointer;
  user-select: none;
}
.billing-inv-cat-row:hover {
  background: rgba(15, 23, 42, 0.04);
}
.billing-inv-line-detail td {
  background: var(--bs-tertiary-bg, #f8f9fa);
  font-size: 0.925rem;
}
.billing-inline-menu {
  position: absolute;
  right: 0;
  top: calc(100% + 0.25rem);
  min-width: 12rem;
  background: #fff;
  border: 1px solid #e8e7ed;
  border-radius: 0.5rem;
  box-shadow: 0 0.5rem 1rem rgba(47, 43, 61, 0.12);
  z-index: 20;
  overflow: hidden;
}
.billing-inline-menu--row {
  min-width: 8rem;
}
.billing-pay-modal {
  max-width: 78rem;
  max-height: min(92dvh, 940px);
}
.billing-pay-modal .crm-vx-modal__body {
  overflow: auto;
  scroll-behavior: smooth;
}
.billing-pay-form-grid {
  display: grid;
  gap: 0.85rem;
}
.billing-pay-field--full {
  grid-column: 1 / -1;
}
.billing-pay-label {
  display: block;
  font-size: 0.8rem;
  font-weight: 600;
  margin-bottom: 0.35rem;
  color: var(--bs-secondary-color, #6c757d);
}
.billing-pay-value {
  min-height: 2.625rem;
  display: flex;
  align-items: center;
  border: 1px solid #dbdade;
  border-radius: 0.375rem;
  padding: 0.625rem 0.75rem;
  background: var(--bs-tertiary-bg, #f8f9fa);
}
.billing-pay-table thead th {
  background: transparent;
  color: var(--bs-secondary-color, #6c757d);
  border-bottom: 1px solid #e8e7ed;
  font-size: 0.7rem;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  font-weight: 600;
}
.billing-pay-table tbody td {
  border-bottom: 1px solid #f1f0f4;
}
.billing-pay-table tbody tr:hover td {
  background: rgba(115, 103, 240, 0.04);
}
.billing-pay-stat-stack {
  display: grid;
  gap: 1rem;
}
.billing-pay-stat {
  width: 100%;
  border: none;
  border-radius: 0.85rem;
  padding: 1.1rem 1rem;
  color: #fff;
  text-align: left;
  box-shadow: 0 10px 20px rgba(47, 43, 61, 0.12);
  opacity: 0.92;
}
.billing-pay-stat.is-active {
  outline: 2px solid rgba(255, 255, 255, 0.75);
  opacity: 1;
}
.billing-pay-stat--blue {
  background: #2f80ed;
}
.billing-pay-stat--green {
  background: #15a05d;
}
.billing-pay-stat--red {
  background: #ea5455;
}
.billing-pay-stat--orange {
  background: #ff9f43;
}
.billing-pay-stat__value {
  font-size: 1.85rem;
  font-weight: 700;
  line-height: 1.1;
}
.billing-pay-stat__label {
  margin-top: 0.25rem;
  font-size: 0.95rem;
}
.billing-send-email-recipients {
  border: 1px solid #dbdade;
  border-radius: 0.5rem;
  background: var(--bs-tertiary-bg, #f8f9fa);
  overflow: hidden;
}
.billing-send-email-recipients__toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem 1rem;
  padding: 0.55rem 0.75rem;
  border-bottom: 1px solid #e8e7ed;
  background: var(--bs-body-bg, #fff);
}
.billing-send-email-recipients__count {
  font-size: 0.8rem;
  font-weight: 500;
}
.billing-send-email-recipients__actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.35rem 0.75rem;
}
.billing-send-email-recipients__action {
  border: none;
  background: none;
  padding: 0.2rem 0;
  font-size: 0.8rem;
  font-weight: 600;
  color: #5e50ee;
  text-decoration: none;
  cursor: pointer;
  transition: color 0.15s ease, opacity 0.15s ease;
}
.billing-send-email-recipients__action:hover:not(:disabled) {
  color: #4b3fd4;
  text-decoration: underline;
}
.billing-send-email-recipients__action:disabled {
  opacity: 0.45;
  cursor: not-allowed;
  text-decoration: none;
}
.billing-send-email-recipients__list {
  max-height: 10rem;
  overflow: auto;
  padding: 0.35rem 0;
  background: var(--bs-body-bg, #fff);
}
.billing-send-email-recipients__row {
  display: flex;
  align-items: center;
  gap: 0.65rem;
  margin: 0;
  padding: 0.45rem 0.75rem;
  cursor: pointer;
  font-size: 0.875rem;
  color: var(--bs-body-color, #2f2b3d);
  transition: background-color 0.12s ease;
}
.billing-send-email-recipients__row:hover {
  background: rgba(115, 103, 240, 0.06);
}
.billing-send-email-recipients__check {
  width: 1.05rem;
  height: 1.05rem;
  margin: 0;
  flex-shrink: 0;
  border-radius: 0.25rem;
  border: 1px solid #c9c6d3;
  accent-color: #7367f0;
}
.billing-send-email-recipients__email {
  word-break: break-all;
  line-height: 1.35;
}
.billing-send-email-message {
  margin-top: 0.35rem;
}
</style>
