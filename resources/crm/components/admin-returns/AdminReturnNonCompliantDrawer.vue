<script setup>
import CrmRightDrawer from "../common/CrmRightDrawer.vue";
import CrmSearchableSelect from "../common/CrmSearchableSelect.vue";
import {
  CRM_BTN_PRIMARY,
  CRM_BTN_SECONDARY,
  CRM_DIALOG_FOOTER_CLASS_DRAWER,
} from "../../constants/dialogFooter.js";

const NON_COMPLIANT_REASONS = [
  { value: "unable_to_identify_customer", label: "Unable to Identify Customer" },
  { value: "item_not_sold_by_client", label: "Item Not Sold by Client" },
  { value: "mixed_products_multiple_orders", label: "Mixed Products from Multiple Orders" },
];

const props = defineProps({
  open: { type: Boolean, default: false },
  accountId: { type: [String, Number], default: "" },
  accountOptions: { type: Array, default: () => [] },
  declaredItems: { type: Number, default: 1 },
  reason: { type: String, default: "" },
  busy: { type: Boolean, default: false },
});

const emit = defineEmits([
  "update:open",
  "update:accountId",
  "update:declaredItems",
  "update:reason",
  "submit",
]);

function close() {
  if (props.busy) return;
  emit("update:open", false);
}
</script>

<template>
  <CrmRightDrawer
    :open="open"
    title="Non-Compliant Return"
    :busy="busy"
    form-id="admin-return-non-compliant-form"
    max-width="3xl"
    @update:open="emit('update:open', $event)"
    @submit="emit('submit')"
  >
    <div class="row g-3">
      <div class="col-12">
        <label class="form-label">Account</label>
        <CrmSearchableSelect
          :model-value="String(accountId)"
          appearance="staff"
          teleport-panel
          :options="accountOptions"
          placeholder="Select account…"
          :allow-empty="false"
          search-placeholder="Search accounts…"
          :disabled="busy"
          @update:model-value="emit('update:accountId', $event)"
        />
      </div>
      <div class="col-12">
        <label class="form-label">Items</label>
        <input
          :value="declaredItems"
          type="number"
          min="1"
          class="form-control"
          :disabled="busy"
          @input="emit('update:declaredItems', Math.max(1, Number($event.target.value) || 1))"
        />
        <p class="form-text mb-0">Declared physical item count for this shipment.</p>
      </div>
      <div class="col-12">
        <label class="form-label">Reason</label>
        <select
          :value="reason"
          class="form-select"
          :disabled="busy"
          @change="emit('update:reason', $event.target.value)"
        >
          <option value="" disabled>Select a reason…</option>
          <option v-for="opt in NON_COMPLIANT_REASONS" :key="opt.value" :value="opt.value">
            {{ opt.label }}
          </option>
        </select>
      </div>
      <div class="col-12">
        <p class="form-text mb-0">Add return line items on the detail page after creation.</p>
      </div>
    </div>

    <template #footer>
      <footer :class="CRM_DIALOG_FOOTER_CLASS_DRAWER">
        <button type="button" :class="CRM_BTN_SECONDARY" :disabled="busy" @click="close">
          Cancel
        </button>
        <button
          type="submit"
          form="admin-return-non-compliant-form"
          :class="CRM_BTN_PRIMARY"
          :disabled="busy"
        >
          {{ busy ? "Creating…" : "Create" }}
        </button>
      </footer>
    </template>
  </CrmRightDrawer>
</template>
