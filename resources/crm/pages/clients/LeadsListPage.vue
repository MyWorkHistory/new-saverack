<script setup>
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast.js";
import { crmIsAdmin } from "../../utils/crmUser.js";
import { formatDateUs } from "../../utils/formatUserDates.js";
import { computed, inject, nextTick, onMounted, onUnmounted, ref, watch } from "vue";
import { RouterLink, useRoute, useRouter } from "vue-router";
import api from "../../services/api";
import ConfirmModal from "../../components/common/ConfirmModal.vue";
import CrmIconRowActions from "../../components/common/CrmIconRowActions.vue";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import LeadBulkEmailDrawer from "../../components/leads/LeadBulkEmailDrawer.vue";
import LeadBulkStatusDrawer from "../../components/leads/LeadBulkStatusDrawer.vue";
import LeadCreateDrawer from "../../components/leads/LeadCreateDrawer.vue";
import LeadQuickAddDrawer from "../../components/leads/LeadQuickAddDrawer.vue";
import LeadStatusUpdateModal from "../../components/leads/LeadStatusUpdateModal.vue";
import LeadSummaryCards from "../../components/leads/LeadSummaryCards.vue";
import {
  LEAD_FOLLOW_UP_DAY_OPTIONS,
  LEAD_REFERRALS,
  LEAD_STATUSES,
  followUpPayloadValue,
  formatFollowUpRemaining,
  leadInitials,
  leadReferralLabel,
  leadStatusLabel,
} from "../../constants/leads.js";
import { gmailSearchHref } from "../../utils/gmailLinks.js";

const crmUser = inject("crmUser", ref(null));
const toast = useToast();
const router = useRouter();
const route = useRoute();

function userHasPerm(key) {
  const u = crmUser.value;
  if (!u) return false;
  if (crmIsAdmin(u) || u.is_crm_owner) return true;
  return Array.isArray(u.permission_keys) && u.permission_keys.includes(key);
}

const canCreate = computed(() => userHasPerm("leads.create"));
const canUpdate = computed(() => userHasPerm("leads.update"));
const canDelete = computed(() => userHasPerm("leads.delete"));

setCrmPageMeta({ title: "Save Rack | Leads", description: "Sales leads directory." });

const loading = ref(true);
const rows = ref([]);
const meta = ref({ current_page: 1, last_page: 1, per_page: 25, total: 0 });
const directoryStats = ref({
  open: 0,
  contacted: 0,
  interested: 0,
  future_opportunity: 0,
  follow_up: 0,
});
const statuses = ref([...LEAD_STATUSES]);
const followUpDayOptions = ref([...LEAD_FOLLOW_UP_DAY_OPTIONS]);

const query = ref({
  search: "",
  status: "all",
  referral: "all",
  follow_up_days: "all",
  email_template_id: "all",
  page: 1,
  per_page: 25,
  sort_by: "follow_up_at",
  sort_dir: "asc",
});

const statusModalTemplateId = ref("custom");
const emailTemplatesFlat = ref([]);
const templateUsages = ref({});

const directorySummaryActiveStatus = computed(() => {
  const s = String(query.value.status || "all");
  return s === "all" ? "" : s;
});

const createOpen = ref(false);
const createBusy = ref(false);
const quickAddOpen = ref(false);
const quickAddBusy = ref(false);

const statusModalOpen = ref(false);
const statusModalRow = ref(null);
const statusModalStatus = ref("open");
const statusModalFollowUpDays = ref(1);
const statusPickerBusy = ref(false);

const deleteOpen = ref(false);
const deleteBusy = ref(false);
const deleteTarget = ref(null);

const manageOpenId = ref(null);
const manageMenuRect = ref({ top: 0, left: 0 });
const manageMenuRow = computed(() => rows.value.find((r) => r.id === manageOpenId.value) ?? null);

const filterMenuOpen = ref(false);
const selectedIds = ref(new Set());
const bulkEmailOpen = ref(false);
const bulkEmailBusy = ref(false);
const bulkStatusOpen = ref(false);
const bulkStatusBusy = ref(false);
const filtersBeforeSearch = ref(null);

const LEAD_SORT_KEYS = [
  "status",
  "company_name",
  "referral",
  "website",
  "follow_up_at",
  "created_at",
];

const selectedCount = computed(() => selectedIds.value.size);
const allPageSelected = computed(() => {
  if (!rows.value.length) return false;
  return rows.value.every((r) => selectedIds.value.has(r.id));
});
const somePageSelected = computed(() => {
  if (!rows.value.length) return false;
  const n = rows.value.filter((r) => selectedIds.value.has(r.id)).length;
  return n > 0 && n < rows.value.length;
});

const searchActive = computed(() => String(query.value.search || "").trim() !== "");

let searchTimer = null;

function clearFilters() {
  query.value = {
    ...query.value,
    status: "all",
    referral: "all",
    follow_up_days: "all",
    email_template_id: "all",
    page: 1,
  };
}

function followUpDaysFilterLabel(days) {
  if (days === "off") return "Off";
  const n = Number(days);
  if (!Number.isFinite(n) || n <= 0) return "Off";
  return n === 1 ? "1 day" : `${n} days`;
}

function lastSentTemplateLabel(row) {
  const name = String(row?.last_sent_template_name || "").trim();
  return name || "—";
}

