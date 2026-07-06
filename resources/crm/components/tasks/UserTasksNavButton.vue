<script setup>
import { computed } from "vue";
import CrmMaterialIcon from "../common/CrmMaterialIcon.vue";
import { useUserPersonalTasks } from "../../composables/useUserPersonalTasks.js";

const props = defineProps({
  open: { type: Boolean, default: false },
});

const emit = defineEmits(["update:open"]);

const { incompleteCount } = useUserPersonalTasks();

const badgeLabel = computed(() => {
  const n = Number(incompleteCount.value || 0);
  if (n <= 0) return "";
  if (n > 9) return "9+";
  return String(n);
});

const ariaLabel = computed(() => {
  const n = Number(incompleteCount.value || 0);
  return n > 0 ? `Tasks, ${n} incomplete` : "Tasks";
});

function toggle() {
  emit("update:open", !props.open);
}
</script>

<template>
  <button
    type="button"
    class="btn vx-icon-btn user-tasks-nav-btn user-personal-tasks flex-shrink-0"
    :aria-label="ariaLabel"
    :aria-expanded="open ? 'true' : 'false'"
    @click="toggle"
  >
    <CrmMaterialIcon name="taskAlt" :size="22" />
    <span v-if="badgeLabel" class="user-tasks-nav-badge">{{ badgeLabel }}</span>
  </button>
</template>
