<script setup>
import { computed, inject, nextTick, onMounted, onUnmounted, ref, watch } from "vue";

import { useRouter } from "vue-router";
import api from "../../services/api";
import { BRAND_MARK_SRC } from "../../utils/brandAssets.js";
import ConfirmModal from "../../components/common/ConfirmModal.vue";
import CrmIconRowActions from "../../components/common/CrmIconRowActions.vue";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import CrmStatusUpdateModal from "../../components/common/CrmStatusUpdateModal.vue";
import { useToast } from "../../composables/useToast";
import { crmIsAdmin } from "../../utils/crmUser";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { formatCents } from "../../utils/formatMoney.js";
import { formatIsoDate, parseCalendarDay, toDateInputValue } from "../../utils/formatUserDates.js";

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

const CLIENT_PAYMENT_TYPE_OPTIONS = [
  "ACH",
  "Wire",
  "Check",
  "Manual",
  "Credit Card",
  "Paypal",
  "Varies",
];

const canUpdate = computed(() => userHasPerm("billing.update"));
const canUpdateClientAccount = computed(() => userHasPerm("clients.update"));
const canDelete = computed(() => userHasPerm("billing.delete"));
const canHardDeleteInvoices = computed(() => {
  const u = crmUser.value;
  if (!u) return false;
  return crmIsAdmin(u) || u.is_crm_owner;
});

const loading = ref(true);
const invoice = ref(null);

const editDueAt = ref("");
const editLines = ref([]);
const draftSaving = ref(false);
const draftEditMode = ref(false);

const payModalOpen = ref(false);
const payAmount = ref("");
const payType = ref("");
const payDate = ref("");
const payNotes = ref("");
const payBusy = ref(false);
const payContextBusy = ref(false);
const payFundsCents = ref(0);
const payOpenBalanceCents = ref(0);
const payPendingBalanceCents = ref(0);
const payRows = ref([]);
const paySelectedInvoiceIds = ref([]);
const payFilterStatus = ref("all");

const voidModalOpen = ref(false);
const voidBusy = ref(false);
const statusModalOpen = ref(false);
const statusForm = ref("draft");
const statusSaving = ref(false);
const invoiceStatuses = [
  "draft",
  "open",
  "collection",
  "processing",
  "payment_failed",
  "paid",
  "void",
];

const deleteModalOpen = ref(false);
const deleteBusy = ref(false);

const pdfDownloading = ref(false);
const copyLinkBusy = ref(false);
const openInvoiceTabBusy = ref(false);
const editBillingPeriodStart = ref("");
const editBillingPeriodEnd = ref("");
const invoiceDatesSaving = ref(false);
const editPaymentType = ref("");
const paymentTypeSaving = ref(false);
const whatsappCaptureModalOpen = ref(false);
const whatsappCaptureApiId = ref("");
const whatsappCaptureBusy = ref(false);
const selectedTableRowId = ref("");
const sendEmailBusy = ref(false);
const sendEmailModalOpen = ref(false);
const sendEmailMessage = ref("");
const sendEmailRecipients = ref([]);
const sendWhatsappBusy = ref(false);
const sendWhatsappModalOpen = ref(false);
const sendWhatsappType = ref("send_invoice");
const sendWhatsappMessage = ref("");
const stripeModalOpen = ref(false);
const stripeMethodsLoading = ref(false);
const stripeChargeBusy = ref(false);
const stripeAmount = ref("");
const stripePaymentMethodId = ref("");
const stripeMethods = ref([]);
const lineMenuOpenId = ref(null);
const groupMenuOpenId = ref(null);
const lineMenuPos = ref({ top: 0, left: 0 });
const groupMenuPos = ref({ top: 0, left: 0 });
const lineEditModalOpen = ref(false);
const lineEditBusy = ref(false);
const lineDeleteModalOpen = ref(false);
const lineDeleteBusy = ref(false);
const lineEditTarget = ref(null);
const invoiceCategoryOptions = [
  { value: "fulfillment", label: "Fulfillment" },
  { value: "amazon prep", label: "Amazon Prep" },
  { value: "postage", label: "Postage" },
  { value: "packaging", label: "Packaging" },
  { value: "returns", label: "Returns" },
  { value: "ad_hoc", label: "Ad Hoc" },
  { value: "bank fee", label: "Bank Fee" },
  { value: "duties & taxes", label: "Duties & Taxes" },
  { value: "storage", label: "Storage" },
  { value: "on_demand", label: "On Demand" },
  { value: "receiving", label: "Receiving" },
  { value: "credits", label: "Credits" },
  { value: "other", label: "Other" },
];
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
  order_number: "",
  metadata: {},
});
const addItemModalOpen = ref(false);
const addItemBusy = ref(false);
const addItemForm = ref({
  display_name: "",
  category: "",
  subtype: "",
  sku: "",
  service_code: "",
  quantity: "1.0",
  unit: "",
  unit_price: "0.00",
  order_number: "",
});
const ccFeeModalOpen = ref(false);
const ccFeeBusy = ref(false);
const ccFeeLabel = ref("Credit Card Fee");
const groupEditModalOpen = ref(false);
const groupEditBusy = ref(false);
const groupDeleteModalOpen = ref(false);
const groupDeleteBusy = ref(false);
const groupEditTarget = ref(null);
const groupEditLines = ref([]);
const groupBulkQty = ref("");
const groupBulkPrice = ref("");
const rightActionsMenuOpen = ref(false);
const accountBalanceLoading = ref(false);

const invoiceLogoSrc = computed(() => BRAND_MARK_SRC());
const activityCardRef = ref(null);

function invoiceStatusKey(inv) {
  return String(inv?.status_key || inv?.status || "").toLowerCase();
}

function payRowStatusKey(row) {
  const key = String(row?.status_key || "").toLowerCase();
  if (key) return key;
  if (String(row?.status || "").toLowerCase() === "draft") return "draft";
  return "open";
}

function payRowStatusLabel(row) {
  const label = String(row?.status_label || "").trim();
  if (label) return label;

  const key = payRowStatusKey(row);
  if (key === "past_due") return "Open";
  if (key === "draft") return "Draft";
  if (key === "collection") return "Collection";
  if (key === "processing") return "Processing";
  if (key === "payment_failed") return "Failed";
  if (key === "paid") return "Paid";
  if (key === "void") return "Void";
  return "Open";
}

function payRowStatusBadgeClass(row) {
  const key = payRowStatusKey(row);
  if (key === "collection") {
    return "bg-danger-subtle text-danger-emphasis";
  }
  if (key === "payment_failed") return "bg-danger-subtle text-danger-emphasis";
  if (key === "processing") return "bg-warning-subtle text-warning-emphasis";
  if (key === "paid") return "bg-success-subtle text-success-emphasis";
  if (key === "void") return "bg-secondary-subtle text-secondary-emphasis";
  if (key === "draft") return "bg-warning-subtle text-warning-emphasis";
  return "bg-primary-subtle text-primary-emphasis";
}

const currentStatusKey = computed(() => invoiceStatusKey(invoice.value));
const canUpdateInvoiceStatus = computed(() => canUpdate.value && !!invoice.value);

/** Show Pay in sidebar whenever invoice might eventually accept payment (not paid/void). */
const payInvoiceVisible = computed(
  () =>
    !!invoice.value &&
    canUpdate.value &&
    currentStatusKey.value !== "paid" &&
    currentStatusKey.value !== "void",
);

/** Matches recordPayment policy: positive balance; draft, sent, or partial (not paid/void). */
const payInvoiceEnabled = computed(() => {
  const inv = invoice.value;
  if (!inv || !canUpdate.value) return false;
  if (Number(inv.balance_due_cents) <= 0) return false;
  const s = invoiceStatusKey(inv);
  return s !== "paid" && s !== "void";
});

const payInvoiceDisabledTitle = computed(() => {
  const inv = invoice.value;
  if (!inv || !payInvoiceVisible.value) return "";
  const s = invoiceStatusKey(inv);
  if (s === "draft" && Number(inv.balance_due_cents) <= 0) {
    return "Add line items or totals before paying.";
  }
  if (s === "open" || s === "collection") {
    if (Number(inv.balance_due_cents) <= 0) return "No balance due.";
  }
  return "";
});

const canAddCharge = computed(
  () => !!invoice.value && canUpdate.value && currentStatusKey.value !== "void",
);

const canEditInvoiceDates = computed(
  () => !!invoice.value && canUpdate.value && currentStatusKey.value !== "void",
);

const canVoidInvoice = computed(
  () => !!invoice.value && canUpdate.value && currentStatusKey.value !== "void",
);

const canRestoreDraft = computed(
  () => !!invoice.value && canUpdate.value && currentStatusKey.value === "void",
);

const canShareInvoice = computed(() => !!invoice.value && currentStatusKey.value !== "void");

/** Show Email / WhatsApp actions for non-void invoices with billing update (disabled while draft). */
const canShowMessagingActions = computed(
  () => !!invoice.value && canUpdate.value && currentStatusKey.value !== "void",
);

const messagingActionsDisabled = computed(() => currentStatusKey.value === "draft");

const messagingActionsDisabledTitle = computed(() =>
  messagingActionsDisabled.value ? "Send the invoice first to enable messaging." : "",
);

const canSendInvoiceFromDraft = computed(
  () => !!invoice.value && canUpdate.value && currentStatusKey.value === "draft",
);

const hasStripeCustomerId = computed(() => {
  const inv = invoice.value;
  if (!inv) return false;
  return !!String(inv.client_account_stripe_customer_id || "").trim();
});

const canStripeCharge = computed(() => {
  const inv = invoice.value;
  if (!inv || !canUpdate.value) return false;
  if (invoiceStatusKey(inv) === "void") return false;
  if (invoiceStatusKey(inv) === "processing") return false;
  return Number(inv.balance_due_cents) > 0;
});

