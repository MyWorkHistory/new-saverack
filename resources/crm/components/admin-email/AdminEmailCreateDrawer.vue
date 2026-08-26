<script setup>
import { computed, ref, watch } from "vue";
import CrmRightDrawer from "../common/CrmRightDrawer.vue";
import CrmRichTextEditor from "../common/CrmRichTextEditor.vue";

const props = defineProps({
  busy: { type: Boolean, default: false },
  testBusy: { type: Boolean, default: false },
  fromOptions: {
    type: Array,
    default: () => [
      { address: "info@saverack.com", name: "Save Rack" },
      { address: "audi@saverack.com", name: "Audi K | Save Rack" },
    ],
  },
});

const open = defineModel("open", { type: Boolean, default: false });
const emit = defineEmits(["submit", "test"]);

const fromAddress = ref("info@saverack.com");
const subject = ref("");
const bodyHtml = ref("");
const testEmail = ref("");

const formBusy = computed(() => props.busy || props.testBusy);

const hasContent = computed(() => {
  if (!String(fromAddress.value || "").trim()) return false;
  if (!String(subject.value || "").trim()) return false;
  const plain = String(bodyHtml.value || "")
    .replace(/<[^>]+>/g, " ")
    .replace(/&nbsp;/gi, " ")
    .trim();
  return plain !== "";
});

const canSubmit = computed(() => hasContent.value && !formBusy.value);

const testEmailValid = computed(() => {
  const email = String(testEmail.value || "").trim();
  if (!email) return false;
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
});

const canSendTest = computed(() => hasContent.value && testEmailValid.value && !formBusy.value);

function resetForm() {
  const first = props.fromOptions?.[0]?.address;
  fromAddress.value = first || "info@saverack.com";
  subject.value = "";
  bodyHtml.value = "";
  testEmail.value = "";
}

watch(open, (isOpen) => {
  if (isOpen) resetForm();
});

function payload() {
  return {
    from_address: String(fromAddress.value || "").trim().toLowerCase(),
    subject: String(subject.value || "").trim(),
    body_html: String(bodyHtml.value || "").trim(),
  };
}

function submit() {
  if (!canSubmit.value) return;
  emit("submit", payload());
}

function sendTest() {
  if (!canSendTest.value) return;
  emit("test", {
    ...payload(),
    test_email: String(testEmail.value || "").trim().toLowerCase(),
  });
}
</script>

<template>
  <CrmRightDrawer
    v-model:open="open"
    title="Create Email"
    subtitle="Send to primary users on all non-inactive accounts."
    :busy="formBusy"
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
          :disabled="formBusy"
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
          :disabled="formBusy"
          required
        />
      </div>

      <div>
        <label class="form-label" for="admin-email-body">Body</label>
        <CrmRichTextEditor
          v-model="bodyHtml"
          :disabled="formBusy"
          aria-label="Email body"
        />
      </div>
    </div>

    <template #footer>
      <footer
        class="d-flex w-100 flex-wrap align-items-center justify-content-between gap-3 border-top border-gray-200 px-4 px-sm-5 py-4"
      >
        <div class="d-flex flex-wrap align-items-center gap-2 admin-email-create-drawer__test">
          <label class="visually-hidden" for="admin-email-test">Test Email</label>
          <input
            id="admin-email-test"
            v-model="testEmail"
            type="email"
            class="form-control form-control-sm admin-email-create-drawer__test-input"
            placeholder="Test Email"
            maxlength="255"
            autocomplete="email"
            :disabled="formBusy"
            @keydown.enter.prevent="sendTest"
          />
          <button
            type="button"
            class="btn btn-outline-secondary btn-sm text-nowrap"
            :disabled="!canSendTest"
            @click="sendTest"
          >
            {{ testBusy ? "Sending…" : "Send Test" }}
          </button>
        </div>

        <div class="d-flex flex-wrap align-items-center gap-2 ms-auto">
          <button
            type="button"
            class="btn btn-outline-secondary"
            :disabled="formBusy"
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
        </div>
      </footer>
    </template>
  </CrmRightDrawer>
</template>

<style scoped>
.admin-email-create-drawer__test {
  min-width: 0;
  flex: 1 1 14rem;
}

.admin-email-create-drawer__test-input {
  width: 12.5rem;
  max-width: 100%;
}
</style>
