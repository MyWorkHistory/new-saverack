<script setup>
import { computed, ref, watch } from "vue";
import {
  displayStatusLabel,
  isFulfilledStatus,
  SHOPIFY_DISPLAY_STATUS_LABELS,
} from "../../composables/useShopifyOrderActions.js";

const props = defineProps({
  open: { type: Boolean, default: false },
  busy: { type: Boolean, default: false },
  order: { type: Object, default: null },
  orderCount: { type: Number, default: 1 },
});

const emit = defineEmits(["close", "pick"]);

const step = ref("pick"); // pick | confirm
const selected = ref("");

const options = computed(() =>
  Object.entries(SHOPIFY_DISPLAY_STATUS_LABELS)
    .filter(([value]) => value !== "shipped" && value !== "cancelled")
    .map(([value, label]) => ({ value, label })),
);

const currentStatus = computed(() => String(props.order?.display_status || ""));

const alreadyFulfilled = computed(
  () => props.orderCount === 1 && isFulfilledStatus(currentStatus.value),
);

/** Shopify-cancelled orders are locked; CRM-only cancel can be recovered via Ready / Hold / Backorder. */
const shopifyCancelledLocked = computed(
  () => props.orderCount === 1 && Boolean(props.order?.cancelled_at),
);

const statusLocked = computed(() => alreadyFulfilled.value || shopifyCancelledLocked.value);

const lockMessage = computed(() =>
  alreadyFulfilled.value
    ? "Cannot change shipped order status."
    : "Cannot change cancelled order status.",
);

watch(
  () => props.open,
  (isOpen) => {
    if (!isOpen) return;
    step.value = "pick";
    selected.value = "";
  },
);

function onClose() {
  if (props.busy) return;
  emit("close");
}

function choose(value) {
  if (statusLocked.value) return;
  selected.value = value;
  step.value = "confirm";
}

function onConfirm() {
  if (!selected.value || props.busy) return;
  emit("pick", selected.value);
}
</script>

<template>
  <Teleport to="body">
    <div
      v-if="open"
      class="so-modal-overlay"
      role="dialog"
      aria-modal="true"
      @click.self="onClose"
    >
      <div class="so-modal" @click.stop>
        <button type="button" class="so-modal__close" aria-label="Close" :disabled="busy" @click="onClose">×</button>

        <template v-if="statusLocked">
          <h2 class="so-modal__title">Cannot Change Status</h2>
          <p class="so-modal__lead">{{ lockMessage }}</p>
          <footer class="so-modal__foot">
            <button type="button" class="btn btn-primary staff-page-primary fw-semibold" @click="onClose">
              OK
            </button>
          </footer>
        </template>

        <template v-else-if="step === 'pick'">
          <h2 class="so-modal__title mb-2">Change Status</h2>
          <p class="so-modal__lead">
            Current:
            <strong>{{ displayStatusLabel(currentStatus) || "—" }}</strong>
          </p>
          <div class="so-status-list">
            <button
              v-for="opt in options"
              :key="opt.value"
              type="button"
              class="so-status-list__item"
              :class="{ 'is-current': opt.value === currentStatus }"
              :disabled="busy || opt.value === currentStatus"
              @click="choose(opt.value)"
            >
              {{ opt.label }}
            </button>
          </div>
          <footer class="so-modal__foot">
            <button type="button" class="btn btn-outline-secondary orders-toolbar-outline-btn" :disabled="busy" @click="onClose">
              Cancel
            </button>
          </footer>
        </template>

        <template v-else>
          <h2 class="so-modal__title mb-2">Confirm Status Change</h2>
          <p class="so-modal__lead">
            Change
            {{ orderCount > 1 ? `${orderCount} orders` : "this order" }}
            to <strong>{{ displayStatusLabel(selected) }}</strong>?
          </p>
          <footer class="so-modal__foot">
            <button type="button" class="btn btn-outline-secondary orders-toolbar-outline-btn" :disabled="busy" @click="step = 'pick'">
              Back
            </button>
            <button
              type="button"
              class="btn btn-primary staff-page-primary fw-semibold"
              :disabled="busy"
              @click="onConfirm"
            >
              {{ busy ? "Updating…" : "Confirm" }}
            </button>
          </footer>
        </template>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.so-modal-overlay {
  position: fixed;
  inset: 0;
  z-index: 1200;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1rem;
  background: rgba(15, 23, 42, 0.45);
}
.so-modal {
  position: relative;
  width: 100%;
  max-width: 26rem;
  background: #fff;
  border-radius: 0.85rem;
  box-shadow: 0 20px 45px rgba(15, 23, 42, 0.2);
  padding: 1.35rem 1.5rem 1.25rem;
}
.so-modal__close {
  position: absolute;
  top: 0.65rem;
  right: 0.75rem;
  border: 0;
  background: transparent;
  color: #9ca3af;
  font-size: 1.4rem;
}
.so-modal__title {
  margin: 0;
  font-size: 1.2rem;
  font-weight: 700;
}
.so-modal__lead {
  margin: 0 0 1rem;
  color: #4b5563;
  font-size: 0.95rem;
}
.so-status-list {
  display: flex;
  flex-direction: column;
  gap: 0.45rem;
  margin-bottom: 1.15rem;
}
.so-status-list__item {
  text-align: left;
  padding: 0.7rem 0.9rem;
  border: 1px solid #e5e7eb !important;
  border-radius: 0.55rem;
  background: #fff;
  font-weight: 600;
  color: #111827;
  box-shadow: none;
  -webkit-appearance: none;
  appearance: none;
}
.so-status-list__item:hover:not(:disabled) {
  border-color: #93c5fd;
  background: #eff6ff;
}
.so-status-list__item.is-current {
  opacity: 0.55;
}
.so-modal__foot {
  display: flex;
  justify-content: flex-end;
  gap: 0.55rem;
}
</style>
