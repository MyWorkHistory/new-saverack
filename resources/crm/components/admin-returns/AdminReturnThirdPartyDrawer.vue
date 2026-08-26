<script setup>
import CrmRightDrawer from "../common/CrmRightDrawer.vue";
import CrmSearchableSelect from "../common/CrmSearchableSelect.vue";
import {
  CRM_BTN_PRIMARY,
  CRM_BTN_SECONDARY,
  CRM_DIALOG_FOOTER_CLASS_DRAWER,
} from "../../constants/dialogFooter.js";

const THIRD_PARTY_OPTIONS = [
  { value: "amazon", label: "Amazon" },
  { value: "other", label: "Other" },
];

const props = defineProps({
  open: { type: Boolean, default: false },
  accountId: { type: [String, Number], default: "" },
  thirdPartyType: { type: String, default: "" },
  referenceNumber: { type: String, default: "" },
  returnComment: { type: String, default: "" },
  accountOptions: { type: Array, default: () => [] },
  busy: { type: Boolean, default: false },
});

const emit = defineEmits([
  "update:open",
  "update:accountId",
  "update:thirdPartyType",
  "update:referenceNumber",
  "update:returnComment",
  "submit",
]);

function close() {
  if (props.busy) return;
  emit("update:open", false);
}
</script>

<template>
  <CrmRightDrawer
    :open="open"
    title="3rd Party Return"
    :busy="busy"
    form-id="admin-return-third-party-form"
    max-width="3xl"
    @update:open="emit('update:open', $event)"
    @submit="emit('submit')"
  >
    <div class="row g-3">
      <div class="col-12">
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
      </div>
      <div class="col-12">
        <label class="form-label" for="admin-return-third-party-type">3rd Party</label>
        <select
          id="admin-return-third-party-type"
          :value="thirdPartyType"
          class="form-select"
          :disabled="busy"
          required
          @change="emit('update:thirdPartyType', $event.target.value)"
        >
          <option value="" disabled>Select channel…</option>
          <option v-for="opt in THIRD_PARTY_OPTIONS" :key="opt.value" :value="opt.value">
            {{ opt.label }}
          </option>
        </select>
      </div>
      <div class="col-12">
        <label class="form-label" for="admin-return-tp-reference">Reference #</label>
        <input
          id="admin-return-tp-reference"
          :value="referenceNumber"
          type="text"
          class="form-control"
          maxlength="255"
          placeholder="Optional reference number"
          :disabled="busy"
          @input="emit('update:referenceNumber', $event.target.value)"
        />
      </div>
      <div class="col-12">
        <label class="form-label" for="admin-return-tp-comment">Return Comment</label>
        <textarea
          id="admin-return-tp-comment"
          :value="returnComment"
          class="form-control"
          rows="3"
          maxlength="20000"
          placeholder="Optional comment visible to account users"
          :disabled="busy"
          @input="emit('update:returnComment', $event.target.value)"
        />
      </div>
      <div class="col-12">
        <p class="form-text mb-0">Add return line items on the detail page after creation.</p>
      </div>
    </div>

    <template #footer>
      <footer :class="CRM_DIALOG_FOOTER_CLASS_DRAWER">
        <button type="button" :class="CRM_BTN_SECONDARY" :disabled="busy" @click="close">
          Cancel
        </button>
        <button
          type="submit"
          form="admin-return-third-party-form"
          :class="CRM_BTN_PRIMARY"
          :disabled="busy"
        >
          {{ busy ? "Creating…" : "Create" }}
        </button>
      </footer>
    </template>
  </CrmRightDrawer>
</template>
