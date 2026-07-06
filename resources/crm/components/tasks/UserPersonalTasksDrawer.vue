<script setup>
import { computed, onUnmounted, ref, watch } from "vue";
import CrmRightDrawer from "../common/CrmRightDrawer.vue";
import CrmMaterialIcon from "../common/CrmMaterialIcon.vue";
import { useUserPersonalTasks } from "../../composables/useUserPersonalTasks.js";
import { CRM_BTN_PRIMARY } from "../../constants/dialogFooter.js";

const props = defineProps({
  open: { type: Boolean, default: false },
});

const emit = defineEmits(["update:open"]);

const newTitle = ref("");

const {
  loading,
  saving,
  canAdd,
  totalCount,
  maxTasks,
  incompleteTasks,
  completedTasks,
  load,
  addTask,
  toggleTask,
  deleteTask,
} = useUserPersonalTasks();

const atCap = computed(() => !canAdd.value);

const capHint = computed(() => {
  if (!atCap.value) return "";
  return `${maxTasks.value} of ${maxTasks.value} tasks — delete one to add another.`;
});

const showEmpty = computed(
  () => !loading.value && incompleteTasks.value.length === 0 && completedTasks.value.length === 0,
);

function close() {
  emit("update:open", false);
}

async function onOpen() {
  try {
    await load();
  } catch {
    /* toast handled */
  }
}

watch(
  () => props.open,
  (isOpen) => {
    if (isOpen) {
      newTitle.value = "";
      void onOpen();
    }
  },
);

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

onUnmounted(() => {
  document.removeEventListener("keydown", onEsc);
});

async function submitAdd() {
  const title = newTitle.value.trim();
  if (!title || !canAdd.value || saving.value) return;
  const ok = await addTask(title);
  if (ok) newTitle.value = "";
}

function onAddKeydown(e) {
  if (e.key === "Enter") {
    e.preventDefault();
    void submitAdd();
  }
}
</script>

<template>
  <CrmRightDrawer
    :open="open"
    title="Tasks"
    subtitle="Up to 10 personal tasks"
    max-width="xl"
    @update:open="emit('update:open', $event)"
    @close="close"
  >
    <div class="user-personal-tasks">
      <div class="user-tasks-drawer-add">
        <input
          v-model="newTitle"
          type="text"
          class="form-control"
          placeholder="What do you need to do?"
          maxlength="255"
          :disabled="saving || atCap"
          @keydown="onAddKeydown"
        />
        <button
          type="button"
          class="btn flex-shrink-0"
          :class="CRM_BTN_PRIMARY"
          :disabled="saving || atCap || !newTitle.trim()"
          @click="submitAdd"
        >
          Add Task
        </button>
      </div>

      <p v-if="atCap" class="user-tasks-cap-hint mb-0">{{ capHint }}</p>

      <div v-if="loading" class="user-tasks-empty">Loading tasks…</div>

      <template v-else>
        <p v-if="showEmpty" class="user-tasks-empty mb-0">No tasks yet. Add one above.</p>

        <div v-if="incompleteTasks.length">
          <div
            v-for="task in incompleteTasks"
            :key="`task-active-${task.id}`"
            class="user-tasks-row"
          >
            <button
              type="button"
              class="user-tasks-check"
              :aria-label="`Mark “${task.title}” complete`"
              :disabled="saving"
              @click="toggleTask(task)"
            >
              <CrmMaterialIcon name="checkCircleOutline" :size="22" />
            </button>
            <span class="user-tasks-title">{{ task.title }}</span>
            <button
              type="button"
              class="user-tasks-delete"
              :aria-label="`Delete “${task.title}”`"
              :disabled="saving"
              @click="deleteTask(task)"
            >
              <CrmMaterialIcon name="close" :size="18" />
            </button>
          </div>
        </div>

        <p v-if="completedTasks.length" class="user-tasks-section-label mb-0">Completed</p>

        <div v-if="completedTasks.length">
          <div
            v-for="task in completedTasks"
            :key="`task-done-${task.id}`"
            class="user-tasks-row user-tasks-row--done"
          >
            <button
              type="button"
              class="user-tasks-check user-tasks-check--done"
              :aria-label="`Mark “${task.title}” incomplete`"
              :disabled="saving"
              @click="toggleTask(task)"
            >
              <CrmMaterialIcon name="checkCircle" :size="22" />
            </button>
            <span class="user-tasks-title">{{ task.title }}</span>
            <button
              type="button"
              class="user-tasks-delete"
              :aria-label="`Delete “${task.title}”`"
              :disabled="saving"
              @click="deleteTask(task)"
            >
              <CrmMaterialIcon name="close" :size="18" />
            </button>
          </div>
        </div>
      </template>
    </div>
  </CrmRightDrawer>
</template>
