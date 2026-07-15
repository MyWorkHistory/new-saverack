<script setup>
import { computed, inject, onMounted, ref } from "vue";
import api from "../../services/api";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import CrmRichTextEditor from "../../components/common/CrmRichTextEditor.vue";
import { useToast } from "../../composables/useToast.js";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { crmIsAdmin } from "../../utils/crmUser.js";
import { allValidationMessages } from "../../utils/apiError.js";

const crmUser = inject("crmUser", ref(null));
const toast = useToast();

const loading = ref(true);
const saving = ref(false);
const editing = ref(false);
const body = ref("");
const draft = ref("");
const publicUrl = ref("");
const errorMsg = ref("");

const canUpdate = computed(() => {
  const u = crmUser.value;
  if (!u) return false;
  if (crmIsAdmin(u) || u.is_crm_owner) return true;
  return Array.isArray(u.permission_keys) && u.permission_keys.includes("settings.update");
});

setCrmPageMeta({
  title: "Save Rack | Terms of Service",
  description: "Default Terms of Service for client accounts.",
});

async function load() {
  loading.value = true;
  errorMsg.value = "";
  try {
    const { data } = await api.get("/settings/terms-of-service");
    body.value = data?.body || "";
    draft.value = body.value;
    publicUrl.value = data?.public_url || "/terms";
  } catch (e) {
    toast.errorFrom(e, "Could not load Terms of Service.");
  } finally {
    loading.value = false;
  }
}

function startEdit() {
  draft.value = body.value;
  errorMsg.value = "";
  editing.value = true;
}

function cancelEdit() {
  draft.value = body.value;
  errorMsg.value = "";
  editing.value = false;
}

async function save() {
  if (!canUpdate.value) return;
  saving.value = true;
  errorMsg.value = "";
  try {
    const { data } = await api.put("/settings/terms-of-service", {
      body: draft.value || "",
    });
    body.value = data?.body || "";
    draft.value = body.value;
    publicUrl.value = data?.public_url || publicUrl.value;
    editing.value = false;
    toast.success("Terms of Service saved.");
  } catch (e) {
    errorMsg.value = allValidationMessages(e, "Could not save Terms of Service.");
    toast.errorFrom(e, "Could not save Terms of Service.");
  } finally {
    saving.value = false;
  }
}

function openPublic() {
  const url = publicUrl.value || "/terms";
  window.open(url, "_blank", "noopener,noreferrer");
}

onMounted(load);
</script>

<template>
  <div>
    <div
      class="d-flex flex-column flex-md-row align-items-start align-items-md-center gap-3 mb-4"
    >
      <div class="min-w-0 flex-grow-1">
        <h1 class="h4 mb-1 fw-semibold text-body">Terms of Service</h1>
        <p class="text-secondary small mb-0">
          Default agreement for all accounts. Account-specific edits do not change this.
        </p>
      </div>
      <div class="d-flex flex-wrap align-items-center gap-2 ms-md-auto flex-shrink-0">
        <button
          type="button"
          class="btn btn-outline-secondary"
          :disabled="loading"
          @click="openPublic"
        >
          View Public Link
        </button>
        <button
          v-if="canUpdate && !editing"
          type="button"
          class="btn btn-primary staff-page-primary"
          :disabled="loading"
          @click="startEdit"
        >
          Edit
        </button>
      </div>
    </div>

    <div v-if="loading" class="staff-surface p-4 d-flex justify-content-center">
      <CrmLoadingSpinner message="Loading…" />
    </div>

    <div v-else class="staff-surface p-3 p-md-4">
      <p v-if="errorMsg" class="small text-danger mb-3">{{ errorMsg }}</p>

      <template v-if="editing">
        <label class="form-label small fw-semibold">Agreement</label>
        <CrmRichTextEditor
          v-model="draft"
          :disabled="saving"
          aria-label="Terms of Service"
        />
        <div class="d-flex justify-content-end gap-2 mt-3">
          <button
            type="button"
            class="btn btn-outline-secondary"
            :disabled="saving"
            @click="cancelEdit"
          >
            Cancel
          </button>
          <button
            type="button"
            class="btn btn-primary staff-page-primary"
            :disabled="saving"
            @click="save"
          >
            {{ saving ? "Saving…" : "Save" }}
          </button>
        </div>
      </template>

      <template v-else>
        <div
          v-if="body && body.replace(/<[^>]+>/g, '').trim()"
          class="crm-terms-preview"
          v-html="body"
        />
        <p v-else class="text-secondary mb-0">No Terms of Service have been set yet.</p>
      </template>
    </div>
  </div>
</template>

<style scoped>
.crm-terms-preview {
  max-height: 420px;
  overflow-y: auto;
  font-size: 0.925rem;
  line-height: 1.55;
}
.crm-terms-preview :deep(p) {
  margin: 0 0 0.65rem;
}
.crm-terms-preview :deep(ul),
.crm-terms-preview :deep(ol) {
  margin: 0 0 0.65rem;
  padding-left: 1.25rem;
}
.crm-terms-preview :deep(h2),
.crm-terms-preview :deep(h3),
.crm-terms-preview :deep(h4) {
  margin: 0.85rem 0 0.45rem;
  font-size: 1.05rem;
  line-height: 1.3;
}
</style>
