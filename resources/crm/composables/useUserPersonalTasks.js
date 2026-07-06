import { computed, ref } from "vue";
import api from "../services/api";
import { useToast } from "./useToast.js";

const tasks = ref([]);
const incompleteCount = ref(0);
const totalCount = ref(0);
const maxTasks = ref(10);
const loading = ref(false);
const saving = ref(false);

function applyPayload(data) {
  tasks.value = Array.isArray(data?.tasks) ? [...data.tasks] : [];
  incompleteCount.value = Number(data?.incomplete_count || 0);
  totalCount.value = Number(data?.total_count || 0);
  maxTasks.value = Number(data?.max_tasks || 10);
}

function sortTasksClient(rows) {
  const incomplete = rows.filter((t) => !t.is_completed).sort((a, b) => {
    return String(b.created_at || "").localeCompare(String(a.created_at || ""));
  });
  const completed = rows.filter((t) => t.is_completed).sort((a, b) => {
    return String(b.completed_at || "").localeCompare(String(a.completed_at || ""));
  });
  return [...incomplete, ...completed];
}

export function useUserPersonalTasks() {
  const toast = useToast();

  const canAdd = computed(() => totalCount.value < maxTasks.value);
  const incompleteTasks = computed(() => tasks.value.filter((t) => !t.is_completed));
  const completedTasks = computed(() => tasks.value.filter((t) => t.is_completed));

  async function load() {
    loading.value = true;
    try {
      const { data } = await api.get("/me/personal-tasks");
      applyPayload(data);
    } catch (e) {
      applyPayload({});
      toast.errorFrom(e, "Could not load tasks.");
      throw e;
    } finally {
      loading.value = false;
    }
  }

  async function addTask(title) {
    const trimmed = String(title || "").trim();
    if (!trimmed || saving.value) return false;

    saving.value = true;
    try {
      const { data } = await api.post("/me/personal-tasks", { title: trimmed });
      const next = sortTasksClient([data, ...tasks.value.filter((t) => t.id !== data.id)]);
      tasks.value = next;
      totalCount.value = next.length;
      incompleteCount.value = next.filter((t) => !t.is_completed).length;
      return true;
    } catch (e) {
      toast.errorFrom(e, "Could not add task.");
      return false;
    } finally {
      saving.value = false;
    }
  }

  async function toggleTask(task) {
    if (!task?.id || saving.value) return;

    const previous = tasks.value.map((t) => ({ ...t }));
    const nextCompleted = !task.is_completed;
    const optimistic = tasks.value.map((t) =>
      t.id === task.id
        ? {
            ...t,
            is_completed: nextCompleted,
            completed_at: nextCompleted ? new Date().toISOString() : null,
          }
        : t,
    );
    tasks.value = sortTasksClient(optimistic);
    incompleteCount.value = tasks.value.filter((t) => !t.is_completed).length;

    saving.value = true;
    try {
      const { data } = await api.patch(`/me/personal-tasks/${task.id}`, {
        is_completed: nextCompleted,
      });
      tasks.value = sortTasksClient(
        tasks.value.map((t) => (t.id === data.id ? data : t)),
      );
      incompleteCount.value = tasks.value.filter((t) => !t.is_completed).length;
    } catch (e) {
      tasks.value = previous;
      incompleteCount.value = previous.filter((t) => !t.is_completed).length;
      toast.errorFrom(e, "Could not update task.");
    } finally {
      saving.value = false;
    }
  }

  async function deleteTask(task) {
    if (!task?.id || saving.value) return;

    const previous = tasks.value.map((t) => ({ ...t }));
    tasks.value = tasks.value.filter((t) => t.id !== task.id);
    totalCount.value = tasks.value.length;
    incompleteCount.value = tasks.value.filter((t) => !t.is_completed).length;

    saving.value = true;
    try {
      await api.delete(`/me/personal-tasks/${task.id}`);
    } catch (e) {
      tasks.value = previous;
      totalCount.value = previous.length;
      incompleteCount.value = previous.filter((t) => !t.is_completed).length;
      toast.errorFrom(e, "Could not delete task.");
    } finally {
      saving.value = false;
    }
  }

  return {
    tasks,
    incompleteCount,
    totalCount,
    maxTasks,
    loading,
    saving,
    canAdd,
    incompleteTasks,
    completedTasks,
    load,
    addTask,
    toggleTask,
    deleteTask,
  };
}
