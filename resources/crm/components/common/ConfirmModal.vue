<script setup>
const props = defineProps({
  open: { type: Boolean, default: false },
  title: { type: String, default: "Confirm" },
  subtitle: { type: String, default: "" },
  message: { type: String, default: "" },
  confirmLabel: { type: String, default: "Delete" },
  cancelLabel: { type: String, default: "Cancel" },
  danger: { type: Boolean, default: true },
  busy: { type: Boolean, default: false },
});

const emit = defineEmits(["close", "confirm"]);

function onBackdrop() {
  if (!props.busy) emit("close");
}
</script>

<template>
  <Teleport to="body">
    <Transition name="crm-vx-confirm">
      <div
        v-if="open"
        class="crm-vx-modal-overlay"
        role="dialog"
        aria-modal="true"
        @click.self="onBackdrop"
      >
        <div
          class="crm-vx-modal crm-vx-modal--sm"
          @click.stop
        >
          <button
            type="button"
            class="crm-vx-modal__close"
            aria-label="Close"
            :disabled="busy"
            @click="$emit('close')"
          >
            <svg
              width="20"
              height="20"
              fill="none"
              viewBox="0 0 24 24"
              stroke="currentColor"
              stroke-width="1.75"
              aria-hidden="true"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="M6 18L18 6M6 6l12 12"
              />
            </svg>
          </button>

          <header class="crm-vx-modal__head">
            <h2 class="crm-vx-modal__title">
              {{ title }}
            </h2>
            <p v-if="subtitle" class="crm-vx-modal__subtitle">
              {{ subtitle }}
            </p>
          </header>

          <div class="crm-vx-modal__body pt-0">
            <p class="mb-0 text-center small text-secondary">
              {{ message }}
            </p>
          </div>

          <footer class="crm-vx-modal__footer">
            <button
              type="button"
              class="crm-vx-modal-btn crm-vx-modal-btn--secondary"
              :disabled="busy"
              @click="$emit('close')"
            >
              {{ cancelLabel }}
            </button>
            <button
              type="button"
              class="crm-vx-modal-btn"
              :class="
                danger ? 'crm-vx-modal-btn--danger' : 'crm-vx-modal-btn--primary'
              "
              :disabled="busy"
              @click="$emit('confirm')"
            >
              {{ busy ? "Please wait…" : confirmLabel }}
            </button>
          </footer>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.crm-vx-confirm-enter-active,
.crm-vx-confirm-leave-active {
  transition: opacity 0.2s ease;
}
.crm-vx-confirm-enter-from,
.crm-vx-confirm-leave-to {
  opacity: 0;
}
</style>
