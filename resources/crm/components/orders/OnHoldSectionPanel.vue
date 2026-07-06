<script setup>
import { computed, ref } from "vue";
import { RouterLink } from "vue-router";
import CrmRefreshToolbarButton from "../common/CrmRefreshToolbarButton.vue";
import ClientAccountShippingStatusIcon from "../clients/ClientAccountShippingStatusIcon.vue";

const PREVIEW_LIMIT = 5;

const props = defineProps({
  sectionKey: { type: String, required: true },
  label: { type: String, required: true },
  holdReason: { type: [String, null], default: null },
  accounts: { type: Array, default: () => [] },
  lastUpdated: { type: String, default: "Not refreshed yet" },
  refreshing: { type: Boolean, default: false },
  ordersHoldRoute: { type: Function, required: true },
});

const emit = defineEmits(["refresh"]);

const expanded = ref(false);

const displayedAccounts = computed(() => {
  const rows = Array.isArray(props.accounts) ? props.accounts : [];
  if (expanded.value || rows.length <= PREVIEW_LIMIT) {
    return rows;
  }
  return rows.slice(0, PREVIEW_LIMIT);
});

const hiddenCount = computed(() => {
  const total = props.accounts?.length || 0;
  return Math.max(0, total - PREVIEW_LIMIT);
});

const showToggle = computed(() => (props.accounts?.length || 0) > PREVIEW_LIMIT);

function toggleExpanded() {
  expanded.value = !expanded.value;
}

function onRefresh() {
  emit("refresh", props.sectionKey);
}
</script>

<template>
  <section
    :id="`hold-${sectionKey}`"
    class="staff-table-card staff-datatable-card staff-datatable-card--white w-100 on-hold-section-panel"
  >
    <div class="staff-table-toolbar">
      <div
        class="staff-table-toolbar--row d-flex align-items-start justify-content-between gap-2"
      >
        <div>
          <h2 class="staff-user-section-title mb-1">{{ label }}</h2>
          <p class="small text-secondary mb-0">Last updated: {{ lastUpdated }}</p>
        </div>
        <CrmRefreshToolbarButton
          :disabled="refreshing"
          :loading="refreshing"
          @click="onRefresh"
        />
      </div>
    </div>
    <div class="table-responsive staff-table-wrap">
      <table class="table table-hover align-middle mb-0 staff-data-table">
        <thead class="table-light staff-table-head">
          <tr>
            <th class="staff-table-head__th" scope="col">Account</th>
            <th class="staff-table-head__th text-end" scope="col" style="width: 5rem">Orders</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in displayedAccounts" :key="`${sectionKey}-${row.account_id}`">
            <td>
              <div class="d-flex align-items-center gap-2 min-w-0">
                <ClientAccountShippingStatusIcon :status="row.account_status" :size="18" />
                <RouterLink
                  :to="ordersHoldRoute(row.account_id, holdReason)"
                  class="text-truncate text-decoration-none fw-semibold"
                >
                  {{ row.account_name }}
                </RouterLink>
              </div>
            </td>
            <td class="text-end fw-semibold tabular-nums">
              {{ Number(row.orders_count || 0).toLocaleString() }}
            </td>
          </tr>
          <tr v-if="!accounts.length">
            <td colspan="2" class="text-secondary small py-4 text-center">
              No accounts with {{ label.toLowerCase() }}.
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <div v-if="showToggle" class="px-3 py-3 border-top">
      <button
        type="button"
        class="btn btn-outline-secondary btn-sm orders-toolbar-outline-btn"
        @click="toggleExpanded"
      >
        <template v-if="expanded">Show Less</template>
        <template v-else>View All ({{ hiddenCount + PREVIEW_LIMIT }})</template>
      </button>
    </div>
  </section>
</template>

<style scoped>
.tabular-nums {
  font-variant-numeric: tabular-nums;
}
</style>
