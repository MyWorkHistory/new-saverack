<script setup>
import { computed, onMounted } from "vue";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import CrmRefreshToolbarButton from "../../components/common/CrmRefreshToolbarButton.vue";
import CrmSyncToolbar from "../../components/common/CrmSyncToolbar.vue";
import OnHoldSectionPanel from "../../components/orders/OnHoldSectionPanel.vue";
import OnHoldSummaryCards from "../../components/orders/OnHoldSummaryCards.vue";
import { HOLD_TYPE_SECTIONS } from "../../constants/holdSummaryCards.js";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useAdminHomeDashboard } from "../../composables/useAdminHomeDashboard.js";
import { useToast } from "../../composables/useToast.js";
import { formatDateTimeUs } from "../../utils/formatUserDates.js";

const toast = useToast();

const { loading, refreshing, sections, load, refreshSection } = useAdminHomeDashboard({
  onError: (e) => toast.errorFrom(e, "Could not load on-hold overview."),
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

const nf = new Intl.NumberFormat(undefined, { maximumFractionDigits: 0 });

function getTotalCount(key) {
  return sectionData(key).total_count;
}

function formatCount(key) {
  return nf.format(Number(getTotalCount(key) || 0));
}

function lastUpdatedLabel(key) {
  const at = sectionData(key).refreshed_at;
  if (!at) return "Not refreshed yet";
  return formatDateTimeUs(at);
}

const onHoldLastSyncedLabel = computed(() => {
  const keys = ["on_hold", ...HOLD_TYPE_SECTIONS.map((hold) => hold.key)];
  let latestMs = null;
  for (const key of keys) {
    const at = sectionData(key).refreshed_at;
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

function ordersHoldRoute(accountId, holdReason) {
  if (holdReason === null) {
    return {
      name: "orders-out-of-stock",
      query: { client_account_id: String(accountId) },
    };
  }
  return {
    name: "orders-on-hold-old",
    query: {
      client_account_id: String(accountId),
      hold_reason: holdReason,
    },
  };
}

function scrollToSection(key) {
  const el = document.getElementById(`hold-${key}`);
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
    const data = await refreshSection("all", { sync: true });
    toast.success(refreshToastMessage(data, false));
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
    title: "Save Rack | Orders | On-Hold",
    description: "On-hold orders by account and hold type.",
  });
  try {
    await load();
  } catch {
    /* toast handled */
  }
});
</script>

<template>
  <div class="staff-page staff-page--wide orders-on-hold-overview">
    <div class="mb-4 d-flex align-items-center justify-content-between gap-2 flex-wrap">
      <div>
        <h1 class="h4 mb-1 fw-semibold text-body staff-page__heading">On-Hold</h1>
        <p class="staff-page__intro mb-0">
          {{ formatCount("on_hold") }} orders on hold across all accounts.
        </p>
      </div>
      <CrmSyncToolbar :last-synced-label="onHoldLastSyncedLabel">
        <CrmRefreshToolbarButton
          :disabled="loading || refreshing"
          :loading="refreshing"
          label="Refresh All"
          title="Refresh all hold sections"
          @click="onRefreshAll"
        />
      </CrmSyncToolbar>
    </div>

    <div v-if="loading" class="d-flex justify-content-center py-5">
      <CrmLoadingSpinner message="Loading on-hold overview…" :center="true" />
    </div>

    <template v-else>
      <OnHoldSummaryCards :get-total-count="getTotalCount" @select="scrollToSection" />

      <div class="row g-3">
        <div
          v-for="hold in HOLD_TYPE_SECTIONS"
          :key="hold.key"
          class="col-12 col-md-6 col-xl-4"
        >
          <OnHoldSectionPanel
            :section-key="hold.key"
            :label="hold.label"
            :icon="hold.icon"
            :icon-style="hold.iconStyle"
            :hold-reason="hold.holdReason"
            :accounts="sectionData(hold.key).accounts"
            :last-updated="lastUpdatedLabel(hold.key)"
            :refreshing="isSectionRefreshing(hold.key)"
            :orders-hold-route="ordersHoldRoute"
            @refresh="onRefreshSection"
          />
        </div>
      </div>
    </template>
  </div>
</template>
