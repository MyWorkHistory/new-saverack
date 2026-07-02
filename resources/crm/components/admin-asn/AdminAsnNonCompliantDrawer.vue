<script setup>
import CrmRightDrawer from "../common/CrmRightDrawer.vue";
import CrmSearchableSelect from "../common/CrmSearchableSelect.vue";
import {
  CRM_BTN_PRIMARY,
  CRM_BTN_SECONDARY,
  CRM_DIALOG_FOOTER_CLASS_DRAWER,
} from "../../constants/dialogFooter.js";
import { ASN_CARRIER_OPTIONS } from "../../utils/asnCarrierOptions.js";

const props = defineProps({
  open: { type: Boolean, default: false },
  accountId: { type: [String, Number], default: "" },
  accountOptions: { type: Array, default: () => [] },
  boxes: { type: Number, default: 0 },
  pallets: { type: Number, default: 0 },
  fee: { type: [String, Number], default: "" },
  trackings: { type: Array, default: () => [] },
  busy: { type: Boolean, default: false },
});

const emit = defineEmits([
  "update:open",
  "update:accountId",
  "update:boxes",
  "update:pallets",
  "update:fee",
  "update:trackings",
  "add-tracking",
  "submit",
]);

function close() {
  if (props.busy) return;
  emit("update:open", false);
}

function updateTracking(index, field, value) {
  const next = props.trackings.map((row, i) =>
    i === index ? { ...row, [field]: value } : row,
  );
  emit("update:trackings", next);
}
</script>

<template>
  <CrmRightDrawer
    :open="open"
    title="Non-Compliant ASN"
    :busy="busy"
    form-id="admin-asn-non-compliant-form"
    max-width="2xl"
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
      <div class="col-6">
        <label class="form-label">Boxes</label>
        <input
          :value="boxes"
          type="number"
          min="0"
          class="form-control"
          :disabled="busy"
          @input="emit('update:boxes', Number($event.target.value) || 0)"
        />
      </div>
      <div class="col-6">
        <label class="form-label">Pallets</label>
        <input
          :value="pallets"
          type="number"
          min="0"
          class="form-control"
          :disabled="busy"
          @input="emit('update:pallets', Number($event.target.value) || 0)"
        />
      </div>
      <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <label class="form-label mb-0">Tracking</label>
          <button type="button" class="btn btn-link btn-sm p-0" :disabled="busy" @click="emit('add-tracking')">
            Add Row
          </button>
        </div>
        <div v-for="(t, i) in trackings" :key="i" class="row g-2 mb-2">
          <div class="col-5">
            <select
              :value="t.carrier"
              class="form-select form-select-sm"
              :disabled="busy"
              @change="updateTracking(i, 'carrier', $event.target.value)"
            >
              <option value="">Carrier</option>
              <option v-for="c in ASN_CARRIER_OPTIONS" :key="c" :value="c">{{ c }}</option>
            </select>
          </div>
          <div class="col-7">
            <input
              :value="t.tracking_number"
              type="text"
              class="form-control form-control-sm"
              placeholder="Tracking #"
              :disabled="busy"
              @input="updateTracking(i, 'tracking_number', $event.target.value)"
            />
          </div>
        </div>
      </div>
      <div class="col-12">
        <label class="form-label">Non-Compliant Fee</label>
        <input
          :value="fee"
          type="number"
          min="0"
          step="0.01"
          class="form-control"
          placeholder="0.00"
          :disabled="busy"
          @input="emit('update:fee', $event.target.value)"
        />
        <p class="form-text mb-0">If greater than zero, a custom bill line is created automatically.</p>
      </div>
    </div>

    <template #footer>
      <footer :class="CRM_DIALOG_FOOTER_CLASS_DRAWER">
        <button type="button" :class="CRM_BTN_SECONDARY" :disabled="busy" @click="close">
          Cancel
        </button>
        <button
          type="submit"
          form="admin-asn-non-compliant-form"
          :class="CRM_BTN_PRIMARY"
          :disabled="busy"
        >
          {{ busy ? "Creating…" : "Create" }}
        </button>
      </footer>
    </template>
  </CrmRightDrawer>
</template>