const emailTemplateFilterOptions = computed(() => {
  const items = Array.isArray(emailTemplatesFlat.value) ? emailTemplatesFlat.value : [];
  return [...items].sort((a, b) =>
    String(a?.name || "").localeCompare(String(b?.name || ""), undefined, { sensitivity: "base" }),
  );
});

function toggleSelectAll(event) {
  const checked = !!event?.target?.checked;
  const next = new Set(selectedIds.value);
  if (checked) {
    for (const row of rows.value) next.add(row.id);
  } else {
    for (const row of rows.value) next.delete(row.id);
  }
  selectedIds.value = next;
}

function toggleSelectRow(id, event) {
  const next = new Set(selectedIds.value);
  if (event?.target?.checked) next.add(id);
  else next.delete(id);
  selectedIds.value = next;
}

function isSelected(id) {
  return selectedIds.value.has(id);
}

function clearSelection() {
  selectedIds.value = new Set();
}

async function openBulkEmail() {
  if (!canUpdate.value || !selectedCount.value) return;
  await loadEmailTemplatesFlat();
  bulkEmailOpen.value = true;
}

async function openBulkStatus() {
  if (!canUpdate.value || !selectedCount.value) return;
  bulkStatusOpen.value = true;
}

async function submitBulkStatus({ status, follow_up_days }) {
  if (!status || !selectedCount.value) return;
  bulkStatusBusy.value = true;
  try {
    const payload = {
      lead_ids: Array.from(selectedIds.value),
      status,
    };
    if (follow_up_days !== undefined) {
      payload.follow_up_days = follow_up_days;
    }
    const { data } = await api.post("/leads/bulk-status", payload);
    const updated = Number(data?.updated || 0);
    const skipped = Number(data?.skipped || 0);
    let msg = `Updated ${updated} lead${updated === 1 ? "" : "s"}.`;
    if (skipped) msg += ` ${skipped} skipped.`;
    toast.success(msg);
    bulkStatusOpen.value = false;
    selectedIds.value = new Set();
    if (updated > 0 && status) {
      query.value = { ...query.value, status, page: 1 };
    }
    await fetchRows();
    await loadMeta();
  } catch (e) {
    toast.errorFrom(e, "Could not update lead status.");
  } finally {
    bulkStatusBusy.value = false;
  }
}

async function submitBulkEmail({ email_template_id }) {
  if (!email_template_id || !selectedCount.value) return;
  bulkEmailBusy.value = true;
  try {
    const { data } = await api.post("/leads/bulk-email", {
      lead_ids: Array.from(selectedIds.value),
      email_template_id,
    });
    const sent = Number(data?.sent ?? data?.queued ?? 0);
    const skipped = Number(data?.skipped || 0);
    const updated = Number(data?.updated || 0);
    const categoryLabel = data?.category ? leadStatusLabel(data.category) : "";
    let msg = "";
    if (updated && categoryLabel) {
      msg = `${updated} lead${updated === 1 ? "" : "s"} set to ${categoryLabel}`;
      if (sent) {
        msg += `; ${sent} email${sent === 1 ? "" : "s"} sent`;
      }
    } else {
      msg = `Sent ${sent} email${sent === 1 ? "" : "s"}`;
    }
    if (skipped) msg += ` (${skipped} skipped)`;
    const failed = Number(data?.failed_ids?.length || 0);
    if (failed) msg += ` (${failed} failed)`;
    toast.success(`${msg}.`);
    bulkEmailOpen.value = false;
    selectedIds.value = new Set();
    if (updated > 0 && data?.category) {
      query.value = { ...query.value, status: data.category, page: 1 };
    }
    await fetchRows();
    await loadMeta();
  } catch (e) {
    toast.errorFrom(e, "Could not send bulk email.");
  } finally {
    bulkEmailBusy.value = false;
  }
}

function toggleSort(column) {
  if (!LEAD_SORT_KEYS.includes(column)) return;
  if (query.value.sort_by === column) {
    query.value.sort_dir = query.value.sort_dir === "asc" ? "desc" : "asc";
  } else {
    query.value.sort_by = column;
    query.value.sort_dir = "asc";
  }
  query.value.page = 1;
}

function sortIndicator(column) {
  if (query.value.sort_by !== column) return "";
  return query.value.sort_dir === "asc" ? "↑" : "↓";
}

function thAriaSort(column) {
  return query.value.sort_by === column
    ? query.value.sort_dir === "asc"
      ? "ascending"
      : "descending"
    : "none";
}

async function loadMeta() {
  try {
    const { data } = await api.get("/leads/meta");
    if (Array.isArray(data?.statuses) && data.statuses.length) {
      statuses.value = data.statuses;
    }
    if (Array.isArray(data?.follow_up_day_options) && data.follow_up_day_options.length) {
      followUpDayOptions.value = data.follow_up_day_options;
    }
    if (data?.directory_stats) {
      directoryStats.value = {
        open: Number(data.directory_stats.open || 0),
        contacted: Number(data.directory_stats.contacted || 0),
        interested: Number(data.directory_stats.interested || 0),
        future_opportunity: Number(data.directory_stats.future_opportunity || 0),
        follow_up: Number(data.directory_stats.follow_up || 0),
      };
    }
  } catch (e) {
    toast.errorFrom(e, "Could not load lead stats.");
  }
}

