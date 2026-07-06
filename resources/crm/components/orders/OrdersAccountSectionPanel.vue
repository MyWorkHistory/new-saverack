<script setup>
import { computed, ref } from "vue";
import { RouterLink } from "vue-router";
import CrmMaterialIcon from "../common/CrmMaterialIcon.vue";
import CrmRefreshToolbarButton from "../common/CrmRefreshToolbarButton.vue";
import ClientAccountShippingStatusIcon from "../clients/ClientAccountShippingStatusIcon.vue";

const props = defineProps({
  sectionKey: { type: String, required: true },
  label: { type: String, required: true },
  icon: { type: String, required: true },
  iconStyle: { type: Object, default: () => ({}) },
  accounts: { type: Array, default: () => [] },
  lastUpdated: { type: String, default: "Not refreshed yet" },
  metaSuffix: { type: String, default: "" },
  refreshing: { type: Boolean, default: false },
  accountRoute: { type: Function, required: true },
  pillVariant: {
    type: String,
    default: "neutral",
    validator: (v) => ["neutral", "success", "alert"].includes(v),
  },
  previewLimit: { type: Number, default: null },
  showViewAllFooter: { type: Boolean, default: false },
  emptyMessage: { type: String, default: "" },
  anchorPrefix: { type: String, default: "section" },
});

const emit = defineEmits(["refresh"]);

const expanded = ref(false);

const displayedAccounts = computed(() => {
  const rows = Array.isArray(props.accounts) ? props.accounts : [];
  const limit = props.previewLimit;
  if (limit == null || expanded.value || rows.length <= limit) {
    return rows;
  }
  return rows.slice(0, limit);
});

const accountCount = computed(() => props.accounts?.length || 0);

const showToggle = computed(() => {
  const limit = props.previewLimit;
  return props.showViewAllFooter && limit != null && accountCount.value > limit;
});

const resolvedEmptyMessage = computed(() => {
  if (props.emptyMessage) return props.emptyMessage;
  return `No accounts with ${props.label.toLowerCase()}.`;
});

const pillClass = computed(() => {
  if (props.pillVariant === "success") return "on-hold-count-pill--success";
  if (props.pillVariant === "alert") return "on-hold-count-pill--alert";
  return "";
});

function toggleExpanded() {
  expanded.value = !expanded.value;
}

function onRefresh() {
  emit("refresh", props.sectionKey);
}
</script>

<template>
  <section
    :id="`${anchorPrefix}-${sectionKey}`"
    class="staff-datatable-card staff-datatable-card--white on-hold-section-panel h-100 d-flex flex-column"
  >
    <div class="on-hold-section-panel__header d-flex align-items-start justify-content-between gap-2">
      <div class="d-flex align-items-start gap-3 min-w-0">
        <div
          class="on-hold-section-panel__header-icon flex-shrink-0"
          :style="iconStyle"
          aria-hidden="true"
        >
          <CrmMaterialIcon :name="icon" :size="22" />
        </div>
        <div class="min-w-0">
          <h2 class="on-hold-section-panel__title mb-1">{{ label }}</h2>
          <p class="on-hold-section-panel__meta mb-0">
            Last updated: {{ lastUpdated }}<span v-if="metaSuffix"> {{ metaSuffix }}</span>
          </p>
        </div>
      </div>
      <CrmRefreshToolbarButton
        :disabled="refreshing"
        :loading="refreshing"
        label="Refresh"
        title="Refresh section"
        @click="onRefresh"
      />
    </div>

    <div class="on-hold-section-panel__table-head d-flex justify-content-between">
      <span class="on-hold-section-panel__col-head">Account</span>
      <span class="on-hold-section-panel__col-head">Orders</span>
    </div>

    <div class="on-hold-section-panel__body flex-grow-1">
      <template v-if="accounts.length">
        <div
          v-for="row in displayedAccounts"
          :key="`${sectionKey}-${row.account_id}`"
          class="on-hold-section-panel__row d-flex align-items-center justify-content-between gap-2"
        >
          <div class="d-flex align-items-center gap-2 min-w-0">
            <ClientAccountShippingStatusIcon :status="row.account_status" :size="18" />
            <RouterLink
              :to="accountRoute(row.account_id)"
              class="on-hold-section-panel__account-link text-truncate"
            >
              {{ row.account_name }}
            </RouterLink>
          </div>
          <span class="on-hold-count-pill flex-shrink-0" :class="pillClass">
            {{ Number(row.orders_count || 0).toLocaleString() }}
          </span>
        </div>
      </template>
      <div v-else class="on-hold-section-panel__empty text-center py-4">
        <div
          class="on-hold-section-panel__empty-icon mx-auto mb-2"
          :style="iconStyle"
          aria-hidden="true"
        >
          <CrmMaterialIcon :name="icon" :size="28" />
        </div>
        <p class="on-hold-section-panel__empty-text mb-0">{{ resolvedEmptyMessage }}</p>
      </div>
    </div>

    <div v-if="showViewAllFooter" class="on-hold-section-panel__footer text-center">
      <button
        v-if="showToggle"
        type="button"
        class="on-hold-view-all-link btn btn-link p-0"
        @click="toggleExpanded"
      >
        <template v-if="expanded">Show Less</template>
        <template v-else>View All Accounts ({{ accountCount }})</template>
      </button>
      <span v-else class="on-hold-view-all-link on-hold-view-all-link--static">
        View All Accounts ({{ accountCount }})
      </span>
    </div>
  </section>
</template>
