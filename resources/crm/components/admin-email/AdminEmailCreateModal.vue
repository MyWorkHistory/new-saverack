<script setup>
import { computed, onUnmounted, ref, watch } from "vue";
import CrmRichTextEditor from "../common/CrmRichTextEditor.vue";
import CrmLoadingSpinner from "../common/CrmLoadingSpinner.vue";

const props = defineProps({
  busy: { type: Boolean, default: false },
  fromOptions: {
    type: Array,
    default: () => [
      { address: "info@saverack.com", name: "Save Rack" },
      { address: "audi@saverack.com", name: "Audi K | Save Rack" },
    ],
  },
});

const open = defineModel("open", { type: Boolean, default: false });
const emit = defineEmits(["submit"]);

const fromAddress = ref("info@saverack.com");
const subject = ref("");
const bodyHtml = ref("");

const canSubmit = computed(() => {
  if (props.busy) return false;
  if (!String(fromAddress.value || "").trim()) return false;
  if (!String(subject.value || "").trim()) return false;
  const plain = String(bodyHtml.value || "")
    .replace(/<[^>]+>/g, " ")
    .replace(/&nbsp;/gi, " ")
    .trim();
  return plain !== "";
});

function resetForm() {
  const first = props.fromOptions?.[0]?.address;
  fromAddress.value = first || "info@saverack.com";
  subject.value = "";
  bodyHtml.value = "";
}

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
    resetForm();
    document.addEventListener("keydown", onEsc);
  } else {
    document.removeEventListener("keydown", onEsc);
  }
});

onUnmounted(() => {
  document.removeEventListener("keydown", onEsc);
});

function submit() {
  if (!canSubmit.value) return;
  emit("submit", {
    from_address: String(fromAddress.value || "").trim().toLowerCase(),
    subject: String(subject.value || "").trim(),
    body_html: String(bodyHtml.value || "").trim(),
  });
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
        aria-labelledby="admin-email-create-title"
      >
        <div class="crm-vx-modal-backdrop" aria-hidden="true" @click="close" />
        <Transition name="modal-panel" appear>
          <div class="crm-vx-modal crm-vx-modal--sm admin-email-create-modal" @click.stop>
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
              <h2 id="admin-email-create-title" class="crm-vx-modal__title">
                Create Email
              </h2>
              <p class="crm-vx-modal__subtitle">
                Send to primary users on all non-inactive accounts.
              </p>
            </header>

            <form class="crm-vx-modal__form" @submit.prevent="submit">
              <div class="crm-vx-modal__body pt-0">
                <div class="mb-3">
                  <label class="form-label" for="admin-email-from">Email From</label>
                  <select
                    id="admin-email-from"
                    v-model="fromAddress"
                    class="form-select"
                    :disabled="busy"
                    required
                  >
                    <option
                      v-for="opt in fromOptions"
                      :key="opt.address"
                      :value="opt.address"
                    >
                      {{ opt.address }}
                    </option>
                  </select>
                </div>

                <div class="mb-3">
                  <label class="form-label" for="admin-email-subject">Subject</label>
                  <input
                    id="admin-email-subject"
                    v-model="subject"
                    type="text"
                    class="form-control"
                    maxlength="500"
                    autocomplete="off"
                    :disabled="busy"
                    required
                  />
                </div>

                <div class="mb-0">
                  <label class="form-label" for="admin-email-body">Body</label>
                  <CrmRichTextEditor
                    v-model="bodyHtml"
                    :disabled="busy"
                    aria-label="Email body"
                  />
                </div>
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
                  class="crm-vx-modal-btn crm-vx-modal-btn--primary"
                  :disabled="!canSubmit"
                >
                  <span v-if="busy" class="d-inline-flex align-items-center gap-2">
                    <CrmLoadingSpinner inline />
                    Sending…
                  </span>
                  <span v-else>Send</span>
                </button>
              </footer>
            </form>
          </div>
        </Transition>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.admin-email-create-modal {
  max-width: 640px;
}
</style>