async function fetchRows() {
  loading.value = true;
  try {
    const params = {
      page: query.value.page,
      per_page: query.value.per_page,
      sort_by: query.value.sort_by,
      sort_dir: query.value.sort_dir,
    };
    const search = String(query.value.search || "").trim();
    if (search) {
      params.search = search;
    } else {
      if (query.value.status && query.value.status !== "all") {
        params.status = query.value.status;
      }
      if (query.value.referral && query.value.referral !== "all") {
        params.referral = query.value.referral;
      }
      if (query.value.follow_up_days && query.value.follow_up_days !== "all") {
        params.follow_up_days = query.value.follow_up_days;
      }
      if (query.value.email_template_id && query.value.email_template_id !== "all") {
        params.email_template_id = query.value.email_template_id;
      }
    }

    const { data } = await api.get("/leads", { params });
    rows.value = Array.isArray(data?.data) ? data.data : [];
    meta.value = {
      current_page: Number(data?.current_page || 1),
      last_page: Number(data?.last_page || 1),
      per_page: Number(data?.per_page || 25),
      total: Number(data?.total || 0),
    };
    const visible = new Set(rows.value.map((r) => r.id));
    selectedIds.value = new Set([...selectedIds.value].filter((id) => visible.has(id)));
  } catch (e) {
    toast.errorFrom(e, "Could not load leads.");
    rows.value = [];
  } finally {
    loading.value = false;
  }
}

function setDirectoryStatFilter(status) {
  const next = query.value.status === status ? "all" : status;
  query.value = { ...query.value, status: next, page: 1 };
}

function avatarClassForEmail(email) {
  const s = String(email || "");
  let hash = 0;
  for (let i = 0; i < s.length; i += 1) {
    hash = (hash + s.charCodeAt(i) * (i + 1)) % 5;
  }
  const classes = [
    "bg-primary-subtle text-primary",
    "bg-success-subtle text-success",
    "bg-warning-subtle text-warning",
    "bg-info-subtle text-info",
    "bg-secondary-subtle text-secondary",
  ];
  return classes[hash] || classes[0];
}

async function loadEmailTemplatesFlat() {
  if (emailTemplatesFlat.value.length) return;
  try {
    const { data } = await api.get("/settings/email-templates", {
      params: { grouped: 1 },
    });
    const flat = [];
    (Array.isArray(data?.groups) ? data.groups : []).forEach((g) => {
      (g.templates || []).forEach((t) => flat.push(t));
    });
    emailTemplatesFlat.value = flat;
  } catch {
    emailTemplatesFlat.value = [];
  }
}

function openStatusModal(row) {
  if (!canUpdate.value || !row) return;
  statusModalRow.value = row;
  statusModalStatus.value = row.status || "open";
  statusModalFollowUpDays.value =
    row.follow_up_days === null || row.follow_up_days === undefined
      ? null
      : Number(row.follow_up_days);
  statusModalTemplateId.value = "custom";
  templateUsages.value = row.template_usages && typeof row.template_usages === "object"
    ? row.template_usages
    : {};
  statusModalOpen.value = true;
  loadEmailTemplatesFlat();
  // Fetch detail for accurate template usage when opening from list.
  api
    .get(`/leads/${row.id}`)
    .then(({ data }) => {
      if (statusModalRow.value?.id === row.id) {
        templateUsages.value =
          data?.template_usages && typeof data.template_usages === "object"
            ? data.template_usages
            : {};
      }
    })
    .catch(() => {});
}

async function saveStatusFromModal() {
  const row = statusModalRow.value;
  if (!row?.id || statusPickerBusy.value) return;
  statusPickerBusy.value = true;
  try {
    const payload = {
      status: statusModalStatus.value,
      follow_up_days: followUpPayloadValue(statusModalFollowUpDays.value),
      record_status_event: true,
    };
    if (
      statusModalTemplateId.value !== "custom" &&
      statusModalTemplateId.value !== null &&
      statusModalTemplateId.value !== ""
    ) {
      payload.email_template_id = Number(statusModalTemplateId.value);
    }
    const { data } = await api.patch(`/leads/${row.id}`, payload);
    const idx = rows.value.findIndex((r) => r.id === row.id);
    if (idx !== -1) {
      rows.value[idx] = { ...rows.value[idx], ...data };
    }
    statusModalOpen.value = false;
    statusModalRow.value = null;
    toast.success("Lead updated.");
    await loadMeta();
    if (query.value.status !== "all" && data.status !== query.value.status) {
      await fetchRows();
    } else {
      await fetchRows();
    }
  } catch (e) {
    toast.errorFrom(e, "Could not update lead.");
  } finally {
    statusPickerBusy.value = false;
  }
}

async function onCreate(payload) {
  createBusy.value = true;
  try {
    const { data } = await api.post("/leads", payload);
    createOpen.value = false;
    toast.success("Lead created.");
    await loadMeta();
    await fetchRows();
    if (data?.id) {
      router.push({ name: "lead-detail", params: { id: data.id } });
    }
  } catch (e) {
    toast.errorFrom(e, "Could not create lead.");
  } finally {
    createBusy.value = false;
  }
}

async function onQuickAdd(payload) {
  quickAddBusy.value = true;
  try {
    const { data } = await api.post("/leads/quick-add", payload);
    quickAddOpen.value = false;
    toast.success("Lead created.");
    await loadMeta();
    await fetchRows();
    if (data?.id) {
      router.push({ name: "lead-detail", params: { id: data.id } });
    }
  } catch (e) {
    toast.errorFrom(e, "Could not create lead from pasted text.");
  } finally {
    quickAddBusy.value = false;
  }
}

