<script setup>
import { computed, onMounted, ref } from "vue";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import CrmRefreshToolbarButton from "../../components/common/CrmRefreshToolbarButton.vue";
import CrmSyncToolbar from "../../components/common/CrmSyncToolbar.vue";
import BackorderSummaryCards from "../../components/orders/BackorderSummaryCards.vue";
import OrdersAccountSectionPanel from "../../components/orders/OrdersAccountSectionPanel.vue";
import { BACKORDER_OVERVIEW_SECTIONS } from "../../constants/backorderOverviewSections.js";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useAdminHomeDashboard } from "../../composables/useAdminHomeDashboard.js";
import { useToast } from "../../composables/useToast.js";
import api from "../../services/api";
import { formatDateTimeUs } from "../../utils/formatUserDates.js";

const toast = useToast();

const {
  loading,
  refreshing,
  sections,
  load,
  refreshSection,
} = useAdminHomeDashboard({
  onError: (e) => toast.errorFrom(e, "Could not load backorder dashboard."),
});

const oosLoading = ref(false);
const oosAccounts = ref([]);
const oosTotalCount = ref(0);
const oosRefreshedAt = ref(null);

const backorderSection = BACKORDER_OVERVIEW_SECTIONS.backorder;
const oosSection = BACKORDER_OVERVIEW_SECTIONS.out_of_stock;

const summaryCards = computed(() => [
  {
    ...backorderSection,
    total: Number(sectionData(backorderSection.key).total_count || 0),
  },
  {
    ...oosSection,
    total: Number(oosTotalCount.value || 0),
  },
]);

function sectionData(key) {
  return (
    sections.value?.[key] || {
      accounts: [],
      total_count: 0,
      status: "idle",
      refreshed_at: null,
      truncated: false,
    }
  );
}

function lastUpdatedLabel(key) {
  const at = sectionData(key).refreshed_at;
  if (!at) return "Not refreshed yet";
  return formatDateTimeUs(at);
}

const oosLastUpdatedLabel = computed(() => {
  if (!oosRefreshedAt.value) return "Not refreshed yet";
  return formatDateTimeUs(oosRefreshedAt.value);
});

const backorderLastSyncedLabel = computed(() => {
  const at = sectionData(backorderSection.key).refreshed_at;
  const oosAt = oosRefreshedAt.value;
  let latestMs = null;
  for (const raw of [at, oosAt]) {
    if (!raw) continue;
    const ms = new Date(raw).getTime();
    if (!Number.isFinite(ms)) continue;
    if (latestMs === null || ms > latestMs) latestMs = ms;
  }
  return latestMs !== null ? formatDateTimeUs(new Date(latestMs).toISOString()) : "";
});

function isSectionRefreshing(key) {
  const s = sectionData(key);
  return s.status === "running" || refreshing.value;
}

function backorderAccountRoute(accountId) {
  return {
    name: "orders-backorder-list",
    query: { client_account_id: String(accountId) },
  };
}

function oosAccountRoute(accountId) {
  return {
    name: "inventory-out-of-stock",
    query: { client_account_id: String(accountId) },
  };
}

function scrollToSection(key) {
  const el = document.getElementById(`backorder-${key}`);
  el?.scrollIntoView({ behavior: "smooth", block: "start" });
}

function refreshToastMessage(data, fallbackQueued) {
  if (data?.refresh_index_only) {
    return "Counts updated from database. Full sync queued if index was empty.";
  }
  if (data?.refresh_synced) {
    return "Section refreshed.";
  }
  if (data?.refresh_enqueued || fallbackQueued) {
    return "Refresh queued — counts will update shortly.";
  }
  return "Refresh started.";
}

