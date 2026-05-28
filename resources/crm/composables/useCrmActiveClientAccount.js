import { ref, watch } from "vue";

const STORAGE_KEY = "crm.active_client_account_id";

const activeClientAccountId = ref(readStored());

function readStored() {
  if (typeof localStorage === "undefined") return null;
  const raw = localStorage.getItem(STORAGE_KEY);
  if (raw === null || raw === "") return null;
  const n = Number(raw);
  return Number.isFinite(n) && n > 0 ? n : null;
}

function persist(id) {
  if (typeof localStorage === "undefined") return;
  if (id === null || id === undefined || id === "") {
    localStorage.removeItem(STORAGE_KEY);
    return;
  }
  localStorage.setItem(STORAGE_KEY, String(id));
}

watch(activeClientAccountId, (id) => {
  persist(id);
});

export function useCrmActiveClientAccount() {
  function setActiveClientAccountId(id) {
    if (id === null || id === undefined || id === "") {
      activeClientAccountId.value = null;
      return;
    }
    const n = Number(id);
    activeClientAccountId.value = Number.isFinite(n) && n > 0 ? n : null;
  }

  function clearActiveClientAccount() {
    activeClientAccountId.value = null;
  }

  return {
    activeClientAccountId,
    setActiveClientAccountId,
    clearActiveClientAccount,
  };
}