function openDelete(row) {
  deleteTarget.value = row;
  deleteOpen.value = true;
  closeManageMenu();
}

async function confirmDelete() {
  const row = deleteTarget.value;
  if (!row?.id || deleteBusy.value) return;
  deleteBusy.value = true;
  try {
    await api.delete(`/leads/${row.id}`);
    deleteOpen.value = false;
    deleteTarget.value = null;
    toast.success("Lead deleted.");
    await loadMeta();
    await fetchRows();
  } catch (e) {
    toast.errorFrom(e, "Could not delete lead.");
  } finally {
    deleteBusy.value = false;
  }
}

const MENU_W = 200;
const MENU_H = 140;

function statusBadgeClass(status) {
  const s = String(status || "").toLowerCase();
  if (s === "open") return "bg-success-subtle text-success";
  if (s === "contacted") return "bg-primary-subtle text-primary";
  if (s === "interested") return "bg-warning-subtle text-warning-emphasis";
  if (s === "future_opportunity") return "bg-info-subtle text-info";
  if (s === "follow_up") return "bg-danger-subtle text-danger";
  if (s === "non_responsive") return "bg-secondary-subtle text-secondary";
  if (s === "not_interested") return "bg-secondary-subtle text-secondary";
  if (s === "not_qualified") return "bg-body-secondary text-body-secondary";
  if (s === "account_created") return "bg-success-subtle text-success";
  return "bg-body-secondary text-body-secondary";
}

function placeManageMenu(anchorEl) {
  if (!(anchorEl instanceof HTMLElement)) return;
  const r = anchorEl.getBoundingClientRect();
  let top = r.bottom + 4;
  let left = r.right - MENU_W;
  left = Math.max(8, Math.min(left, window.innerWidth - MENU_W - 8));
  if (top + MENU_H > window.innerHeight - 8) {
    top = Math.max(8, r.top - MENU_H - 4);
  }
  manageMenuRect.value = { top, left };
}

function closeManageMenu() {
  manageOpenId.value = null;
}

async function toggleManageMenu(rowId, e) {
  e.stopPropagation();
  if (manageOpenId.value === rowId) {
    closeManageMenu();
    return;
  }
  const btn = e.currentTarget;
  manageOpenId.value = rowId;
  await nextTick();
  requestAnimationFrame(() => {
    requestAnimationFrame(() => {
      if (manageOpenId.value !== rowId) return;
      if (btn instanceof HTMLElement) placeManageMenu(btn);
    });
  });
}

function onDocClick(e) {
  if (!e.target?.closest?.("[data-toolbar-filter]")) filterMenuOpen.value = false;
  closeManageMenu();
}

function websiteHref(website) {
  const raw = String(website || "").trim();
  if (!raw) return "";
  if (/^https?:\/\//i.test(raw)) return raw;
  return `https://${raw}`;
}

watch(
  () => query.value.search,
  (next, prev) => {
    const trimmed = String(next || "").trim();
    const wasTrimmed = String(prev || "").trim();
    if (trimmed && !wasTrimmed) {
      filtersBeforeSearch.value = {
        status: query.value.status,
        referral: query.value.referral,
        follow_up_days: query.value.follow_up_days,
        email_template_id: query.value.email_template_id,
      };
    } else if (!trimmed && wasTrimmed && filtersBeforeSearch.value) {
      query.value = {
        ...query.value,
        status: filtersBeforeSearch.value.status,
        referral: filtersBeforeSearch.value.referral,
        follow_up_days: filtersBeforeSearch.value.follow_up_days,
        email_template_id: filtersBeforeSearch.value.email_template_id,
      };
      filtersBeforeSearch.value = null;
    }
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
      query.value = { ...query.value, page: 1 };
      fetchRows();
    }, 280);
  },
);

watch(
  () => [
    query.value.status,
    query.value.referral,
    query.value.follow_up_days,
    query.value.email_template_id,
    query.value.page,
    query.value.sort_by,
    query.value.sort_dir,
  ],
  () => {
    fetchRows();
  },
);

onMounted(async () => {
  document.addEventListener("click", onDocClick);
  const statusFromQuery = String(route.query.status || "").toLowerCase();
  if (LEAD_STATUSES.includes(statusFromQuery)) {
    query.value.status = statusFromQuery;
  }
  await Promise.all([loadMeta(), loadEmailTemplatesFlat()]);
  await fetchRows();
});

onUnmounted(() => {
  document.removeEventListener("click", onDocClick);
  clearTimeout(searchTimer);
});
</script>

