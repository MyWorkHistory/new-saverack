<script setup>
import { computed, onMounted, onUnmounted, ref } from "vue";
import CrmMaterialIcon from "../common/CrmMaterialIcon.vue";
import {
  PROJECT_STATUSES,
  projectStatusDisplay,
} from "../../utils/projectStatusDisplay.js";

const props = defineProps({
  status: { type: String, default: "pending" },
  disabled: { type: Boolean, default: false },
});

const emit = defineEmits(["change"]);

const open = ref(false);
const menuStyle = ref({});
const btnRef = ref(null);

const display = computed(() => projectStatusDisplay(props.status));

function toggle(e) {
  e.stopPropagation();
  if (props.disabled) return;
  if (open.value) {
    open.value = false;
    return;
  }
  const rect = btnRef.value?.getBoundingClientRect?.();
  if (rect) {
    menuStyle.value = {
      position: "fixed",
      top: `${Math.round(rect.bottom + 4)}px`,
      left: `${Math.round(rect.left)}px`,
      zIndex: 1080,
      minWidth: "160px",
    };
  }
  open.value = true;
}

function pick(status) {
  open.value = false;
  if (status !== props.status) {
    emit("change", status);
  }
}

function onDocClick() {
  open.value = false;
}

onMounted(() => document.addEventListener("click", onDocClick));
onUnmounted(() => document.removeEventListener("click", onDocClick));
</script>

<template>
  <div class="project-status-chip position-relative d-inline-block" @click.stop>
    <button
      ref="btnRef"
      type="button"
      class="project-status-chip__btn"
      :disabled="disabled"
      :aria-expanded="open"
      @click="toggle"
    >
      <span class="project-status-chip__icon" :style="display.iconStyle">
        <CrmMaterialIcon :name="display.icon" :size="16" />
      </span>
      <span class="project-status-chip__label" :style="{ color: display.labelColor }">
        {{ display.label }}
      </span>
    </button>
    <Teleport to="body">
      <ul
        v-if="open"
        class="dropdown-menu show shadow project-status-chip__menu"
        :style="menuStyle"
        role="menu"
      >
        <li v-for="s in PROJECT_STATUSES" :key="s">
          <button
            type="button"
            class="dropdown-item"
            :class="{ active: s === status }"
            role="menuitem"
            @click="pick(s)"
          >
            {{ projectStatusDisplay(s).label }}
          </button>
        </li>
      </ul>
    </Teleport>
  </div>
</template>

<style scoped>
.project-status-chip__btn {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  border: 0;
  background: transparent;
  padding: 0.15rem 0.25rem;
  border-radius: 0.375rem;
  cursor: pointer;
}
.project-status-chip__btn:hover:not(:disabled) {
  background: rgba(0, 0, 0, 0.04);
}
.project-status-chip__btn:disabled {
  cursor: default;
  opacity: 0.7;
}
.project-status-chip__icon {
  width: 1.5rem;
  height: 1.5rem;
  border-radius: 999px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
}
.project-status-chip__label {
  font-size: 0.8125rem;
  font-weight: 600;
  white-space: nowrap;
}
</style>
