<script setup>
import { onMounted, onUnmounted, ref } from "vue";
import { useToast } from "../../composables/useToast.js";
import { downloadListCsv } from "../../utils/downloadListCsv.js";

const props = defineProps({
  path: { type: String, required: true },
  params: { type: Object, default: () => ({}) },
  filenameBase: { type: String, required: true },
  disabled: { type: Boolean, default: false },
});

const toast = useToast();
const open = ref(false);
const busy = ref(false);

function onDocClick(event) {
  if (!event.target?.closest?.("[data-export-root]")) {
    open.value = false;
  }
}

onMounted(() => document.addEventListener("click", onDocClick));
onUnmounted(() => document.removeEventListener("click", onDocClick));

async function runExport() {
  open.value = false;
  if (busy.value) return;
  busy.value = true;
  try {
    await downloadListCsv({
      path: props.path,
      params: props.params,
      filenameBase: props.filenameBase,
      toast,
    });
  } catch {
    /* toast handled in downloadListCsv */
  } finally {
    busy.value = false;
  }
}
</script>

<template>
  <div class="position-relative" data-export-root>
    <button
      type="button"
      class="btn btn-outline-secondary staff-toolbar-btn d-inline-flex align-items-center gap-2"
      :aria-expanded="open"
      :disabled="disabled || busy"
      @click.stop="open = !open"
    >
      <svg width="18" height="18" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
        <path d="M9 16h6v-6h4l-7-7-7 7h4v6zm-4 2h14v2H5v-2z" />
      </svg>
      Export
      <svg
        width="14"
        height="14"
        fill="currentColor"
        viewBox="0 0 24 24"
        class="text-secondary"
        aria-hidden="true"
      >
        <path d="M7 10l5 5 5-5H7z" />
      </svg>
    </button>
    <div
      v-if="open"
      class="dropdown-menu show shadow border px-0 py-1 mt-1"
      style="min-width: 11rem; right: 0; left: auto"
      @click.stop
    >
      <button type="button" class="dropdown-item small" :disabled="busy" @click="runExport">
        Download CSV
      </button>
    </div>
  </div>
</template>
