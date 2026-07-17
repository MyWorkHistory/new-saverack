<script setup>
defineProps({
  open: { type: Boolean, default: false },
  lg: { type: Boolean, default: true },
  xl: { type: Boolean, default: false },
  scrollable: { type: Boolean, default: false },
});

const emit = defineEmits(["update:open"]);

function close() {
  emit("update:open", false);
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
        @click.self="close"
      >
        <div class="crm-vx-modal-backdrop" aria-hidden="true" @click="close" />
        <div
          class="crm-vx-modal portal-onboard-modal__panel"
          :class="{
            'portal-onboard-modal__panel--lg': lg && !xl,
            'portal-onboard-modal__panel--xl': xl,
            'portal-onboard-modal__panel--scroll': scrollable,
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
.portal-onboard-modal__panel--lg {
  max-width: min(52rem, 100%);
}

.portal-onboard-modal__panel--xl {
  max-width: min(64rem, 100%);
}

.portal-onboard-modal__panel--scroll {
  max-height: min(90dvh, 720px);
}

.portal-onboard-modal__panel--scroll :deep(.portal-onboard-modal__body) {
  overflow-y: auto;
  flex: 1 1 auto;
  min-height: 0;
}
</style>
