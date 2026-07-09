<script setup>
import { computed, onMounted } from "vue";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import CrmRefreshToolbarButton from "../../components/common/CrmRefreshToolbarButton.vue";
import CrmSyncToolbar from "../../components/common/CrmSyncToolbar.vue";
import FulfillmentSummaryCards from "../../components/orders/FulfillmentSummaryCards.vue";
import OrdersAccountSectionPanel from "../../components/orders/OrdersAccountSectionPanel.vue";
import { FULFILLMENT_SECTIONS } from "../../constants/fulfillmentSections.js";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useAdminHomeDashboard } from "../../composables/useAdminHomeDashboard.js";
import { useToast } from "../../composables/useToast.js";
import { formatDateTimeUs } from "../../utils/formatUserDates.js";

const toast = useToast();

const { loading, refreshing, sections, load, refreshSection } = useAdminHomeDashboard({
  onError: (e) => toast.errorFrom(e, "Could not load fulfillment dashboard."),
});

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

function getTotalCount(key) {
  return sectionData(key).total_count;
}

function lastUpdatedLabel(key) {
  const at = sectionData(key).refreshed_at;
  if (!at) return "Not refreshed yet";
  return formatDateTimeUs(at);
}

const fulfillmentLastSyncedLabel = computed(() => {
  let latestMs = null;
  for (const section of FULFILLMENT_SECTIONS) {
    const at = sectionData(section.key).refreshed_at;
    if (!at) continue;
    const ms = new Date(at).getTime();
    if (!Number.isFinite(ms)) continue;
    if (latestMs === null || ms > latestMs) latestMs = ms;
  }
  return latestMs !== null ? formatDateTimeUs(new Date(latestMs).toISOString()) : "";
});

function isSectionRefreshing(key) {
  const s = sectionData(key);
  return s.status === "running" || refreshing.value;
}

function accountRoute(section, accountId) {
  const query = { client_account_id: String(accountId) };
  if (section.key === "shipped") {
    query.date_preset = "today";
  }
  return {
    name: section.routeName,
    query,
  };
}

function scrollToSection(key) {
  const el = document.getElementById(`fulfillment-${key}`);
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

async function onRefreshAll() {
  try {
    for (const section of FULFILLMENT_SECTIONS) {
      await refreshSection(section.key, { sync: true });
    }
    toast.success("Fulfillment sections refreshed.");
  } catch {
    /* toast handled */
  }
}

async function onRefreshSection(key) {
  try {
    const data = await refreshSection(key, { sync: true });
    toast.success(refreshToastMessage(data, false));
  } catch {
    /* toast handled */
  }
}

onMounted(async () => {
  setCrmPageMeta({
    title: "Save Rack | Orders | Fulfillment",
    description: "Ready to ship and shipped orders by account.",
  });
  try {
    await load();
  } catch {
    /* toast handled */
  }
});
</script>

<template>
  <div class="staff-page staff-page--wide orders-on-hold-overview orders-fulfillment-overview">
    <div class="mb-4 d-flex align-items-center justify-content-between gap-2 flex-wrap">
      <div>
        <h1 class="h4 mb-1 fw-semibold text-body staff-page__heading">Fulfillment</h1>
        <p class="staff-page__intro mb-0">Ready to ship and shipped orders by account.</p>
      </div>
      <CrmSyncToolbar :last-synced-label="fulfillmentLastSyncedLabel">
        <CrmRefreshToolbarButton
          :disabled="loading || refreshing"
          :loading="refreshing"
          label="Refresh All"
          title="Refresh ready to ship and shipped"
          @click="onRefreshAll"
        />
      </CrmSyncToolbar>
    </div>

    <div v-if="loading" class="d-flex justify-content-center py-5">
      <CrmLoadingSpinner message="Loading fulfillment…" :center="true" />
    </div>

    <template v-else>
      <FulfillmentSummaryCards :get-total-count="getTotalCount" @select="scrollToSection" />

      <div class="row g-3">
        <div
          v-for="section in FULFILLMENT_SECTIONS"
          :key="section.key"
          class="col-12 col-lg-6"
        >
          <OrdersAccountSectionPanel
            :section-key="section.key"
            :label="section.label"
            :icon="section.icon"
            :icon-style="section.iconStyle"
            :accounts="sectionData(section.key).accounts"
            :last-updated="lastUpdatedLabel(section.key)"
            :meta-suffix="section.metaSuffix || ''"
            :refreshing="isSectionRefreshing(section.key)"
            :account-route="(id) => accountRoute(section, id)"
            :pill-variant="section.pillVariant || 'neutral'"
            :empty-message="section.emptyMessage || ''"
            :show-view-all-footer="false"
            anchor-prefix="fulfillment"
            @refresh="onRefreshSection"
          />
        </div>
      </div>
    </template>
  </div>
</template>
