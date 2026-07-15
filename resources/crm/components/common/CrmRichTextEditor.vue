<script setup>
import { computed, onBeforeUnmount, watch } from "vue";
import { EditorContent, useEditor } from "@tiptap/vue-3";
import StarterKit from "@tiptap/starter-kit";
import Underline from "@tiptap/extension-underline";
import Link from "@tiptap/extension-link";

const props = defineProps({
  modelValue: { type: String, default: "" },
  disabled: { type: Boolean, default: false },
  ariaLabel: { type: String, default: "Rich text editor" },
});

const emit = defineEmits(["update:modelValue"]);

const editor = useEditor({
  content: props.modelValue || "",
  editable: !props.disabled,
  extensions: [
    StarterKit.configure({
      heading: { levels: [2, 3, 4] },
      code: false,
      codeBlock: false,
    }),
    Underline,
    Link.configure({
      openOnClick: false,
      HTMLAttributes: {
        rel: "noopener noreferrer",
        target: "_blank",
      },
    }),
  ],
  editorProps: {
    attributes: {
      class: "crm-rte__prose",
      "aria-label": props.ariaLabel,
    },
  },
  onUpdate: ({ editor: ed }) => {
    emit("update:modelValue", ed.getHTML());
  },
});

watch(
  () => props.modelValue,
  (value) => {
    if (!editor.value) return;
    const next = value || "";
    if (editor.value.getHTML() === next) return;
    editor.value.commands.setContent(next, false);
  },
);

watch(
  () => props.disabled,
  (disabled) => {
    editor.value?.setEditable(!disabled);
  },
);

onBeforeUnmount(() => {
  editor.value?.destroy();
});

const canBold = computed(() => editor.value?.can().chain().focus().toggleBold().run());
const canItalic = computed(() => editor.value?.can().chain().focus().toggleItalic().run());
const canUnderline = computed(() => editor.value?.can().chain().focus().toggleUnderline().run());
const canStrike = computed(() => editor.value?.can().chain().focus().toggleStrike().run());

function run(fn) {
  if (!editor.value || props.disabled) return;
  fn(editor.value.chain().focus());
}

function setLink() {
  if (!editor.value || props.disabled) return;
  const previous = editor.value.getAttributes("link").href || "";
  const url = window.prompt("Link URL", previous);
  if (url === null) return;
  const trimmed = url.trim();
  if (trimmed === "") {
    editor.value.chain().focus().extendMarkRange("link").unsetLink().run();
    return;
  }
  editor.value
    .chain()
    .focus()
    .extendMarkRange("link")
    .setLink({ href: trimmed })
    .run();
}
</script>

<template>
  <div class="crm-rte" :class="{ 'crm-rte--disabled': disabled }">
    <div v-if="editor" class="crm-rte__toolbar" role="toolbar" aria-label="Formatting">
      <button
        type="button"
        class="crm-rte__btn"
        title="Bold"
        :class="{ 'crm-rte__btn--active': editor.isActive('bold') }"
        :disabled="disabled || !canBold"
        @mousedown.prevent
        @click="run((c) => c.toggleBold().run())"
      >
        <strong>B</strong>
      </button>
      <button
        type="button"
        class="crm-rte__btn"
        title="Italic"
        :class="{ 'crm-rte__btn--active': editor.isActive('italic') }"
        :disabled="disabled || !canItalic"
        @mousedown.prevent
        @click="run((c) => c.toggleItalic().run())"
      >
        <em>I</em>
      </button>
      <button
        type="button"
        class="crm-rte__btn"
        title="Underline"
        :class="{ 'crm-rte__btn--active': editor.isActive('underline') }"
        :disabled="disabled || !canUnderline"
        @mousedown.prevent
        @click="run((c) => c.toggleUnderline().run())"
      >
        <span class="crm-rte__u">U</span>
      </button>
      <button
        type="button"
        class="crm-rte__btn"
        title="Strikethrough"
        :class="{ 'crm-rte__btn--active': editor.isActive('strike') }"
        :disabled="disabled || !canStrike"
        @mousedown.prevent
        @click="run((c) => c.toggleStrike().run())"
      >
        <s>S</s>
      </button>
      <span class="crm-rte__sep" aria-hidden="true" />
      <button
        type="button"
        class="crm-rte__btn"
        title="Heading 2"
        :class="{ 'crm-rte__btn--active': editor.isActive('heading', { level: 2 }) }"
        :disabled="disabled"
        @mousedown.prevent
        @click="run((c) => c.toggleHeading({ level: 2 }).run())"
      >
        H2
      </button>
      <button
        type="button"
        class="crm-rte__btn"
        title="Heading 3"
        :class="{ 'crm-rte__btn--active': editor.isActive('heading', { level: 3 }) }"
        :disabled="disabled"
        @mousedown.prevent
        @click="run((c) => c.toggleHeading({ level: 3 }).run())"
      >
        H3
      </button>
      <button
        type="button"
        class="crm-rte__btn"
        title="Heading 4"
        :class="{ 'crm-rte__btn--active': editor.isActive('heading', { level: 4 }) }"
        :disabled="disabled"
        @mousedown.prevent
        @click="run((c) => c.toggleHeading({ level: 4 }).run())"
      >
        H4
      </button>
      <span class="crm-rte__sep" aria-hidden="true" />
      <button
        type="button"
        class="crm-rte__btn"
        title="Bulleted List"
        :class="{ 'crm-rte__btn--active': editor.isActive('bulletList') }"
        :disabled="disabled"
        @mousedown.prevent
        @click="run((c) => c.toggleBulletList().run())"
      >
        • List
      </button>
      <button
        type="button"
        class="crm-rte__btn"
        title="Numbered List"
        :class="{ 'crm-rte__btn--active': editor.isActive('orderedList') }"
        :disabled="disabled"
        @mousedown.prevent
        @click="run((c) => c.toggleOrderedList().run())"
      >
        1. List
      </button>
      <button
        type="button"
        class="crm-rte__btn"
        title="Blockquote"
        :class="{ 'crm-rte__btn--active': editor.isActive('blockquote') }"
        :disabled="disabled"
        @mousedown.prevent
        @click="run((c) => c.toggleBlockquote().run())"
      >
        Quote
      </button>
      <span class="crm-rte__sep" aria-hidden="true" />
      <button
        type="button"
        class="crm-rte__btn"
        title="Link"
        :class="{ 'crm-rte__btn--active': editor.isActive('link') }"
        :disabled="disabled"
        @mousedown.prevent
        @click="setLink"
      >
        Link
      </button>
      <button
        type="button"
        class="crm-rte__btn"
        title="Undo"
        :disabled="disabled || !editor.can().chain().focus().undo().run()"
        @mousedown.prevent
        @click="run((c) => c.undo().run())"
      >
        Undo
      </button>
      <button
        type="button"
        class="crm-rte__btn"
        title="Redo"
        :disabled="disabled || !editor.can().chain().focus().redo().run()"
        @mousedown.prevent
        @click="run((c) => c.redo().run())"
      >
        Redo
      </button>
    </div>
    <EditorContent :editor="editor" class="crm-rte__editor" />
  </div>
