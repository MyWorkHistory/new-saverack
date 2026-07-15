<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from "vue";

const props = defineProps({
  modelValue: { type: String, default: "" },
  disabled: { type: Boolean, default: false },
  ariaLabel: { type: String, default: "Rich text editor" },
});

const emit = defineEmits(["update:modelValue"]);

const editorEl = ref(null);
let syncing = false;

function readHtml() {
  return editorEl.value ? editorEl.value.innerHTML : "";
}

function writeHtml(html) {
  if (!editorEl.value) return;
  const next = html || "";
  if (editorEl.value.innerHTML === next) return;
  syncing = true;
  editorEl.value.innerHTML = next;
  syncing = false;
}

function onInput() {
  if (syncing || props.disabled) return;
  emit("update:modelValue", readHtml());
}

function exec(cmd) {
  if (props.disabled || !editorEl.value) return;
  editorEl.value.focus();
  document.execCommand(cmd, false, null);
  onInput();
}

watch(
  () => props.modelValue,
  (v) => {
    if (!editorEl.value) return;
    if (readHtml() !== (v || "")) {
      writeHtml(v || "");
    }
  },
);

onMounted(() => {
  writeHtml(props.modelValue || "");
});

onBeforeUnmount(() => {
  editorEl.value = null;
});
</script>

<template>
  <div class="crm-rte" :class="{ 'crm-rte--disabled': disabled }">
    <div class="crm-rte__toolbar" role="toolbar" aria-label="Formatting">
      <button
        type="button"
        class="crm-rte__btn"
        title="Bold"
        :disabled="disabled"
        @mousedown.prevent
        @click="exec('bold')"
      >
        <strong>B</strong>
      </button>
      <button
        type="button"
        class="crm-rte__btn"
        title="Bulleted List"
        :disabled="disabled"
        @mousedown.prevent
        @click="exec('insertUnorderedList')"
      >
        • List
      </button>
    </div>
    <div
      ref="editorEl"
      class="crm-rte__editor"
      :contenteditable="disabled ? 'false' : 'true'"
      role="textbox"
      aria-multiline="true"
      :aria-label="ariaLabel"
      @input="onInput"
      @blur="onInput"
    />
  </div>
</template>

<style scoped>
.crm-rte {
  border: 1px solid rgba(47, 43, 61, 0.16);
  border-radius: 0.5rem;
  background: #fff;
  overflow: hidden;
}
.crm-rte--disabled {
  opacity: 0.7;
  background: #f8f9fa;
}
.crm-rte__toolbar {
  display: flex;
  gap: 0.35rem;
  padding: 0.4rem 0.5rem;
  border-bottom: 1px solid rgba(47, 43, 61, 0.12);
  background: #f8f9fb;
}
.crm-rte__btn {
  border: 1px solid rgba(47, 43, 61, 0.14);
  background: #fff;
  border-radius: 0.35rem;
  padding: 0.2rem 0.55rem;
  font-size: 0.8rem;
  line-height: 1.2;
  color: #2f2b3d;
}
.crm-rte__btn:disabled {
  opacity: 0.55;
  cursor: not-allowed;
}
.crm-rte__editor {
  min-height: 220px;
  max-height: 320px;
  overflow-y: auto;
  padding: 0.75rem 0.9rem;
  font-size: 0.925rem;
  line-height: 1.55;
  outline: none;
}
.crm-rte__editor :deep(p) {
  margin: 0 0 0.65rem;
}
.crm-rte__editor :deep(ul),
.crm-rte__editor :deep(ol) {
  margin: 0 0 0.65rem;
  padding-left: 1.25rem;
}
.crm-rte__editor :deep(h2),
.crm-rte__editor :deep(h3),
.crm-rte__editor :deep(h4) {
  margin: 0.85rem 0 0.45rem;
  font-size: 1.05rem;
  line-height: 1.3;
}
</style>
