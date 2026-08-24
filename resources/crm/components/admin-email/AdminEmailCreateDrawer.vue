<script setup>
import { computed, onUnmounted, ref, watch } from "vue";
import CrmRightDrawer from "../common/CrmRightDrawer.vue";
import CrmRichTextEditor from "../common/CrmRichTextEditor.vue";

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

watch(open, (isOpen) => {
  if (isOpen) resetForm();
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
  <CrmRightDrawer
    v-model:open="open"
    title="Create Email"
    subtitle="Send to primary users on all non-inactive accounts."
    :busy="busy"
    max-width="xl"
    form-id="admin-email-create-form"
    @submit="submit"
  >
    <div class="d-flex flex-column gap-3">
      <div>
        <label class="form-label" for="admin-email-from">Email From</label>
        <select
          id="admin-email-from"
          v-model="fromAddress"
          class="form-select"
          :disabled="busy"
          required
        >
          <option v-for="opt in fromOptions" :key="opt.address" :value="opt.address">
            {{ opt.address }}
          </option>
        </select>
      </div>

      <div>
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

      <div>
        <label class="form-label" for="admin-email-body">Body</label>
        <CrmRichTextEditor
          v-model="bodyHtml"
          :disabled="busy"
          aria-label="Email body"
        />
      </div>
    </div>

    <template #footer>
      <footer
        class="d-flex w-100 align-items-center justify-content-end gap-2 border-top border-gray-200 px-5 py-4"
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
          form="admin-email-create-form"
          class="btn btn-primary staff-page-primary"
          :disabled="!canSubmit"
        >
          {{ busy ? "Sending…" : "Continue" }}
        </button>
      </footer>
    </template>
  </CrmRightDrawer>
</template>
