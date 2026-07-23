<script setup>
import { computed } from "vue";
import CrmLoadingSpinner from "../common/CrmLoadingSpinner.vue";

const props = defineProps({
  open: { type: Boolean, default: false },
  busy: { type: Boolean, default: false },
  loading: { type: Boolean, default: false },
  binName: { type: String, default: "" },
  sku: { type: String, default: "" },
  name: { type: String, default: "" },
  availableQty: { type: Number, default: 0 },
  destinationMode: { type: String, default: "current" },
  toLocationId: { type: String, default: "" },
  toLocation: { type: String, default: "" },
  quantity: { type: String, default: "" },
  destinationOptions: { type: Array, default: () => [] },
});

const emit = defineEmits([
  "close",
  "submit",
  "update:destinationMode",
  "update:toLocationId",
  "update:toLocation",
  "update:quantity",
  "transfer-all",
]);

const showForm = computed(() => !props.loading && props.availableQty > 0);

function locationOptionLabel(loc) {
  const name = loc?.location_name || loc?.location_id || "—";
  const qty = Number(loc?.quantity ?? 0);
  return `${name} (QTY: ${qty.toLocaleString()})`;
}

function setDestinationMode(mode) {
  if (props.destinationMode === mode) {
    emit("update:destinationMode", "current");
    return;
  }
  emit("update:destinationMode", mode);
}
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="crm-vx-modal-overlay" @click.self="emit('close')">
      <div class="crm-vx-modal crm-vx-modal--sm restock-xfer-modal" @click.stop>
        <header class="crm-vx-modal__head restock-xfer-modal__head">
          <h2 class="crm-vx-modal__title restock-xfer-modal__title">Transfer QTY</h2>
        </header>
        <div class="crm-vx-modal__body">
          <div v-if="loading" class="py-4">
            <CrmLoadingSpinner message="Loading locations…" :center="true" />
          </div>
          <template v-else-if="showForm">
            <p class="small text-secondary mb-1">SKU: {{ sku || "—" }}</p>
            <p class="small text-secondary mb-3">{{ name || "—" }}</p>

            <label class="form-label small" for="return-bin-xfer-from">Transfer From</label>
            <input
              id="return-bin-xfer-from"
              type="text"
              class="form-control mb-3"
              :value="binName || '—'"
              disabled
              readonly
            />

            <label class="form-label small" for="return-bin-xfer-to">Transfer To</label>
            <select
              id="return-bin-xfer-to"
              :value="destinationMode === 'current' ? toLocationId : ''"
              class="form-select mb-3"
              :disabled="busy || destinationMode !== 'current'"
              @change="emit('update:toLocationId', $event.target.value)"
            >
              <option value="">Select pick location</option>
              <option
                v-for="dest in destinationOptions"
                :key="`pick-${dest.warehouse_id}-${dest.location_id}`"
                :value="dest.location_id"
              >
                {{ locationOptionLabel(dest) }}
              </option>
            </select>

            <div class="restock-xfer-modal__mode-row mb-3">
              <button
                type="button"
                class="restock-xfer-modal__mode-btn"
                :class="{ 'is-active': destinationMode === 'new' }"
                :disabled="busy"
                @click="setDestinationMode('new')"
              >
                New Location
              </button>
            </div>

            <template v-if="destinationMode === 'new'">
              <label class="form-label small" for="return-bin-xfer-new">New Location</label>
              <input
                id="return-bin-xfer-new"
                :value="toLocation"
                type="text"
                class="form-control mb-3"
                placeholder="Enter location name"
                :disabled="busy"
                @input="emit('update:toLocation', $event.target.value)"
              />
            </template>

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
                  class="btn restock-xfer-modal__transfer-all-btn w-100"
                  :disabled="busy || availableQty <= 0"
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
.restock-xfer-modal__head {
  padding-bottom: 0.25rem;
}

.restock-xfer-modal__title {
  font-size: 1.125rem;
}

.restock-xfer-modal__mode-row {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}

.restock-xfer-modal__mode-btn {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  border: 1px solid rgba(15, 23, 42, 0.12);
  background: #fff;
  color: #334155;
  font-weight: 600;
  font-size: 0.8125rem;
  border-radius: 0.5rem;
  padding: 0.45rem 0.75rem;
}

.restock-xfer-modal__mode-btn.is-active {
  border-color: rgba(37, 99, 235, 0.45);
  background: rgba(37, 99, 235, 0.08);
  color: #1d4ed8;
}

.restock-xfer-modal__transfer-all-btn {
  border: 1px solid rgba(15, 23, 42, 0.12);
  background: #fff;
  color: #334155;
  font-weight: 600;
}

.restock-xfer-modal__transfer-all-btn:hover:not(:disabled),
.restock-xfer-modal__transfer-all-btn:focus-visible {
  background: #f8fafc;
  border-color: rgba(15, 23, 42, 0.2);
  color: #0f172a;
}
</style>
