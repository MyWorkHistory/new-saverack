<script setup>
import { computed, reactive, watch } from "vue";
import { SHOPIFY_ORDER_HOLD_REASONS } from "../../composables/useShopifyOrderActions.js";

const props = defineProps({
  open: { type: Boolean, default: false },
  busy: { type: Boolean, default: false },
  orderCount: { type: Number, default: 1 },
});

const emit = defineEmits(["close", "confirm"]);

const selected = reactive({});

function resetSelection() {
  SHOPIFY_ORDER_HOLD_REASONS.forEach((r) => {
    selected[r.label] = false;
  });
}

watch(
  () => props.open,
  (isOpen) => {
    if (isOpen) resetSelection();
  },
);

const canSubmit = computed(() => SHOPIFY_ORDER_HOLD_REASONS.some((r) => selected[r.label]));

const title = computed(() =>
  props.orderCount > 1 ? `Hold ${props.orderCount} Orders` : "Hold Order",
);

function onClose() {
  if (props.busy) return;
  emit("close");
}

function onSubmit() {
  if (!canSubmit.value || props.busy) return;
  emit(
    "confirm",
    SHOPIFY_ORDER_HOLD_REASONS.filter((r) => selected[r.label]).map((r) => r.label),
  );
}
</script>

<template>
  <Teleport to="body">
    <div
      v-if="open"
      class="so-modal-overlay"
      role="dialog"
      aria-modal="true"
      aria-labelledby="shopify-hold-title"
      @click.self="onClose"
    >
      <div class="so-modal so-modal--hold" @click.stop>
        <button type="button" class="so-modal__close" aria-label="Close" :disabled="busy" @click="onClose">
          <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>

        <div class="so-modal__head">
          <span class="so-modal__icon so-modal__icon--hold" aria-hidden="true">
            <svg width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M10 9v6m4-6v6" />
              <circle cx="12" cy="12" r="9" />
            </svg>
          </span>
          <div>
            <h2 id="shopify-hold-title" class="so-modal__title">{{ title }}</h2>
            <p class="so-modal__subtitle mb-0">
              Select one or more reasons to place this order on hold.
            </p>
          </div>
        </div>

        <div class="so-hold-grid">
          <button
            v-for="reason in SHOPIFY_ORDER_HOLD_REASONS"
            :key="reason.label"
            type="button"
            class="so-hold-card"
            :class="{ 'is-selected': selected[reason.label] }"
            :disabled="busy"
            @click="selected[reason.label] = !selected[reason.label]"
          >
            <span class="so-hold-card__check" :class="{ 'is-on': selected[reason.label] }" aria-hidden="true">
              <svg v-if="selected[reason.label]" width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="#fff" stroke-width="3">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
              </svg>
            </span>
            <span class="so-hold-card__label">{{ reason.label }}</span>
            <span class="so-hold-card__desc">{{ reason.description }}</span>
          </button>
        </div>

        <div class="so-info-banner so-info-banner--warn">
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z" />
          </svg>
          Held orders will not be released for fulfillment until the hold is removed.
        </div>

        <footer class="so-modal__foot">
          <button type="button" class="btn btn-outline-secondary orders-toolbar-outline-btn" :disabled="busy" @click="onClose">
            Cancel
          </button>
          <button
            type="button"
            class="btn btn-primary staff-page-primary fw-semibold"
            :disabled="busy || !canSubmit"
            @click="onSubmit"
          >
            {{ busy ? "Applying…" : "Apply Hold" }}
          </button>
        </footer>
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
  max-width: 32rem;
  background: #fff;
  border-radius: 0.85rem;
  box-shadow: 0 20px 45px rgba(15, 23, 42, 0.2);
  padding: 1.35rem 1.5rem 1.25rem;
}
.so-modal__close {
  position: absolute;
  top: 0.75rem;
  right: 0.75rem;
  border: 0;
  background: transparent;
  color: #9ca3af;
  width: 2rem;
  height: 2rem;
  border-radius: 0.4rem;
}
.so-modal__head {
  display: flex;
  gap: 0.85rem;
  align-items: flex-start;
  margin-bottom: 1.15rem;
  padding-right: 1.5rem;
}
.so-modal__icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 2.75rem;
  height: 2.75rem;
  border-radius: 0.65rem;
  flex-shrink: 0;
}
.so-modal__icon--hold {
  background: #ffedd5;
  color: #c2410c;
}
.so-modal__title {
  margin: 0 0 0.25rem;
  font-size: 1.25rem;
  font-weight: 700;
  color: #111827;
}
.so-modal__subtitle {
  font-size: 0.9rem;
  color: #6b7280;
}
.so-hold-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.65rem;
  margin-bottom: 1rem;
}
.so-hold-card {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 0.2rem;
  text-align: left;
  padding: 0.85rem 0.9rem;
  border: 1px solid #e5e7eb !important;
  border-radius: 0.65rem;
  background: #fff;
  box-shadow: none;
  -webkit-appearance: none;
  appearance: none;
}
.so-hold-card.is-selected {
  border-color: #3b82f6 !important;
  background: #eff6ff;
}
.so-hold-card__check {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 1.15rem;
  height: 1.15rem;
  border: 1.5px solid #d1d5db;
  border-radius: 0.25rem;
  margin-bottom: 0.35rem;
}
.so-hold-card__check.is-on {
  background: #2563eb;
  border-color: #2563eb;
}
.so-hold-card__label {
  font-weight: 700;
  font-size: 0.95rem;
  color: #111827;
}
.so-hold-card__desc {
  font-size: 0.8rem;
  color: #6b7280;
}
.so-info-banner {
  display: flex;
  gap: 0.55rem;
  align-items: flex-start;
  padding: 0.75rem 0.9rem;
  border-radius: 0.55rem;
  font-size: 0.85rem;
  margin-bottom: 1rem;
}
.so-info-banner--warn {
  background: #fff7ed;
  color: #9a3412;
}
.so-modal__foot {
  display: flex;
  justify-content: flex-end;
  gap: 0.55rem;
}
@media (max-width: 575.98px) {
  .so-hold-grid {
    grid-template-columns: 1fr;
  }
}
</style>
