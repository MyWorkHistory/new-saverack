<script setup>
import { computed, ref, watch } from "vue";

const props = defineProps({
  open: { type: Boolean, default: false },
  busy: { type: Boolean, default: false },
  line: { type: Object, default: null },
});

const emit = defineEmits(["close", "pick"]);

const step = ref("pick"); // pick | fulfill
const trackingNumber = ref("");

const options = [
  { value: "cancelled", label: "Cancel" },
  { value: "backorder", label: "Backorder" },
  { value: "fulfilled", label: "Fulfilled" },
];

const itemTitle = computed(() => props.line?.title || props.line?.sku || "Item");
const currentStatus = computed(() => String(props.line?.line_status || "pending"));

function statusLabel(status) {
  if (status === "cancelled") return "Cancelled";
  if (status === "fulfilled") return "Fulfilled";
  if (status === "backorder") return "Backorder";
  return "Pending";
}

watch(
  () => props.open,
  (isOpen) => {
    if (!isOpen) return;
    step.value = "pick";
    trackingNumber.value = "";
  },
);

function onClose() {
  if (props.busy) return;
  emit("close");
}

function choose(value) {
  if (props.busy) return;
  if (value === currentStatus.value) return;
  if (value === "fulfilled") {
    step.value = "fulfill";
    return;
  }
  emit("pick", { status: value });
}

function onFulfillConfirm() {
  if (props.busy) return;
  emit("pick", {
    status: "fulfilled",
    trackingNumber: trackingNumber.value.trim(),
  });
}

function backToPick() {
  if (props.busy) return;
  step.value = "pick";
}
</script>

<template>
  <Teleport to="body">
    <div
      v-if="open"
      class="so-modal-overlay"
      role="dialog"
      aria-modal="true"
      aria-labelledby="so-line-status-title"
      @click.self="onClose"
    >
      <div class="so-modal" @click.stop>
        <button type="button" class="so-modal__close" aria-label="Close" :disabled="busy" @click="onClose">×</button>

        <template v-if="step === 'pick'">
          <h2 id="so-line-status-title" class="so-modal__title mb-2">Change Item Status</h2>
          <p class="so-modal__lead">
            {{ itemTitle }}
            <span class="d-block mt-1">
              Current:
              <strong>{{ statusLabel(currentStatus) }}</strong>
            </span>
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
        </template>

        <template v-else>
          <h2 id="so-line-status-title" class="so-modal__title">Mark Fulfilled</h2>
          <p class="so-modal__lead">{{ itemTitle }} will be fulfilled in Shopify.</p>
          <label class="form-label fw-semibold" for="so-line-status-tracking">Tracking Number</label>
          <input
            id="so-line-status-tracking"
            v-model="trackingNumber"
            type="text"
            class="form-control mb-2"
            placeholder="Enter tracking number"
            :disabled="busy"
            @keydown.enter.prevent="onFulfillConfirm"
          >
          <p class="small text-secondary mb-4">Optional. Tracking is sent to Shopify with this item.</p>
          <div class="d-flex justify-content-end gap-2">
            <button type="button" class="btn btn-light" :disabled="busy" @click="backToPick">Back</button>
            <button type="button" class="btn btn-primary staff-page-primary fw-semibold" :disabled="busy" @click="onFulfillConfirm">
              {{ busy ? "Saving…" : "Mark Fulfilled" }}
            </button>
          </div>
        </template>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.so-modal-overlay {
  position: fixed;
  inset: 0;
  z-index: 1300;
  background: rgba(17, 24, 39, 0.45);
  display: flex;
  align-items: flex-start;
  justify-content: center;
  padding: 1rem;
  overflow-y: auto;
}
.so-modal {
  width: min(26rem, 100%);
  max-height: calc(100vh - 2rem);
  overflow-x: hidden;
  overflow-y: auto;
  background: #fff;
  border-radius: 0.85rem;
  padding: 1.25rem 1.25rem 1.1rem;
  position: relative;
  box-shadow: 0 20px 50px rgba(15, 23, 42, 0.18);
  margin: auto;
}
.so-modal__close {
  position: absolute;
  top: 0.65rem;
  right: 0.75rem;
  border: 0;
  background: transparent;
  font-size: 1.4rem;
  line-height: 1;
  color: #6b7280;
}
.so-modal__title {
  font-size: 1.15rem;
  font-weight: 700;
  margin: 0 0 0.35rem;
  padding-right: 1.5rem;
}
.so-modal__lead {
  color: #4b5563;
  font-size: 0.92rem;
  margin-bottom: 1rem;
}
.so-status-list {
  display: flex;
  flex-direction: column;
  gap: 0.45rem;
}
.so-status-list__item {
  width: 100%;
  text-align: left;
  border: 1px solid #e5e7eb;
  background: #fff;
  border-radius: 0.65rem;
  padding: 0.7rem 0.85rem;
  font-weight: 600;
  color: #111827;
}
.so-status-list__item:hover:not(:disabled) {
  background: #f8fafc;
  border-color: #cbd5e1;
}
.so-status-list__item.is-current,
.so-status-list__item:disabled {
  opacity: 0.55;
  cursor: default;
}
</style>
