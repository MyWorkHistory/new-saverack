<script setup>
import { computed, reactive, watch } from "vue";
import { SHOPIFY_ORDER_HOLD_REASONS } from "../../composables/useShopifyOrderActions.js";

const props = defineProps({
  open: { type: Boolean, default: false },
  busy: { type: Boolean, default: false },
  /** Active hold reason labels on the order. */
  activeReasons: { type: Array, default: () => [] },
});

const emit = defineEmits(["close", "confirm"]);

/** Checked = remove this hold. */
const selected = reactive({});

const visibleReasons = computed(() => {
  const active = new Set(
    (Array.isArray(props.activeReasons) ? props.activeReasons : [])
      .map((r) => String(r || "").trim())
      .filter(Boolean),
  );
  return SHOPIFY_ORDER_HOLD_REASONS.filter((r) => active.has(r.label));
});

function syncSelection() {
  SHOPIFY_ORDER_HOLD_REASONS.forEach((r) => {
    selected[r.label] = false;
  });
  visibleReasons.value.forEach((r) => {
    selected[r.label] = true;
  });
}

watch(
  () => props.open,
  (isOpen) => {
    if (isOpen) syncSelection();
  },
);

const canSubmit = computed(() => visibleReasons.value.some((r) => selected[r.label]));

function onClose() {
  if (props.busy) return;
  emit("close");
}

function onSubmit() {
  if (!canSubmit.value || props.busy) return;
  emit(
    "confirm",
    visibleReasons.value.filter((r) => selected[r.label]).map((r) => r.label),
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
      aria-labelledby="shopify-remove-hold-title"
      @click.self="onClose"
    >
      <div class="so-modal so-modal--hold" @click.stop>
        <button type="button" class="so-modal__close" aria-label="Close" :disabled="busy" @click="onClose">
          <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>

        <div class="so-modal__head">
          <span class="so-modal__icon so-modal__icon--remove" aria-hidden="true">
            <svg width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M10 9v6m4-6v6" />
              <circle cx="12" cy="12" r="9" />
            </svg>
          </span>
          <div>
            <h2 id="shopify-remove-hold-title" class="so-modal__title">Remove Holds</h2>
            <p class="so-modal__subtitle mb-0">
              Select the holds you would like to remove. Uncheck any hold you want to keep.
            </p>
          </div>
        </div>

        <div v-if="!visibleReasons.length" class="small text-secondary mb-3">
          No removable holds on this order.
        </div>
        <ul v-else class="list-unstyled mb-0 d-flex flex-column gap-2 so-remove-hold-list">
          <li v-for="reason in visibleReasons" :key="reason.label" class="form-check">
            <input
              :id="`shopify-remove-hold-${reason.label}`"
              v-model="selected[reason.label]"
              class="form-check-input"
              type="checkbox"
              :disabled="busy"
            />
            <label class="form-check-label" :for="`shopify-remove-hold-${reason.label}`">
              <span class="fw-semibold d-block">{{ reason.label }}</span>
              <span class="small text-secondary">{{ reason.description }}</span>
            </label>
          </li>
        </ul>

        <footer class="so-modal__foot">
          <button type="button" class="btn btn-outline-secondary orders-toolbar-outline-btn" :disabled="busy" @click="onClose">
            Cancel
          </button>
          <button
            type="button"
            class="btn btn-danger text-white fw-semibold"
            :disabled="busy || !canSubmit"
            @click="onSubmit"
          >
            {{ busy ? "Removing…" : "Remove Hold" }}
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
  max-width: 28rem;
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
.so-modal__icon--remove {
  background: #fee2e2;
  color: #b91c1c;
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
.so-remove-hold-list .form-check {
  display: flex;
  align-items: flex-start;
  gap: 0.65rem;
  padding: 0.75rem 0.9rem;
  border: 1px solid #e5e7eb;
  border-radius: 0.65rem;
  margin: 0;
}
.so-remove-hold-list .form-check-input {
  margin-top: 0.2rem;
  flex-shrink: 0;
}
.so-remove-hold-list .form-check-label {
  cursor: pointer;
}
.so-modal__foot {
  display: flex;
  justify-content: flex-end;
  gap: 0.55rem;
  margin-top: 1.15rem;
}
</style>
