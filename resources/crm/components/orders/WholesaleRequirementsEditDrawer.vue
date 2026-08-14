<script setup>
import { computed, ref, watch } from "vue";
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

/** @type {import('vue').Ref<Record<string, string>>} */
const drafts = ref(
  Object.fromEntries(WHOLESALE_REQUIREMENT_SECTIONS.map((s) => [s.valueKey, ""])),
);

const qtyPerBoxDraft = ref("");
const boxSizeDraft = ref("");

const showCustomPackagingFields = computed(
  () => String(drafts.value.shipping_method_requirement || "") === "custom",
);

function normalizeBundleValue(raw) {
  const v = String(raw || "").trim();
  if (v === "bundle_together") return "yes";
  if (v === "not_bundled") return "no";
  return v;
}

function initDrafts() {
  const next = {};
  for (const section of WHOLESALE_REQUIREMENT_SECTIONS) {
    let value = String(props.order?.[section.valueKey] || "");
    if (section.valueKey === "bundle_configuration") {
      value = normalizeBundleValue(value);
    }
    next[section.valueKey] = value;
  }
  drafts.value = next;
  qtyPerBoxDraft.value = String(props.order?.shipping_packaging_qty_per_box || "");
  boxSizeDraft.value = String(props.order?.shipping_packaging_box_size || "");
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
  const baseOk = WHOLESALE_REQUIREMENT_SECTIONS.every(
    (section) => String(drafts.value[section.valueKey] || "").trim() !== "",
  );
  if (!baseOk) return false;
  if (showCustomPackagingFields.value) {
    return (
      String(qtyPerBoxDraft.value || "").trim() !== "" &&
      String(boxSizeDraft.value || "").trim() !== ""
    );
  }
  return true;
}

function submit() {
  if (!canSubmit() || props.busy) return;
  const payload = {};
  for (const section of WHOLESALE_REQUIREMENT_SECTIONS) {
    payload[section.valueKey] = String(drafts.value[section.valueKey] || "").trim() || null;
  }
  if (showCustomPackagingFields.value) {
    payload.shipping_packaging_qty_per_box = String(qtyPerBoxDraft.value || "").trim() || null;
    payload.shipping_packaging_box_size = String(boxSizeDraft.value || "").trim() || null;
  } else {
    payload.shipping_packaging_qty_per_box = null;
    payload.shipping_packaging_box_size = null;
  }
  emit("save", payload);
}
</script>

<template>
  <CrmRightDrawer
    :open="open"
    title="Product & Fulfillment Requirements"
    subtitle="Set all fulfillment options for this order."
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
        <label
          class="form-label fw-semibold mb-0"
          :for="`wholesale-req-bulk-${section.id}`"
        >
          {{ section.label }} <span class="text-danger">*</span>
        </label>
        <p v-if="section.helper" class="small text-secondary mb-2 mt-1">
          {{ section.helper }}
        </p>
        <select
          :id="`wholesale-req-bulk-${section.id}`"
          v-model="drafts[section.valueKey]"
          class="form-select"
          required
          :disabled="busy"
        >
          <option value="">Select an option</option>
          <option v-for="opt in section.options" :key="opt.value" :value="opt.value">
            {{ opt.label }}
          </option>
        </select>

        <div
          v-if="section.customFields && showCustomPackagingFields"
          class="row g-3 mt-1"
        >
          <div class="col-md-6">
            <label class="form-label small fw-semibold" for="wholesale-req-qty-per-box">
              QTY Per Box <span class="text-danger">*</span>
            </label>
            <input
              id="wholesale-req-qty-per-box"
              v-model="qtyPerBoxDraft"
              type="text"
              class="form-control"
              placeholder="QTY Per Box"
              :disabled="busy"
            />
          </div>
          <div class="col-md-6">
            <label class="form-label small fw-semibold" for="wholesale-req-box-size">
              Box Size <span class="text-danger">*</span>
            </label>
            <input
              id="wholesale-req-box-size"
              v-model="boxSizeDraft"
              type="text"
              class="form-control"
              placeholder="Box Size"
              :disabled="busy"
            />
          </div>
        </div>
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
  border-top: 1px solid var(--bs-border-color);
  padding-top: 1rem;
}
</style>
