<script setup>
import { computed, onUnmounted, watch } from "vue";
import CrmLoadingSpinner from "../common/CrmLoadingSpinner.vue";
import { LEAD_REFERRALS, leadReferralLabel } from "../../constants/leads.js";

const props = defineProps({
  busy: { type: Boolean, default: false },
  lead: { type: Object, default: null },
});

const open = defineModel("open", { type: Boolean, default: false });
const form = defineModel("form", {
  type: Object,
  default: () => ({
    created_at: "",
    email: "",
    website: "",
    name: "",
    company_name: "",
    referral: "bizy",
  }),
});

const emit = defineEmits(["save"]);

const saveDisabled = computed(() => {
  if (props.busy) return true;
  const email = String(form.value?.email || "").trim();
  return !email;
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

function toDateInputValue(iso) {
  if (!iso) return "";
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) {
    const m = String(iso).match(/^(\d{4}-\d{2}-\d{2})/);
    return m ? m[1] : "";
  }
  const y = d.getFullYear();
  const mo = String(d.getMonth() + 1).padStart(2, "0");
  const day = String(d.getDate()).padStart(2, "0");
  return `${y}-${mo}-${day}`;
}

watch(open, (o) => {
  if (!o || !props.lead) return;
  form.value = {
    created_at: toDateInputValue(props.lead.created_at),
    email: props.lead.email || "",
    website: props.lead.website || "",
    name: props.lead.name || "",
    company_name: props.lead.company_name || "",
    referral: String(props.lead.referral || "bizy").toLowerCase() === "google" ? "google" : "bizy",
  };
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
        aria-labelledby="lead-edit-modal-title"
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
              <h2 id="lead-edit-modal-title" class="crm-vx-modal__title">Edit Lead</h2>
              <p class="crm-vx-modal__subtitle">Update lead details.</p>
            </header>

            <div class="crm-vx-modal__body">
              <form
                id="lead-edit-modal-form"
                class="d-flex flex-column gap-3"
                @submit.prevent="emit('save')"
              >
                <div>
                  <label class="form-label small mb-1 text-secondary" for="lead-edit-referral">
                    Referral
                  </label>
                  <select
                    id="lead-edit-referral"
                    v-model="form.referral"
                    class="form-select"
                    :disabled="busy"
                  >
                    <option v-for="key in LEAD_REFERRALS" :key="key" :value="key">
                      {{ leadReferralLabel(key) }}
                    </option>
                  </select>
                </div>
                <div>
                  <label class="form-label small mb-1 text-secondary" for="lead-edit-company">
                    Company
                  </label>
                  <input
                    id="lead-edit-company"
                    v-model="form.company_name"
                    type="text"
                    class="form-control"
                    :disabled="busy"
                    required
                  />
                </div>
                <div>
                  <label class="form-label small mb-1 text-secondary" for="lead-edit-created">
                    Created Date
                  </label>
                  <input
                    id="lead-edit-created"
                    v-model="form.created_at"
                    type="date"
                    class="form-control"
                    :disabled="busy"
                  />
                </div>
                <div>
                  <label class="form-label small mb-1 text-secondary" for="lead-edit-email">
                    Email
                  </label>
                  <input
                    id="lead-edit-email"
                    v-model="form.email"
                    type="email"
                    class="form-control"
                    :disabled="busy"
                    required
                  />
                </div>
                <div>
                  <label class="form-label small mb-1 text-secondary" for="lead-edit-website">
                    Website
                  </label>
                  <input
                    id="lead-edit-website"
                    v-model="form.website"
                    type="text"
                    class="form-control"
                    :disabled="busy"
                  />
                </div>
                <div>
                  <label class="form-label small mb-1 text-secondary" for="lead-edit-name">
                    Name
                  </label>
                  <input
                    id="lead-edit-name"
                    v-model="form.name"
                    type="text"
                    class="form-control"
                    :disabled="busy"
                  />
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
                form="lead-edit-modal-form"
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
