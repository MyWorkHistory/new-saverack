<script setup>
import { computed, onUnmounted, reactive, ref, watch } from "vue";
import api from "../../services/api";
import CrmRichTextEditor from "../common/CrmRichTextEditor.vue";
import { useToast } from "../../composables/useToast";
import { errorMessage } from "../../utils/apiError";
import {
  CRM_BTN_PRIMARY,
  CRM_BTN_SECONDARY,
  CRM_DIALOG_FOOTER_CLASS_DRAWER,
} from "../../constants/dialogFooter.js";

const toast = useToast();

const props = defineProps({
  open: { type: Boolean, default: false },
  categories: { type: Array, default: () => [] },
});

const emit = defineEmits(["update:open", "saved"]);

const saving = ref(false);
const errorMsg = ref("");
const loadingSupport = ref(false);
const localCategories = ref([]);

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

function resetForm() {
  form.title = "";
  form.description = "";
  form.category = "";
  errorMsg.value = "";
}

async function ensureSupportData() {
  loadingSupport.value = true;
  try {
    if (!props.categories.length) {
      const { data } = await api.get("/resources/tutorials/meta");
      localCategories.value = data.categories || [];
    } else {
      localCategories.value = [];
    }
  } catch {
    localCategories.value = [];
  } finally {
    loadingSupport.value = false;
  }
}

const displayCategories = computed(() =>
  props.categories.length ? props.categories : localCategories.value,
);

watch(
  () => props.open,
  async (isOpen) => {
    if (!isOpen) return;
    await ensureSupportData();
    resetForm();
  },
);

function onEsc(e) {
  if (e.key === "Escape" && props.open && !saving.value && !loadingSupport.value) {
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

function close() {
  emit("update:open", false);
}

async function onSubmit() {
  saving.value = true;
  errorMsg.value = "";
  try {
    await api.post("/resources/tutorials", {
      title: form.title.trim(),
      description: normalizeDescription(form.description),
      category: form.category,
    });
    toast.success("Tutorial created.");
    emit("saved");
    close();
    resetForm();
  } catch (e) {
    errorMsg.value = errorMessage(e, "Could not save tutorial.");
    toast.errorFrom(e, "Could not save tutorial.");
  } finally {
    saving.value = false;
  }
}

function onBackdropClick() {
  if (!saving.value && !loadingSupport.value) close();
}
</script>

<template>
  <Teleport to="body">
    <Transition name="drawer-fade">
      <div
        v-if="open"
        class="fixed inset-0 z-[1200] flex h-[100dvh] max-h-[100dvh] justify-end overflow-hidden"
        aria-modal="true"
        role="dialog"
        aria-labelledby="tutorial-drawer-title"
      >
        <div
          class="absolute inset-0 bg-gray-900/40 backdrop-blur-[1px] dark:bg-black/50"
          aria-hidden="true"
          @click="onBackdropClick"
        />
        <Transition name="drawer-slide" appear>
          <aside
            class="relative flex h-full max-h-full min-h-0 w-full max-w-xl flex-col overflow-hidden border-l border-gray-200 bg-white shadow-2xl dark:border-gray-700 dark:bg-gray-900 sm:max-w-2xl"
          >
            <header
              class="flex shrink-0 items-center justify-between border-b border-gray-200 px-5 py-4 dark:border-gray-800"
            >
              <h2 id="tutorial-drawer-title" class="text-lg font-semibold text-gray-900 dark:text-white">
                Add Tutorial
              </h2>
              <button
                type="button"
                class="rounded-lg p-2 text-gray-500 transition hover:bg-gray-100 hover:text-gray-800 dark:hover:bg-white/10 dark:hover:text-white"
                aria-label="Close"
                :disabled="saving"
                @click="close"
              >
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
            </header>

            <div class="min-h-0 flex-1 overflow-y-auto overflow-x-hidden overscroll-y-contain px-5 py-4">
              <div v-if="loadingSupport" class="flex justify-center py-10 text-sm text-gray-500">Loading…</div>
              <template v-else>
                <p v-if="errorMsg" class="mb-4 text-sm text-red-600">{{ errorMsg }}</p>
                <form id="tutorial-drawer-form" class="space-y-4" @submit.prevent="onSubmit">
                  <div>
                    <label class="mb-1 block text-xs font-medium text-gray-500">Title<span class="text-red-500">*</span></label>
                    <input
                      v-model="form.title"
                      type="text"
                      required
                      maxlength="255"
                      class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                    />
                  </div>
                  <div>
                    <label class="mb-1 block text-xs font-medium text-gray-500">Description</label>
                    <CrmRichTextEditor
                      v-model="form.description"
                      :disabled="saving"
                      aria-label="Tutorial description"
                    />
                  </div>
                  <div>
                    <label class="mb-1 block text-xs font-medium text-gray-500">Category<span class="text-red-500">*</span></label>
                    <select
                      v-model="form.category"
                      required
                      class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                    >
                      <option value="" disabled>Select category</option>
                      <option v-for="c in displayCategories" :key="c.value" :value="c.value">{{ c.label }}</option>
                    </select>
                  </div>
                </form>
              </template>
            </div>

            <footer :class="CRM_DIALOG_FOOTER_CLASS_DRAWER">
              <button type="button" :class="CRM_BTN_SECONDARY" :disabled="saving" @click="close">Cancel</button>
              <button type="submit" form="tutorial-drawer-form" :class="CRM_BTN_PRIMARY" :disabled="saving || loadingSupport">
                {{ saving ? "Saving…" : "Save Tutorial" }}
              </button>
            </footer>
          </aside>
        </Transition>
      </div>
    </Transition>
  </Teleport>
</template>
