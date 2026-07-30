<script setup>
import { reactive, watch } from "vue";
import CrmRightDrawer from "../common/CrmRightDrawer.vue";
import CrmSearchableSelect from "../common/CrmSearchableSelect.vue";
import {
  CRM_BTN_PRIMARY,
  CRM_BTN_SECONDARY,
  CRM_DIALOG_FOOTER_CLASS_DRAWER,
} from "../../constants/dialogFooter.js";
import { LTL_DIRECTIONS } from "../../constants/ltlSections.js";

const props = defineProps({
  open: { type: Boolean, default: false },
  busy: { type: Boolean, default: false },
  /** When true, hide account picker (portal). */
  portal: { type: Boolean, default: false },
  accountOptions: { type: Array, default: () => [] },
});

const emit = defineEmits(["update:open", "submit"]);

const form = reactive({
  client_account_id: "",
  direction: "ship_to_save_rack",
  company_name: "",
  address_line1: "",
  city: "",
  state: "",
  zip: "",
});

watch(
  () => props.open,
  (v) => {
    if (!v) return;
    form.client_account_id = "";
    form.direction = "ship_to_save_rack";
    form.company_name = "";
    form.address_line1 = "";
    form.city = "";
    form.state = "";
    form.zip = "";
  },
);

function close() {
  if (props.busy) return;
  emit("update:open", false);
}

function onSubmit() {
  emit("submit", { ...form });
}
</script>

<template>
  <CrmRightDrawer
    :open="open"
    title="Create LTL"
    :busy="busy"
    form-id="ltl-create-form"
    @update:open="emit('update:open', $event)"
    @submit="onSubmit"
  >
    <div class="d-flex flex-column gap-3">
      <div v-if="!portal">
        <label class="form-label">Account <span class="text-danger">*</span></label>
        <CrmSearchableSelect
          v-model="form.client_account_id"
          appearance="staff"
          teleport-panel
          :options="accountOptions"
          placeholder="Select account…"
          :allow-empty="false"
          search-placeholder="Search accounts…"
          :disabled="busy"
        />
      </div>
      <div>
        <label class="form-label">Location <span class="text-danger">*</span></label>
        <select v-model="form.direction" class="form-select" :disabled="busy">
          <option v-for="d in LTL_DIRECTIONS" :key="d.value" :value="d.value">
            {{ d.label }}
          </option>
        </select>
      </div>
      <div>
        <label class="form-label">Company Name <span class="text-danger">*</span></label>
        <input v-model="form.company_name" type="text" class="form-control" :disabled="busy" />
      </div>
      <div>
        <label class="form-label">Address <span class="text-danger">*</span></label>
        <input v-model="form.address_line1" type="text" class="form-control" :disabled="busy" />
      </div>
      <div class="row g-2">
        <div class="col-5">
          <label class="form-label">City <span class="text-danger">*</span></label>
          <input v-model="form.city" type="text" class="form-control" :disabled="busy" />
        </div>
        <div class="col-3">
          <label class="form-label">State <span class="text-danger">*</span></label>
          <input v-model="form.state" type="text" class="form-control" :disabled="busy" />
        </div>
        <div class="col-4">
          <label class="form-label">Zip <span class="text-danger">*</span></label>
          <input v-model="form.zip" type="text" class="form-control" :disabled="busy" />
        </div>
      </div>
    </div>

    <template #footer>
      <footer :class="CRM_DIALOG_FOOTER_CLASS_DRAWER">
        <button type="button" :class="CRM_BTN_SECONDARY" :disabled="busy" @click="close">
          Close
        </button>
        <button
          type="submit"
          form="ltl-create-form"
          :class="CRM_BTN_PRIMARY"
          :disabled="busy || (!portal && !form.client_account_id)"
        >
          {{ busy ? "Creating…" : "Create" }}
        </button>
      </footer>
    </template>
  </CrmRightDrawer>
</template>
