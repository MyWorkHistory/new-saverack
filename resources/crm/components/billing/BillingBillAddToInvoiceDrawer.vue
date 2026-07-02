<script setup>
import { computed } from "vue";
import CrmRightDrawer from "../common/CrmRightDrawer.vue";
import { formatCents } from "../../utils/formatMoney.js";
import {
  CRM_BTN_PRIMARY,
  CRM_BTN_SECONDARY,
  CRM_DIALOG_FOOTER_CLASS_DRAWER,
} from "../../constants/dialogFooter.js";

const props = defineProps({
  open: { type: Boolean, default: false },
  draftInvoices: { type: Array, default: () => [] },
  clientAccountName: { type: String, default: "" },
  chargeOptions: { type: Array, default: () => [] },
  busy: { type: Boolean, default: false },
  selectedInvoiceId: { type: String, default: "" },
  selectedLineTypes: { type: Array, default: () => [] },
  submitLabel: { type: String, default: "Add To Invoice" },
});

const emit = defineEmits([
  "update:open",
  "update:selectedInvoiceId",
  "update:selectedLineTypes",
  "submit",
]);

function close() {
  if (props.busy) return;
  emit("update:open", false);
}

const showChargeTypes = computed(() => props.chargeOptions.length > 0);

function toggleLineType(lineType) {
  const set = new Set(props.selectedLineTypes);
  if (set.has(lineType)) set.delete(lineType);
  else set.add(lineType);
  emit("update:selectedLineTypes", [...set]);
}

const canSubmit = computed(() => {
  if (!props.draftInvoices.length || !props.selectedInvoiceId) return false;
  if (showChargeTypes.value && !props.selectedLineTypes.length) return false;
  return true;
});

const subtitle = computed(() => {
  const name = props.clientAccountName || "this account";
  if (showChargeTypes.value) {
    return `Select a draft invoice and charge types for ${name}.`;
  }
  return `Select a draft invoice for ${name}.`;
});
</script>

<template>
  <CrmRightDrawer
    :open="open"
    title="Add To Invoice"
    :subtitle="subtitle"
    :busy="busy"
    @update:open="emit('update:open', $event)"
  >
    <p v-if="!draftInvoices.length" class="small text-secondary mb-0">
      No draft invoices for this account. Create a draft invoice first.
    </p>
    <div v-else class="list-group list-group-flush border rounded mb-4">
      <label
        v-for="inv in draftInvoices"
        :key="inv.id"
        class="list-group-item list-group-item-action d-flex align-items-center gap-2 mb-0 cursor-pointer"
      >
        <input
          :checked="selectedInvoiceId === String(inv.id)"
          type="radio"
          class="form-check-input mt-0"
          :value="String(inv.id)"
          :disabled="busy"
          @change="emit('update:selectedInvoiceId', String(inv.id))"
        />
        <span class="fw-semibold">Invoice #{{ inv.invoice_number }}</span>
        <span class="ms-auto text-secondary">{{ formatCents(inv.total_cents) }}</span>
      </label>
    </div>

    <template v-if="showChargeTypes">
      <p class="small fw-semibold mb-2">Charge types</p>
      <div class="d-flex flex-column gap-2">
        <label
          v-for="opt in chargeOptions"
          :key="opt.line_type"
          class="d-flex align-items-center gap-2 mb-0 small"
        >
          <input
            type="checkbox"
            class="form-check-input mt-0"
            :checked="selectedLineTypes.includes(opt.line_type)"
            :disabled="busy"
            @change="toggleLineType(opt.line_type)"
          />
          <span>{{ opt.display_name }}</span>
          <span class="ms-auto text-secondary">{{ formatCents(opt.default_unit_price_cents) }} default</span>
        </label>
      </div>
    </template>

    <template #footer>
      <footer :class="CRM_DIALOG_FOOTER_CLASS_DRAWER">
        <button type="button" :class="CRM_BTN_SECONDARY" :disabled="busy" @click="close">
          Cancel
        </button>
        <button
          type="button"
          :class="CRM_BTN_PRIMARY"
          :disabled="busy || !canSubmit"
          @click="emit('submit')"
        >
          {{ busy ? "Processing…" : submitLabel }}
        </button>
      </footer>
    </template>
  </CrmRightDrawer>
</template>

<style scoped>
.cursor-pointer {
  cursor: pointer;
}
</style>
