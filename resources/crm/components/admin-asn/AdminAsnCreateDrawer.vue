<script setup>
import CrmRightDrawer from "../common/CrmRightDrawer.vue";
import CrmSearchableSelect from "../common/CrmSearchableSelect.vue";
import {
  CRM_BTN_PRIMARY,
  CRM_BTN_SECONDARY,
  CRM_DIALOG_FOOTER_CLASS_DRAWER,
} from "../../constants/dialogFooter.js";

const props = defineProps({
  open: { type: Boolean, default: false },
  accountId: { type: [String, Number], default: "" },
  accountOptions: { type: Array, default: () => [] },
  busy: { type: Boolean, default: false },
});

const emit = defineEmits(["update:open", "update:accountId", "submit"]);

function close() {
  if (props.busy) return;
  emit("update:open", false);
}
</script>

<template>
  <CrmRightDrawer
    :open="open"
    title="Create ASN"
    :busy="busy"
    form-id="admin-asn-create-form"
    @update:open="emit('update:open', $event)"
    @submit="emit('submit')"
  >
    <label class="form-label">Account</label>
    <CrmSearchableSelect
      :model-value="String(accountId)"
      appearance="staff"
      teleport-panel
      :options="accountOptions"
      placeholder="Select account…"
      :allow-empty="false"
      search-placeholder="Search accounts…"
      :disabled="busy"
      @update:model-value="emit('update:accountId', $event)"
    />

    <template #footer>
      <footer :class="CRM_DIALOG_FOOTER_CLASS_DRAWER">
        <button type="button" :class="CRM_BTN_SECONDARY" :disabled="busy" @click="close">
          Cancel
        </button>
        <button
          type="submit"
          form="admin-asn-create-form"
          :class="CRM_BTN_PRIMARY"
          :disabled="busy || !accountId"
        >
          {{ busy ? "Creating…" : "Continue" }}
        </button>
      </footer>
    </template>
  </CrmRightDrawer>
</template>
