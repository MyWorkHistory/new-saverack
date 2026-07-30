<script setup>
import { computed, onUnmounted, ref, watch } from "vue";
import {
  LEAD_FOLLOW_UP_DAY_OPTIONS,
  LEAD_FOLLOW_UP_OFF,
  followUpPayloadValue,
  followUpSelectValue,
  formatFollowUpDays,
  leadStatusLabel,
} from "../../constants/leads.js";
import { EMAIL_TEMPLATE_CATEGORIES } from "../../constants/emailTemplates.js";
import CrmLoadingSpinner from "../common/CrmLoadingSpinner.vue";
import { useToast } from "../../composables/useToast.js";

const props = defineProps({
  busy: { type: Boolean, default: false },
  statuses: { type: Array, default: () => [] },
  followUpDayOptions: {
    type: Array,
    default: () => LEAD_FOLLOW_UP_DAY_OPTIONS,
  },
  /** Templates for the selected status category (from settings). */
  templates: { type: Array, default: () => [] },
  /** Statuses that already have a template usage on this lead. */
  templatesUsedStatuses: { type: Array, default: () => [] },
});

const open = defineModel("open", { type: Boolean, default: false });
const status = defineModel("status", { type: String, default: "" });
const followUpDays = defineModel("followUpDays", {
  type: [Number, String, null],
  default: 1,
});
const emailTemplateId = defineModel("emailTemplateId", {
  type: [Number, String, null],
  default: "custom",
});

const emit = defineEmits(["save"]);
const toast = useToast();
const copyBusy = ref(false);

const followUpModel = computed({
  get() {
    return followUpSelectValue(followUpDays.value);
  },
  set(v) {
    followUpDays.value = followUpPayloadValue(v);
  },
});

const showTemplateField = computed(() => {
  const st = String(status.value || "").toLowerCase();
  if (!EMAIL_TEMPLATE_CATEGORIES.includes(st)) return false;
  const used = (props.templatesUsedStatuses || []).map((s) => String(s).toLowerCase());
  return !used.includes(st);
});

const templatesForStatus = computed(() => {
  const st = String(status.value || "").toLowerCase();
  return (props.templates || []).filter(
    (t) => String(t.category || "").toLowerCase() === st,
  );
});

const selectedTemplate = computed(() => {
  const id = emailTemplateId.value;
  if (id === "custom" || id === null || id === "") return null;
  return templatesForStatus.value.find((t) => Number(t.id) === Number(id)) || null;
});

const canCopy = computed(() => !!selectedTemplate.value?.body);

const saveDisabled = computed(() => props.busy || !String(status.value || "").trim());

watch(status, () => {
  emailTemplateId.value = "custom";
});

watch(open, (o) => {
  if (o) {
    document.addEventListener("keydown", onEsc);
    emailTemplateId.value = "custom";
  } else {
    document.removeEventListener("keydown", onEsc);
  }
});

onUnmounted(() => {
  document.removeEventListener("keydown", onEsc);
});

function close() {
  if (!props.busy) open.value = false;
}

function onEsc(e) {
  if (e.key === "Escape" && open.value && !props.busy) {
    e.preventDefault();
    close();
  }
}

async function copyTemplateBody() {
  const body = selectedTemplate.value?.body;
  if (!body) return;
  copyBusy.value = true;
  try {
    await navigator.clipboard.writeText(String(body));
    toast.success("Template copied.");
  } catch {
    toast.error("Could not copy template.");
  } finally {
    copyBusy.value = false;
  }
}
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
                    v-model="followUpModel"
                    class="form-select"
                    :disabled="busy"
                  >
                    <option :value="LEAD_FOLLOW_UP_OFF">Off</option>
                    <option
                      v-for="days in followUpDayOptions"
                      :key="days"
                      :value="days"
                    >
                      {{ formatFollowUpDays(days) }}
                    </option>
                  </select>
                </div>
                <div v-if="showTemplateField">
                  <label class="form-label small mb-1 text-secondary" for="lead-status-template">
                    Template
                  </label>
                  <div class="d-flex align-items-stretch gap-2">
                    <select
                      id="lead-status-template"
                      v-model="emailTemplateId"
                      class="form-select"
                      :disabled="busy"
                    >
                      <option value="custom">Custom</option>
                      <option
                        v-for="t in templatesForStatus"
                        :key="t.id"
                        :value="t.id"
                      >
                        {{ t.name }}
                      </option>
                    </select>
                    <button
                      type="button"
                      class="btn btn-outline-secondary flex-shrink-0 px-2"
                      title="Copy template"
                      :disabled="busy || !canCopy || copyBusy"
                      @click="copyTemplateBody"
                    >
                      <svg
                        width="18"
                        height="18"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                        stroke-width="1.75"
                        aria-hidden="true"
                      >
                        <path
                          stroke-linecap="round"
                          stroke-linejoin="round"
                          d="M15.666 3.888A2.25 2.25 0 0013.5 2.25h-3c-1.03 0-1.9.693-2.166 1.638m7.332 0c.055.194.084.4.084.612v0a.75.75 0 01-.75.75H9.75a.75.75 0 01-.75-.75v0c0-.212.03-.418.084-.612m7.332 0c.646.049 1.288.11 1.927.184 1.1.128 1.907 1.077 1.907 2.185V19.5a2.25 2.25 0 01-2.25 2.25H6.75A2.25 2.25 0 014.5 19.5V6.257c0-1.108.806-2.057 1.907-2.185a48.208 48.208 0 011.927-.184"
                        />
                      </svg>
                      <span class="visually-hidden">Copy Template</span>
                    </button>
                  </div>
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