<template>
  <div class="staff-page">
    <LeadCreateDrawer v-model:open="createOpen" :busy="createBusy" @submit="onCreate" />
    <LeadQuickAddDrawer v-model:open="quickAddOpen" :busy="quickAddBusy" @submit="onQuickAdd" />
    <LeadBulkEmailDrawer
      v-model:open="bulkEmailOpen"
      :busy="bulkEmailBusy"
      :selected-count="selectedCount"
      :templates="emailTemplatesFlat"
      @submit="submitBulkEmail"
    />
    <LeadBulkStatusDrawer
      v-model:open="bulkStatusOpen"
      :busy="bulkStatusBusy"
      :selected-count="selectedCount"
      :statuses="statuses"
      :follow-up-day-options="followUpDayOptions"
      @submit="submitBulkStatus"
    />
    <LeadStatusUpdateModal
      v-model:open="statusModalOpen"
      v-model:status="statusModalStatus"
      v-model:follow-up-days="statusModalFollowUpDays"
      v-model:email-template-id="statusModalTemplateId"
      :statuses="statuses"
      :follow-up-day-options="followUpDayOptions"
      :templates="emailTemplatesFlat"
      :template-usages="templateUsages"
      :busy="statusPickerBusy"
      @save="saveStatusFromModal"
    />
    <ConfirmModal
      :open="deleteOpen"
      title="Delete Lead?"
      :message="
        deleteTarget
          ? `Delete lead “${deleteTarget.company_name}”? This cannot be undone.`
          : 'Delete this lead?'
      "
      confirm-label="Delete"
      :busy="deleteBusy"
      danger
      @close="deleteOpen = false"
      @confirm="confirmDelete"
    />

    <div
      class="d-flex flex-column flex-md-row align-items-start align-items-md-center gap-3 mb-4"
    >
      <div class="min-w-0 flex-grow-1">
        <h1 class="h4 mb-1 fw-semibold text-body">Leads</h1>
        <p class="text-secondary small mb-0">Track sales leads and follow-ups</p>
      </div>
      <div class="d-flex flex-wrap align-items-center gap-2 ms-md-auto flex-shrink-0">
        <button
          v-if="canCreate"
          type="button"
          class="btn btn-outline-primary staff-page-primary fw-semibold"
          @click="quickAddOpen = true"
        >
          Quick Add
        </button>
        <button
          v-if="canCreate"
          type="button"
          class="btn btn-primary staff-page-primary d-inline-flex align-items-center gap-2"
          @click="createOpen = true"
        >
          <svg width="18" height="18" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z" />
          </svg>
          Add Lead
        </button>
      </div>
    </div>

    <LeadSummaryCards
      :values="directoryStats"
      :active-status="directorySummaryActiveStatus"
      @select="setDirectoryStatFilter"
    />

    <div class="staff-table-card staff-datatable-card staff-datatable-card--white">
      <div class="staff-table-toolbar">
        <div class="staff-table-toolbar--row">
          <input
            v-model="query.search"
            type="search"
            class="form-control staff-toolbar-search staff-toolbar-search--inline"
            placeholder="Search leads"
            autocomplete="off"
          />
          <div class="position-relative flex-shrink-0" data-toolbar-filter>
            <button
              type="button"
              class="btn btn-outline-secondary staff-toolbar-btn d-inline-flex align-items-center gap-2"
              :aria-expanded="filterMenuOpen"
              aria-haspopup="true"
              aria-controls="leads-filter-panel"
              :disabled="loading"
              @click.stop="filterMenuOpen = !filterMenuOpen"
            >
              <svg
                width="18"
                height="18"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
                viewBox="0 0 24 24"
                aria-hidden="true"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"
                />
              </svg>
              <span class="staff-toolbar-filter-text">Filters</span>
            </button>
            <div
              v-if="filterMenuOpen"
              id="leads-filter-panel"
              class="dropdown-menu dropdown-menu-end show shadow border p-0 staff-toolbar-filter-dropdown"
              role="dialog"
              aria-label="Table filters"
              @click.stop
            >
              <div class="staff-toolbar-filter-dropdown__head">
                <span>Filters</span>
                <button
                  type="button"
                  class="btn btn-link btn-sm text-secondary text-decoration-none p-0"
                  :disabled="loading"
                  @click="
                    clearFilters();
                    filterMenuOpen = false;
                  "
                >
                  Reset
                </button>
              </div>
              <div class="staff-toolbar-filter-dropdown__body">
                <label class="form-label" for="leads-filter-status">Status</label>
                <select
                  id="leads-filter-status"
                  v-model="query.status"
                  class="form-select staff-datatable-filters__select mb-3"
                  :disabled="loading || searchActive"
                  @change="query.page = 1"
                >
                  <option value="all">All Statuses</option>
                  <option v-for="st in statuses" :key="st" :value="st">
                    {{ leadStatusLabel(st) }}
                  </option>
                </select>
                <label class="form-label" for="leads-filter-template">Last Sent Template</label>
                <select
                  id="leads-filter-template"
                  v-model="query.email_template_id"
                  class="form-select staff-datatable-filters__select mb-3"
                  :disabled="loading || searchActive"
                  @change="query.page = 1"
                >
                  <option value="all">All Templates</option>
                  <option value="none">Never Sent</option>
                  <option
                    v-for="tpl in emailTemplateFilterOptions"
                    :key="tpl.id"
                    :value="String(tpl.id)"
                  >
                    {{ tpl.name }}
                  </option>
                </select>
                <label class="form-label" for="leads-filter-follow-up">Follow Up Days</label>
                <select
                  id="leads-filter-follow-up"
                  v-model="query.follow_up_days"
                  class="form-select staff-datatable-filters__select mb-3"
                  :disabled="loading || searchActive"
                  @change="query.page = 1"
                >
                  <option value="all">All Follow Up Days</option>
                  <option value="off">Off</option>
                  <option v-for="days in followUpDayOptions" :key="days" :value="String(days)">
                    {{ followUpDaysFilterLabel(days) }}
                  </option>
                </select>
                <label class="form-label" for="leads-filter-referral">Referral</label>
                <select
                  id="leads-filter-referral"
                  v-model="query.referral"
                  class="form-select staff-datatable-filters__select"
                  :disabled="loading || searchActive"
                  @change="query.page = 1"
                >
                  <option value="all">All Referrals</option>
                  <option v-for="ref in LEAD_REFERRALS" :key="ref" :value="ref">
                    {{ leadReferralLabel(ref) }}
                  </option>
                </select>
              </div>
            </div>
          </div>
        </div>
        <p v-if="searchActive" class="form-text text-secondary small mb-0 mt-2 px-1">
          Searching all statuses, referrals, templates, and follow-up days.
        </p>
      </div>

      <div
        v-if="canUpdate && selectedCount"
        class="staff-bulk-selection-bar d-flex flex-wrap align-items-center gap-2 gap-md-3 px-3 px-md-4 py-3"
      >
        <span class="small staff-bulk-selection-bar__count">
          {{ selectedCount }} lead{{ selectedCount === 1 ? "" : "s" }} selected
        </span>
        <button
          type="button"
          class="btn btn-sm staff-page-primary"
          :disabled="bulkStatusBusy"
          @click="openBulkStatus"
        >
          Bulk Update Status
        </button>
        <button
          type="button"
          class="btn btn-sm staff-page-primary"
          :disabled="bulkEmailBusy"
          @click="openBulkEmail"
        >
          Bulk Send
        </button>
        <button
          type="button"
          class="btn btn-link btn-sm staff-bulk-clear-link ms-auto text-decoration-none"
          :disabled="bulkStatusBusy || bulkEmailBusy"
          @click="clearSelection"
        >
          Clear Selection
        </button>
      </div>

      <div v-if="loading" class="p-5 d-flex justify-content-center d-none d-lg-flex">
        <CrmLoadingSpinner message="Loading leads…" />
      </div>
      <div v-else class="table-responsive staff-table-wrap d-none d-lg-block">
        <table class="table table-hover align-middle mb-0 staff-data-table">
          <thead class="table-light staff-table-head">
            <tr>
              <th class="staff-table-head__th text-center" scope="col" style="width: 2.5rem">
                <input
                  v-if="canUpdate"
                  type="checkbox"
                  class="form-check-input"
                  :checked="allPageSelected"
                  :indeterminate.prop="somePageSelected"
                  aria-label="Select all on page"
                  @change="toggleSelectAll"
                />
              </th>
              <th class="staff-table-head__th staff-table-head__th--sort" scope="col" :aria-sort="thAriaSort('status')">
                <button
                  type="button"
                  class="staff-sort-btn"
                  :disabled="loading"
                  @click="toggleSort('status')"
                >
                  Status
                  <span v-if="sortIndicator('status')" class="staff-sort-ind">{{ sortIndicator("status") }}</span>
                </button>
              </th>
              <th class="staff-table-head__th" scope="col" style="width: 3rem"></th>
              <th
                class="staff-table-head__th staff-table-head__th--sort"
                scope="col"
                :aria-sort="thAriaSort('company_name')"
              >
                <button
                  type="button"
                  class="staff-sort-btn"
                  :disabled="loading"
                  @click="toggleSort('company_name')"
                >
                  Company Name
                  <span v-if="sortIndicator('company_name')" class="staff-sort-ind">{{
                    sortIndicator("company_name")
                  }}</span>
                </button>
              </th>
              <th
                class="staff-table-head__th staff-table-head__th--sort"
                scope="col"
                :aria-sort="thAriaSort('referral')"
              >
                <button
                  type="button"
                  class="staff-sort-btn"
                  :disabled="loading"
                  @click="toggleSort('referral')"
                >
                  Referral
                  <span v-if="sortIndicator('referral')" class="staff-sort-ind">{{
                    sortIndicator("referral")
                  }}</span>
                </button>
              </th>
              <th
                class="staff-table-head__th"
                scope="col"
              >
                Last Sent Template
              </th>
              <th
                class="staff-table-head__th staff-table-head__th--sort"
                scope="col"
                :aria-sort="thAriaSort('website')"
              >
                <button
                  type="button"
                  class="staff-sort-btn"
                  :disabled="loading"
                  @click="toggleSort('website')"
                >
                  Website
                  <span v-if="sortIndicator('website')" class="staff-sort-ind">{{
                    sortIndicator("website")
                  }}</span>
                </button>
              </th>
              <th
                class="staff-table-head__th staff-table-head__th--sort text-center"
                scope="col"
                :aria-sort="thAriaSort('follow_up_at')"
              >
                <button
                  type="button"
                  class="staff-sort-btn"
                  :disabled="loading"
                  @click="toggleSort('follow_up_at')"
                >
                  Follow Up
                  <span v-if="sortIndicator('follow_up_at')" class="staff-sort-ind">{{
                    sortIndicator("follow_up_at")
                  }}</span>
                </button>
              </th>
              <th
                class="staff-table-head__th staff-table-head__th--sort text-center"
                scope="col"
                :aria-sort="thAriaSort('created_at')"
              >
                <button
                  type="button"
                  class="staff-sort-btn"
                  :disabled="loading"
                  @click="toggleSort('created_at')"
                >
                  Date Created
                  <span v-if="sortIndicator('created_at')" class="staff-sort-ind">{{
                    sortIndicator("created_at")
                  }}</span>
                </button>
              </th>
              <th class="staff-table-head__th staff-actions-col text-center" scope="col">Action</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="!rows.length">
              <td colspan="10" class="px-4 py-5 text-center text-secondary">No leads found.</td>
            </tr>
            <tr v-for="row in rows" :key="row.id" class="align-middle">
              <td class="text-center" @click.stop>
                <input
                  v-if="canUpdate"
                  type="checkbox"
                  class="form-check-input"
                  :checked="isSelected(row.id)"
                  :aria-label="`Select ${row.company_name}`"
                  @change="toggleSelectRow(row.id, $event)"
                />
              </td>
              <td>
                <button
                  v-if="canUpdate"
                  type="button"
                  class="staff-status-badge"
                  :class="statusBadgeClass(row.status)"
                  title="Change lead status"
                  @click.stop="openStatusModal(row)"
                >
                  {{ leadStatusLabel(row.status) }}
                </button>
                <span
                  v-else
                  class="staff-status-badge"
                  :class="statusBadgeClass(row.status)"
                >
                  {{ leadStatusLabel(row.status) }}
                </span>
              </td>
              <td>
                <img
                  v-if="row.logo_url"
                  :src="row.logo_url"
                  alt=""
                  class="rounded-circle object-fit-cover"
                  width="32"
                  height="32"
                />
                <span
                  v-else
                  class="rounded-circle d-inline-flex align-items-center justify-content-center small fw-semibold"
                  style="width: 32px; height: 32px; font-size: 0.65rem"
                  :class="avatarClassForEmail(row.email || row.company_name)"
                  aria-hidden="true"
                >{{ leadInitials(row.company_name) }}</span>
              </td>
              <td>
                <div class="lead-list-company min-w-0">
                  <RouterLink
                    :to="{ name: 'lead-detail', params: { id: row.id } }"
                    class="lead-list-company__name fw-semibold text-decoration-none text-body"
                    target="_blank"
                    rel="noopener noreferrer"
                  >
                    {{ row.company_name }}
                  </RouterLink>
                  <div class="lead-list-company__email text-secondary small">
                    <a
                      v-if="row.email"
                      :href="gmailSearchHref(row.email)"
                      class="text-decoration-none text-secondary"
                      target="_blank"
                      rel="noopener noreferrer"
                    >
                      {{ row.email }}
                    </a>
                    <span v-else>—</span>
                  </div>
                </div>
              </td>
              <td class="text-body staff-table-cell__meta">
                {{ row.referral_label || leadReferralLabel(row.referral) || "—" }}
              </td>
              <td
                class="text-body staff-table-cell__meta text-truncate"
                style="max-width: 14rem"
                :title="lastSentTemplateLabel(row)"
              >
                {{ lastSentTemplateLabel(row) }}
              </td>
              <td
                class="text-body staff-table-cell__meta text-truncate"
                style="max-width: 12rem"
              >
                <a
                  v-if="row.website"
                  :href="websiteHref(row.website)"
                  class="text-decoration-none"
                  target="_blank"
                  rel="noopener noreferrer"
                >
                  {{ row.website }}
                </a>
                <span v-else class="text-secondary">—</span>
              </td>
              <td class="text-center text-body staff-table-cell__meta text-nowrap">
                {{ formatFollowUpRemaining(row) }}
              </td>
              <td class="text-center text-body staff-table-cell__meta text-nowrap">
                {{ formatDateUs(row.created_at) }}
              </td>
              <td class="staff-actions-cell text-center">
                <div
                  data-row-actions
                  class="staff-actions-inner staff-actions-inner--single justify-content-center"
                >
                  <button
                    type="button"
                    class="staff-action-btn staff-action-btn--more"
                    :class="{ 'is-open': manageOpenId === row.id }"
                    :aria-expanded="manageOpenId === row.id"
                    aria-haspopup="true"
                    aria-label="Row actions"
                    @click="toggleManageMenu(row.id, $event)"
                  >
                    <CrmIconRowActions variant="horizontal" />
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="crm-mobile-item-cards d-lg-none" aria-label="Leads">
        <div v-if="loading" class="crm-mobile-item-card__empty">
          <div class="d-flex justify-content-center py-3">
            <CrmLoadingSpinner message="Loading leads…" />
          </div>
        </div>
        <div v-else-if="!rows.length" class="crm-mobile-item-card__empty">No leads found.</div>
        <template v-else>
          <article v-for="row in rows" :key="`mobile-${row.id}`" class="crm-mobile-item-card">
            <div class="crm-mobile-item-card__head">
              <div class="crm-mobile-item-card__head-start">
                <button
                  v-if="canUpdate"
                  type="button"
                  class="staff-status-badge"
                  :class="statusBadgeClass(row.status)"
                  title="Change lead status"
                  @click.stop="openStatusModal(row)"
                >
                  {{ leadStatusLabel(row.status) }}
                </button>
                <span
                  v-else
                  class="staff-status-badge"
                  :class="statusBadgeClass(row.status)"
                >
                  {{ leadStatusLabel(row.status) }}
                </span>
              </div>
              <div class="crm-mobile-item-card__head-end" data-row-actions>
                <button
                  type="button"
                  class="staff-action-btn staff-action-btn--more"
                  :class="{ 'is-open': manageOpenId === row.id }"
                  :aria-expanded="manageOpenId === row.id"
                  aria-haspopup="true"
                  aria-label="Row actions"
                  @click="toggleManageMenu(row.id, $event)"
                >
                  <CrmIconRowActions variant="horizontal" />
                </button>
              </div>
            </div>

            <div class="crm-mobile-item-card__product">
              <span class="crm-mobile-item-card__thumb crm-mobile-item-card__thumb--avatar">
                <img
                  v-if="row.logo_url"
                  :src="row.logo_url"
                  alt=""
                  class="w-100 h-100 object-fit-cover"
                />
                <span
                  v-else
                  class="d-flex w-100 h-100 align-items-center justify-content-center fw-semibold small"
                  :class="avatarClassForEmail(row.email || row.company_name)"
                >
                  {{ leadInitials(row.company_name) }}
                </span>
              </span>
              <div class="crm-mobile-item-card__copy min-w-0">
                <RouterLink
                  :to="{ name: 'lead-detail', params: { id: row.id } }"
                  class="crm-mobile-item-card__sku text-decoration-none"
                  target="_blank"
                  rel="noopener noreferrer"
                >
                  {{ row.company_name }}
                </RouterLink>
                <div class="lead-list-company__email text-secondary small">
                  <a
                    v-if="row.email"
                    :href="gmailSearchHref(row.email)"
                    class="text-decoration-none text-secondary"
                    target="_blank"
                    rel="noopener noreferrer"
                  >
                    {{ row.email }}
                  </a>
                  <span v-else>—</span>
                </div>
              </div>
            </div>

            <div class="crm-mobile-item-card__meta">
              <div class="crm-mobile-item-card__meta-row">
                <span class="crm-mobile-item-card__meta-label">Referral</span>
                <span class="crm-mobile-item-card__meta-value">
                  {{ row.referral_label || leadReferralLabel(row.referral) || "—" }}
                </span>
              </div>
              <div class="crm-mobile-item-card__meta-row">
                <span class="crm-mobile-item-card__meta-label">Last Sent Template</span>
                <span class="crm-mobile-item-card__meta-value">{{ lastSentTemplateLabel(row) }}</span>
              </div>
              <div class="crm-mobile-item-card__meta-row">
                <span class="crm-mobile-item-card__meta-label">Website</span>
                <span class="crm-mobile-item-card__meta-value">
                  <a
                    v-if="row.website"
                    :href="websiteHref(row.website)"
                    class="text-decoration-none"
                    target="_blank"
                    rel="noopener noreferrer"
                  >
                    {{ row.website }}
                  </a>
                  <template v-else>—</template>
                </span>
              </div>
              <div class="crm-mobile-item-card__meta-row">
                <span class="crm-mobile-item-card__meta-label">Follow Up</span>
                <span class="crm-mobile-item-card__meta-value">{{ formatFollowUpRemaining(row) }}</span>
              </div>
              <div class="crm-mobile-item-card__meta-row">
                <span class="crm-mobile-item-card__meta-label">Date Created</span>
                <span class="crm-mobile-item-card__meta-value">{{ formatDateUs(row.created_at) }}</span>
              </div>
            </div>
          </article>
        </template>
      </div>

      <div
        v-if="meta.last_page > 1"
        class="staff-table-footer card-footer d-flex flex-column flex-sm-row align-items-stretch align-items-sm-center justify-content-between gap-2"
      >
        <span class="small text-secondary">
          Page {{ meta.current_page }} of {{ meta.last_page }} ({{ meta.total }} total)
        </span>
        <div class="btn-group btn-group-sm ms-sm-auto">
          <button
            type="button"
            class="btn btn-outline-secondary"
            :disabled="meta.current_page <= 1 || loading"
            @click="query.page = meta.current_page - 1"
          >
            Previous
          </button>
          <button
            type="button"
            class="btn btn-outline-secondary"
            :disabled="meta.current_page >= meta.last_page || loading"
            @click="query.page = meta.current_page + 1"
          >
            Next
          </button>
        </div>
      </div>
    </div>

    <Teleport to="body">
      <Transition
        enter-active-class="transition ease-out duration-100"
        enter-from-class="opacity-0"
        enter-to-class="opacity-100"
        leave-active-class="transition ease-in duration-75"
        leave-from-class="opacity-100"
        leave-to-class="opacity-0"
      >
        <div
          v-if="manageMenuRow"
          data-row-actions
          class="staff-row-menu fixed z-[300]"
          role="menu"
          :style="{
            top: `${manageMenuRect.top}px`,
            left: `${manageMenuRect.left}px`,
            minWidth: '200px',
          }"
          @click.stop
        >
          <RouterLink
            class="staff-row-menu__item"
            role="menuitem"
            :to="{ name: 'lead-detail', params: { id: manageMenuRow.id } }"
            target="_blank"
            rel="noopener noreferrer"
            @click="closeManageMenu"
          >
            View
          </RouterLink>
          <hr v-if="canUpdate || canDelete" class="staff-row-menu__divider" />
          <button
            v-if="canUpdate"
            type="button"
            class="staff-row-menu__item"
            role="menuitem"
            @click="
              openStatusModal(manageMenuRow);
              closeManageMenu();
            "
          >
            Update Status
          </button>
          <hr v-if="canUpdate && canDelete" class="staff-row-menu__divider" />
          <button
            v-if="canDelete"
            type="button"
            class="staff-row-menu__item staff-row-menu__item--danger"
            role="menuitem"
            @click="openDelete(manageMenuRow)"
          >
            Delete
          </button>
        </div>
      </Transition>
    </Teleport>
  </div>
</template>

<style scoped>
.lead-list-company__name {
  display: block;
  line-height: 1.35;
  word-break: break-word;
}

.lead-list-company__email {
  display: block;
  line-height: 1.35;
  margin-top: 0.15rem;
  word-break: break-word;
}
</style>
