<script setup>
import { computed } from "vue";
import CrmLoadingSpinner from "../common/CrmLoadingSpinner.vue";

const props = defineProps({
  open: { type: Boolean, default: false },
  busy: { type: Boolean, default: false },
  loading: { type: Boolean, default: false },
  sku: { type: String, default: "" },
  name: { type: String, default: "" },
  availableQty: { type: Number, default: 0 },
  transferType: { type: String, default: "current" },
  toLocationId: { type: String, default: "" },
  toLocation: { type: String, default: "" },
  quantity: { type: String, default: "" },
  destinationOptions: { type: Array, default: () => [] },
});

const emit = defineEmits([
  "close",
  "submit",
  "update:transferType",
  "update:toLocationId",
  "update:toLocation",
  "update:quantity",
  "transfer-all",
]);

const showForm = computed(() => !props.loading && props.availableQty > 0);
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="crm-vx-modal-overlay" @click.self="emit('close')">
      <div class="crm-vx-modal crm-vx-modal--sm" @click.stop>
        <header class="crm-vx-modal__head">
          <h2 class="crm-vx-modal__title">Transfer QTY</h2>
        </header>
        <div class="crm-vx-modal__body">
          <div v-if="loading" class="py-4">
            <CrmLoadingSpinner message="Loading locations…" :center="true" />
          </div>
          <template v-else-if="showForm">
            <p class="small text-secondary mb-1">SKU: {{ sku || "—" }}</p>
            <p class="small text-secondary mb-1">{{ name || "—" }}</p>
            <p class="small text-secondary mb-3">QTY in bin: {{ availableQty.toLocaleString() }}</p>
            <label class="form-label small" for="return-bin-transfer-type">Transfer Type</label>
            <select
              id="return-bin-transfer-type"
              :value="transferType"
              class="form-select mb-3"
              :disabled="busy"
              @change="emit('update:transferType', $event.target.value)"
            >
              <option value="current">Current Locations</option>
              <option value="new">Transfer New</option>
            </select>
            <label class="form-label small" for="return-bin-transfer-to">Transfer To</label>
            <select
              v-if="transferType === 'current'"
              id="return-bin-transfer-to"
              :value="toLocationId"
              class="form-select mb-3"
              :disabled="busy"
              @change="emit('update:toLocationId', $event.target.value)"
            >
              <option value="">Select location</option>
              <option
                v-for="dest in destinationOptions"
                :key="`${dest.warehouse_id}-${dest.location_id}`"
                :value="dest.location_id"
              >
                {{ dest.location_name || dest.location_id }}
              </option>
            </select>
            <input
              v-else
              id="return-bin-transfer-to"
              :value="toLocation"
              type="text"
              class="form-control mb-3"
              placeholder="Type location name"
              :disabled="busy"
              @input="emit('update:toLocation', $event.target.value)"
            />
            <div class="row g-2 align-items-end mb-3">
              <div class="col-6">
                <label class="form-label small" for="return-bin-transfer-qty">QTY</label>
                <input
                  id="return-bin-transfer-qty"
                  :value="quantity"
                  type="number"
                  min="1"
                  class="form-control"
                  :disabled="busy"
                  @input="emit('update:quantity', $event.target.value)"
                />
              </div>
              <div class="col-6">
                <button
                  type="button"
                  class="btn inventory-transfer-modal__transfer-all-btn w-100"
                  :disabled="busy"
                  @click="emit('transfer-all')"
                >
                  Transfer All
                </button>
              </div>
            </div>
            <label class="form-label small">Reason</label>
            <input type="text" class="form-control" value="Return" disabled readonly />
          </template>
          <p v-else class="small text-secondary mb-0">No quantity available in this bin.</p>
        </div>
        <footer class="crm-vx-modal__footer">
          <button
            type="button"
            class="crm-vx-modal-btn crm-vx-modal-btn--secondary"
            :disabled="busy"
            @click="emit('close')"
          >
            Cancel
          </button>
          <button
            type="button"
            class="crm-vx-modal-btn crm-vx-modal-btn--primary"
            :disabled="busy || loading || !showForm"
            @click="emit('submit')"
          >
            {{ busy ? "Please wait…" : "Transfer" }}
          </button>
        </footer>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.inventory-transfer-modal__transfer-all-btn {
  border: 1px solid rgba(15, 23, 42, 0.12);
  background: #fff;
  color: #334155;
  font-weight: 600;
}

.inventory-transfer-modal__transfer-all-btn:hover:not(:disabled),
.inventory-transfer-modal__transfer-all-btn:focus-visible {
  background: #f8fafc;
  border-color: rgba(15, 23, 42, 0.2);
  color: #0f172a;
}
</style>
