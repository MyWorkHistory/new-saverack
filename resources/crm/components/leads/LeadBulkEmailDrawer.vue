<script setup>
import { computed, ref, watch } from "vue";
import CrmRightDrawer from "../common/CrmRightDrawer.vue";
import {
  EMAIL_TEMPLATE_CATEGORIES,
  emailTemplateCategoryLabel,
} from "../../constants/emailTemplates.js";

const props = defineProps({
  busy: { type: Boolean, default: false },
  selectedCount: { type: Number, default: 0 },
  templates: { type: Array, default: () => [] },
});

const open = defineModel("open", { type: Boolean, default: false });
const emit = defineEmits(["submit"]);

const category = ref("");
const templateId = ref("");

const templatesForCategory = computed(() => {
  const cat = String(category.value || "").toLowerCase();
  if (!cat) return [];
  return (props.templates || []).filter(
    (t) => String(t.category || "").toLowerCase() === cat,
  );
});

const canSubmit = computed(() => {
  if (props.busy) return false;
  if (!props.selectedCount) return false;
  if (!category.value) return false;
  return !!Number(templateId.value);
});

watch(open, (isOpen) => {
  if (isOpen) {
    category.value = "";
    templateId.value = "";
  }
});

watch(category, () => {
  templateId.value = "";
});

function submit() {
  if (!canSubmit.value) return;
  emit("submit", {
    email_template_id: Number(templateId.value),
    category: category.value,
  });
}
</script>

<template>
  <CrmRightDrawer
    v-model:open="open"
    title="Bulk Send"
    :subtitle="`Send a template email to ${selectedCount} selected lead${
      selectedCount === 1 ? '' : 's'
    }.`"
    :busy="busy"
    max-width="md"
    form-id="lead-bulk-email-form"
    @submit="submit"
  >
    <div class="d-flex flex-column gap-3">
      <div>
        <label class="form-label" for="lead-bulk-email-category">Template Category</label>
        <select
          id="lead-bulk-email-category"
          v-model="category"
          class="form-select"
          :disabled="busy"
          required
        >
          <option value="" disabled>Select category…</option>
          <option v-for="cat in EMAIL_TEMPLATE_CATEGORIES" :key="cat" :value="cat">
            {{ emailTemplateCategoryLabel(cat) }}
          </option>
        </select>
      </div>

      <div>
        <label class="form-label" for="lead-bulk-email-template">Template</label>
        <select
          id="lead-bulk-email-template"
          v-model="templateId"
          class="form-select"
          :disabled="busy || !category"
          required
        >
          <option value="" disabled>
            {{ category ? "Select template…" : "Select a category first…" }}
          </option>
          <option
            v-for="tpl in templatesForCategory"
            :key="tpl.id"
            :value="String(tpl.id)"
          >
            {{ tpl.name }}
          </option>
        </select>
        <p v-if="category && !templatesForCategory.length" class="form-text text-danger mb-0">
          No templates in this category.
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
          form="lead-bulk-email-form"
          class="btn btn-primary staff-page-primary"
          :disabled="!canSubmit"
        >
          {{ busy ? "Sending…" : "Send" }}
        </button>
      </footer>
    </template>
  </CrmRightDrawer>
</template>
