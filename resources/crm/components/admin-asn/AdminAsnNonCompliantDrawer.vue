<script setup>
import { computed } from "vue";
import CrmRightDrawer from "../common/CrmRightDrawer.vue";
import CrmSearchableSelect from "../common/CrmSearchableSelect.vue";
import AsnProductCatalogPanel from "../inventory/AsnProductCatalogPanel.vue";
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
  feeDefaultLabel: { type: String, default: "" },
  trackings: { type: Array, default: () => [] },
  pendingLines: { type: Array, default: () => [] },
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
  "add-line",
  "remove-line",
  "submit",
]);

const resolvedAccountId = computed(() => Number(props.accountId || 0));
const catalogActive = computed(() => props.open && resolvedAccountId.value > 0);

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
      <div v-if="resolvedAccountId > 0" class="col-12">
        <label class="form-label">Items</label>
        <div class="border rounded admin-asn-nc-drawer__catalog">
          <AsnProductCatalogPanel
            :client-account-id="resolvedAccountId"
            :active="catalogActive"
            :busy="busy"
            show-add-new-sku
            qty-label="Expected QTY"
            search-input-id="admin-asn-nc-catalog-search"
            @add="emit('add-line', $event)"
          />
        </div>
        <div v-if="pendingLines.length" class="table-responsive mt-3">
          <table class="table table-sm align-middle mb-0 admin-asn-nc-drawer__lines-table">
            <thead>
              <tr>
                <th scope="col">SKU</th>
                <th scope="col">Product</th>
                <th scope="col" class="text-center">Expected QTY</th>
                <th scope="col" class="text-end">Actions</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(line, index) in pendingLines" :key="line._key || `${line.sku}-${index}`">
                <td class="small fw-semibold">{{ line.sku }}</td>
                <td class="small text-secondary">{{ line.name }}</td>
                <td class="text-center small">{{ line.expected_qty }}</td>
                <td class="text-end">
                  <button
                    type="button"
                    class="btn btn-link btn-sm text-danger p-0"
                    :disabled="busy"
                    @click="emit('remove-line', index)"
                  >
                    Remove
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
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
        <p v-if="feeDefaultLabel" class="form-text mb-0">{{ feeDefaultLabel }}</p>
        <p v-else class="form-text mb-0">If greater than zero, a receiving bill line is created automatically.</p>
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

<style scoped>
.admin-asn-nc-drawer__catalog {
  overflow: hidden;
  background: var(--bs-body-bg);
}

.admin-asn-nc-drawer__lines-table thead th {
  font-size: 0.75rem;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--bs-secondary-color);
}
</style>
