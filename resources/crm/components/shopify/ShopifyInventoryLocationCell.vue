<script setup>
import { computed, onUnmounted, ref } from "vue";

const PREVIEW = 2;

const props = defineProps({
  locations: { type: Array, default: () => [] },
  label: { type: String, default: "Locations" },
  showPrimary: { type: Boolean, default: true },
});

let closeActive = null;

const open = ref(false);
const panelStyle = ref({});
const trigger = ref(null);

const names = computed(() =>
  (Array.isArray(props.locations) ? props.locations : [])
    .filter((loc) => Number(loc?.available) > 0)
    .map((loc) => String(loc?.name || "").trim())
    .filter(Boolean),
);

const visible = computed(() => names.value.slice(0, PREVIEW));
const extra = computed(() => Math.max(0, names.value.length - PREVIEW));

function placePanel() {
  const el = trigger.value;
  if (!el) return;
  const rect = el.getBoundingClientRect();
  const width = 280;
  const left = Math.max(8, Math.min(rect.left, window.innerWidth - width - 8));
  const estimated = 64 + names.value.length * 40;
  const spaceBelow = window.innerHeight - rect.bottom;
  const top = spaceBelow < estimated && rect.top > estimated ? rect.top - estimated - 6 : rect.bottom + 6;
  panelStyle.value = { top: `${top}px`, left: `${left}px`, width: `${width}px` };
}

function toggle(event) {
  event.stopPropagation();
  if (open.value) {
    open.value = false;
    return;
  }
  closeActive?.();
  open.value = true;
  placePanel();
  closeActive = () => {
    open.value = false;
  };
}

function onDocClick(event) {
  if (!open.value) return;
  if (event.target?.closest?.("[data-sip-loc-popover]")) return;
  if (trigger.value?.contains(event.target)) return;
  open.value = false;
}

document.addEventListener("click", onDocClick);
onUnmounted(() => {
  document.removeEventListener("click", onDocClick);
  if (closeActive && open.value) closeActive = null;
});
</script>

<template>
  <span v-if="!names.length" class="text-body">—</span>
  <span v-else class="sip-loc-cell" @click.stop>
    <span v-for="name in visible" :key="name" class="sip-loc-chip">{{ name }}</span>
    <button
      v-if="extra"
      ref="trigger"
      type="button"
      class="sip-loc-more"
      @click="toggle"
    >
      +{{ extra }} more
    </button>

    <Teleport to="body">
      <div
        v-if="open"
        data-sip-loc-popover
        class="sip-loc-popover"
        role="dialog"
        :aria-label="label"
        :style="panelStyle"
        @click.stop
      >
        <div class="sip-loc-popover__title">{{ label }} ({{ names.length }})</div>
        <ol class="sip-loc-popover__list">
          <li v-for="(name, index) in names" :key="`${name}-${index}`">
            <span class="sip-loc-popover__num">{{ index + 1 }}</span>
            <span class="sip-loc-popover__name">{{ name }}</span>
            <span v-if="showPrimary && index === 0" class="sip-loc-popover__primary">Primary</span>
          </li>
        </ol>
      </div>
    </Teleport>
  </span>
</template>

<style scoped>
.sip-loc-cell {
  display: inline-flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.35rem;
}
.sip-loc-chip {
  display: inline-flex;
  align-items: center;
  max-width: 8.5rem;
  padding: 0.12rem 0.5rem;
  border-radius: 0.4rem;
  background: #eef4ff;
  color: #2563eb;
  font-size: 0.78rem;
  font-weight: 600;
  line-height: 1.35;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.sip-loc-more {
  border: 0;
  background: transparent;
  padding: 0;
  color: #2563eb;
  font-size: 0.82rem;
  font-weight: 600;
  white-space: nowrap;
}
.sip-loc-more:hover {
  text-decoration: underline;
}
.sip-loc-popover {
  position: fixed;
  z-index: 400;
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 0.75rem;
  box-shadow: 0 16px 40px rgba(15, 23, 42, 0.14);
  padding: 0.85rem 0.95rem 0.7rem;
}
.sip-loc-popover__title {
  font-weight: 700;
  color: #111827;
  margin-bottom: 0.65rem;
}
.sip-loc-popover__list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 0.45rem;
}
.sip-loc-popover__list li {
  display: flex;
  align-items: center;
  gap: 0.55rem;
}
.sip-loc-popover__num {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 1.35rem;
  height: 1.35rem;
  border-radius: 999px;
  background: #f3f4f6;
  color: #6b7280;
  font-size: 0.72rem;
  font-weight: 700;
  flex-shrink: 0;
}
.sip-loc-popover__name {
  font-weight: 600;
  color: #111827;
  min-width: 0;
}
.sip-loc-popover__primary {
  margin-left: auto;
  padding: 0.08rem 0.45rem;
  border-radius: 999px;
  background: #eef4ff;
  color: #2563eb;
  font-size: 0.72rem;
  font-weight: 700;
}
</style>