async function loadOutOfStockByAccount() {
  oosLoading.value = true;
  try {
    const { data } = await api.get("/inventory-beta/out-of-stock-by-account");
    oosAccounts.value = Array.isArray(data?.accounts) ? data.accounts : [];
    oosTotalCount.value = Number(data?.total_count || 0);
    oosRefreshedAt.value = new Date().toISOString();
  } catch (e) {
    oosAccounts.value = [];
    oosTotalCount.value = 0;
    toast.errorFrom(e, "Could not load out-of-stock by account.");
  } finally {
    oosLoading.value = false;
  }
}

async function onRefreshAll() {
  try {
    const data = await refreshSection(backorderSection.key, { sync: true });
    toast.success(refreshToastMessage(data, false));
  } catch {
    /* toast handled */
  }
  await loadOutOfStockByAccount();
}

async function onRefreshBackorder() {
  try {
    const data = await refreshSection(backorderSection.key, { sync: true });
    toast.success(refreshToastMessage(data, false));
  } catch {
    /* toast handled */
  }
}

async function onRefreshOos() {
  await loadOutOfStockByAccount();
  toast.success("Out of stock refreshed.");
}

onMounted(async () => {
  setCrmPageMeta({
    title: "Save Rack | Orders | Backorder",
    description: "Backorder orders and out-of-stock inventory by account.",
  });
  try {
    await Promise.all([load(), loadOutOfStockByAccount()]);
  } catch {
    /* toast handled */
  }
});
</script>

<template>
  <div class="staff-page staff-page--wide orders-on-hold-overview orders-fulfillment-overview">
    <div class="mb-4 d-flex align-items-center justify-content-between gap-2 flex-wrap">
      <div>
        <h1 class="h4 mb-1 fw-semibold text-body staff-page__heading">Backorder</h1>
        <p class="staff-page__intro mb-0">
          Backorder orders and out-of-stock inventory by account.
        </p>
      </div>
      <CrmSyncToolbar :last-synced-label="backorderLastSyncedLabel">
        <CrmRefreshToolbarButton
          :disabled="loading || refreshing || oosLoading"
          :loading="refreshing || oosLoading"
          label="Refresh All"
          title="Refresh backorder and out of stock"
          @click="onRefreshAll"
        />
      </CrmSyncToolbar>
    </div>

    <div v-if="loading" class="d-flex justify-content-center py-5">
      <CrmLoadingSpinner message="Loading backorder…" :center="true" />
    </div>

    <template v-else>
      <BackorderSummaryCards :cards="summaryCards" @select="scrollToSection" />

      <div class="row g-3">
        <div class="col-12 col-lg-6">
          <OrdersAccountSectionPanel
            :section-key="backorderSection.key"
            :label="backorderSection.label"
            :icon="backorderSection.icon"
            :icon-style="backorderSection.iconStyle"
            :accounts="sectionData(backorderSection.key).accounts"
            :last-updated="lastUpdatedLabel(backorderSection.key)"
            :refreshing="isSectionRefreshing(backorderSection.key)"
            :account-route="backorderAccountRoute"
            :pill-variant="backorderSection.pillVariant"
            :empty-message="backorderSection.emptyMessage"
            :count-column-label="backorderSection.countColumnLabel"
            :count-field="backorderSection.countField"
            :preview-limit="10"
            :show-view-all-footer="true"
            anchor-prefix="backorder"
            @refresh="onRefreshBackorder"
          />
        </div>
        <div class="col-12 col-lg-6">
          <OrdersAccountSectionPanel
            :section-key="oosSection.key"
            :label="oosSection.label"
            :icon="oosSection.icon"
            :icon-style="oosSection.iconStyle"
            :accounts="oosAccounts"
            :last-updated="oosLastUpdatedLabel"
            :refreshing="oosLoading"
            :account-route="oosAccountRoute"
            :pill-variant="oosSection.pillVariant"
            :empty-message="oosSection.emptyMessage"
            :count-column-label="oosSection.countColumnLabel"
            :count-field="oosSection.countField"
            :preview-limit="10"
            :show-view-all-footer="true"
            anchor-prefix="backorder"
            @refresh="onRefreshOos"
          />
        </div>
      </div>
    </template>
  </div>
</template>
