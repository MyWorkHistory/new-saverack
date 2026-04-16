<script setup>
import { computed, inject, onMounted, ref, watch } from "vue";

import { useRouter } from "vue-router";
import api, { getApiBaseUrl } from "../../services/api";
import { BRAND_MARK_SRC } from "../../utils/brandAssets.js";
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
const draftEditMode = ref(false);
const draftGroupLines = ref([]);

const payModalOpen = ref(false);
const payAmount = ref("");
const payBusy = ref(false);

const voidModalOpen = ref(false);
const voidBusy = ref(false);

const deleteModalOpen = ref(false);
const deleteBusy = ref(false);

const pdfDownloading = ref(false);
const copyLinkBusy = ref(false);
const selectedTableRowId = ref("");

const invoiceLogoSrc = computed(() => BRAND_MARK_SRC());

function fallbackCategoryKey(raw) {
  const s = String(raw || "")
    .trim()
    .toLowerCase()
    .replace(/-/g, "_")
    .replace(/\s+/g, "_");
  if (!s) return "other";
  if (s === "ondemand" || s === "on_demand") return "on_demand";
  if (s.includes("on_demand")) return "on_demand";
  if (s.includes("product") && s.includes("demand")) return "on_demand";
  if (s === "adhoc" || s === "ad_hoc") return "ad_hoc";
  if (s === "credit" || s === "credits") return "credits";
  if (s === "storage") return "storage";
  if (["fulfillment", "postage", "packaging", "returns"].includes(s)) return s;
  return "other";
}

function fallbackCategoryLabel(cat, serviceName = "") {
  if (cat === "on_demand") return "Product (On-Demand)";
  if (cat === "ad_hoc") return "Ad Hoc";
  if (
    cat === "packaging" &&
    ["bubble wrap", "kraft paper", "bubble wrap & kraft paper"].includes(
      String(serviceName || "").trim().toLowerCase(),
    )
  ) {
    return serviceName;
  }
  const labels = {
    fulfillment: "Fulfillment",
    postage: "Postage",
    packaging: "Packaging",
    returns: "Returns",
    storage: "Storage",
    credits: "Credits",
    other: "Other",
  };
  return labels[cat] || "Other";
}

function fallbackServiceName(item) {
  const base = String(item?.display_name || item?.description || "—").trim();
  if (!base) return "—";
  const m = base.match(/^(Postage|Packaging|Fulfillment)\s*\((.+)\)$/i);
  if (m && m[2]) return String(m[2]).trim();
  return base;
}

function formatQtyDisplay(v) {
  const n = Number(v);
  if (!Number.isFinite(n)) return "0.000";
  if (Math.abs(n) < 1) return n.toFixed(3);
  return Number.isInteger(n) ? String(n) : n.toFixed(2);
}

function toArray(input) {
  if (Array.isArray(input)) return input;
  if (input && typeof input === "object") return Object.values(input);
  return [];
}

function normalizeDetailRow(raw, fallback = {}) {
  const service = String(
    raw?.name ||
      raw?.service ||
      raw?.display_name ||
      raw?.description ||
      fallback.service ||
      "—",
  ).trim();
  const cat = fallbackCategoryKey(raw?.category_key || raw?.category || fallback.category);
  return {
    id: raw?.id ?? fallback.id ?? `detail-${Math.random()}`,
    name: service || "—",
    type: raw?.type || raw?.category_text || fallbackCategoryLabel(cat, service),
    category_key: raw?.category_key || raw?.category || fallback.category || cat,
    qty: Number(raw?.qty ?? raw?.quantity ?? 0),
    price_cents: Number(raw?.price_cents ?? raw?.unit_price_cents ?? 0),
    total_cents: Number(raw?.total_cents ?? raw?.line_total_cents ?? 0),
    display_name: raw?.display_name || null,
    description: raw?.description || null,
    sku: raw?.sku || null,
    service_code: raw?.service_code || null,
    group_key: raw?.group_key || null,
    subtype: raw?.subtype || null,
    unit: raw?.unit || null,
    metadata: raw?.metadata || null,
  };
}

