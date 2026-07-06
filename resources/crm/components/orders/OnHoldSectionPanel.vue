<script setup>
import { computed } from "vue";
import OrdersAccountSectionPanel from "./OrdersAccountSectionPanel.vue";

const props = defineProps({
  sectionKey: { type: String, required: true },
  label: { type: String, required: true },
  icon: { type: String, required: true },
  iconStyle: { type: Object, default: () => ({}) },
  holdReason: { type: [String, null], default: null },
  accounts: { type: Array, default: () => [] },
  lastUpdated: { type: String, default: "Not refreshed yet" },
  refreshing: { type: Boolean, default: false },
  ordersHoldRoute: { type: Function, required: true },
});

defineEmits(["refresh"]);

const pillVariant = computed(() =>
  props.sectionKey === "hold_backorder" ? "alert" : "neutral",
);

function accountRoute(accountId) {
  return props.ordersHoldRoute(accountId, props.holdReason);
}
</script>

<template>
  <OrdersAccountSectionPanel
    :section-key="sectionKey"
    :label="label"
    :icon="icon"
    :icon-style="iconStyle"
    :accounts="accounts"
    :last-updated="lastUpdated"
    :refreshing="refreshing"
    :account-route="accountRoute"
    :pill-variant="pillVariant"
    :preview-limit="5"
    :show-view-all-footer="true"
    anchor-prefix="hold"
    @refresh="$emit('refresh', $event)"
  />
</template>
