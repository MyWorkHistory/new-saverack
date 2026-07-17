<script setup>
import { computed } from "vue";
import CrmLoadingSpinner from "../common/CrmLoadingSpinner.vue";

const props = defineProps({
  open: { type: Boolean, default: false },
  busy: { type: Boolean, default: false },
  loading: { type: Boolean, default: false },
  /** Single source location (put-away). */
  fromLocation: { type: Object, default: null },
  /** Restock: selectable backstock sources. */
  fromOptions: { type: Array, default: () => [] },
  fromLocationId: { type: String, default: "" },
  transferType: { type: String, default: "current" },
  toLocationId: { type: String, default: "" },
  toLocation: { type: String, default: "" },
  quantity: { type: String, default: "" },
  reason: { type: String, default: "Restock" },
  destinationOptions: { type: Array, default: () => [] },
  reasonOptions: { type: Array, default: () => ["Restock"] },
  /** When true, destination dropdown is labeled as pick locations. */
  pickDestinations: { type: Boolean, default: false },
});

const emit = defineEmits([
  "close",
  "submit",
  "update:fromLocationId",
  "update:transferType",
  "update:toLocationId",
  "update:toLocation",
  "update:quantity",
  "update:reason",
  "transfer-all",
]);

const useFromDropdown = computed(() => props.fromOptions.length > 0);

const selectedFrom = computed(() => {
  if (useFromDropdown.value) {
    const id = String(props.fromLocationId || "");
    return (
      props.fromOptions.find((loc) => String(loc.location_id || "") === id) || null
    );
  }
  return props.fromLocation;
});

const fromLabel = computed(() => {
  const loc = selectedFrom.value;
  if (!loc) return "—";
  return loc.location_name || loc.location_id || "—";
});

const fromQty = computed(() => Number(selectedFrom.value?.quantity ?? 0));
const showForm = computed(() => {
  if (props.loading) return false;
  if (useFromDropdown.value) return props.fromOptions.length > 0;
  return Boolean(props.fromLocation);
});

function locationOptionLabel(loc) {
  const name = loc?.location_name || loc?.location_id || "—";
  const qty = Number(loc?.quantity ?? 0);
  return `${name}(QTY: ${qty.toLocaleString()})`;
}
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
            <template v-if="useFromDropdown">
              <p class="form-label small mb-1">Transfer From</p>
              <label class="form-label small text-secondary" for="restock-transfer-from">
                Backstock Locations
              </label>
              <select
                id="restock-transfer-from"
                :value="fromLocationId"
                class="form-select mb-3"
                :disabled="busy"
                @change="emit('update:fromLocationId', $event.target.value)"
              >
                <option value="">Select backstock location</option>
                <option
                  v-for="loc in fromOptions"
                  :key="`from-${loc.warehouse_id}-${loc.location_id}`"
                  :value="loc.location_id"
                >
                  {{ locationOptionLabel(loc) }}
                </option>
              </select>
            </template>
            <template v-else>
              <p class="small text-secondary mb-1">Transfer From: {{ fromLabel }}</p>
              <p class="small text-secondary mb-3">QTY: {{ fromQty.toLocaleString() }}</p>
            </template>

            <label class="form-label small" for="restock-transfer-type">Transfer Type</label>
            <select
              id="restock-transfer-type"
              :value="transferType"
              class="form-select mb-3"
              :disabled="busy"
              @change="emit('update:transferType', $event.target.value)"
            >
              <option value="current">Current Locations</option>
              <option value="new">Transfer New</option>
            </select>

            <label class="form-label small" for="restock-transfer-to">Transfer To</label>
            <p
              v-if="transferType === 'current' && pickDestinations"
              class="small text-secondary mb-1"
            >
              Pick Locations
            </p>
            <select
              v-if="transferType === 'current'"
              id="restock-transfer-to"
              :value="toLocationId"
              class="form-select mb-3"
              :disabled="busy || (useFromDropdown && !fromLocationId)"
              @change="emit('update:toLocationId', $event.target.value)"
            >
              <option value="">
                {{ pickDestinations ? "Select pick location" : "Select location" }}
              </option>
              <option
                v-for="dest in destinationOptions"
                :key="`to-${dest.warehouse_id}-${dest.location_id}`"
                :value="dest.location_id"
              >
                {{ pickDestinations ? locationOptionLabel(dest) : (dest.location_name || dest.location_id) }}
              </option>
            </select>
            <input
              v-else
              id="restock-transfer-to"
              :value="toLocation"
              type="text"
              class="form-control mb-3"
              placeholder="Type location name"
              :disabled="busy"
              @input="emit('update:toLocation', $event.target.value)"
            />

            <div class="row g-2 align-items-end mb-3">
              <div class="col-6">
                <label class="form-label small" for="restock-transfer-qty">QTY</label>
                <input
                  id="restock-transfer-qty"
                  :value="quantity"
                  type="number"
                  min="1"
                  class="form-control"
                  :disabled="busy || (useFromDropdown && !fromLocationId)"
                  @input="emit('update:quantity', $event.target.value)"
                />
              </div>
              <div class="col-6">
                <button
                  type="button"
                  class="btn inventory-transfer-modal__transfer-all-btn w-100"
                  :disabled="busy || (useFromDropdown && !fromLocationId) || fromQty <= 0"
                  @click="emit('transfer-all')"
                >
                  Transfer All
                </button>
              </div>
            </div>
            <label class="form-label small">Reason</label>
            <select
              :value="reason"
              class="form-select"
              :disabled="busy"
              @change="emit('update:reason', $event.target.value)"
            >
              <option v-for="item in reasonOptions" :key="item" :value="item">{{ item }}</option>
            </select>
          </template>
          <p v-else-if="useFromDropdown" class="text-secondary small mb-0">
            No backstock locations with quantity found.
          </p>
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
            :disabled="busy || loading || !showForm || (useFromDropdown && !fromLocationId)"
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
