<script setup>
import CrmRightDrawer from "../common/CrmRightDrawer.vue";
import {
  CRM_BTN_PRIMARY,
  CRM_BTN_SECONDARY,
  CRM_DIALOG_FOOTER_CLASS_DRAWER,
} from "../../constants/dialogFooter.js";

const props = defineProps({
  open: { type: Boolean, default: false },
  asnNumber: { type: String, default: "" },
  scanText: { type: String, default: "" },
  busy: { type: Boolean, default: false },
});

const emit = defineEmits(["update:open", "update:asnNumber", "update:scanText", "submit"]);

function close() {
  if (props.busy) return;
  emit("update:open", false);
}
</script>

<template>
  <CrmRightDrawer
    :open="open"
    title="Scan Items"
    :busy="busy"
    form-id="admin-asn-scan-form"
    @update:open="emit('update:open', $event)"
    @submit="emit('submit')"
  >
    <label class="form-label" for="admin-asn-scan-asn-number">ASN #</label>
    <input
      id="admin-asn-scan-asn-number"
      :value="asnNumber"
      type="text"
      class="form-control mb-3"
      placeholder="e.g. 0010"
      autocomplete="off"
      :disabled="busy"
      @input="emit('update:asnNumber', $event.target.value)"
    />
    <label class="form-label" for="admin-asn-scan-barcodes">Enter barcodes line by line</label>
    <textarea
      id="admin-asn-scan-barcodes"
      :value="scanText"
      class="form-control font-monospace"
      rows="10"
      :disabled="busy"
      @input="emit('update:scanText', $event.target.value)"
    />

    <template #footer>
      <footer :class="CRM_DIALOG_FOOTER_CLASS_DRAWER">
        <button type="button" :class="CRM_BTN_SECONDARY" :disabled="busy" @click="close">
          Cancel
        </button>
        <button
          type="submit"
          form="admin-asn-scan-form"
          :class="CRM_BTN_PRIMARY"
          :disabled="busy"
        >
          {{ busy ? "Saving…" : "Save" }}
        </button>
      </footer>
    </template>
  </CrmRightDrawer>
</template>
