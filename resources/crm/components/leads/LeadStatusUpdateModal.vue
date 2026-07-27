<script setup>
import { computed, onUnmounted, watch } from "vue";
import { LEAD_FOLLOW_UP_DAY_OPTIONS, leadStatusLabel } from "../../constants/leads.js";
import CrmLoadingSpinner from "../common/CrmLoadingSpinner.vue";

const props = defineProps({
  busy: { type: Boolean, default: false },
  statuses: { type: Array, default: () => [] },
  followUpDayOptions: {
    type: Array,
    default: () => LEAD_FOLLOW_UP_DAY_OPTIONS,
  },
});

const open = defineModel("open", { type: Boolean, default: false });
const status = defineModel("status", { type: String, default: "" });
const followUpDays = defineModel("followUpDays", { type: [Number, String], default: 1 });

const emit = defineEmits(["save"]);

const saveDisabled = computed(() => props.busy || !String(status.value || "").trim());

function close() {
  if (!props.busy) open.value = false;
}

function onEsc(e) {
  if (e.key === "Escape" && open.value && !props.busy) {
    e.preventDefault();
    close();
  }
}

watch(open, (o) => {
  if (o) {
    document.addEventListener("keydown", onEsc);
  } else {
    document.removeEventListener("keydown", onEsc);
  }
});

onUnmounted(() => {
  document.removeEventListener("keydown", onEsc);
});
</script>

<template>
  <Teleport to="body">
    <Transition name="modal-backdrop">
      <div
        v-if="open"
        class="crm-vx-modal-overlay"
        aria-modal="true"
        role="dialog"
        aria-labelledby="lead-status-update-modal-title"
      >
        <div class="crm-vx-modal-backdrop" aria-hidden="true" @click="close" />
        <Transition name="modal-panel" appear>
          <div class="crm-vx-modal crm-vx-modal--sm" @click.stop>
            <button
              type="button"
              class="crm-vx-modal__close"
              aria-label="Close"
              :disabled="busy"
              @click="close"
            >
              <svg
                width="20"
                height="20"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
                stroke-width="1.75"
                aria-hidden="true"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  d="M6 18L18 6M6 6l12 12"
                />
              </svg>
            </button>

            <header class="crm-vx-modal__head">
              <h2 id="lead-status-update-modal-title" class="crm-vx-modal__title">
                Lead Status
              </h2>
              <p class="crm-vx-modal__subtitle">Update status and follow-up days.</p>
            </header>

            <div class="crm-vx-modal__body">
              <form
                id="lead-status-update-modal-form"
                class="d-flex flex-column gap-3"
                @submit.prevent="emit('save')"
              >
                <div>
                  <label class="form-label small mb-1 text-secondary" for="lead-status-value">
                    Status
                  </label>
                  <select
                    id="lead-status-value"
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
                  <label class="form-label small mb-1 text-secondary" for="lead-follow-up-days">
                    Follow Up
                  </label>
                  <select
                    id="lead-follow-up-days"
                    v-model.number="followUpDays"
                    class="form-select"
                    :disabled="busy"
                    required
                  >
                    <option
                      v-for="days in followUpDayOptions"
                      :key="days"
                      :value="days"
                    >
                      {{ days === 1 ? "1 day" : `${days} days` }}
                    </option>
                  </select>
                </div>
              </form>
            </div>

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
                type="submit"
                form="lead-status-update-modal-form"
                class="crm-vx-modal-btn crm-vx-modal-btn--primary"
                :disabled="saveDisabled"
              >
                <CrmLoadingSpinner v-if="busy" small class="me-1" />
                Save
              </button>
            </footer>
          </div>
        </Transition>
      </div>
    </Transition>
  </Teleport>
</template>
