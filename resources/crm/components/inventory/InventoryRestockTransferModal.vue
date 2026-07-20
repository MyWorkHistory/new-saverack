<script setup>
import { computed } from "vue";
import CrmLoadingSpinner from "../common/CrmLoadingSpinner.vue";
import CrmMaterialIcon from "../common/CrmMaterialIcon.vue";
import { TRANSFER_CART_LOCATIONS } from "../../constants/restockTransferCart.js";

const props = defineProps({
  open: { type: Boolean, default: false },
  busy: { type: Boolean, default: false },
  loading: { type: Boolean, default: false },
  /** "pending" | "transfer_cart" */
  mode: { type: String, default: "pending" },
  fromOptions: { type: Array, default: () => [] },
  fromLocationId: { type: String, default: "" },
  /** Destination mode: current | cart | new */
  destinationMode: { type: String, default: "current" },
  toLocationId: { type: String, default: "" },
  toLocation: { type: String, default: "" },
  cartLocation: { type: String, default: "" },
  quantity: { type: String, default: "" },
  reason: { type: String, default: "Restock" },
  pickOptions: { type: Array, default: () => [] },
  reasonOptions: { type: Array, default: () => ["Restock"] },
});

const emit = defineEmits([
  "close",
  "submit",
  "transfer-all",
  "update:fromLocationId",
  "update:destinationMode",
  "update:toLocationId",
  "update:toLocation",
  "update:cartLocation",
  "update:quantity",
  "update:reason",
]);

const isCartStatusMode = computed(() => props.mode === "transfer_cart");
const title = computed(() => (isCartStatusMode.value ? "Transfer Cart" : "Transfer QTY"));

const selectedFrom = computed(() => {
  const id = String(props.fromLocationId || "");
  return props.fromOptions.find((loc) => String(loc.location_id || "") === id) || null;
});

const fromQty = computed(() => Number(selectedFrom.value?.quantity ?? 0));
const showForm = computed(() => !props.loading && props.fromOptions.length > 0);

function locationOptionLabel(loc) {
  const name = loc?.location_name || loc?.location_id || "—";
  const qty = Number(loc?.quantity ?? 0);
  return `${name}(QTY: ${qty.toLocaleString()})`;
}

