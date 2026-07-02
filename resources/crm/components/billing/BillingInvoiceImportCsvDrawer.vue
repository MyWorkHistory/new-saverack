<script setup>
import CrmRightDrawer from "../common/CrmRightDrawer.vue";
import CrmSearchableSelect from "../common/CrmSearchableSelect.vue";
import {
  CRM_BTN_PRIMARY,
  CRM_BTN_SECONDARY,
  CRM_DIALOG_FOOTER_CLASS_DRAWER,
} from "../../constants/dialogFooter.js";

defineProps({
  open: { type: Boolean, default: false },
  form: { type: Object, required: true },
  clientAccounts: { type: Array, default: () => [] },
  busy: { type: Boolean, default: false },
});

const emit = defineEmits(["update:open", "file-change", "submit"]);

function close() {
  emit("update:open", false);
}
</script>

<template>
  <CrmRightDrawer
    :open="open"
    title="Import Invoice CSV"
    :busy="busy"
    @update:open="emit('update:open', $event)"
  >
    <div class="mb-3">
      <label class="form-label" for="billing-import-type">Import Type</label>
      <select id="billing-import-type" v-model="form.import_type" class="form-select" :disabled="busy">
        <option value="charges">Charge CSV</option>
        <option value="storage">Storage CSV</option>
        <option value="duties_taxes_asendia">Asendia Duties & Taxes</option>
        <option value="duties_taxes_ups">UPS Duties & Taxes</option>
      </select>
    </div>
    <div class="mb-3">
      <label class="form-label" for="billing-import-file">Import File</label>
      <input
        id="billing-import-file"
        type="file"
        class="form-control"
        accept=".csv,.txt,.xlsx,text/csv,text/plain,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
        :disabled="busy"
        @change="emit('file-change', $event)"
      />
    </div>
    <div class="mb-3">
      <label class="form-label" for="billing-import-client">Client Account</label>
      <CrmSearchableSelect
        v-model="form.client_account_id"
        appearance="staff"
        teleport-panel
        :options="clientAccounts"
        placeholder="Select client account"
        search-placeholder="Search clients…"
        empty-label="No client account selected"
        button-id="billing-import-client"
        :disabled="busy"
      />
    </div>
    <div class="mb-0">
      <label class="form-label" for="billing-import-number">Invoice # (Optional)</label>
      <input
        id="billing-import-number"
        v-model="form.invoice_number"
        type="text"
        class="form-control"
        placeholder="12001"
        :disabled="busy"
      />
    </div>

    <template #footer>
      <footer :class="CRM_DIALOG_FOOTER_CLASS_DRAWER">
        <button type="button" :class="CRM_BTN_SECONDARY" :disabled="busy" @click="close">
          Cancel
        </button>
        <button type="button" :class="CRM_BTN_PRIMARY" :disabled="busy" @click="emit('submit')">
          {{ busy ? "Importing…" : "Import CSV" }}
        </button>
      </footer>
    </template>
  </CrmRightDrawer>
</template>
