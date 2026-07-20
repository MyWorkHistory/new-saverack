<script setup>
import { computed } from "vue";
import CrmLoadingSpinner from "../common/CrmLoadingSpinner.vue";
import { TRANSFER_CART_LOCATIONS } from "../../constants/restockTransferCart.js";

const props = defineProps({
  open: { type: Boolean, default: false },
  busy: { type: Boolean, default: false },
  loading: { type: Boolean, default: false },
  /** "pending" | "transfer_cart" */
  mode: { type: String, default: "pending" },
  fromOptions: { type: Array, default: () => [] },
  fromLocationId: { type: String, default: "" },
  /** Destination mode for pending: current | cart | new */
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

const isCartMode = computed(() => props.mode === "transfer_cart");
const title = computed(() => (isCartMode.value ? "Transfer Cart" : "Transfer QTY"));

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

function setDestinationMode(mode) {
  emit("update:destinationMode", mode);
}
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="crm-vx-modal-overlay" @click.self="emit('close')">
      <div class="crm-vx-modal crm-vx-modal--sm" @click.stop>
        <header class="crm-vx-modal__head">
          <h2 class="crm-vx-modal__title">{{ title }}</h2>
        </header>
        <div class="crm-vx-modal__body">
          <div v-if="loading" class="py-4">
            <CrmLoadingSpinner message="Loading locations…" :center="true" />
          </div>
          <template v-else-if="showForm">
            <p class="form-label small mb-1">Transfer From</p>
            <label
              class="form-label small text-secondary"
              for="restock-xfer-from"
            >
              {{ isCartMode ? "Transfer Cart Locations" : "Backstock Locations" }}
            </label>
            <select
              id="restock-xfer-from"
              :value="fromLocationId"
              class="form-select mb-3"
              :disabled="busy"
              @change="emit('update:fromLocationId', $event.target.value)"
            >
              <option value="">
                {{ isCartMode ? "Select transfer cart location" : "Select backstock location" }}
              </option>
              <option
                v-for="loc in fromOptions"
                :key="`from-${loc.warehouse_id}-${loc.location_id}`"
                :value="loc.location_id"
              >
                {{ locationOptionLabel(loc) }}
              </option>
            </select>

            <p class="form-label small mb-2">Transfer To</p>

            <!-- Pending mode: Current / Transfer Cart / New Location toggles -->
            <template v-if="!isCartMode">
              <div class="d-flex flex-wrap gap-2 mb-3">
                <button
                  type="button"
                  class="btn btn-sm"
                  :class="
                    destinationMode === 'current'
                      ? 'btn-primary staff-page-primary'
                      : 'btn-outline-secondary'
                  "
                  :disabled="busy"
                  @click="setDestinationMode('current')"
                >
                  Current
                </button>
                <button
                  type="button"
                  class="btn btn-sm"
                  :class="
                    destinationMode === 'cart'
                      ? 'btn-primary staff-page-primary'
                      : 'btn-outline-secondary'
                  "
                  :disabled="busy"
                  @click="setDestinationMode('cart')"
                >
                  Transfer Cart
                </button>
                <button
                  type="button"
                  class="btn btn-sm"
                  :class="
                    destinationMode === 'new'
                      ? 'btn-primary staff-page-primary'
                      : 'btn-outline-secondary'
                  "
                  :disabled="busy"
                  @click="setDestinationMode('new')"
                >
                  New Location
                </button>
              </div>

              <template v-if="destinationMode === 'current'">
                <label class="form-label small text-secondary" for="restock-xfer-pick">
                  Pick Locations
                </label>
                <select
                  id="restock-xfer-pick"
                  :value="toLocationId"
                  class="form-select mb-3"
                  :disabled="busy || !fromLocationId"
                  @change="emit('update:toLocationId', $event.target.value)"
                >
                  <option value="">Select pick location</option>
                  <option
                    v-for="dest in pickOptions"
                    :key="`pick-${dest.warehouse_id}-${dest.location_id}`"
                    :value="dest.location_id"
                  >
                    {{ locationOptionLabel(dest) }}
                  </option>
                </select>
              </template>

              <template v-else-if="destinationMode === 'cart'">
                <label class="form-label small text-secondary" for="restock-xfer-cart">
                  Transfer Cart Location
                </label>
                <select
                  id="restock-xfer-cart"
                  :value="cartLocation"
                  class="form-select mb-3"
                  :disabled="busy || !fromLocationId"
                  @change="emit('update:cartLocation', $event.target.value)"
                >
                  <option value="">Select cart location</option>
                  <option
                    v-for="code in TRANSFER_CART_LOCATIONS"
                    :key="`cart-${code}`"
                    :value="code"
                  >
                    {{ code }}
                  </option>
                </select>
              </template>

              <template v-else>
                <label class="form-label small text-secondary" for="restock-xfer-new">
                  New Location
                </label>
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
            </template>

            <!-- Transfer Cart status mode: Current Pick / New Location -->
            <template v-else>
              <div class="d-flex flex-wrap gap-2 mb-3">
                <button
                  type="button"
                  class="btn btn-sm"
                  :class="
                    destinationMode === 'current'
                      ? 'btn-primary staff-page-primary'
                      : 'btn-outline-secondary'
                  "
                  :disabled="busy"
                  @click="setDestinationMode('current')"
                >
                  Current Pick Location
                </button>
                <button
                  type="button"
                  class="btn btn-sm"
                  :class="
                    destinationMode === 'new'
                      ? 'btn-primary staff-page-primary'
                      : 'btn-outline-secondary'
                  "
                  :disabled="busy"
                  @click="setDestinationMode('new')"
                >
                  New Location
                </button>
              </div>

              <template v-if="destinationMode === 'current'">
                <label class="form-label small text-secondary" for="restock-cart-pick">
                  Pick Locations
                </label>
                <select
                  id="restock-cart-pick"
                  :value="toLocationId"
                  class="form-select mb-3"
                  :disabled="busy || !fromLocationId"
                  @change="emit('update:toLocationId', $event.target.value)"
                >
                  <option value="">Select pick location</option>
                  <option
                    v-for="dest in pickOptions"
                    :key="`cart-pick-${dest.warehouse_id}-${dest.location_id}`"
                    :value="dest.location_id"
                  >
                    {{ locationOptionLabel(dest) }}
                  </option>
                </select>
              </template>
              <template v-else>
                <label class="form-label small text-secondary" for="restock-cart-new">
                  New Location
                </label>
                <input
                  id="restock-cart-new"
                  :value="toLocation"
                  type="text"
                  class="form-control mb-3"
                  placeholder="Enter location name"
                  :disabled="busy || !fromLocationId"
                  @input="emit('update:toLocation', $event.target.value)"
                />
              </template>
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
              isCartMode
                ? "No transfer cart locations with quantity found."
                : "No backstock locations with quantity found."
            }}
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
