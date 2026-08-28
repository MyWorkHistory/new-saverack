<script setup>
import CrmRightDrawer from "../common/CrmRightDrawer.vue";
import {
  CRM_BTN_PRIMARY,
  CRM_BTN_SECONDARY,
  CRM_DIALOG_FOOTER_CLASS_DRAWER,
} from "../../constants/dialogFooter.js";
import { WHOLESALE_TYPE_CREATE_OPTIONS } from "../../utils/formatWholesaleOrderDisplay.js";

const props = defineProps({
  open: { type: Boolean, default: false },
  accountName: { type: String, default: "" },
  orderType: { type: String, default: "" },
  orderNumber: { type: String, default: "" },
  instructions: { type: String, default: "" },
  busy: { type: Boolean, default: false },
});

const emit = defineEmits([
  "update:open",
  "update:orderType",
  "update:orderNumber",
  "update:instructions",
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
    title="Set as Wholesale"
    :busy="busy"
    form-id="set-as-wholesale-form"
    max-width="3xl"
    @update:open="emit('update:open', $event)"
    @submit="emit('submit')"
  >
    <p class="small text-secondary mb-3">
      Create a wholesale order from this ShipHero order. Line items, order number, and client-provides shipping labels
      will be copied automatically.
    </p>
    <div class="row g-3">
      <div class="col-12">
        <label class="form-label">Account</label>
        <input
          type="text"
          class="form-control"
          :value="accountName || '—'"
          readonly
          aria-readonly="true"
        />
      </div>
      <div class="col-md-6">
        <label class="form-label">Type</label>
        <select
          :value="orderType"
          class="form-select"
          :disabled="busy"
          required
          @change="emit('update:orderType', $event.target.value)"
        >
          <option value="" disabled>Select type…</option>
          <option v-for="opt in WHOLESALE_TYPE_CREATE_OPTIONS" :key="opt.value" :value="opt.value">
            {{ opt.label }}
          </option>
        </select>
      </div>
      <div class="col-md-6">
        <label class="form-label">Order #</label>
        <input
          :value="orderNumber"
          type="text"
          class="form-control"
          placeholder="Order number"
          :disabled="busy"
          required
          @input="emit('update:orderNumber', $event.target.value)"
        />
      </div>
      <div class="col-12">
        <label class="form-label">Instructions</label>
        <textarea
          :value="instructions"
          class="form-control"
          rows="4"
          placeholder="Warehouse instructions for this order…"
          :disabled="busy"
          @input="emit('update:instructions', $event.target.value)"
        />
      </div>
    </div>

    <template #footer>
      <footer :class="CRM_DIALOG_FOOTER_CLASS_DRAWER">
        <button type="button" :class="CRM_BTN_SECONDARY" :disabled="busy" @click="close">
          Cancel
        </button>
        <button
          type="submit"
          form="set-as-wholesale-form"
          :class="CRM_BTN_PRIMARY"
          :disabled="busy"
        >
          {{ busy ? "Creating…" : "Create Wholesale Order" }}
        </button>
      </footer>
    </template>
  </CrmRightDrawer>
</template>
