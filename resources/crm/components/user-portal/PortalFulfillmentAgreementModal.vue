<script setup>
import { computed, onUnmounted, ref, watch } from "vue";
import api from "../../services/api";
import PortalOnboardingModalShell from "./PortalOnboardingModalShell.vue";
import { useToast } from "../../composables/useToast";
import {
  CRM_BTN_PRIMARY,
  CRM_BTN_SECONDARY,
  CRM_DIALOG_FOOTER_CLASS,
} from "../../constants/dialogFooter.js";

const props = defineProps({
  open: { type: Boolean, default: false },
  agreement: { type: Object, default: null },
});

const emit = defineEmits(["update:open", "accepted"]);

const toast = useToast();
const accepting = ref(false);

const accepted = computed(() => props.agreement?.status === "completed");
const bodyHtml = computed(() => props.agreement?.body || "");
const hasBody = computed(
  () => !!(bodyHtml.value && String(bodyHtml.value).replace(/<[^>]+>/g, "").trim()),
);

function close() {
  if (!accepting.value) emit("update:open", false);
}

function onEsc(e) {
  if (e.key === "Escape") close();
}

watch(
  () => props.open,
  (isOpen) => {
    if (isOpen) {
      window.addEventListener("keydown", onEsc);
    } else {
      window.removeEventListener("keydown", onEsc);
    }
  },
);

onUnmounted(() => {
  window.removeEventListener("keydown", onEsc);
});

async function accept() {
  if (accepted.value || accepting.value) return;
  accepting.value = true;
  try {
    const { data } = await api.post("/portal/onboarding/fulfillment-agreement/accept");
    emit("accepted", data);
    toast.success("Fulfillment Agreement accepted.");
    emit("update:open", false);
  } catch (e) {
    toast.errorFrom(e, "Could not accept agreement.");
  } finally {
    accepting.value = false;
  }
}
</script>

<template>
  <PortalOnboardingModalShell :open="open" lg scrollable @update:open="close">
    <header class="crm-vx-modal__head">
      <h2 class="crm-vx-modal__title">Fulfillment Agreement</h2>
      <p class="crm-vx-modal__subtitle mb-0">
        Review the agreement below. Accept to complete this onboarding step.
      </p>
    </header>

    <div class="crm-vx-modal__body portal-onboard-modal__body">
      <div
        v-if="hasBody"
        class="portal-fulfillment-agreement-modal__body"
        v-html="bodyHtml"
      />
      <p v-else class="text-secondary mb-0">
        The fulfillment agreement is not available yet. Please contact support.
      </p>
    </div>

    <footer :class="CRM_DIALOG_FOOTER_CLASS">
      <button type="button" :class="CRM_BTN_SECONDARY" :disabled="accepting" @click="close">
        {{ accepted ? "Close" : "Cancel" }}
      </button>
      <button
        v-if="!accepted"
        type="button"
        :class="CRM_BTN_PRIMARY"
        :disabled="accepting || !hasBody"
        @click="accept"
      >
        {{ accepting ? "Accepting…" : "Accept Agreement" }}
      </button>
    </footer>
  </PortalOnboardingModalShell>
</template>

<style scoped>
.portal-fulfillment-agreement-modal__body {
  font-size: 0.925rem;
  line-height: 1.55;
  max-height: min(55vh, 420px);
  overflow-y: auto;
}
.portal-fulfillment-agreement-modal__body :deep(p) {
  margin: 0 0 0.65rem;
}
.portal-fulfillment-agreement-modal__body :deep(ul),
.portal-fulfillment-agreement-modal__body :deep(ol) {
  margin: 0 0 0.65rem;
  padding-left: 1.25rem;
}
.portal-fulfillment-agreement-modal__body :deep(h2),
.portal-fulfillment-agreement-modal__body :deep(h3),
.portal-fulfillment-agreement-modal__body :deep(h4) {
  margin: 0.85rem 0 0.45rem;
  font-size: 1.05rem;
  line-height: 1.3;
}
</style>