/** Toggle Cart / New; clicking active returns to Current Pick Location. */
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
          <h2 class="crm-vx-modal__title restock-xfer-modal__title">{{ title }}</h2>
        </header>
        <div class="crm-vx-modal__body">
          <div v-if="loading" class="py-4">
            <CrmLoadingSpinner message="Loading locations…" :center="true" />
          </div>
          <template v-else-if="showForm">
            <label class="form-label small" for="restock-xfer-from">Transfer From</label>
            <select
              id="restock-xfer-from"
              :value="fromLocationId"
              class="form-select mb-3"
              :disabled="busy"
              @change="emit('update:fromLocationId', $event.target.value)"
            >
              <option value="">
                {{ isCartStatusMode ? "Transfer Cart Locations" : "Backstock Locations" }}
              </option>
              <option
                v-for="loc in fromOptions"
                :key="`from-${loc.warehouse_id}-${loc.location_id}`"
                :value="loc.location_id"
              >
                {{ locationOptionLabel(loc) }}
              </option>
            </select>

            <label class="form-label small" for="restock-xfer-to">Transfer To</label>
            <select
              id="restock-xfer-to"
              :value="destinationMode === 'current' ? toLocationId : ''"
              class="form-select mb-3"
              :disabled="busy || !fromLocationId || destinationMode !== 'current'"
              @change="emit('update:toLocationId', $event.target.value)"
            >
              <option value="">Current Pick Location</option>
              <option
                v-for="dest in pickOptions"
                :key="`pick-${dest.warehouse_id}-${dest.location_id}`"
                :value="dest.location_id"
              >
                {{ locationOptionLabel(dest) }}
              </option>
            </select>

            <!-- Pending: Transfer Cart / New Location toggles -->
            <div v-if="!isCartStatusMode" class="restock-xfer-modal__mode-row mb-3">
              <button
                type="button"
                class="restock-xfer-modal__mode-btn"
                :class="{ 'is-active': destinationMode === 'cart' }"
                :disabled="busy"
                @click="setDestinationMode('cart')"
              >
                <CrmMaterialIcon name="shoppingCart" :size="18" />
                Transfer Cart
              </button>
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

            <!-- Transfer Cart status mode: New Location toggle only -->
            <div v-else class="restock-xfer-modal__mode-row mb-3">
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

            <template v-if="destinationMode === 'cart' && !isCartStatusMode">
              <label class="form-label small" for="restock-xfer-cart">Transfer Location</label>
              <select
                id="restock-xfer-cart"
                :value="cartLocation"
                class="form-select mb-3"
                :disabled="busy || !fromLocationId"
                @change="emit('update:cartLocation', $event.target.value)"
              >
                <option value="">Select location</option>
                <option
                  v-for="code in TRANSFER_CART_LOCATIONS"
                  :key="`cart-${code}`"
                  :value="code"
                >
                  {{ code }}
                </option>
              </select>
            </template>

            <template v-else-if="destinationMode === 'new'">
              <label class="form-label small" for="restock-xfer-new">New Location</label>
              <input
                id="restock-xfer-new"
                :value="toLocation"
                type="text"
                class="form-control mb-3"
                placeholder="Enter location name"
                :disabled="busy || !fromLocationId"
                @input="emit('update:toLocation', $event.target.value)"
              />
            </template>

            <div class="row g-2 align-items-end mb-3">
              <div class="col-6">
                <label class="form-label small" for="restock-xfer-qty">QTY</label>
                <input
                  id="restock-xfer-qty"
                  :value="quantity"
                  type="number"
                  min="1"
                  class="form-control"
                  :disabled="busy || !fromLocationId"
                  @input="emit('update:quantity', $event.target.value)"
                />
              </div>
              <div class="col-6">
                <button
                  type="button"
                  class="btn restock-xfer-modal__transfer-all-btn w-100"
                  :disabled="busy || !fromLocationId || fromQty <= 0"
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
          <p v-else class="text-secondary small mb-0">
            {{
              isCartStatusMode
                ? "No transfer cart locations with quantity found."
                : "No backstock locations with quantity found."
            }}
          </p>
        </div>
        <footer class="crm-vx-modal__footer restock-xfer-modal__footer">
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
            :disabled="busy || loading || !showForm || !fromLocationId"
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
  justify-content: center;
  text-align: center;
}

.restock-xfer-modal__title {
  text-align: center;
  width: 100%;
}

.restock-xfer-modal__mode-row {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.625rem;
}

.restock-xfer-modal__mode-row:has(> :only-child) {
  grid-template-columns: 1fr;
}

.restock-xfer-modal__mode-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 0.45rem;
  min-height: 2.625rem;
  padding: 0.5rem 0.75rem;
  border: 1px solid #e3e3e9;
  border-radius: 0.5rem;
  background: #fff;
  color: #2b4561;
  font-size: 0.9375rem;
  font-weight: 500;
  line-height: 1.2;
  box-shadow: none;
  transition: border-color 0.15s ease, color 0.15s ease, background-color 0.15s ease;
}

.restock-xfer-modal__mode-btn:hover:not(:disabled):not(.is-active),
.restock-xfer-modal__mode-btn:focus-visible:not(.is-active) {
  border-color: #cfd0d6;
  color: #1e3349;
  background: #fff;
}

.restock-xfer-modal__mode-btn.is-active {
  border-color: #4a7af8;
  color: #4a7af8;
  background: #f1f5fd;
  box-shadow: none;
}

.restock-xfer-modal__mode-btn.is-active:hover:not(:disabled),
.restock-xfer-modal__mode-btn.is-active:focus-visible {
  border-color: #3b6ef0;
  color: #3b6ef0;
  background: #eaf0fc;
}

.restock-xfer-modal__mode-btn:disabled {
  opacity: 0.55;
  cursor: not-allowed;
}

.restock-xfer-modal__transfer-all-btn {
  border: 1px solid #e3e3e9;
  background: #fff;
  color: #2b4561;
  font-weight: 500;
  border-radius: 0.5rem;
  min-height: 2.625rem;
}

.restock-xfer-modal__transfer-all-btn:hover:not(:disabled),
.restock-xfer-modal__transfer-all-btn:focus-visible {
  background: #f8fafc;
  border-color: #cfd0d6;
  color: #1e3349;
}

.restock-xfer-modal__footer {
  justify-content: center;
  gap: 0.75rem;
}
</style>
