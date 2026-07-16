<script setup>
import { ref, watch } from "vue";
import CrmRightDrawer from "../common/CrmRightDrawer.vue";
import {
  CRM_BTN_PRIMARY,
  CRM_BTN_SECONDARY,
  CRM_DIALOG_FOOTER_CLASS_DRAWER,
} from "../../constants/dialogFooter.js";
import { WHOLESALE_REQUIREMENT_SECTIONS } from "../../utils/formatWholesaleOrderDisplay.js";

const props = defineProps({
  open: { type: Boolean, default: false },
  order: { type: Object, default: null },
  busy: { type: Boolean, default: false },
});

const emit = defineEmits(["update:open", "save", "close"]);

/** @type {import('vue').Ref<Record<string, { value: string, comment: string }>>} */
const drafts = ref(
  Object.fromEntries(
    WHOLESALE_REQUIREMENT_SECTIONS.map((s) => [s.valueKey, { value: "", comment: "" }]),
  ),
);

function initDrafts() {
  const next = {};
  for (const section of WHOLESALE_REQUIREMENT_SECTIONS) {
    next[section.valueKey] = {
      value: String(props.order?.[section.valueKey] || ""),
      comment: String(props.order?.[section.commentKey] || ""),
    };
  }
  drafts.value = next;
}

watch(
  () => props.open,
  (isOpen) => {
    if (isOpen) initDrafts();
  },
);

function close() {
  if (props.busy) return;
  emit("update:open", false);
  emit("close");
}

function canSubmit() {
  return WHOLESALE_REQUIREMENT_SECTIONS.every((section) =>
    String(drafts.value[section.valueKey]?.value || "").trim() !== "",
  );
}

function submit() {
  if (!canSubmit() || props.busy) return;
  const payload = {};
  for (const section of WHOLESALE_REQUIREMENT_SECTIONS) {
    const row = drafts.value[section.valueKey] || { value: "", comment: "" };
    payload[section.valueKey] = String(row.value || "").trim() || null;
    payload[section.commentKey] = String(row.comment || "").trim() || null;
  }
  emit("save", payload);
}
</script>

<template>
  <CrmRightDrawer
    :open="open"
    title="Product & Fulfillment Requirements"
    subtitle="Set all fulfillment options and optional comments."
    :busy="busy"
    form-id="wholesale-requirements-bulk-form"
    max-width="2xl"
    @update:open="(v) => { emit('update:open', v); if (!v) emit('close'); }"
    @submit="submit"
  >
    <div class="d-flex flex-column gap-4">
      <div
        v-for="section in WHOLESALE_REQUIREMENT_SECTIONS"
        :key="section.id"
        class="wholesale-req-bulk-block"
      >
        <label class="form-label small text-secondary" :for="`wholesale-req-bulk-${section.id}`">
          {{ section.label }} <span class="text-danger">*</span>
        </label>
        <select
          :id="`wholesale-req-bulk-${section.id}`"
          v-model="drafts[section.valueKey].value"
          class="form-select mb-2"
          required
          :disabled="busy"
        >
          <option value="">Select an option</option>
          <option v-for="opt in section.options" :key="opt.value" :value="opt.value">
            {{ opt.label }}
          </option>
        </select>
        <label
          class="form-label small text-secondary"
          :for="`wholesale-req-bulk-comment-${section.id}`"
        >
          Comments (Optional)
        </label>
        <textarea
          :id="`wholesale-req-bulk-comment-${section.id}`"
          v-model="drafts[section.valueKey].comment"
          class="form-control"
          rows="2"
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
          form="wholesale-requirements-bulk-form"
          :class="CRM_BTN_PRIMARY"
          :disabled="busy || !canSubmit()"
        >
          {{ busy ? "Saving…" : "Save" }}
        </button>
      </div>
    </template>
  </CrmRightDrawer>
</template>

<style scoped>
.wholesale-req-bulk-block + .wholesale-req-bulk-block {
  padding-top: 0.25rem;
  border-top: 1px solid var(--bs-border-color);
  padding-top: 1rem;
}
</style>
