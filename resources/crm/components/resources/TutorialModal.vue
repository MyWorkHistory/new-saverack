<script setup>
import { computed, onUnmounted, reactive, ref, watch } from "vue";
import api from "../../services/api";
import CrmLoadingSpinner from "../common/CrmLoadingSpinner.vue";
import CrmRichTextEditor from "../common/CrmRichTextEditor.vue";
import { useToast } from "../../composables/useToast";
import { errorMessage } from "../../utils/apiError";

const toast = useToast();

const props = defineProps({
  open: { type: Boolean, default: false },
  tutorial: { type: Object, default: null },
  categories: { type: Array, default: () => [] },
});

const emit = defineEmits(["update:open", "saved"]);

const saving = ref(false);
const errorMsg = ref("");
const loadingSupport = ref(false);
const localCategories = ref([]);

const displayCategories = computed(() =>
  props.categories.length ? props.categories : localCategories.value,
);

const form = reactive({
  title: "",
  description: "",
  category: "",
});

/** TipTap empty doc is often `<p></p>` — store null instead. */
function normalizeDescription(html) {
  const s = String(html || "").trim();
  if (!s) return null;
  const text = s.replace(/<[^>]+>/g, "").replace(/&nbsp;/gi, " ").trim();
  return text ? s : null;
}

function applyTutorial(t) {
  if (!t?.id) return;
  form.title = t.title || "";
  form.description = t.description || "";
  form.category = t.category || "";
  errorMsg.value = "";
}

async function ensureSupportData() {
  loadingSupport.value = true;
  try {
    if (!props.categories.length) {
      const { data } = await api.get("/resources/tutorials/meta");
      localCategories.value = data.categories || [];
    }
  } catch {
    localCategories.value = [];
  } finally {
    loadingSupport.value = false;
  }
}

watch(
  () => props.open,
  async (isOpen) => {
    if (!isOpen) return;
    await ensureSupportData();
    applyTutorial(props.tutorial);
  },
);

watch(
  () => props.tutorial,
  (t) => {
    if (props.open) applyTutorial(t);
  },
);

function close() {
  emit("update:open", false);
}

async function onSubmit() {
  if (!props.tutorial?.id) return;
  saving.value = true;
  errorMsg.value = "";
  try {
    await api.put(`/resources/tutorials/${props.tutorial.id}`, {
      title: form.title.trim(),
      description: normalizeDescription(form.description),
      category: form.category,
    });
    toast.success("Tutorial updated.");
    emit("saved");
    close();
  } catch (e) {
    errorMsg.value = errorMessage(e, "Could not update tutorial.");
    toast.errorFrom(e, "Could not update tutorial.");
  } finally {
    saving.value = false;
  }
}

function onEsc(e) {
  if (e.key === "Escape" && props.open && !saving.value) {
    e.preventDefault();
    close();
  }
}

watch(
  () => props.open,
  (o) => {
    if (o) document.addEventListener("keydown", onEsc);
    else document.removeEventListener("keydown", onEsc);
  },
);

onUnmounted(() => document.removeEventListener("keydown", onEsc));
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="crm-vx-modal-overlay" @click.self="close">
      <div class="crm-vx-modal crm-vx-modal--tutorial-edit" @click.stop>
        <header class="crm-vx-modal__head">
          <h2 class="crm-vx-modal__title">Edit Tutorial</h2>
        </header>
        <div class="crm-vx-modal__body">
          <div v-if="loadingSupport" class="py-4">
            <CrmLoadingSpinner message="Loading…" :center="true" />
          </div>
          <template v-else>
            <p v-if="errorMsg" class="small text-danger mb-3">{{ errorMsg }}</p>
            <form id="tutorial-edit-form" @submit.prevent="onSubmit">
              <label class="form-label small" for="tutorial-edit-title">Title</label>
              <input
                id="tutorial-edit-title"
                v-model="form.title"
                type="text"
                required
                maxlength="255"
                class="form-control mb-3"
                :disabled="saving"
              />
              <label class="form-label small" for="tutorial-edit-desc">Description</label>
              <div id="tutorial-edit-desc" class="mb-3 tutorial-edit-desc">
                <CrmRichTextEditor
                  v-model="form.description"
                  :disabled="saving"
                  aria-label="Tutorial description"
                />
              </div>
              <label class="form-label small" for="tutorial-edit-category">Category</label>
              <select
                id="tutorial-edit-category"
                v-model="form.category"
                required
                class="form-select"
                :disabled="saving"
              >
                <option v-for="c in displayCategories" :key="c.value" :value="c.value">{{ c.label }}</option>
              </select>
            </form>
          </template>
        </div>
        <footer class="crm-vx-modal__footer">
          <button type="button" class="crm-vx-modal-btn crm-vx-modal-btn--secondary" :disabled="saving" @click="close">
            Cancel
          </button>
          <button
            type="submit"
            form="tutorial-edit-form"
            class="crm-vx-modal-btn crm-vx-modal-btn--primary"
            :disabled="saving || loadingSupport"
          >
            {{ saving ? "Saving…" : "Save" }}
          </button>
        </footer>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.tutorial-edit-desc {
  min-height: 14rem;
}
</style>
