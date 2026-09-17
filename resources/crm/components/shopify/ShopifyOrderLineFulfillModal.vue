<script setup>
import { computed, ref, watch } from "vue";

const props = defineProps({
  open: { type: Boolean, default: false },
  busy: { type: Boolean, default: false },
  line: { type: Object, default: null },
});

const emit = defineEmits(["close", "confirm"]);

const trackingNumber = ref("");

const title = computed(() => props.line?.title || props.line?.sku || "Item");

watch(
  () => props.open,
  (isOpen) => {
    if (isOpen) trackingNumber.value = "";
  },
);

function onClose() {
  if (props.busy) return;
  emit("close");
}

function onSubmit() {
  if (props.busy) return;
  emit("confirm", { trackingNumber: trackingNumber.value.trim() });
}
</script>

<template>
  <Teleport to="body">
    <div
      v-if="open"
      class="so-modal-overlay"
      role="dialog"
      aria-modal="true"
      aria-labelledby="so-line-fulfill-title"
      @click.self="onClose"
    >
      <div class="so-modal" @click.stop>
        <button type="button" class="so-modal__close" aria-label="Close" :disabled="busy" @click="onClose">×</button>
        <div class="so-modal__head">
          <h2 id="so-line-fulfill-title" class="so-modal__title">Mark Fulfilled</h2>
          <p class="so-modal__lead mb-0">{{ title }} will be fulfilled in Shopify.</p>
        </div>
        <label class="form-label fw-semibold" for="so-line-tracking">Tracking Number</label>
        <input
          id="so-line-tracking"
          v-model="trackingNumber"
          type="text"
          class="form-control mb-2"
          placeholder="Enter tracking number"
          :disabled="busy"
          @keydown.enter.prevent="onSubmit"
        >
        <p class="small text-secondary mb-4">Optional. Tracking is sent to Shopify with this item.</p>
        <div class="d-flex justify-content-end gap-2">
          <button type="button" class="btn btn-light" :disabled="busy" @click="onClose">Cancel</button>
          <button type="button" class="btn btn-success fw-semibold" :disabled="busy" @click="onSubmit">
            {{ busy ? "Saving…" : "Mark Fulfilled" }}
          </button>
        </div>
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
  align-items: center;
  justify-content: center;
  padding: 1rem;
}
.so-modal {
  width: min(28rem, 100%);
  background: #fff;
  border-radius: 0.85rem;
  padding: 1.25rem 1.25rem 1.1rem;
  position: relative;
  box-shadow: 0 20px 50px rgba(15, 23, 42, 0.18);
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
}
.so-modal__lead {
  color: #4b5563;
  font-size: 0.92rem;
  margin-bottom: 1rem !important;
}
</style>
