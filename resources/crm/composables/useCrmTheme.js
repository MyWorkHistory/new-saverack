import { ref } from "vue";

const THEME_STORAGE = "crm-theme-mode";

/** @type {import('vue').Ref<'light'|'dark'|'system'>} */
export const themeMode = ref(
  typeof localStorage !== "undefined"
    ? localStorage.getItem(THEME_STORAGE) || "system"
    : "system",
);

function resolveEffectiveDark(mode) {
  if (mode === "dark") return true;
  if (mode === "light") return false;
  return (
    typeof window !== "undefined" &&
    window.matchMedia("(prefers-color-scheme: dark)").matches
  );
}

export function applyTheme(mode) {
  if (typeof document === "undefined") return;
  const dark = resolveEffectiveDark(mode);
  document.documentElement.setAttribute(
    "data-bs-theme",
    dark ? "dark" : "light",
  );
  document.documentElement.classList.toggle("dark", dark);
}

export function setThemeMode(mode) {
  if (mode !== "light" && mode !== "dark" && mode !== "system") return;
  themeMode.value = mode;
  try {
    localStorage.setItem(THEME_STORAGE, mode);
  } catch {
    /* ignore */
  }
  applyTheme(mode);
}

let mediaBound = false;

/** Call once from App.vue (authenticated bootstrap). */
export function initCrmTheme() {
  applyTheme(themeMode.value);
  if (typeof window !== "undefined" && !mediaBound) {
    mediaBound = true;
    window
      .matchMedia("(prefers-color-scheme: dark)")
      .addEventListener("change", () => {
        if (themeMode.value === "system") {
          applyTheme("system");
        }
      });
  }
}

export function useCrmTheme() {
  return { themeMode, setThemeMode, applyTheme };
}
