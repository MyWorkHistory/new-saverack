<script setup>
import { computed, reactive, ref, watch } from "vue";
import ConfirmModal from "../common/ConfirmModal.vue";
import CrmRightDrawer from "../common/CrmRightDrawer.vue";
import {
  CRM_BTN_PRIMARY,
  CRM_BTN_SECONDARY,
  CRM_DIALOG_FOOTER_CLASS_DRAWER,
} from "../../constants/dialogFooter.js";

const props = defineProps({
  open: { type: Boolean, default: false },
  mode: { type: String, default: "create" },
  event: { type: Object, default: null },
  categories: { type: Array, default: () => [] },
  initialStartDate: { type: String, default: "" },
  initialEndDate: { type: String, default: "" },
  canSave: { type: Boolean, default: false },
  canDelete: { type: Boolean, default: false },
  busy: { type: Boolean, default: false },
  deleting: { type: Boolean, default: false },
});

const emit = defineEmits(["update:open", "save", "delete"]);

const deleteConfirmOpen = ref(false);
const errorMsg = ref("");

const form = reactive({
  title: "",
  category: "",
  start_date: "",
  end_date: "",
  description: "",
  is_personal: false,
  repeat: "none",
});

const drawerTitle = computed(() => (props.mode === "edit" ? "Edit Event" : "Add Event"));

const displayCategories = computed(() => props.categories);

const showRepeatField = computed(() => props.mode !== "edit");

const repeatOptions = [
  { value: "none", label: "Do Not Repeat" },
  { value: "monthly", label: "Monthly" },
  { value: "yearly", label: "Yearly" },
];

function resetForm() {
  form.title = "";
  form.category = displayCategories.value[0]?.value || "";
  form.start_date = props.initialStartDate || "";
  form.end_date = props.initialEndDate || props.initialStartDate || "";
  form.description = "";
  form.is_personal = false;
  form.repeat = "none";
  errorMsg.value = "";
}

function populateFromEvent(event) {
  if (!event) {
    resetForm();
    return;
  }
  form.title = event.title || "";
  form.category = event.category || displayCategories.value[0]?.value || "";
  form.start_date = event.start_date || "";
  form.end_date = event.end_date || event.start_date || "";
  form.description = event.description || "";
  form.is_personal = Boolean(event.is_personal);
  form.repeat = event.repeat || "none";
  errorMsg.value = "";
}

watch(
  () => props.open,
  (isOpen) => {
    if (!isOpen) return;
    if (props.mode === "edit" && props.event) {
      populateFromEvent(props.event);
    } else {
      resetForm();
    }
  },
);

watch(
  () => props.event,
  (ev) => {
    if (props.open && props.mode === "edit" && ev) {
      populateFromEvent(ev);
    }
  },
);

function close() {
  if (props.busy || props.deleting) return;
  emit("update:open", false);
}

function onOpenChange(nextOpen) {
  if (!nextOpen) close();
}

function onSubmit() {
  errorMsg.value = "";
  const title = form.title.trim();
  if (!title) {
    errorMsg.value = "Title is required.";
    return;
  }
  if (!form.category) {
    errorMsg.value = "Category is required.";
    return;
  }
  if (!form.start_date || !form.end_date) {
    errorMsg.value = "Start and end dates are required.";
    return;
  }
  if (form.end_date < form.start_date) {
    errorMsg.value = "End date must be on or after start date.";
    return;
  }

  emit("save", {
    title,
    category: form.category,
    start_date: form.start_date,
    end_date: form.end_date,
    description: form.description.trim() || null,
    is_personal: Boolean(form.is_personal),
    ...(props.mode === "create" ? { repeat: form.repeat || "none" } : {}),
  });
}

function requestDelete() {
  deleteConfirmOpen.value = true;
}

function confirmDelete() {
  deleteConfirmOpen.value = false;
  emit("delete");
}
</script>

<template>
  <CrmRightDrawer
    :open="open"
    :title="drawerTitle"
    :busy="busy || deleting"
    form-id="calendar-event-drawer-form"
    max-width="xl"
    @update:open="onOpenChange"
    @close="close"
    @submit="onSubmit"
  >
    <p v-if="errorMsg" class="mb-3 text-sm text-danger">{{ errorMsg }}</p>

    <div class="space-y-4">
      <div>
        <label class="form-label small text-muted mb-1">Title<span class="text-danger">*</span></label>
        <input
          v-model="form.title"
          type="text"
          required
          maxlength="255"
          class="form-control"
        />
      </div>

      <div>
        <label class="form-label small text-muted mb-1">Category<span class="text-danger">*</span></label>
        <select v-model="form.category" required class="form-select">
          <option value="" disabled>Select category</option>
          <option v-for="c in displayCategories" :key="c.value" :value="c.value">
            {{ c.label }}
          </option>
        </select>
      </div>

      <div class="row g-3">
        <div class="col-sm-6">
          <label class="form-label small text-muted mb-1">Start Date<span class="text-danger">*</span></label>
          <input v-model="form.start_date" type="date" required class="form-control" />
        </div>
        <div class="col-sm-6">
          <label class="form-label small text-muted mb-1">End Date<span class="text-danger">*</span></label>
          <input v-model="form.end_date" type="date" required class="form-control" />
        </div>
      </div>

      <div v-if="showRepeatField">
        <label class="form-label small text-muted mb-1" for="calendar-event-repeat">Repeat</label>
        <select id="calendar-event-repeat" v-model="form.repeat" class="form-select">
          <option v-for="opt in repeatOptions" :key="opt.value" :value="opt.value">
            {{ opt.label }}
          </option>
        </select>
      </div>

      <div class="d-flex align-items-center justify-content-between gap-3 py-1">
        <div>
          <div class="fw-medium">Personal</div>
          <div class="small text-muted">Only you can see this event when enabled.</div>
        </div>
        <div class="form-check form-switch mb-0">
          <input
            id="calendar-event-personal"
            v-model="form.is_personal"
            class="form-check-input"
            type="checkbox"
            role="switch"
          />
        </div>
      </div>

      <div>
        <label class="form-label small text-muted mb-1">Description</label>
        <textarea
          v-model="form.description"
          rows="5"
          class="form-control"
          placeholder="Optional details"
        />
      </div>
    </div>

    <template #footer>
      <div :class="CRM_DIALOG_FOOTER_CLASS_DRAWER" class="w-100">
        <button
          v-if="mode === 'edit' && canDelete"
          type="button"
          class="btn btn-outline-danger fw-semibold px-4 rounded-3 me-auto"
          :disabled="busy || deleting"
          @click="requestDelete"
        >
          {{ deleting ? "Deleting…" : "Delete Event" }}
        </button>
        <button type="button" :class="CRM_BTN_SECONDARY" :disabled="busy || deleting" @click="close">
          Cancel
        </button>
        <button
          v-if="canSave"
          type="submit"
          form="calendar-event-drawer-form"
          :class="CRM_BTN_PRIMARY"
          :disabled="busy || deleting"
        >
          {{ busy ? "Saving…" : "Save Event" }}
        </button>
      </div>
    </template>
  </CrmRightDrawer>

  <ConfirmModal
    :open="deleteConfirmOpen"
    title="Delete Event"
    message="Delete this calendar event? This cannot be undone."
    confirm-label="Delete Event"
    :busy="deleting"
    @close="deleteConfirmOpen = false"
    @confirm="confirmDelete"
  />
</template>
