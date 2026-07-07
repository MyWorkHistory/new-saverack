<script setup>
import { ref, watch } from "vue";
import CrmRightDrawer from "../common/CrmRightDrawer.vue";
import {
  CRM_BTN_PRIMARY,
  CRM_BTN_SECONDARY,
  CRM_DIALOG_FOOTER_CLASS_DRAWER,
} from "../../constants/dialogFooter.js";

const props = defineProps({
  open: { type: Boolean, default: false },
  title: { type: String, required: true },
  options: { type: Array, default: () => [] },
  value: { type: String, default: "" },
  comment: { type: String, default: "" },
  busy: { type: Boolean, default: false },
});

const emit = defineEmits(["update:open", "save", "close"]);

const valueDraft = ref("");
const commentDraft = ref("");

watch(
  () => props.open,
  (isOpen) => {
    if (!isOpen) return;
    valueDraft.value = String(props.value || "");
    commentDraft.value = String(props.comment || "");
  },
);

function close() {
  if (props.busy) return;
  emit("update:open", false);
  emit("close");
}

function submit() {
  if (!String(valueDraft.value || "").trim()) return;
  emit("save", {
    value: valueDraft.value,
    comment: commentDraft.value.trim(),
  });
}
</script>

<template>
  <CrmRightDrawer
    :open="open"
    :title="title"
    subtitle="Choose an option and add an optional comment."
    :busy="busy"
    form-id="wholesale-requirement-edit-form"
    max-width="xl"
    @update:open="(v) => { emit('update:open', v); if (!v) emit('close'); }"
    @submit="submit"
  >
    <div class="d-flex flex-column gap-3">
      <div>
        <label class="form-label small text-secondary" :for="`wholesale-req-drawer-${title}`">
          {{ title }} <span class="text-danger">*</span>
        </label>
        <select
          :id="`wholesale-req-drawer-${title}`"
          v-model="valueDraft"
          class="form-select"
          required
          :disabled="busy"
        >
          <option value="">Select an option</option>
          <option v-for="opt in options" :key="opt.value" :value="opt.value">
            {{ opt.label }}
          </option>
        </select>
      </div>
      <div>
        <label class="form-label small text-secondary" for="wholesale-req-drawer-comment">
          Comments (Optional)
        </label>
        <textarea
          id="wholesale-req-drawer-comment"
          v-model="commentDraft"
          class="form-control"
          rows="4"
          placeholder="Enter any additional comments..."
          :disabled="busy"
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
          form="wholesale-requirement-edit-form"
          :class="CRM_BTN_PRIMARY"
          :disabled="busy || !String(valueDraft || '').trim()"
        >
          {{ busy ? "Saving…" : "Save" }}
        </button>
      </div>
    </template>
  </CrmRightDrawer>
</template>