const creditChargeDisabledTitle = computed(() => {
  const inv = invoice.value;
  if (!inv) return "";
  if (!canUpdate.value) return "You do not have permission to charge this invoice.";
  if (invoiceStatusKey(inv) === "void") return "Void invoices cannot be charged.";
  if (invoiceStatusKey(inv) === "processing") return "Payment is processing. Wait for Stripe settlement.";
  if (Number(inv.balance_due_cents) <= 0) return "No balance due.";
  return "";
});

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
  if (payFilterStatus.value === "pending") {
    return payRows.value.filter((row) => payRowStatusKey(row) === "draft");
  }
  if (payFilterStatus.value === "open") {
    return payRows.value.filter((row) => payRowStatusKey(row) === "open");
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

const payAvailableDisplayCents = computed(
  () => Math.max(0, payAvailablePreviewCents.value),
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

/** City, state ZIP for invoice “Invoice to” block */
const invoiceClientCityStateZip = computed(() => {
  const inv = invoice.value;
  if (!inv) return "";
  const city = String(inv.client_account_city || "").trim();
  const st = String(inv.client_account_state || "").trim();
  const zip = String(inv.client_account_zip || "").trim();
  const line2 = [st, zip].filter(Boolean).join(" ");
  return [city, line2].filter(Boolean).join(city && line2 ? ", " : "");
});

/** Account default payment type shown on invoice face */
const invoicePaymentTypeDisplay = computed(() => {
  const t = invoice.value?.client_account_default_payment_type;
  if (t == null || String(t).trim() === "") return "";
  return String(t).trim();
});

const isCreditCardPaymentType = computed(
  () => invoicePaymentTypeDisplay.value.toLowerCase() === "credit card",
);

const hasCreditCardFee = computed(() => {
  const items = Array.isArray(invoice.value?.items) ? invoice.value.items : [];
  return items.some((item) => {
    const groupKey = String(item?.group_key || "").trim().toLowerCase();
    if (groupKey.startsWith("cc_fee:")) return true;
    const name = String(item?.display_name || item?.description || "").trim().toLowerCase();
    return name === "credit card fee" || name === "cc fee";
  });
});

const ccFeePercent = computed(() => {
  const n = Number(invoice.value?.client_account_cc_fee_percent);
  return Number.isFinite(n) ? n : 0;
});

const ccFeeBaseCents = computed(() => {
  const items = Array.isArray(invoice.value?.items) ? invoice.value.items : [];
  return items
    .filter((item) => {
      const groupKey = String(item?.group_key || "").trim().toLowerCase();
      if (groupKey.startsWith("cc_fee:")) return false;
      const name = String(item?.display_name || item?.description || "").trim().toLowerCase();
      return name !== "credit card fee" && name !== "cc fee";
    })
    .reduce((sum, item) => sum + Number(item?.line_total_cents || 0), 0);
});

const ccFeePreviewCents = computed(() =>
  Math.round(ccFeeBaseCents.value * (ccFeePercent.value / 100)),
);

const canAddCcFee = computed(
  () =>
    canAddCharge.value &&
    isCreditCardPaymentType.value &&
    !hasCreditCardFee.value &&
    ccFeePercent.value > 0 &&
    ccFeePreviewCents.value > 0,
);

const clientAccountDetailHref = computed(() => {
  const id = invoice.value?.client_account_id;
  if (id == null || id === "") return "";
  return `/clients/accounts/${encodeURIComponent(String(id))}`;
});

function formatQtyDisplay(v) {
  const n = Number(v);
  if (!Number.isFinite(n)) return "0.000";
  if (Math.abs(n) < 1) return n.toFixed(3);
  return Number.isInteger(n) ? String(n) : n.toFixed(2);
}

function displayRowOrderNumber(row) {
  const direct = String(row?.order_number || "").trim();
  if (direct) return direct;
  const details = Array.isArray(row?.details) ? row.details : [];
  if (!details.length) return "—";
  const unique = [
    ...new Set(
      details
        .map((detail) => String(detail?.order_number || "").trim())
        .filter(Boolean),
    ),
  ];
  if (!unique.length) return "—";
  if (unique.length === 1) return unique[0];
  return "Multiple";
}

const invoiceTableRows = computed(() => invoice.value?.presentation?.rows || []);

const QTY_COMPARE_EPS = 1e-6;

function qtyNumbersClose(a, b) {
  return Math.abs(Number(a) - Number(b)) <= QTY_COMPARE_EPS;
}

function isIncludedPackagingName(raw) {
  const n = String(raw || "").trim().toUpperCase();
  if (!n) return false;
  if (n === "SHIP AS IS" || n.startsWith("SHIP AS IS ")) return true;
  if (n.startsWith("BOX") || n.startsWith("BOX (")) return true;
  if (n.startsWith("POLY")) return true;
  if (n.startsWith("BUBBLE MAILER")) return true;
  if (n.startsWith("ENVELOP")) return true;
  return false;
}

const hasFulfillmentFirstPickRow = computed(() =>
  invoiceTableRows.value.some(
    (row) =>
      String(row.type || "").toLowerCase() === "fulfillment" &&
      /first\s*pick/i.test(String(row.name || "")),
  ),
);

const fulfillmentBaselineQty = computed(() => {
  let sum = 0;
  for (const row of invoiceTableRows.value) {
    if (String(row.type || "").toLowerCase() !== "fulfillment") continue;
    if (!/first\s*pick/i.test(String(row.name || ""))) continue;
    const q = Number(row.qty);
    if (Number.isFinite(q)) sum += q;
  }
  return sum;
});

const postageCategoryQtySum = computed(() => {
  let sum = 0;
  for (const row of invoiceTableRows.value) {
    if (String(row.type || "").toLowerCase() !== "postage") continue;
    const q = Number(row.qty);
    if (Number.isFinite(q)) sum += q;
  }
  return sum;
});

const packagingFilteredCategoryQtySum = computed(() => {
  let sum = 0;
  for (const row of invoiceTableRows.value) {
    if (String(row.type || "").toLowerCase() !== "packaging") continue;
    if (!isIncludedPackagingName(row.name)) continue;
    const q = Number(row.qty);
    if (Number.isFinite(q)) sum += q;
  }
  return sum;
});

const postageMatchesFulfillmentBaseline = computed(() =>
  qtyNumbersClose(postageCategoryQtySum.value, fulfillmentBaselineQty.value),
);
const packagingMatchesFulfillmentBaseline = computed(() =>
  qtyNumbersClose(packagingFilteredCategoryQtySum.value, fulfillmentBaselineQty.value),
);

const selectedTableRow = computed(() => {
  if (!selectedTableRowId.value) return null;
  return invoiceTableRows.value.find((r) => r.id === selectedTableRowId.value) || null;
});

const selectedTableRowDetails = computed(() => selectedTableRow.value?.details || []);
const selectedBreakdownOrderColumnLabel = computed(() =>
  String(selectedTableRow.value?.type || "").toLowerCase() === "storage" ? "Location ID" : "Order #",
);
const groupEditOrderColumnLabel = computed(() =>
  String(groupEditTarget.value?.type || "").toLowerCase() === "storage" ? "Location ID" : "Order #",
);
const lineMenuStyle = computed(() => ({
  position: "fixed",
  top: `${lineMenuPos.value.top}px`,
  left: `${lineMenuPos.value.left}px`,
}));
const groupMenuStyle = computed(() => ({
  position: "fixed",
  top: `${groupMenuPos.value.top}px`,
  left: `${groupMenuPos.value.left}px`,
}));

const MENU_W = 128;
const MENU_H = 96;

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
  const dueAt = inv.due_at ? parseCalendarDay(inv.due_at) : null;
  if (!dueAt) return false;
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  const due0 = new Date(dueAt.getFullYear(), dueAt.getMonth(), dueAt.getDate());
  due0.setHours(0, 0, 0, 0);
  return due0 < today;
}

const statusDisplayText = computed(() => {
  const inv = invoice.value;
  if (!inv) return "";
  const statusKey = String(inv.status_key || inv.status || "").trim().toLowerCase();
  if (statusKey === "payment_failed") return "Failed";
  const label = String(inv.status_label || "").trim();
  if (label.toLowerCase() === "payment failed") return "Failed";
  if (label) return label;
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
  return formatIsoDate(iso);
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
    const url = await ensureCustomerViewUrl();
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

async function ensureCustomerViewUrl() {
  if (!invoice.value?.id) return null;
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
  return url || null;
}

async function openPublicInvoiceInNewTab() {
  if (!invoice.value?.id || openInvoiceTabBusy.value) return;
  openInvoiceTabBusy.value = true;
  try {
    const url = await ensureCustomerViewUrl();
    if (!url) {
      toast.error("Could not open customer invoice link.");
      return;
    }
    window.open(url, "_blank", "noopener,noreferrer");
  } catch (e) {
    toast.errorFrom(e, "Could not open invoice.");
  } finally {
    openInvoiceTabBusy.value = false;
  }
}

async function saveClientPaymentType() {
  if (!invoice.value?.client_account_id || !canUpdateClientAccount.value) {
    toast.error("You do not have permission to update this client account.");
    return;
  }
  const v = String(editPaymentType.value || "").trim();
  paymentTypeSaving.value = true;
  try {
    await api.patch(`/client-accounts/${invoice.value.client_account_id}`, {
      default_payment_type: v || null,
    });
    toast.success("Payment type updated.");
    await load();
  } catch (e) {
    toast.errorFrom(e, "Could not update payment type.");
  } finally {
    paymentTypeSaving.value = false;
  }
}

function syncEditFromInvoice() {
  const inv = invoice.value;
  editPaymentType.value = String(inv?.client_account_default_payment_type || "").trim();
  if (!inv) {
    editDueAt.value = "";
    editBillingPeriodStart.value = "";
    editBillingPeriodEnd.value = "";
    editLines.value = [];
    return;
  }
  editDueAt.value = toDateInputValue(inv.due_at);
  const periodStart =
    inv.billing_period_start ||
    inv.invoice_date_from ||
    null;
  const periodEnd =
    inv.billing_period_end ||
    inv.invoice_date_to ||
    null;
  editBillingPeriodStart.value = toDateInputValue(periodStart);
  editBillingPeriodEnd.value = toDateInputValue(periodEnd);
  if (invoiceStatusKey(inv) !== "draft") {
    editLines.value = [];
    return;
  }
  const items = inv.items || [];
  editLines.value = items.length
    ? items.map((i) => ({
        description: i.display_name || i.description || "",
        sku: i.sku != null && String(i.sku) !== "" ? String(i.sku) : "",
        quantity: formatQtyOneDecimal(i.quantity ?? 1),
        category: i.category || null,
        unit_price: formPriceDollarsFromCents(i.unit_price_cents, i.category),
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
    await loadAccountBalanceSummary();
    setCrmPageMeta({
      title: `Invoice # ${data?.invoice_number || "Invoice"} - Save Rack`,
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

function isCreditCategory(category) {
  return String(category || "").toLowerCase() === "credits";
}

function formPriceDollarsFromCents(cents, category) {
  const n = Number(cents || 0);
  const signed = isCreditCategory(category) ? Math.abs(n) : n;
  return (signed / 100).toFixed(2);
}

function signedUnitCents(category, value) {
  const cents = dollarsToCents(value);
  return isCreditCategory(category) ? -Math.abs(cents) : Math.abs(cents);
}

function signedLineTotalCents(category, quantity, unitPrice) {
  const qty = Math.abs(Number.parseFloat(String(quantity).replace(/,/g, "")) || 0);
  const unit = signedUnitCents(category, unitPrice);
  const total = Math.round(qty * Math.abs(unit));
  return isCreditCategory(category) ? -total : total;
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
    const unitCents = signedUnitCents(line.category, line.unit_price);
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
      line_total_cents: signedLineTotalCents(line.category, qty, line.unit_price),
      metadata: line.metadata || null,
    });
  }
  draftSaving.value = true;
  try {
    await api.patch(`/invoices/${invoice.value.id}`, {
      due_at: editDueAt.value || null,
      billing_period_start: editBillingPeriodStart.value || null,
      billing_period_end: editBillingPeriodEnd.value || null,
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

async function saveInvoiceDates() {
  if (!invoice.value || !canEditInvoiceDates.value || invoiceDatesSaving.value) return;
  invoiceDatesSaving.value = true;
  try {
    const { data } = await api.patch(`/invoices/${invoice.value.id}/dates`, {
      due_at: editDueAt.value || null,
      billing_period_start: editBillingPeriodStart.value || null,
      billing_period_end: editBillingPeriodEnd.value || null,
    });
    invoice.value = data;
    syncEditFromInvoice();
    toast.success("Invoice dates updated.");
    await loadAccountBalanceSummary();
  } catch (e) {
    toast.errorFrom(e, "Could not update invoice dates.");
  } finally {
    invoiceDatesSaving.value = false;
  }
}

function statusBadgeClass(status) {
  const s = String(status || "").toLowerCase();
  if (s === "paid") return "bg-success-subtle text-success";
  if (s === "draft") return "bg-secondary-subtle text-secondary";
  if (s === "void") return "bg-dark-subtle text-secondary";
  if (s === "collection") return "bg-warning-subtle text-warning-emphasis";
  if (s === "processing") return "bg-warning-subtle text-warning-emphasis";
  if (s === "failed" || s === "payment failed" || s === "payment_failed") {
    return "bg-danger-subtle text-danger-emphasis";
  }
  if (s === "past due" || s === "past_due") return "bg-primary-subtle text-primary-emphasis";
  if (s === "open") return "bg-primary-subtle text-primary-emphasis";
  return "bg-body-secondary text-body-secondary";
}

function openStatusModal() {
  if (!canUpdateInvoiceStatus.value || !invoice.value) return;
  statusForm.value = currentStatusKey.value || "draft";
  statusModalOpen.value = true;
}

async function saveStatusFromModal() {
  if (!canUpdateInvoiceStatus.value || !invoice.value) return;
  const next = statusForm.value;
  if (next === currentStatusKey.value) {
    statusModalOpen.value = false;
    return;
  }
  statusSaving.value = true;
  try {
    await api.post(`/invoices/${invoice.value.id}/status`, { status: next });
    toast.success("Invoice status updated.");
    await load();
    statusModalOpen.value = false;
  } catch (e) {
    toast.errorFrom(e, "Could not update invoice status.");
  } finally {
    statusSaving.value = false;
  }
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
  if (messagingActionsDisabled.value) return;
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

function closeSendEmailModal(force = false) {
  if (sendEmailBusy.value && !force) return;
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
    closeSendEmailModal(true);
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
  if (!invoice.value || messagingActionsDisabled.value) return;
  const wa = String(invoice.value.client_account_whatsapp_api_id || "").trim();
  if (!wa) {
    if (!canUpdateClientAccount.value) {
      toast.error("Add a WhatsApp API ID on the client account first.");
      return;
    }
    whatsappCaptureApiId.value = "";
    whatsappCaptureModalOpen.value = true;
    return;
  }
  sendWhatsappType.value = "send_invoice";
  sendWhatsappMessage.value = buildWhatsappDefaultMessage(sendWhatsappType.value);
  sendWhatsappModalOpen.value = true;
  ensureShareLinkForMessaging()
    .then(() => {
      sendWhatsappMessage.value = buildWhatsappDefaultMessage(sendWhatsappType.value);
    })
    .catch(() => {});
}

function closeWhatsappCaptureModal(force = false) {
  if (whatsappCaptureBusy.value && !force) return;
  whatsappCaptureModalOpen.value = false;
}

async function confirmWhatsappCapture() {
  if (!invoice.value?.client_account_id || !canUpdateClientAccount.value) {
    toast.error("You do not have permission to update this client account.");
    return;
  }
  const raw = String(whatsappCaptureApiId.value || "").trim();
  if (!raw) {
    toast.error("Enter a WhatsApp API ID.");
    return;
  }
  whatsappCaptureBusy.value = true;
  try {
    await api.patch(`/client-accounts/${invoice.value.client_account_id}`, {
      whatsapp_api_id: raw,
    });
    invoice.value = {
      ...invoice.value,
      client_account_whatsapp_api_id: raw,
    };
    toast.success("WhatsApp API ID saved.");
    closeWhatsappCaptureModal(true);
    openSendWhatsappModal();
  } catch (e) {
    toast.errorFrom(e, "Could not save WhatsApp API ID.");
  } finally {
    whatsappCaptureBusy.value = false;
  }
}

function closeSendWhatsappModal(force = false) {
  if (sendWhatsappBusy.value && !force) return;
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
    closeSendWhatsappModal(true);
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

function placeOverlayMenu(targetEl, setPos) {
  if (!(targetEl instanceof HTMLElement)) return;
  const rect = targetEl.getBoundingClientRect();
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
  groupMenuOpenId.value = null;
  lineMenuOpenId.value = lineId;
  await nextTick();
  requestAnimationFrame(() => {
    placeOverlayMenu(btn, (v) => {
      lineMenuPos.value = v;
    });
  });
}

async function toggleGroupMenu(rowId, event) {
  event?.stopPropagation?.();
  if (groupMenuOpenId.value === rowId) {
    groupMenuOpenId.value = null;
    return;
  }
  const btn = event?.currentTarget;
  lineMenuOpenId.value = null;
  groupMenuOpenId.value = rowId;
  await nextTick();
  requestAnimationFrame(() => {
    placeOverlayMenu(btn, (v) => {
      groupMenuPos.value = v;
    });
  });
}

function openGroupEditModal(row) {
  groupMenuOpenId.value = null;
  groupEditTarget.value = row;
  groupBulkQty.value = "";
  groupBulkPrice.value = "";
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
        unit_price: formPriceDollarsFromCents(line.price_cents, line.category),
        metadata: (() => {
          const m = line.metadata && typeof line.metadata === "object" ? { ...line.metadata } : {};
          const on = String(line.order_number || "").trim();
          if (on && !String(m.order_number || "").trim()) {
            m.order_number = on;
          }
          return m;
        })(),
      }))
    : [];
  groupEditModalOpen.value = true;
}

function closeGroupEditModal() {
  if (groupEditBusy.value) return;
  groupEditModalOpen.value = false;
  groupEditTarget.value = null;
  groupBulkQty.value = "";
  groupBulkPrice.value = "";
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

/** Set every row in the group editor to the same quantity and unit price (keeps per-line service, category, order #, etc.). */
function applyGroupBulkConsolidate() {
  const qtyRaw = String(groupBulkQty.value || "").trim();
  const priceRaw = String(groupBulkPrice.value || "").trim();
  if (!groupEditLines.value.length) {
    toast.error("No lines to update.");
    return;
  }
  if (!qtyRaw) {
    toast.error("Enter a quantity.");
    return;
  }
  if (!priceRaw) {
    toast.error("Enter a unit price.");
    return;
  }
  const parsedPrice = Number.parseFloat(String(priceRaw).replace(/,/g, ""));
  if (!Number.isFinite(parsedPrice) || parsedPrice < 0) {
    toast.error("Enter a valid unit price.");
    return;
  }
  const priceStr = parsedPrice.toFixed(2);
  const qtyNum = Number.parseFloat(String(qtyRaw).replace(/,/g, ""));
  const qtyStr = Number.isFinite(qtyNum) ? formatQtyOneDecimal(qtyNum) : qtyRaw;
  groupEditLines.value = groupEditLines.value.map((line) => ({
    ...line,
    quantity: qtyStr,
    unit_price: priceStr,
    metadata:
      line.metadata && typeof line.metadata === "object" ? { ...line.metadata } : line.metadata || {},
  }));
}

function openGroupDeleteFromGroupEditModal() {
  if (!groupEditTarget.value?.line_group_key || groupEditBusy.value) return;
  groupEditModalOpen.value = false;
  groupEditLines.value = [];
  groupBulkQty.value = "";
  groupBulkPrice.value = "";
  groupDeleteModalOpen.value = true;
}

async function confirmGroupEdit() {
  if (!invoice.value || !groupEditTarget.value?.line_group_key) return;
  groupEditBusy.value = true;
  try {
    const payloadItems = groupEditLines.value.map((line) => {
      const qty = Number.parseFloat(String(line.quantity).replace(/,/g, "")) || 0;
      const unitCents = signedUnitCents(line.category, line.unit_price);
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
        line_total_cents: signedLineTotalCents(line.category, qty, line.unit_price),
        metadata: line.metadata || null,
      };
    });
    await api.put(
      `/invoices/${invoice.value.id}/line-groups/${encodeURIComponent(groupEditTarget.value.line_group_key)}`,
      { items: payloadItems },
    );
    toast.success("Grouped line items updated.");
    groupEditModalOpen.value = false;
    groupEditTarget.value = null;
    groupBulkQty.value = "";
    groupBulkPrice.value = "";
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
    groupDeleteModalOpen.value = false;
    groupEditTarget.value = null;
    groupEditModalOpen.value = false;
    groupEditLines.value = [];
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
  const metadata =
    line.metadata && typeof line.metadata === "object" ? { ...line.metadata } : {};
  const orderNumber = String(
    metadata.order_number ?? line.order_number ?? "",
  );
  lineEditForm.value = {
    description: line.description || line.name || "",
    display_name: line.display_name || line.name || "",
    category: line.category || "",
    subtype: line.subtype || "",
    sku: line.sku || "",
    service_code: line.service_code || "",
    quantity: formatQtyOneDecimal(line.qty ?? 1),
    unit: line.unit || "",
    unit_price: formPriceDollarsFromCents(line.price_cents, line.category),
    order_number: orderNumber,
    metadata,
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
    const metadata =
      lineEditForm.value.metadata && typeof lineEditForm.value.metadata === "object"
        ? { ...lineEditForm.value.metadata }
        : {};
    const orderNumber = String(lineEditForm.value.order_number || "").trim();
    if (orderNumber) {
      metadata.order_number = orderNumber;
    } else {
      delete metadata.order_number;
    }
    const qty = Number.parseFloat(String(lineEditForm.value.quantity).replace(/,/g, "")) || 0;
    const unitCents = signedUnitCents(lineEditForm.value.category, lineEditForm.value.unit_price);
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
      line_total_cents: signedLineTotalCents(
        lineEditForm.value.category,
        qty,
        lineEditForm.value.unit_price,
      ),
      metadata: Object.keys(metadata).length ? metadata : null,
    });
    toast.success("Line item updated.");
    lineEditModalOpen.value = false;
    lineEditTarget.value = null;
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
    lineDeleteModalOpen.value = false;
    lineEditTarget.value = null;
    await load();
  } catch (e) {
    toast.errorFrom(e, "Could not delete line item.");
  } finally {
    lineDeleteBusy.value = false;
  }
}

function openAddItemModal() {
  addItemForm.value = {
    display_name: "",
    category: "",
    subtype: "",
    sku: "",
    service_code: "",
    quantity: "1.0",
    unit: "",
    unit_price: "0.00",
    order_number: "",
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
    const orderNumber = String(addItemForm.value.order_number || "").trim();
    const qty = Number.parseFloat(String(addItemForm.value.quantity).replace(/,/g, "")) || 0;
    const unitCents = signedUnitCents(addItemForm.value.category, addItemForm.value.unit_price);
    const serviceName = String(addItemForm.value.display_name || "").trim();
    await api.post(`/invoices/${invoice.value.id}/add-item`, {
      description: serviceName || "Service",
      display_name: serviceName || "Service",
      category: addItemForm.value.category || null,
      subtype: addItemForm.value.subtype || null,
      sku: addItemForm.value.sku || null,
      service_code: addItemForm.value.service_code || null,
      quantity: qty,
      unit: addItemForm.value.unit || null,
      unit_price_cents: unitCents,
      line_total_cents: signedLineTotalCents(
        addItemForm.value.category,
        qty,
        addItemForm.value.unit_price,
      ),
      metadata: orderNumber ? { order_number: orderNumber } : null,
    });
    toast.success("Item added to invoice.");
    addItemModalOpen.value = false;
    await load();
  } catch (e) {
    toast.errorFrom(e, "Could not add item.");
  } finally {
    addItemBusy.value = false;
  }
}

function openCcFeeModal() {
  ccFeeLabel.value = "Credit Card Fee";
  ccFeeModalOpen.value = true;
}

function closeCcFeeModal(force = false) {
  if (ccFeeBusy.value && !force) return;
  ccFeeModalOpen.value = false;
}

async function confirmCcFee() {
  if (!invoice.value) return;
  if (!canAddCcFee.value) {
    toast.error("Credit card fee cannot be added for this invoice.");
    return;
  }
  ccFeeBusy.value = true;
  try {
    await api.post(`/invoices/${invoice.value.id}/add-cc-fee`, {
      label: ccFeeLabel.value || "Credit Card Fee",
    });
    toast.success("CC fee added.");
    closeCcFeeModal(true);
    await load();
  } catch (e) {
    toast.errorFrom(e, "Could not add CC fee.");
  } finally {
    ccFeeBusy.value = false;
  }
}

function closePayModal(force = false) {
  if ((payBusy.value || payContextBusy.value) && !force) return;
  payModalOpen.value = false;
}

async function loadPayContext(options = {}) {
  if (!invoice.value?.id) return null;
  const includeRows = options.includeRows === true;
  const { data } = await api.get(`/invoices/${invoice.value.id}/pay-context`);
  payOpenBalanceCents.value = Number(data?.open_balance_cents || 0);
  payPendingBalanceCents.value = Number(data?.pending_balance_cents || 0);
  if (includeRows) {
    payRows.value = Array.isArray(data?.rows) ? data.rows : [];
  }
  return data;
}

async function loadAccountBalanceSummary() {
  if (!invoice.value?.id || !canUpdate.value || invoice.value.status === "void") {
    payOpenBalanceCents.value = 0;
    payPendingBalanceCents.value = 0;
    return;
  }
  accountBalanceLoading.value = true;
  try {
    await loadPayContext();
  } catch {
    payOpenBalanceCents.value = 0;
    payPendingBalanceCents.value = 0;
  } finally {
    accountBalanceLoading.value = false;
  }
}

function toggleRightActionsMenu(event) {
  event?.stopPropagation?.();
  rightActionsMenuOpen.value = !rightActionsMenuOpen.value;
}

function closeRightActionsMenu() {
  rightActionsMenuOpen.value = false;
}

function openRightMenuVoid() {
  closeRightActionsMenu();
  openVoidModal();
}

function openRightMenuDownloadPdf() {
  closeRightActionsMenu();
  downloadInvoicePdf();
}

function openRightMenuOpenInvoice() {
  closeRightActionsMenu();
  markInvoiceOpen();
}

function openRightMenuCcFee() {
  if (!canAddCcFee.value) return;
  closeRightActionsMenu();
  openCcFeeModal();
}

function openRightMenuRestoreDraft() {
  closeRightActionsMenu();
  restoreInvoiceDraft();
}

function openRightMenuDelete() {
  closeRightActionsMenu();
  openDeleteModal();
}

async function openStripeModal() {
  if (!invoice.value || !canStripeCharge.value) return;
  if (!hasStripeCustomerId.value) {
    toast.error("Set Stripe customer ID in Client Account > Settings first.");
    return;
  }
  stripeModalOpen.value = true;
  stripeMethods.value = [];
  stripePaymentMethodId.value = "";
  stripeAmount.value = (Number(invoice.value.balance_due_cents || 0) / 100).toFixed(2);
  stripeMethodsLoading.value = true;
  try {
    const { data } = await api.get(`/invoices/${invoice.value.id}/stripe-payment-methods`);
    stripeMethods.value = Array.isArray(data?.methods) ? data.methods : [];
    const defaultMethod = stripeMethods.value.find((m) => m?.is_default);
    stripePaymentMethodId.value = String(defaultMethod?.id || stripeMethods.value[0]?.id || "");
  } catch (e) {
    toast.errorFrom(e, "Could not load Stripe payment methods.");
    stripeModalOpen.value = false;
  } finally {
    stripeMethodsLoading.value = false;
  }
}

function closeStripeModal(force = false) {
  if (stripeChargeBusy.value && !force) return;
  stripeModalOpen.value = false;
}

const stripeAmountCents = computed(() => {
  const n = Number.parseFloat(String(stripeAmount.value || "").replace(/,/g, ""));
  if (!Number.isFinite(n) || n <= 0) return 0;
  return Math.round(n * 100);
});

const stripeCanSubmit = computed(
  () =>
    !!invoice.value &&
    !!String(stripePaymentMethodId.value || "").trim() &&
    stripeAmountCents.value > 0 &&
    stripeAmountCents.value <= Number(invoice.value?.balance_due_cents || 0),
);

async function confirmStripeCharge() {
  if (!invoice.value || !stripeCanSubmit.value) {
    toast.error("Choose a payment method and valid amount.");
    return;
  }
  const fullBalanceCents = Number(invoice.value.balance_due_cents || 0);
  if (fullBalanceCents <= 0) {
    toast.error("No balance due.");
    return;
  }
  stripeChargeBusy.value = true;
  try {
    const { data } = await api.post(`/invoices/${invoice.value.id}/stripe-charge`, {
      payment_method_id: stripePaymentMethodId.value,
      amount_cents: fullBalanceCents,
      payment_type: "Credit Card",
      payment_date: new Date().toISOString().slice(0, 10),
    });
    if (data?.result === "pending") {
      toast.success("Stripe payment submitted and pending settlement.");
    } else if (data?.result === "succeeded") {
      toast.success("Stripe payment completed.");
    } else {
      toast.error("Stripe payment failed.");
    }
    closeStripeModal(true);
    await load();
  } catch (e) {
    toast.errorFrom(e, "Could not process Stripe payment.");
  } finally {
    stripeChargeBusy.value = false;
  }
}

function goToInvoiceBucket(bucket) {
  if (!invoice.value?.client_account_id) return;
  let status = "all";
  if (bucket === "open") status = "open";
  if (bucket === "past_due") status = "open";
  if (bucket === "draft") status = "draft";
  router.push({
    path: "/billing/invoices",
    query: {
      status,
      client_account_id: String(invoice.value.client_account_id),
    },
  });
}

async function openPayModal() {
  if (!invoice.value || !payInvoiceEnabled.value) return;
  payAmount.value = "";
  payType.value = String(invoice.value.client_account_default_payment_type || "");
  payDate.value = new Date().toISOString().slice(0, 10);
  payNotes.value = "";
  payFundsCents.value = 0;
  payOpenBalanceCents.value = 0;
  payPendingBalanceCents.value = 0;
  payRows.value = [];
  paySelectedInvoiceIds.value = [];
  payFilterStatus.value = "all";
  payModalOpen.value = true;
  payContextBusy.value = true;
  try {
    await loadPayContext({ includeRows: true });
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
    closePayModal(true);
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

function closeVoidModal(force = false) {
  if (voidBusy.value && !force) return;
  voidModalOpen.value = false;
}

async function confirmVoid() {
  if (!invoice.value) return;
  voidBusy.value = true;
  try {
    await api.post(`/invoices/${invoice.value.id}/void`);
    toast.success("Invoice voided.");
    closeVoidModal(true);
    await load();
  } catch (e) {
    toast.errorFrom(e, "Could not void invoice.");
  } finally {
    voidBusy.value = false;
  }
}

async function restoreInvoiceDraft() {
  if (!invoice.value) return;
  try {
    await api.post(`/invoices/${invoice.value.id}/status`, { status: "draft" });
    toast.success("Invoice moved to draft.");
    await load();
  } catch (e) {
    toast.errorFrom(e, "Could not move invoice to draft.");
  }
}

async function markInvoiceOpen() {
  if (!invoice.value || openInvoiceTabBusy.value) return;
  openInvoiceTabBusy.value = true;
  try {
    await api.post(`/invoices/${invoice.value.id}/status`, { status: "open" });
    toast.success("Invoice moved to open.");
    await load();
  } catch (e) {
    toast.errorFrom(e, "Could not move invoice to open.");
  } finally {
    openInvoiceTabBusy.value = false;
  }
}

function openDeleteModal() {
  deleteModalOpen.value = true;
}

function closeDeleteModal(force = false) {
  if (deleteBusy.value && !force) return;
  deleteModalOpen.value = false;
}

async function confirmDelete() {
  if (!invoice.value) return;
  deleteBusy.value = true;
  try {
    await api.delete(`/invoices/${invoice.value.id}`);
    toast.success("Invoice deleted.");
    closeDeleteModal(true);
    router.replace("/billing/invoices");
  } catch (e) {
    toast.errorFrom(e, "Could not delete invoice.");
  } finally {
    deleteBusy.value = false;
  }
}

onMounted(() => {
  document.addEventListener("click", onDocClick);
  window.addEventListener("scroll", onWindowScrollOrResize, true);
  window.addEventListener("resize", onWindowScrollOrResize);
  document.addEventListener("keydown", onDocKeydown);
  load();
});

onUnmounted(() => {
  document.removeEventListener("click", onDocClick);
  window.removeEventListener("scroll", onWindowScrollOrResize, true);
  window.removeEventListener("resize", onWindowScrollOrResize);
  document.removeEventListener("keydown", onDocKeydown);
});

function onDocClick(e) {
  if (!e.target?.closest?.("[data-row-actions]")) {
    lineMenuOpenId.value = null;
    groupMenuOpenId.value = null;
  }
  if (!e.target?.closest?.("[data-right-actions]")) {
    rightActionsMenuOpen.value = false;
  }
}

function onWindowScrollOrResize() {
  lineMenuOpenId.value = null;
  groupMenuOpenId.value = null;
  rightActionsMenuOpen.value = false;
}

function onDocKeydown(e) {
  if (e.key === "Escape") {
    lineMenuOpenId.value = null;
    groupMenuOpenId.value = null;
    rightActionsMenuOpen.value = false;
  }
}
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
            v-if="currentStatusKey !== 'void'"
            type="button"
            class="btn btn-outline-primary btn-sm"
            :disabled="copyLinkBusy"
            @click="copyCustomerLink"
          >
            {{ copyLinkBusy ? "Working…" : "Copy Customer Link" }}
          </button>
          <button
            v-if="canSendInvoiceFromDraft"
            type="button"
            class="btn btn-outline-primary btn-sm"
            @click="sendInvoice"
          >
            Send Invoice
          </button>
          <button
            v-if="payInvoiceVisible"
            type="button"
            class="btn btn-outline-primary btn-sm"
            :disabled="!payInvoiceEnabled"
            :title="payInvoiceDisabledTitle || undefined"
            @click="openPayModal"
          >
            Pay Invoice
          </button>
          <button
            v-if="canShowMessagingActions"
            type="button"
            class="btn btn-sm"
            :class="messagingActionsDisabled ? 'btn-outline-secondary' : 'btn-outline-primary'"
            :disabled="messagingActionsDisabled"
            :title="messagingActionsDisabledTitle || undefined"
            @click="openSendEmailModal"
          >
            Email Invoice
          </button>
          <button
            v-if="canShowMessagingActions"
            type="button"
            class="btn btn-sm"
            :class="messagingActionsDisabled ? 'btn-outline-secondary' : 'btn-outline-primary'"
            :disabled="messagingActionsDisabled"
            :title="messagingActionsDisabledTitle || undefined"
            @click="openSendWhatsappModal"
          >
            Send To Whatsapp
          </button>
          <button
            v-if="invoice && currentStatusKey !== 'paid' && currentStatusKey !== 'void'"
            type="button"
            class="btn btn-outline-primary btn-sm"
            :disabled="!canStripeCharge"
            :title="creditChargeDisabledTitle"
            @click="openStripeModal"
          >
            Credit Charge
          </button>
        </div>
        <div class="billing-inv-toolbar-actions ms-lg-auto" data-right-actions>
          <button
            type="button"
            class="staff-action-btn staff-action-btn--more"
            :aria-expanded="rightActionsMenuOpen"
            aria-label="Invoice options"
            @click="toggleRightActionsMenu"
          >
            <CrmIconRowActions variant="horizontal" />
          </button>
          <div v-if="rightActionsMenuOpen" class="staff-row-menu billing-inv-right-menu">
            <button
              v-if="canVoidInvoice"
              type="button"
              class="staff-row-menu__item staff-row-menu__item--danger"
              role="menuitem"
              @click="openRightMenuVoid"
            >
              Void Invoice
            </button>
            <button
              v-if="canRestoreDraft"
              type="button"
              class="staff-row-menu__item"
              role="menuitem"
              @click="openRightMenuRestoreDraft"
            >
              Make Draft
            </button>
            <button
              v-if="canDelete && (currentStatusKey === 'draft' || canHardDeleteInvoices)"
              type="button"
              class="staff-row-menu__item staff-row-menu__item--danger"
              role="menuitem"
              @click="openRightMenuDelete"
            >
              {{
                canHardDeleteInvoices && currentStatusKey !== "draft"
                  ? "Delete Invoice"
                  : "Delete Draft"
              }}
            </button>
            <button
              v-if="canShareInvoice"
              type="button"
              class="staff-row-menu__item"
              role="menuitem"
              :disabled="pdfDownloading"
              @click="openRightMenuDownloadPdf"
            >
              {{ pdfDownloading ? "Downloading..." : "Download PDF" }}
            </button>
            <button
              v-if="canUpdateInvoiceStatus && currentStatusKey !== 'open'"
              type="button"
              class="staff-row-menu__item"
              role="menuitem"
              :disabled="openInvoiceTabBusy"
              @click="openRightMenuOpenInvoice"
            >
              {{ openInvoiceTabBusy ? "Updating..." : "Open Invoice" }}
            </button>
            <button
              v-if="canAddCcFee"
              type="button"
              class="staff-row-menu__item"
              role="menuitem"
              @click="openRightMenuCcFee"
            >
              Add CC Fee
            </button>
          </div>
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
                  <div class="min-w-0 billing-inv-invoice-to-block">
                    <div class="billing-inv-section-label">Invoice to</div>
                    <div class="fw-bold text-body fs-5 mb-1">
                      <a
                        v-if="invoice.client_account_id && clientAccountDetailHref"
                        :href="clientAccountDetailHref"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="text-body text-decoration-none billing-inv-client-link"
                      >{{ invoice.client_company_name || "—" }}</a>
                      <template v-else>{{ invoice.client_company_name || "—" }}</template>
                    </div>
                    <div
                      v-if="invoice.client_account_contact_name"
                      class="small text-body mb-1"
                    >
                      {{ invoice.client_account_contact_name }}
                    </div>
                    <div class="small text-secondary lh-sm billing-inv-issuer-lines">
                      <div v-if="invoice.client_account_street">
                        {{ invoice.client_account_street }}
                      </div>
                      <div v-if="invoiceClientCityStateZip">
                        {{ invoiceClientCityStateZip }}
                      </div>
                      <div v-if="invoice.client_account_country" class="mt-1">
                        {{ invoice.client_account_country }}
                      </div>
                      <div v-if="invoice.client_account_email" class="mt-2">
                        <a
                          class="text-decoration-none text-body"
                          :href="`mailto:${invoice.client_account_email}`"
                        >{{ invoice.client_account_email }}</a>
                      </div>
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
                    <div v-if="canEditInvoiceDates" class="mb-2 text-lg-end">
                      <div class="text-secondary mb-1">Invoice Date (service period)</div>
                      <div
                        v-if="invoiceDateRangeLabel && invoiceDateRangeLabel !== '—'"
                        class="small text-body mb-2 billing-inv-service-period-readonly"
                      >
                        {{ invoiceDateRangeLabel }}
                      </div>
                      <div
                        class="d-inline-flex flex-wrap align-items-center justify-content-lg-end billing-inv-date-range"
                      >
                        <input
                          v-model="editBillingPeriodStart"
                          type="date"
                          class="form-control form-control-sm billing-inv-date-input"
                          :disabled="invoiceDatesSaving"
                        />
                        <span class="text-secondary billing-inv-date-sep" aria-hidden="true">–</span>
                        <input
                          v-model="editBillingPeriodEnd"
                          type="date"
                          class="form-control form-control-sm billing-inv-date-input"
                          :disabled="invoiceDatesSaving"
                        />
                      </div>
                    </div>
                    <div v-else class="mb-1">
                      <span class="text-secondary">Invoice Date</span>
                      <span class="fw-medium ms-1">{{ invoiceDateRangeLabel }}</span>
                    </div>
                    <div class="mb-1">
                      <span class="text-secondary d-lg-block">Date due</span>
                      <template v-if="canEditInvoiceDates">
                        <input
                          v-model="editDueAt"
                          type="date"
                          class="form-control form-control-sm billing-inv-date-input d-inline-block mt-1 mt-lg-0 ms-lg-1"
                          :disabled="invoiceDatesSaving"
                        />
                      </template>
                      <span v-else class="fw-medium ms-1">{{ formatInvoiceShortDate(invoice.due_at) }}</span>
                    </div>
                    <div v-if="canEditInvoiceDates" class="mt-2">
                      <button
                        type="button"
                        class="btn btn-sm btn-primary"
                        :disabled="invoiceDatesSaving"
                        @click="saveInvoiceDates"
                      >
                        {{ invoiceDatesSaving ? "Saving…" : "Save Dates" }}
                      </button>
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
                <div class="billing-inv-section-label">Payment type</div>
                <div v-if="canUpdateClientAccount && invoice.client_account_id" class="d-flex flex-wrap align-items-center gap-2">
                  <select v-model="editPaymentType" class="form-select form-select-sm billing-inv-payment-select">
                    <option value="">—</option>
                    <option v-for="opt in CLIENT_PAYMENT_TYPE_OPTIONS" :key="opt" :value="opt">{{ opt }}</option>
                  </select>
                  <button
                    type="button"
                    class="btn btn-sm btn-primary"
                    :disabled="paymentTypeSaving"
                    @click="saveClientPaymentType"
                  >
                    {{ paymentTypeSaving ? "Saving…" : "Save" }}
                  </button>
                </div>
                <div v-else class="fw-medium text-body">
                  {{ invoicePaymentTypeDisplay || "—" }}
                </div>
              </div>
              <div class="col-md-6 text-md-end">
                <div class="billing-inv-section-label">Bill to</div>
                <div class="fw-semibold text-body fs-5">
                  Total due:
                  {{ formatCents(invoice.balance_due_cents, invoice.currency) }}
                </div>
              </div>
            </div>

            <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
              <button
                v-if="canUpdateInvoiceStatus"
                type="button"
                class="staff-status-badge text-capitalize"
                :class="statusBadgeClass(statusDisplayText)"
                title="Change invoice status"
                @click="openStatusModal"
              >
                {{ statusDisplayText }}
              </button>
              <span
                v-else
                class="staff-status-badge text-capitalize"
                :class="statusBadgeClass(statusDisplayText)"
              >
                {{ statusDisplayText }}
              </span>
            </div>

            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
              <h2 class="h6 fw-semibold mb-0">Fulfillment Services</h2>
              <button
                v-if="canAddCharge"
                type="button"
                class="btn btn-primary btn-sm staff-page-primary flex-shrink-0"
                @click="openAddItemModal"
              >
                Add To Invoice
              </button>
            </div>
            <template v-if="currentStatusKey === 'draft' && canUpdate && false">
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
                            signedLineTotalCents(line.category, line.quantity, line.unit_price),
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
                      <th v-if="canUpdate && currentStatusKey !== 'void'" class="text-end">Actions</th>
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
                        <td v-if="canUpdate && currentStatusKey !== 'void'" class="text-end" @click.stop>
                          <div data-row-actions class="position-relative d-inline-block">
                            <button
                              v-if="row.line_group_key"
                              type="button"
                              class="staff-action-btn staff-action-btn--more"
                              :class="{ 'is-open': groupMenuOpenId === row.id }"
                              :aria-expanded="groupMenuOpenId === row.id"
                              aria-haspopup="true"
                              aria-label="Grouped row actions"
                              @click.stop="toggleGroupMenu(row.id, $event)"
                            >
                              <CrmIconRowActions variant="horizontal" />
                            </button>
                            <div
                              v-if="groupMenuOpenId === row.id"
                              data-row-actions
                              class="billing-inline-menu billing-inline-menu--row"
                              role="menu"
                              :style="groupMenuStyle"
                            >
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
                      <td :colspan="canUpdate && currentStatusKey !== 'void' ? 6 : 5" class="text-center text-secondary py-3">
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
                        <th class="text-end">{{ selectedBreakdownOrderColumnLabel }}</th>
                        <th class="text-end">Qty</th>
                        <th class="text-end">Price</th>
                        <th class="text-end">Total</th>
                        <th
                          v-if="currentStatusKey !== 'void' && canUpdate"
                          class="text-end"
                          style="width: 3.5rem"
                        >
                          Actions
                        </th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr
                        v-for="row in selectedTableRowDetails"
                        :key="row.id"
                        class="billing-inv-line-detail"
                        :class="{ 'billing-inv-line-detail--danger': row?.metadata?.box_not_selected }"
                      >
                        <td class="fw-medium" :class="{ 'text-danger fw-semibold': row?.metadata?.box_not_selected }">
                          {{ row.name }}
                        </td>
                        <td>{{ row.type }}</td>
                        <td class="text-end text-nowrap">{{ row.order_number || "—" }}</td>
                        <td class="text-end text-nowrap">{{ formatQtyDisplay(row.qty) }}</td>
                        <td class="text-end">{{ formatCents(row.price_cents, invoice.currency) }}</td>
                        <td class="text-end">{{ formatCents(row.total_cents, invoice.currency) }}</td>
                        <td v-if="currentStatusKey !== 'void' && canUpdate" class="text-end">
                          <div data-row-actions class="position-relative d-inline-block">
                            <button
                              type="button"
                              class="staff-action-btn staff-action-btn--more"
                              :class="{ 'is-open': lineMenuOpenId === row.id }"
                              :aria-expanded="lineMenuOpenId === row.id"
                              aria-haspopup="true"
                              aria-label="Line item actions"
                              @click.stop="toggleLineMenu(row.id, $event)"
                            >
                              <CrmIconRowActions variant="horizontal" />
                            </button>
                            <div
                              v-if="lineMenuOpenId === row.id"
                              data-row-actions
                              class="billing-inline-menu billing-inline-menu--row"
                              role="menu"
                              :style="lineMenuStyle"
                            >
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
                        <td :colspan="currentStatusKey !== 'void' && canUpdate ? 7 : 6" class="text-center text-secondary py-3">
                          No line items.
                        </td>
                      </tr>
                    </tbody>
                  </table>
                </div>
              </div>
            </template>

            <div class="row mt-4 pt-3 border-top">
              <div class="col-12 col-md-10 col-lg-8 ms-md-auto">
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
            <div class="billing-inv-summary-grid mb-3">
              <button
                type="button"
                class="staff-stat-card billing-inv-summary-card h-100 text-start"
                :disabled="accountBalanceLoading"
                @click="goToInvoiceBucket('open')"
              >
                <p class="staff-stat-card__label">Open Balance Due</p>
                <p class="staff-stat-card__value">
                  {{ formatCents(payOpenBalanceCents, invoice.currency) }}
                </p>
                <p class="staff-stat-card__sub">Sent and partial — unpaid total</p>
                <div
                  class="staff-stat-card__icon text-white"
                  style="background: #2563eb"
                  aria-hidden="true"
                >
                  <svg width="22" height="22" fill="currentColor" viewBox="0 0 24 24">
                    <path
                      d="M11.8 10.9c-2.27-.59-3-1.2-3-2.15 0-1.09 1.01-1.85 2.7-1.85 1.78 0 2.44.85 2.5 2.1h2.21c-.07-1.72-1.12-3.3-3.21-3.81V3h-3v2.16c-1.94.42-3.5 1.68-3.5 3.61 0 2.31 1.91 3.46 4.7 4.13 2.5.6 3 1.48 3 2.41 0 .69-.49 1.79-2.7 1.79-2.06 0-2.87-.92-2.98-2.1h-2.2c.12 2.19 1.76 3.42 3.68 3.83V21h3v-2.15c1.95-.37 3.5-1.5 3.5-3.55 0-2.84-2.43-3.81-4.7-4.4z"
                    />
                  </svg>
                </div>
              </button>
              <button
                type="button"
                class="staff-stat-card billing-inv-summary-card h-100 text-start"
                :disabled="accountBalanceLoading"
                @click="goToInvoiceBucket('draft')"
              >
                <p class="staff-stat-card__label">Draft Balance</p>
                <p class="staff-stat-card__value">
                  {{ formatCents(payPendingBalanceCents, invoice.currency) }}
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
          </div>

          <div class="staff-surface p-4 mb-4 billing-inv-totals-check-card">
            <h2 class="h6 fw-semibold mb-2">Category Totals</h2>
            <p v-if="!hasFulfillmentFirstPickRow" class="small text-secondary mb-3 mb-lg-2">
              No Fulfillment (First Pick) row
            </p>
            <ul class="list-unstyled mb-0 billing-inv-totals-check-rows">
              <li class="billing-inv-totals-check-row">
                <span class="billing-inv-totals-check-label">Fulfillment</span>
                <span class="billing-inv-totals-check-qty text-nowrap">{{
                  formatQtyDisplay(fulfillmentBaselineQty)
                }}</span>
                <span
                  class="billing-inv-totals-check-ind billing-inv-totals-check-ind--ok"
                  aria-label="Baseline reference"
                >
                  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <path
                      d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"
                      fill="currentColor"
                    />
                  </svg>
                </span>
              </li>
              <li class="billing-inv-totals-check-row">
                <span class="billing-inv-totals-check-label">Postage</span>
                <span class="billing-inv-totals-check-qty text-nowrap">{{
                  formatQtyDisplay(postageCategoryQtySum)
                }}</span>
                <span
                  class="billing-inv-totals-check-ind"
                  :class="
                    postageMatchesFulfillmentBaseline
                      ? 'billing-inv-totals-check-ind--ok'
                      : 'billing-inv-totals-check-ind--bad'
                  "
                  :aria-label="postageMatchesFulfillmentBaseline ? 'Matches fulfillment' : 'Does not match fulfillment'"
                >
                  <svg
                    v-if="postageMatchesFulfillmentBaseline"
                    width="18"
                    height="18"
                    viewBox="0 0 24 24"
                    fill="none"
                    aria-hidden="true"
                  >
                    <path
                      d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"
                      fill="currentColor"
                    />
                  </svg>
                  <svg v-else width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <path
                      d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"
                      fill="currentColor"
                    />
                  </svg>
                </span>
              </li>
              <li class="billing-inv-totals-check-row">
                <span class="billing-inv-totals-check-label">Packaging</span>
                <span class="billing-inv-totals-check-qty text-nowrap">{{
                  formatQtyDisplay(packagingFilteredCategoryQtySum)
                }}</span>
                <span
                  class="billing-inv-totals-check-ind"
                  :class="
                    packagingMatchesFulfillmentBaseline
                      ? 'billing-inv-totals-check-ind--ok'
                      : 'billing-inv-totals-check-ind--bad'
                  "
                  :aria-label="
                    packagingMatchesFulfillmentBaseline ? 'Matches fulfillment' : 'Does not match fulfillment'
                  "
                >
                  <svg
                    v-if="packagingMatchesFulfillmentBaseline"
                    width="18"
                    height="18"
                    viewBox="0 0 24 24"
                    fill="none"
                    aria-hidden="true"
                  >
                    <path
                      d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"
                      fill="currentColor"
                    />
                  </svg>
                  <svg v-else width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <path
                      d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"
                      fill="currentColor"
                    />
                  </svg>
                </span>
              </li>
            </ul>
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
              <div class="billing-group-edit-bulk-panel">
                <div class="billing-group-edit-bulk-panel__title">Bulk edit</div>
                <div class="billing-group-edit-toolbar mb-0">
                  <div class="billing-group-edit-bulk billing-group-edit-bulk--wrap">
                    <input
                      v-model="groupBulkQty"
                      type="text"
                      class="form-control form-control-sm text-end"
                      placeholder="Qty"
                      :disabled="groupEditBusy"
                    />
                    <input
                      v-model="groupBulkPrice"
                      type="text"
                      class="form-control form-control-sm text-end"
                      placeholder="Unit price"
                      :disabled="groupEditBusy"
                    />
                    <button
                      type="button"
                      class="btn btn-sm btn-primary"
                      :disabled="groupEditBusy"
                      @click="applyGroupBulkConsolidate"
                    >
                      Update
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" :disabled="groupEditBusy" @click="addGroupEditLine">
                      Add Line
                    </button>
                  </div>
                </div>
                <p class="small text-secondary mb-0 mt-2">
                  Update sets this quantity and unit price on every row below (service names, categories, and {{ groupEditOrderColumnLabel }} stay as they are per row).
                </p>
              </div>
              <div class="table-responsive">
                <table class="table table-sm align-middle mb-0 billing-inv-items-table">
                  <thead>
                    <tr>
                      <th>Service</th>
                      <th>Category</th>
                      <th>{{ groupEditOrderColumnLabel }}</th>
                      <th class="text-end">Qty</th>
                      <th class="text-end">Price</th>
                      <th class="text-end">Total</th>
                      <th />
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="(line, idx) in groupEditLines" :key="idx">
                      <td><input v-model="line.display_name" type="text" class="form-control form-control-sm" /></td>
                      <td>
                        <select v-model="line.category" class="form-select form-select-sm">
                          <option value="">Select category</option>
                          <option
                            v-for="category in invoiceCategoryOptions"
                            :key="`group-category-${category.value}`"
                            :value="category.value"
                          >
                            {{ category.label }}
                          </option>
                        </select>
                      </td>
                      <td><input v-model="line.metadata.order_number" type="text" class="form-control form-control-sm" /></td>
                      <td><input v-model="line.quantity" type="text" class="form-control form-control-sm text-end" /></td>
                      <td><input v-model="line.unit_price" type="text" class="form-control form-control-sm text-end" /></td>
                      <td class="text-end">{{ formatCents(signedLineTotalCents(line.category, line.quantity, line.unit_price), invoice.currency) }}</td>
                      <td class="text-end"><button type="button" class="btn btn-link btn-sm text-danger p-0" @click="removeGroupEditLine(idx)">Delete</button></td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
            <footer class="crm-vx-modal__footer d-flex flex-wrap gap-2 align-items-center justify-content-between">
              <button
                type="button"
                class="btn btn-sm btn-outline-danger"
                :disabled="groupEditBusy"
                @click="openGroupDeleteFromGroupEditModal"
              >
                Delete entire group
              </button>
              <div class="d-flex gap-2">
                <button type="button" class="crm-vx-modal-btn crm-vx-modal-btn--secondary" :disabled="groupEditBusy" @click="closeGroupEditModal">Cancel</button>
                <button type="button" class="crm-vx-modal-btn crm-vx-modal-btn--primary" :disabled="groupEditBusy" @click="confirmGroupEdit">{{ groupEditBusy ? "Saving…" : "Save" }}</button>
              </div>
            </footer>
          </div>
        </div>
      </Transition>
    </Teleport>

    <Teleport to="body">
      <Transition name="crm-vx-confirm">
        <div
          v-if="stripeModalOpen"
          class="crm-vx-modal-overlay"
          role="dialog"
          aria-modal="true"
          @click.self="closeStripeModal"
        >
          <div class="crm-vx-modal crm-vx-modal--sm" @click.stop>
            <header class="crm-vx-modal__head">
              <h2 class="crm-vx-modal__title">Pay with Stripe</h2>
            </header>
            <div class="crm-vx-modal__body">
              <div v-if="stripeMethodsLoading" class="py-3">
                <CrmLoadingSpinner message="Loading payment methods…" />
              </div>
              <template v-else>
                <label class="form-label">Payment Method</label>
                <select v-model="stripePaymentMethodId" class="form-select mb-3">
                  <option value="">Select payment method</option>
                  <option v-for="m in stripeMethods" :key="m.id" :value="m.id">
                    {{ m.label }}
                  </option>
                </select>
                <label class="form-label">Amount</label>
                <input
                  v-model="stripeAmount"
                  type="text"
                  class="form-control text-end"
                  inputmode="decimal"
                  placeholder="0.00"
                />
                <div class="small text-secondary mt-2">
                  Balance due: {{ formatCents(invoice?.balance_due_cents || 0, invoice?.currency || "USD") }}
                </div>
              </template>
            </div>
            <footer class="crm-vx-modal__footer d-flex gap-2 justify-content-end">
              <button
                type="button"
                class="crm-vx-modal-btn crm-vx-modal-btn--secondary"
                :disabled="stripeChargeBusy"
                @click="closeStripeModal"
              >
                Cancel
              </button>
              <button
                type="button"
                class="crm-vx-modal-btn crm-vx-modal-btn--primary"
                :disabled="stripeChargeBusy || stripeMethodsLoading || !stripeCanSubmit"
                @click="confirmStripeCharge"
              >
                {{ stripeChargeBusy ? "Charging…" : "Charge Stripe" }}
              </button>
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
          v-if="whatsappCaptureModalOpen"
          class="crm-vx-modal-overlay"
          role="dialog"
          aria-modal="true"
          @click.self="closeWhatsappCaptureModal"
        >
          <div class="crm-vx-modal crm-vx-modal--sm" @click.stop>
            <header class="crm-vx-modal__head">
              <h2 class="crm-vx-modal__title">Add WhatsApp API ID</h2>
            </header>
            <div class="crm-vx-modal__body">
              <p class="small text-secondary mb-3">
                This client account does not have a WhatsApp API ID on file. Enter the API chat/customer ID used by the WhatsApp provider. It will be saved to the client account settings.
              </p>
              <label class="form-label" for="billing-inv-wa-capture">WhatsApp API ID</label>
              <input
                id="billing-inv-wa-capture"
                v-model="whatsappCaptureApiId"
                type="text"
                class="form-control"
                placeholder="WhatsApp provider/API ID"
                autocomplete="off"
              />
            </div>
            <footer class="crm-vx-modal__footer d-flex gap-2 justify-content-end">
              <button
                type="button"
                class="crm-vx-modal-btn crm-vx-modal-btn--secondary"
                :disabled="whatsappCaptureBusy"
                @click="closeWhatsappCaptureModal"
              >
                Cancel
              </button>
              <button
                type="button"
                class="crm-vx-modal-btn crm-vx-modal-btn--primary"
                :disabled="whatsappCaptureBusy"
                @click="confirmWhatsappCapture"
              >
                {{ whatsappCaptureBusy ? "Saving…" : "Save & continue" }}
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
              <label class="form-label">Category</label>
              <select v-model="lineEditForm.category" class="form-select mb-2">
                <option value="">Select category</option>
                <option
                  v-for="category in invoiceCategoryOptions"
                  :key="`line-category-${category.value}`"
                  :value="category.value"
                >
                  {{ category.label }}
                </option>
              </select>
              <label class="form-label">Order #</label>
              <input v-model="lineEditForm.order_number" type="text" class="form-control mb-2" />
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
              <label class="form-label">Category</label>
              <select v-model="addItemForm.category" class="form-select mb-2">
                <option value="">Select category</option>
                <option
                  v-for="category in invoiceCategoryOptions"
                  :key="`add-category-${category.value}`"
                  :value="category.value"
                >
                  {{ category.label }}
                </option>
              </select>
              <label class="form-label">Order #</label>
              <input v-model="addItemForm.order_number" type="text" class="form-control mb-2" />
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
              <div class="small text-secondary">
                Account CC fee: {{ ccFeePercent.toFixed(2) }}%
              </div>
              <div class="small text-secondary">
                Base invoice total: {{ formatCents(ccFeeBaseCents, invoice.currency) }}
              </div>
              <div class="mt-2 fw-semibold">
                Fee to add: {{ formatCents(ccFeePreviewCents, invoice.currency) }}
              </div>
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
                        <option value="Manual">Manual</option>
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
                    <div class="text-end">
                      <button
                        type="button"
                        class="btn btn-success"
                        :disabled="!payCanAddFunds"
                        @click="addFundsToPayPool"
                      >
                        Add Funds
                      </button>
                      <div class="small text-secondary mt-1" style="max-width: 22rem; margin-left: auto">
                        Add funds to the pool, select invoice(s), then Pay Invoice. Only the pool amount is applied;
                        the invoice stays open until the balance is fully paid.
                      </div>
                    </div>
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
                              :class="payRowStatusBadgeClass(row)"
                            >
                              {{ payRowStatusLabel(row) }}
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
                      class="billing-pay-stat"
                      :class="[
                        'billing-pay-stat--blue',
                        { 'is-active': payFilterStatus === 'all' },
                      ]"
                      @click="payFilterStatus = 'all'"
                    >
                      <div class="billing-pay-stat__body">
                        <div>
                          <div class="billing-pay-stat__value">
                            {{ formatCents(payAvailableDisplayCents, invoice?.currency || 'USD') }}
                          </div>
                          <div class="billing-pay-stat__label">Available Funds</div>
                        </div>
                        <span class="billing-pay-stat__icon billing-pay-stat__icon--blue" aria-hidden="true">
                          <svg width="20" height="20" fill="currentColor" viewBox="0 0 24 24">
                            <path
                              d="M11.8 10.9c-2.27-.59-3-1.2-3-2.15 0-1.09 1.01-1.85 2.7-1.85 1.78 0 2.44.85 2.5 2.1h2.21c-.07-1.72-1.12-3.3-3.21-3.81V3h-3v2.16c-1.94.42-3.5 1.68-3.5 3.61 0 2.31 1.91 3.46 4.7 4.13 2.5.6 3 1.48 3 2.41 0 .69-.49 1.79-2.7 1.79-2.06 0-2.87-.92-2.98-2.1h-2.2c.12 2.19 1.76 3.42 3.68 3.83V21h3v-2.15c1.95-.37 3.5-1.5 3.5-3.55 0-2.84-2.43-3.81-4.7-4.4z"
                            />
                          </svg>
                        </span>
                      </div>
                    </button>
                    <button
                      type="button"
                      class="billing-pay-stat"
                      :class="[
                        'billing-pay-stat--green',
                        { 'is-active': payFilterStatus === 'open' },
                      ]"
                      @click="payFilterStatus = 'open'"
                    >
                      <div class="billing-pay-stat__body">
                        <div>
                          <div class="billing-pay-stat__value">
                            {{ formatCents(payOpenBalanceCents, invoice?.currency || 'USD') }}
                          </div>
                          <div class="billing-pay-stat__label">Open Balance Due</div>
                        </div>
                        <span class="billing-pay-stat__icon billing-pay-stat__icon--green" aria-hidden="true">
                          <svg width="20" height="20" fill="currentColor" viewBox="0 0 24 24">
                            <path
                              d="M11.8 10.9c-2.27-.59-3-1.2-3-2.15 0-1.09 1.01-1.85 2.7-1.85 1.78 0 2.44.85 2.5 2.1h2.21c-.07-1.72-1.12-3.3-3.21-3.81V3h-3v2.16c-1.94.42-3.5 1.68-3.5 3.61 0 2.31 1.91 3.46 4.7 4.13 2.5.6 3 1.48 3 2.41 0 .69-.49 1.79-2.7 1.79-2.06 0-2.87-.92-2.98-2.1h-2.2c.12 2.19 1.76 3.42 3.68 3.83V21h3v-2.15c1.95-.37 3.5-1.5 3.5-3.55 0-2.84-2.43-3.81-4.7-4.4z"
                            />
                          </svg>
                        </span>
                      </div>
                    </button>
                    <button
                      type="button"
                      class="billing-pay-stat"
                      :class="[
                        'billing-pay-stat--orange',
                        { 'is-active': payFilterStatus === 'pending' },
                      ]"
                      @click="payFilterStatus = 'pending'"
                    >
                      <div class="billing-pay-stat__body">
                        <div>
                          <div class="billing-pay-stat__value">
                            {{ formatCents(payPendingBalanceCents, invoice?.currency || 'USD') }}
                          </div>
                          <div class="billing-pay-stat__label">Draft Balance</div>
                        </div>
                        <span class="billing-pay-stat__icon billing-pay-stat__icon--gray" aria-hidden="true">
                          <svg width="20" height="20" fill="currentColor" viewBox="0 0 24 24">
                            <path
                              d="M14 2H6c-1.1 0-1.99.9-1.99 2L4 20c0 1.1.89 2 1.99 2H18c1.1 0 2-.9 2-2V8l-6-6zm2 16H8v-2h8v2zm0-4H8v-2h8v2zm-3-5V3.5L18.5 9H13z"
                            />
                          </svg>
                        </span>
                      </div>
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
      :title="
        invoice && canHardDeleteInvoices && currentStatusKey !== 'draft'
          ? 'Delete invoice?'
          : 'Delete draft?'
      "
      :message="
        invoice && canHardDeleteInvoices && currentStatusKey !== 'draft'
          ? `Permanently delete invoice ${invoice.invoice_number}? This cannot be undone.`
          : invoice
            ? `Delete ${invoice.invoice_number}? This cannot be undone.`
            : ''
      "
      confirm-label="Delete"
      cancel-label="Cancel"
      :busy="deleteBusy"
      danger
      @close="closeDeleteModal"
      @confirm="confirmDelete"
    />
    <CrmStatusUpdateModal
      v-model:open="statusModalOpen"
      v-model:status="statusForm"
      title="Invoice status"
      subtitle="Choose the billing status for this invoice."
      :statuses="invoiceStatuses"
      :busy="statusSaving"
      @save="saveStatusFromModal"
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
.billing-inv-toolbar-actions {
  display: flex;
  justify-content: flex-end;
  position: relative;
  align-items: center;
  flex-shrink: 0;
}
.billing-inv-right-menu {
  position: absolute;
  top: calc(100% + 0.35rem);
  right: 0;
  z-index: 20;
  min-width: 12rem;
}
.billing-inv-totals-check-card .billing-inv-totals-check-rows {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}
.billing-inv-totals-check-row {
  display: grid;
  grid-template-columns: 1fr auto auto;
  align-items: center;
  gap: 0.5rem 0.75rem;
  font-size: 0.875rem;
}
.billing-inv-totals-check-label {
  font-weight: 500;
  color: rgba(47, 43, 61, 0.85);
}
.billing-inv-totals-check-qty {
  font-variant-numeric: tabular-nums;
  text-align: right;
}
.billing-inv-totals-check-ind {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 1.5rem;
  flex-shrink: 0;
}
.billing-inv-totals-check-ind--ok {
  color: #16a34a;
}
.billing-inv-totals-check-ind--bad {
  color: #dc2626;
}
.billing-inv-summary-grid {
  display: grid;
  gap: 0.75rem;
}
.billing-inv-summary-card {
  width: 100%;
  cursor: pointer;
  text-align: left;
  font: inherit;
  color: inherit;
  border: 1px solid rgba(47, 43, 61, 0.14) !important;
  transition:
    border-color 0.15s ease,
    box-shadow 0.15s ease,
    transform 0.15s ease;
}
.billing-inv-summary-card:hover:not(:disabled) {
  border-color: rgba(115, 103, 240, 0.35) !important;
  box-shadow: 0 0.45rem 1rem rgba(47, 43, 61, 0.12);
  transform: translateY(-1px);
}
.billing-inv-summary-card:disabled {
  opacity: 0.75;
  cursor: not-allowed;
}
.billing-inv-summary-card .staff-stat-card__icon {
  width: 6.2rem;
  height: 6.2rem;
  border-radius: 0.6rem;
}
.billing-inv-summary-card .staff-stat-card__icon svg {
  width: 1.85rem;
  height: 1.85rem;
}
.billing-group-edit-bulk-panel {
  border: 1px solid rgba(47, 43, 61, 0.1);
  border-radius: 0.5rem;
  padding: 0.75rem 1rem;
  margin-bottom: 0.75rem;
  background: var(--bs-tertiary-bg, #f8f9fa);
}
.billing-group-edit-bulk-panel__title {
  font-size: 0.75rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--bs-secondary-color, #6c757d);
  margin-bottom: 0.5rem;
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
.billing-inv-line-detail--danger td {
  background: rgba(234, 84, 85, 0.08);
}
.billing-group-edit-toolbar {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  justify-content: space-between;
}
.billing-group-edit-bulk {
  display: flex;
  align-items: center;
  gap: 0.45rem;
}
.billing-group-edit-bulk .form-control {
  width: 8rem;
}
.billing-group-edit-bulk--wrap {
  flex-wrap: wrap;
  justify-content: flex-start;
}
.billing-inline-menu {
  position: fixed;
  min-width: 12rem;
  background: #fff;
  border: 1px solid #e8e7ed;
  border-radius: 0.5rem;
  box-shadow: 0 0.5rem 1rem rgba(47, 43, 61, 0.12);
  z-index: 2200;
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
  border: 2px solid #c8cad6!important;
  border-radius: 0.85rem;
  padding: 1rem 1.1rem;
  text-align: left;
  cursor: pointer;
  font: inherit;
  background: #fff;
  color: #2f2b3d;
  box-shadow:
    inset 0 0 0 1px rgba(255, 255, 255, 0.85),
    0 2px 10px rgba(47, 43, 61, 0.06);
  transition:
    border-color 0.15s ease,
    transform 0.15s ease,
    box-shadow 0.15s ease,
    opacity 0.15s ease;
}
.billing-pay-stat:hover {
  transform: translateY(-1px);
  border-color: #b7bac8;
  box-shadow: 0 0.45rem 1rem rgba(47, 43, 61, 0.12);
}
.billing-pay-stat.is-active {
  border-color: #8f94a8;
  box-shadow: 0 0 0 3px rgba(143, 148, 168, 0.2);
}
.billing-pay-stat--blue {
  border-color: #c8cad6;
}
.billing-pay-stat--green {
  border-color: #c8cad6;
}
.billing-pay-stat--red {
  border-color: #c8cad6;
}
.billing-pay-stat--orange {
  border-color: #c8cad6;
}
.billing-pay-stat__body {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.85rem;
}
.billing-pay-stat__value {
  font-size: 1.4rem;
  font-weight: 600;
  line-height: 1.1;
  color: #2f2b3d;
}
.billing-pay-stat__label {
  margin-top: 0.25rem;
  font-size: 0.82rem;
  color: #6c757d;
}
.billing-pay-stat__icon {
  width: 3rem;
  height: 3rem;
  border-radius: 0.65rem;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.billing-pay-stat__icon--blue {
  background: #2563eb;
  color: #fff;
}
.billing-pay-stat__icon--green {
  background: #28c76f;
  color: #fff;
}
.billing-pay-stat__icon--red {
  background: rgba(234, 84, 85, 0.2);
  color: #7f1d1d;
}
.billing-pay-stat__icon--gray {
  background: #e5e7eb;
  color: #6b7280;
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
.billing-inv-client-link:hover {
  text-decoration: underline !important;
}
.billing-inv-date-range {
  gap: 0.35rem;
  max-width: 100%;
}
.billing-inv-date-input {
  flex: 0 0 auto;
  width: 10.25rem;
  min-width: 0;
  max-width: 100%;
}
.billing-inv-date-sep {
  flex: 0 0 auto;
  line-height: 1;
  padding: 0 0.1rem;
}
.billing-inv-service-period-readonly {
  opacity: 0.92;
}
.billing-inv-payment-select {
  max-width: 12rem;
}
</style>
