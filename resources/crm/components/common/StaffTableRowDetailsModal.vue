<script setup>
defineProps({
  open: { type: Boolean, default: false },
  title: { type: String, default: "Details" },
  subtitle: { type: String, default: "" },
});

const emit = defineEmits(["close"]);

function onBackdrop() {
  emit("close");
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
        <div class="crm-vx-modal staff-table-row-details-modal" @click.stop>
          <button
            type="button"
            class="crm-vx-modal__close"
            aria-label="Close"
            @click="emit('close')"
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
            <slot />
          </div>

          <footer v-if="$slots.footer" class="crm-vx-modal__footer">
            <slot name="footer" />
          </footer>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>
