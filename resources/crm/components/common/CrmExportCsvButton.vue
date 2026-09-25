<script setup>
import { ref } from "vue";
import { useToast } from "../../composables/useToast.js";
import { downloadListCsv } from "../../utils/downloadListCsv.js";

const props = defineProps({
  path: { type: String, required: true },
  params: { type: Object, default: () => ({}) },
  filenameBase: { type: String, required: true },
  disabled: { type: Boolean, default: false },
  compact: { type: Boolean, default: false },
});

const toast = useToast();
const busy = ref(false);

async function runExport() {
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
  <button
    type="button"
    class="btn staff-page-primary d-inline-flex align-items-center gap-2"
    :class="compact ? 'btn-sm' : 'btn-primary'"
    :disabled="disabled || busy"
    @click="runExport"
  >
    <svg
      width="16"
      height="16"
      fill="none"
      stroke="currentColor"
      stroke-width="1.8"
      viewBox="0 0 24 24"
      aria-hidden="true"
    >
      <path
        stroke-linecap="round"
        stroke-linejoin="round"
        d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3"
      />
    </svg>
    {{ busy ? "Exporting…" : "Export" }}
  </button>
</template>