</template>

<style scoped>
.crm-rte {
  display: flex;
  flex-direction: column;
  border: 1px solid rgba(47, 43, 61, 0.16);
  border-radius: 0.5rem;
  background: #fff;
  overflow: hidden;
  min-height: calc(100vh - 240px);
  max-height: calc(100vh - 180px);
}
.crm-rte--disabled {
  opacity: 0.75;
  background: #f8f9fa;
}
.crm-rte__toolbar {
  display: flex;
  flex-wrap: wrap;
  gap: 0.35rem;
  padding: 0.5rem 0.6rem;
  border-bottom: 1px solid rgba(47, 43, 61, 0.12);
  background: #f8f9fb;
  flex-shrink: 0;
}
.crm-rte__sep {
  width: 1px;
  align-self: stretch;
  background: rgba(47, 43, 61, 0.12);
  margin: 0 0.15rem;
}
.crm-rte__btn {
  border: 1px solid rgba(47, 43, 61, 0.14);
  background: #fff;
  border-radius: 0.35rem;
  padding: 0.25rem 0.55rem;
  font-size: 0.78rem;
  line-height: 1.2;
  color: #2f2b3d;
}
.crm-rte__btn--active {
  background: rgba(37, 115, 186, 0.12);
  border-color: rgba(37, 115, 186, 0.35);
  color: #1e58b7;
}
.crm-rte__btn:disabled {
  opacity: 0.55;
  cursor: not-allowed;
}
.crm-rte__u {
  text-decoration: underline;
  font-weight: 600;
}
.crm-rte__editor {
  flex: 1 1 auto;
  min-height: 0;
  overflow-y: auto;
}
.crm-rte__editor :deep(.crm-rte__prose),
.crm-rte__editor :deep(.ProseMirror) {
  min-height: 100%;
  padding: 0.9rem 1rem 1.25rem;
  font-size: 0.925rem;
  line-height: 1.55;
  outline: none;
}
.crm-rte__editor :deep(.ProseMirror p) {
  margin: 0 0 0.65rem;
}
.crm-rte__editor :deep(.ProseMirror ul),
.crm-rte__editor :deep(.ProseMirror ol) {
  margin: 0 0 0.65rem;
  padding-left: 1.25rem;
}
.crm-rte__editor :deep(.ProseMirror h2),
.crm-rte__editor :deep(.ProseMirror h3),
.crm-rte__editor :deep(.ProseMirror h4) {
  margin: 0.85rem 0 0.45rem;
  font-size: 1.05rem;
  line-height: 1.3;
}
.crm-rte__editor :deep(.ProseMirror blockquote) {
  margin: 0 0 0.85rem;
  padding-left: 0.85rem;
  border-left: 3px solid rgba(47, 43, 61, 0.2);
  color: #555;
}
.crm-rte__editor :deep(.ProseMirror a) {
  color: #2573ba;
}
</style>
