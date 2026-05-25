<script setup>
defineProps({
  open: { type: Boolean, default: false },
  lg: { type: Boolean, default: true },
  scrollable: { type: Boolean, default: false },
});

const emit = defineEmits(["update:open"]);

function close() {
  emit("update:open", false);
}
</script>

<template>
  <Teleport to="body">
    <Transition name="portal-modal">
      <div
        v-if="open"
        class="portal-onboard-modal"
        tabindex="-1"
        role="dialog"
        aria-modal="true"
        @click.self="close"
      >
        <div class="portal-onboard-modal__scrim" aria-hidden="true" @click="close" />
        <div
          class="modal-dialog modal-dialog-centered portal-onboard-modal__dialog"
          :class="{
            'modal-lg': lg,
            'modal-dialog-scrollable': scrollable,
          }"
          @click.stop
        >
          <slot />
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.portal-onboard-modal {
  position: fixed;
  inset: 0;
  z-index: 1055;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1rem;
  overflow-y: auto;
}

.portal-onboard-modal__scrim {
  position: fixed;
  inset: 0;
  background: rgba(15, 23, 42, 0.45);
  z-index: 0;
}

.portal-onboard-modal__dialog {
  position: relative;
  z-index: 1;
  margin: 0;
  width: 100%;
  max-width: 100%;
}

.portal-modal-enter-active,
.portal-modal-leave-active {
  transition: opacity 0.22s ease;
}

.portal-modal-enter-active .portal-onboard-modal__dialog,
.portal-modal-leave-active .portal-onboard-modal__dialog {
  transition:
    opacity 0.22s ease,
    transform 0.22s ease;
}

.portal-modal-enter-from,
.portal-modal-leave-to {
  opacity: 0;
}

.portal-modal-enter-from .portal-onboard-modal__dialog,
.portal-modal-leave-to .portal-onboard-modal__dialog {
  opacity: 0;
  transform: scale(0.97) translateY(0.5rem);
}
</style>
