<script setup>
import { computed, ref, watch } from "vue";
import CrmLoadingSpinner from "../common/CrmLoadingSpinner.vue";
import CrmRightDrawer from "../common/CrmRightDrawer.vue";
import {
  CRM_BTN_PRIMARY,
  CRM_BTN_SECONDARY,
  CRM_DIALOG_FOOTER_CLASS_DRAWER,
} from "../../constants/dialogFooter.js";

const props = defineProps({
  open: { type: Boolean, default: false },
  busy: { type: Boolean, default: false },
});

const emit = defineEmits(["update:open", "submit"]);

const text = ref("");
const errorMsg = ref("");

const canSubmit = computed(() => String(text.value || "").trim() !== "" && !props.busy);

watch(
  () => props.open,
  (open) => {
    if (open) {
      text.value = "";
      errorMsg.value = "";
    }
  },
);

function close() {
  if (!props.busy) emit("update:open", false);
}

function submit() {
  if (!canSubmit.value) {
    errorMsg.value = "Paste lead details to continue.";
    return;
  }
  errorMsg.value = "";
  emit("submit", { text: String(text.value).trim() });
}
</script>

<template>
  <CrmRightDrawer
    :open="open"
    title="Quick Add"
    subtitle="Paste Company, Website, Email, and Email Thread."
    :busy="busy"
    max-width="xl"
    form-id="lead-quick-add-form"
    @update:open="close"
    @submit="submit"
  >
    <div class="d-flex flex-column gap-3">
      <p v-if="errorMsg" class="alert alert-danger py-2 mb-0 small">{{ errorMsg }}</p>
      <div>
        <label class="form-label" for="lead-quick-add-text">Paste Lead Info</label>
        <textarea
          id="lead-quick-add-text"
          v-model="text"
          class="form-control font-monospace"
          rows="14"
          :disabled="busy"
          placeholder="Company: Blue Ridge Exotics
Website: blueridgeexotics.com
Email: sales@blueridgeexotics.com

Email Thread:
Can you send over some details on what this would look like for us?"
        />
      </div>
    </div>

    <template #footer>
      <div :class="CRM_DIALOG_FOOTER_CLASS_DRAWER">
        <button type="button" :class="CRM_BTN_SECONDARY" :disabled="busy" @click="close">
          Cancel
        </button>
        <button
          type="submit"
          form="lead-quick-add-form"
          :class="CRM_BTN_PRIMARY"
          :disabled="!canSubmit"
        >
          <CrmLoadingSpinner v-if="busy" small class="me-1" />
          Create Lead
        </button>
      </div>
    </template>
  </CrmRightDrawer>
</template>
