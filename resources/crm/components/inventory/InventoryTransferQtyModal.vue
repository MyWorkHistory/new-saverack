<script setup>
import { computed } from "vue";

const props = defineProps({
  open: { type: Boolean, default: false },
  busy: { type: Boolean, default: false },
  fromLocation: { type: Object, default: null },
  transferType: { type: String, default: "current" },
  toLocationId: { type: String, default: "" },
  toLocation: { type: String, default: "" },
  quantity: { type: String, default: "" },
  reason: { type: String, default: "Restock" },
  destinationOptions: { type: Array, default: () => [] },
  reasonOptions: { type: Array, default: () => ["Restock"] },
});

const emit = defineEmits([
  "close",
  "submit",
  "update:transferType",
  "update:toLocationId",
  "update:toLocation",
  "update:quantity",
  "update:reason",
  "transfer-all",
]);

const fromLabel = computed(() => {
  const loc = props.fromLocation;
  if (!loc) return "—";
  return loc.location_name || loc.location_id || "—";
});

const fromQty = computed(() => Number(props.fromLocation?.quantity ?? 0));
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="crm-vx-modal-overlay" @click.self="emit('close')">
      <div class="crm-vx-modal crm-vx-modal--sm" @click.stop>
        <header class="crm-vx-modal__head">
          <h2 class="crm-vx-modal__title">Transfer QTY</h2>
        </header>
        <div class="crm-vx-modal__body">
          <p class="small text-secondary mb-1">Transfer From: {{ fromLabel }}</p>
          <p class="small text-secondary mb-3">QTY: {{ fromQty.toLocaleString() }}</p>
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
          <select
            v-if="transferType === 'current'"
            id="restock-transfer-to"
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
                :disabled="busy"
                @input="emit('update:quantity', $event.target.value)"
              />
            </div>
            <div class="col-6">
              <button
                type="button"
                class="btn inventory-detail__transfer-all-btn w-100"
                :disabled="busy"
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
            :disabled="busy"
            @click="emit('submit')"
          >
            {{ busy ? "Please wait…" : "Transfer" }}
          </button>
        </footer>
      </div>
    </div>
  </Teleport>
</template>
