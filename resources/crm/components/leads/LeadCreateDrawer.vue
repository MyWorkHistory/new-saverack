<script setup>
import { computed, ref, watch } from "vue";
import CrmLoadingSpinner from "../common/CrmLoadingSpinner.vue";
import CrmRightDrawer from "../common/CrmRightDrawer.vue";
import {
  CRM_BTN_PRIMARY,
  CRM_BTN_SECONDARY,
  CRM_DIALOG_FOOTER_CLASS_DRAWER,
} from "../../constants/dialogFooter.js";

const props = defineProps({
  open: { type: Boolean, default: false },
  busy: { type: Boolean, default: false },
});

const emit = defineEmits(["update:open", "submit"]);

defineExpose({
  setError(message) {
    errorMsg.value = String(message || "").trim();
  },
  clearError() {
    errorMsg.value = "";
  },
});

const companyName = ref("");
const email = ref("");
const website = ref("");
const name = ref("");
const comment = ref("");
const errorMsg = ref("");

const canSubmit = computed(
  () =>
    String(companyName.value || "").trim() !== "" &&
    String(email.value || "").trim() !== "" &&
    !props.busy,
);

watch(
  () => props.open,
  (open) => {
    if (open) {
      companyName.value = "";
      email.value = "";
      website.value = "";
      name.value = "";
      comment.value = "";
      errorMsg.value = "";
    }
  },
);

function close() {
  if (!props.busy) emit("update:open", false);
}

function submit() {
  if (!canSubmit.value) {
    errorMsg.value = "Company Name and Email are required.";
    return;
  }
  errorMsg.value = "";
  emit("submit", {
    company_name: String(companyName.value).trim(),
    email: String(email.value).trim(),
    website: String(website.value || "").trim() || null,
    name: String(name.value || "").trim() || null,
    comment: String(comment.value || "").trim() || null,
  });
}
</script>

<template>
  <CrmRightDrawer
    :open="open"
    title="Add Lead"
    :busy="busy"
    max-width="xl"
    form-id="lead-create-form"
    @update:open="close"
    @submit="submit"
  >
    <div class="d-flex flex-column gap-3">
      <p v-if="errorMsg" class="alert alert-danger py-2 mb-0 small">{{ errorMsg }}</p>
      <div>
        <label class="form-label" for="lead-create-company">Company Name *</label>
        <input
          id="lead-create-company"
          v-model="companyName"
          type="text"
          class="form-control"
          :disabled="busy"
          required
          autocomplete="organization"
        />
      </div>
      <div>
        <label class="form-label" for="lead-create-email">Email *</label>
        <input
          id="lead-create-email"
          v-model="email"
          type="email"
          class="form-control"
          :disabled="busy"
          required
          autocomplete="email"
        />
      </div>
      <div>
        <label class="form-label" for="lead-create-website">Website</label>
        <input
          id="lead-create-website"
          v-model="website"
          type="text"
          class="form-control"
          :disabled="busy"
          placeholder="example.com"
        />
      </div>
      <div>
        <label class="form-label" for="lead-create-name">Name</label>
        <input
          id="lead-create-name"
          v-model="name"
          type="text"
          class="form-control"
          :disabled="busy"
          autocomplete="name"
        />
      </div>
      <div>
        <label class="form-label" for="lead-create-comment">Comment</label>
        <textarea
          id="lead-create-comment"
          v-model="comment"
          class="form-control"
          rows="4"
          :disabled="busy"
        />
      </div>
    </div>

    <template #footer>
      <div :class="CRM_DIALOG_FOOTER_CLASS_DRAWER">
        <button type="button" :class="CRM_BTN_SECONDARY" :disabled="busy" @click="close">
          Cancel
        </button>
        <button type="submit" form="lead-create-form" :class="CRM_BTN_PRIMARY" :disabled="!canSubmit">
          <CrmLoadingSpinner v-if="busy" small class="me-1" />
          Add Lead
        </button>
      </div>
    </template>
  </CrmRightDrawer>
</template>