function normalizeTopRow(raw, index = 0) {
  const service = String(
    raw?.name || raw?.groupName || raw?.service || raw?.display_name || raw?.description || "—",
  ).trim();
  const cat = fallbackCategoryKey(raw?.category_key || raw?.category || raw?.type);
  const detailsRaw = toArray(raw?.details?.length ? raw.details : raw?.items);
  const details = detailsRaw.length
    ? detailsRaw.map((d, i) =>
        normalizeDetailRow(d, {
          id: raw?.id ? `${raw.id}-detail-${i}` : `row-${index}-detail-${i}`,
          service,
          category: raw?.category || cat,
        }),
      )
    : [
        normalizeDetailRow(raw, {
          id: raw?.id || `row-${index}`,
          service,
          category: raw?.category || cat,
        }),
      ];
  const qty = Number(raw?.qty ?? raw?.quantity ?? details.reduce((sum, d) => sum + Number(d.qty || 0), 0));
  const total = Number(
    raw?.total_cents ??
      raw?.line_total_cents ??
      details.reduce((sum, d) => sum + Number(d.total_cents || 0), 0),
  );
  const price = Number(raw?.price_cents ?? raw?.unit_price_cents ?? (qty ? Math.round(total / qty) : 0));
  return {
    id: raw?.id || raw?.group_key || `row-${index}`,
    name: service || "—",
    type: raw?.type || raw?.category_text || fallbackCategoryLabel(cat, service),
    qty,
    price_cents: price,
    total_cents: total,
    edit_group_key: raw?.edit_group_key || raw?.group_key || null,
    details,
  };
}

const invoiceTableRows = computed(() => {
  const presentationRows = toArray(
    invoice.value?.presentation?.rows ||
      invoice.value?.presentation?.items ||
      invoice.value?.rows ||
      invoice.value?.line_groups,
  );
  if (presentationRows.length) {
    return presentationRows.map((row, idx) => normalizeTopRow(row, idx));
  }

  const items = toArray(invoice.value?.items || invoice.value?.line_items || invoice.value?.lines);
  if (!items.length) return [];

  const groups = new Map();
  for (const item of items) {
    const cat = fallbackCategoryKey(item.category);
    const service = fallbackServiceName(item);
    const key = `${cat}::${String(item.group_key || service || "").toLowerCase()}`;
    if (!groups.has(key)) {
      groups.set(key, {
        id: key,
        name: service,
        type: fallbackCategoryLabel(cat, service),
        qty: 0,
        price_cents: 0,
        total_cents: 0,
        edit_group_key: item.group_key || null,
        details: [],
      });
    }
    const row = groups.get(key);
    const qty = Number(item.quantity || 0);
    const total = Number(item.line_total_cents || 0);
    row.qty += qty;
    row.total_cents += total;
    row.details.push({
      id: item.id,
      name: service,
      type: fallbackCategoryLabel(cat, service),
      category_key: item.category || cat,
      qty,
      price_cents: Number(item.unit_price_cents || 0),
      total_cents: total,
      display_name: item.display_name || null,
      description: item.description || null,
      sku: item.sku || null,
      service_code: item.service_code || null,
      group_key: item.group_key || null,
      subtype: item.subtype || null,
      unit: item.unit || null,
      metadata: item.metadata || null,
    });
  }

  return [...groups.values()].map((row) => ({
    ...row,
    price_cents: row.qty > 0 ? Math.round(row.total_cents / row.qty) : 0,
  }));
});

const invoiceVisibleRows = computed(() => {
  if (invoiceTableRows.value.length) {
    return invoiceTableRows.value;
  }

  const items = toArray(invoice.value?.items || invoice.value?.line_items || invoice.value?.lines);
  return items.map((item, idx) => {
    const service = fallbackServiceName(item);
    const cat = fallbackCategoryKey(item.category);
    return normalizeTopRow(
      {
        id: item.id ? `raw-${item.id}` : `raw-${idx}`,
        name: service,
        type: fallbackCategoryLabel(cat, service),
        category: item.category || cat,
        qty: Number(item.quantity || 0),
        price_cents: Number(item.unit_price_cents || 0),
        total_cents: Number(item.line_total_cents || 0),
        edit_group_key: item.group_key || null,
        details: [item],
      },
      idx,
    );
  });
});

const selectedTableRow = computed(() => {
  if (!selectedTableRowId.value) return null;
  return invoiceVisibleRows.value.find((r) => r.id === selectedTableRowId.value) || null;
});

const selectedTableRowDetails = computed(() => {
  const row = selectedTableRow.value;
  if (!row) return [];
  if (Array.isArray(row.details) && row.details.length) {
    return row.details;
  }
  return [
    {
      id: row.id,
      name: row.name,
      type: row.type,
      qty: row.qty,
      price_cents: row.price_cents,
      total_cents: row.total_cents,
    },
  ];
});

function openTableRow(row) {
  selectedTableRowId.value = row.id;
}

