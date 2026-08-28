<script setup>
import { computed, ref, watch } from "vue";
import CrmRightDrawer from "../common/CrmRightDrawer.vue";
import {
  LEAD_FOLLOW_UP_DAY_OPTIONS,
  LEAD_FOLLOW_UP_OFF,
  followUpPayloadValue,
  followUpSelectValue,
  leadStatusLabel,
} from "../../constants/leads.js";

const props = defineProps({
  busy: { type: Boolean, default: false },
  selectedCount: { type: Number, default: 0 },
  statuses: { type: Array, default: () => [] },
  followUpDayOptions: {
    type: Array,
    default: () => LEAD_FOLLOW_UP_DAY_OPTIONS,
  },
});

const open = defineModel("open", { type: Boolean, default: false });
const emit = defineEmits(["submit"]);

const status = ref("open");
const followUpDays = ref(1);

const followUpModel = computed({
  get() {
    return followUpSelectValue(followUpDays.value);
  },
  set(v) {
    followUpDays.value = followUpPayloadValue(v);
  },
});

const canSubmit = computed(() => {
  if (props.busy) return false;
  if (!props.selectedCount) return false;
  return !!String(status.value || "").trim();
});

watch(open, (isOpen) => {
  if (isOpen) {
    status.value = "open";
    followUpDays.value = 1;
  }
});

function submit() {
  if (!canSubmit.value) return;
  emit("submit", {
    status: status.value,
    follow_up_days: followUpPayloadValue(followUpDays.value),
  });
}
</script>

<template>
  <CrmRightDrawer
    v-model:open="open"
    title="Bulk Update Status"
    :subtitle="`Update status for ${selectedCount} selected lead${
      selectedCount === 1 ? '' : 's'
    } without sending email.`"
    :busy="busy"
    max-width="md"
    form-id="lead-bulk-status-form"
    @submit="submit"
  >
    <div class="d-flex flex-column gap-3">
      <div>
        <label class="form-label" for="lead-bulk-status">Status</label>
        <select
          id="lead-bulk-status"
          v-model="status"
          class="form-select"
          :disabled="busy"
          required
        >
          <option v-for="st in statuses" :key="st" :value="st">
            {{ leadStatusLabel(st) }}
          </option>
        </select>
      </div>

      <div>
        <label class="form-label" for="lead-bulk-follow-up">Follow Up</label>
        <select
          id="lead-bulk-follow-up"
          v-model="followUpModel"
          class="form-select"
          :disabled="busy"
        >
          <option :value="LEAD_FOLLOW_UP_OFF">Off</option>
          <option v-for="days in followUpDayOptions" :key="days" :value="days">
            {{ days }} day{{ days === 1 ? "" : "s" }}
          </option>
        </select>
        <p class="form-text mb-0">
          Applies the same follow-up schedule to every selected lead.
        </p>
      </div>
    </div>

    <template #footer>
      <footer
        class="d-flex w-100 align-items-center justify-content-end gap-2 border-top border-gray-200 px-4 py-4"
      >
        <button
          type="button"
          class="btn btn-outline-secondary"
          :disabled="busy"
          @click="open = false"
        >
          Cancel
        </button>
        <button
          type="submit"
          form="lead-bulk-status-form"
          class="btn btn-primary staff-page-primary"
          :disabled="!canSubmit"
        >
          {{ busy ? "Updating…" : "Update Status" }}
        </button>
      </footer>
    </template>
  </CrmRightDrawer>
</template>
