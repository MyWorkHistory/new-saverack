import { reactive } from "vue";
import { errorMessage } from "../utils/apiError";

const state = reactive({
  items: [],
});

let nextId = 0;

function removeToast(id) {
  const i = state.items.findIndex((x) => x.id === id);
  if (i !== -1) {
    state.items.splice(i, 1);
  }
}

function push(type, message, durationMs) {
  const id = ++nextId;
  const item = { id, type, message: String(message) };
  state.items.push(item);
  const ms = durationMs ?? (type === "error" ? 7000 : 4500);
  if (ms > 0) {
    setTimeout(() => removeToast(id), ms);
  }
  return id;
}

/** Shared toast queue (mount ToastStack once in App.vue). */
export function useToast() {
  return {
    items: state.items,
    success: (msg, durationMs) => push("success", msg, durationMs),
    error: (msg, durationMs) => push("error", msg, durationMs),
    /** Prefer this for catch blocks: extracts Laravel message / validation. */
    errorFrom: (e, fallback) => push("error", errorMessage(e, fallback), 7000),
    remove: removeToast,
  };
}