function closeTableRow() {
  selectedTableRowId.value = "";
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

function formatQtyOneDecimal(v) {
  const n = Number(v);
  if (!Number.isFinite(n)) return "0.0";
  return n.toFixed(1);
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

function syncDraftGroupFromSelection() {
  const row = selectedTableRow.value;
  draftGroupLines.value =
    row?.details?.length && draftEditMode.value
      ? row.details.map((detail) => ({
          id: detail.id,
          service: detail.name || "",
          categoryText: detail.type || "",
          category: detail.category_key || null,
          subtype: detail.subtype || null,
          display_name: detail.display_name || detail.name || "",
          description: detail.description || detail.name || "",
          sku: detail.sku || "",
          service_code: detail.service_code || null,
          quantity: formatQtyOneDecimal(detail.qty ?? 1),
          unit_price: (Number(detail.price_cents || 0) / 100).toFixed(2),
          unit: detail.unit || null,
          metadata: detail.metadata || null,
        }))
      : [];
}

watch([selectedTableRow, draftEditMode], () => {
  syncDraftGroupFromSelection();
});

async function load() {
  loading.value = true;
  try {
    const { data } = await api.get(`/invoices/${props.id}`);
    const payload = data?.data && typeof data.data === "object" ? data.data : data;
    invoice.value = payload;
    if (payload?.status !== "draft") {
      draftEditMode.value = false;
    }
    if (selectedTableRowId.value) {
      const stillExists = invoiceVisibleRows.value.some((r) => r.id === selectedTableRowId.value);
      if (!stillExists) selectedTableRowId.value = "";
    }
    syncEditFromInvoice();
    setCrmPageMeta({
      title: `Save Rack | ${payload?.invoice_number || "Invoice"}`,
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

function addDraftGroupLine() {
  const row = selectedTableRow.value;
  draftGroupLines.value = [
    ...draftGroupLines.value,
    {
      id: `new-${Date.now()}`,
      service: "",
      categoryText: row?.type || "",
      category: row?.details?.[0]?.category_key || null,
      subtype: row?.details?.[0]?.subtype || null,
      display_name: "",
      description: "",
      sku: "",
      service_code: row?.details?.[0]?.service_code || null,
      quantity: "1.0",
      unit_price: "0.00",
      unit: row?.details?.[0]?.unit || null,
      metadata: row?.details?.[0]?.metadata || null,
    },
  ];
}

function removeDraftGroupLine(idx) {
  if (draftGroupLines.value.length <= 1) return;
  draftGroupLines.value = draftGroupLines.value.filter((_, i) => i !== idx);
}

async function saveDraftGroup() {
  if (!invoice.value || invoice.value.status !== "draft") return;
  const row = selectedTableRow.value;
  const groupKey = row?.edit_group_key;
  if (!groupKey) {
    toast.error("This group cannot be edited yet.");
    return;
  }

  const items = [];
  for (const line of draftGroupLines.value) {
    const service = (line.service || "").trim();
    if (!service) continue;
    const qty = Number.parseFloat(String(line.quantity).replace(/,/g, "")) || 0;
    const unitCents = dollarsToCents(line.unit_price);
    const skuTrim = (line.sku || "").trim();
    items.push({
      description: service,
      display_name: service,
      category: line.category || null,
      subtype: line.subtype || null,
      sku: skuTrim || null,
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
    await api.put(`/invoices/${invoice.value.id}/line-groups/${encodeURIComponent(groupKey)}`, {
      items,
    });
    toast.success("Draft group saved.");
    await load();
  } catch (e) {
    toast.errorFrom(e, "Could not save draft group.");
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
    toast.success(
      "Invoice sent. For now, email notification is sent to chaowang318915@gmail.com (development).",
    );
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
                      <span class="text-secondary">Date issued</span>
                      <span class="fw-medium ms-1">{{
                        formatInvoiceLongDate(invoice.issued_at)
                      }}</span>
                    </div>
                    <div class="mb-1">
                      <span class="text-secondary">Date due</span>
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

            <div
              v-if="canUpdate && invoice.status !== 'draft'"
              class="alert alert-light border small py-2 mb-3 mb-md-4"
              role="status"
            >
              Line items can only be edited while this invoice is a draft. Save changes with
              <strong>Save Draft</strong> before sending.
            </div>

            <h2 class="h6 fw-semibold mb-3">Line items</h2>
            <div
              v-if="invoice.status === 'draft' && canUpdate"
              class="d-flex justify-content-end mb-2"
            >
              <button
                type="button"
                class="btn btn-sm btn-outline-secondary"
                :disabled="draftSaving"
                @click="draftEditMode = !draftEditMode"
              >
                {{ draftEditMode ? "View Grouped Lines" : "Edit Draft Lines" }}
              </button>
            </div>

            <template>
              <div v-if="!selectedTableRow" class="table-responsive billing-inv-items-wrap">
                <table class="table table-sm align-middle mb-0 billing-inv-items-table">
                  <thead>
                    <tr>
                      <th>Service</th>
                      <th>Category</th>
                      <th class="text-end">Qty</th>
                      <th class="text-end">Price</th>
                      <th class="text-end">Total</th>
                    </tr>
                  </thead>
                  <tbody>
                    <template v-for="row in invoiceVisibleRows" :key="row.id">
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
                      </tr>
                    </template>
                    <tr v-if="!invoiceVisibleRows.length">
                      <td colspan="5" class="text-center text-secondary py-3">No line items.</td>
                    </tr>
                  </tbody>
                </table>
              </div>

              <div v-if="selectedTableRow" class="billing-inv-drill mt-3">
                <div class="d-flex align-items-center justify-content-between mb-2">
                  <h3 class="h6 fw-semibold mb-0">
                    {{ selectedTableRow.name }} — {{ draftEditMode ? "Edit Group" : "Detail" }}
                  </h3>
                  <div class="d-flex gap-2">
                    <button
                      v-if="invoice.status === 'draft' && canUpdate && draftEditMode"
                      type="button"
                      class="btn btn-sm btn-outline-secondary"
                      :disabled="draftSaving"
                      @click="addDraftGroupLine"
                    >
                      Add Line
                    </button>
                    <button
                      type="button"
                      class="btn btn-sm btn-outline-secondary"
                      @click="closeTableRow"
                    >
                      Back to Invoice Items
                    </button>
                  </div>
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
                      </tr>
                    </thead>
                    <tbody>
                      <tr
                        v-for="(row, idx) in draftEditMode ? draftGroupLines : selectedTableRowDetails"
                        :key="row.id"
                        class="billing-inv-line-detail"
                      >
                        <template v-if="draftEditMode && invoice.status === 'draft' && canUpdate">
                          <td>
                            <input
                              v-model="row.service"
                              type="text"
                              class="form-control form-control-sm"
                              placeholder="Service"
                              :disabled="draftSaving"
                            />
                          </td>
                          <td>{{ row.categoryText }}</td>
                          <td class="text-end text-nowrap">
                            <input
                              v-model="row.quantity"
                              type="text"
                              inputmode="decimal"
                              class="form-control form-control-sm text-end"
                              :disabled="draftSaving"
                              @blur="row.quantity = formatQtyOneDecimal(row.quantity)"
                            />
                          </td>
                          <td class="text-end">
                            <input
                              v-model="row.unit_price"
                              type="text"
                              class="form-control form-control-sm text-end"
                              placeholder="0.00"
                              :disabled="draftSaving"
                            />
                          </td>
                          <td class="text-end">
                            <div class="d-flex align-items-center justify-content-end gap-2">
                              <span>
                                {{
                                  formatCents(
                                    Math.max(
                                      0,
                                      Math.round(
                                        (Number.parseFloat(String(row.quantity).replace(/,/g, "")) || 0) *
                                          dollarsToCents(row.unit_price),
                                      ),
                                    ),
                                    invoice.currency,
                                  )
                                }}
                              </span>
                              <button
                                type="button"
                                class="btn btn-link btn-sm text-danger p-0"
                                :disabled="draftSaving || draftGroupLines.length <= 1"
                                @click="removeDraftGroupLine(idx)"
                              >
                                Remove
                              </button>
                            </div>
                          </td>
                        </template>
                        <template v-else>
                          <td class="fw-medium">{{ row.name }}</td>
                          <td>{{ row.type }}</td>
                          <td class="text-end text-nowrap">{{ formatQtyDisplay(row.qty) }}</td>
                          <td class="text-end">{{ formatCents(row.price_cents, invoice.currency) }}</td>
                          <td class="text-end">{{ formatCents(row.total_cents, invoice.currency) }}</td>
                        </template>
                      </tr>
                      <tr v-if="!(draftEditMode ? draftGroupLines : selectedTableRowDetails).length">
                        <td colspan="5" class="text-center text-secondary py-3">No line items.</td>
                      </tr>
                    </tbody>
                  </table>
                </div>
                <div
                  v-if="invoice.status === 'draft' && canUpdate && draftEditMode"
                  class="mt-3 d-flex justify-content-end"
                >
                  <button
                    type="button"
                    class="btn btn-primary staff-page-primary btn-sm"
                    :disabled="draftSaving || !selectedTableRow?.edit_group_key"
                    @click="saveDraftGroup"
                  >
                    {{ draftSaving ? "Saving…" : "Save Group" }}
                  </button>
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
</style>
