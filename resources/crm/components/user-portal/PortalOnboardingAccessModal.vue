<script setup>
import PortalOnboardingModalShell from "./PortalOnboardingModalShell.vue";

defineProps({
  open: { type: Boolean, default: false },
});

const emit = defineEmits(["update:open"]);

function close() {
  emit("update:open", false);
}
</script>

<template>
  <PortalOnboardingModalShell :open="open" :lg="false" @update:open="close">
    <button
      type="button"
      class="crm-vx-modal__close"
      aria-label="Close"
      @click="close"
    >
      <span aria-hidden="true">&times;</span>
    </button>
    <header class="crm-vx-modal__head">
      <h2 id="portal-onboarding-access-title" class="crm-vx-modal__title">
        Account Onboarding
      </h2>
    </header>
    <div class="crm-vx-modal__body" role="document" aria-labelledby="portal-onboarding-access-title">
      <p class="mb-0 text-body">
        Your account is currently in the onboarding process. Access to the WMS will be available
        once your account has been activated. If you have any questions, please contact your
        Account Manager.
      </p>
    </div>
    <footer class="crm-vx-modal__footer d-flex justify-content-end">
      <button type="button" class="crm-vx-modal-btn crm-vx-modal-btn--primary" @click="close">
        Close
      </button>
    </footer>
  </PortalOnboardingModalShell>
</template>
