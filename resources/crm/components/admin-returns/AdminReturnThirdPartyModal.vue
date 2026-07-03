<script setup>
import CrmSearchableSelect from "../common/CrmSearchableSelect.vue";

const THIRD_PARTY_OPTIONS = [
  { value: "amazon", label: "Amazon" },
  { value: "other", label: "Other" },
];

defineProps({
  open: { type: Boolean, default: false },
  accountId: { type: [String, Number], default: "" },
  thirdPartyType: { type: String, default: "" },
  accountOptions: { type: Array, default: () => [] },
  busy: { type: Boolean, default: false },
});

const emit = defineEmits([
  "update:open",
  "update:accountId",
  "update:thirdPartyType",
  "submit",
]);

function close() {
  emit("update:open", false);
}
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="crm-vx-modal-overlay" @click.self="close">
      <div class="crm-vx-modal crm-vx-modal--sm" role="dialog" aria-modal="true" @click.stop>
        <header class="crm-vx-modal__head">
          <h2 class="crm-vx-modal__title">3rd Party Return</h2>
        </header>
        <form class="crm-vx-modal__body" @submit.prevent="emit('submit')">
          <div class="mb-3">
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
          <div class="mb-3">
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
          <p class="form-text mb-0">Add return line items on the detail page after creation.</p>
        </form>
        <footer class="crm-vx-modal__footer">
          <button
            type="button"
            class="crm-vx-modal-btn crm-vx-modal-btn--secondary"
            :disabled="busy"
            @click="close"
          >
            Cancel
          </button>
          <button
            type="button"
            class="crm-vx-modal-btn crm-vx-modal-btn--primary"
            :disabled="busy"
            @click="emit('submit')"
          >
            {{ busy ? "Creating…" : "Create" }}
          </button>
        </footer>
      </div>
    </div>
  </Teleport>
</template>
