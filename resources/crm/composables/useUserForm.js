import { reactive, ref } from "vue";
import api from "../services/api";

export function useUserForm() {
  const loading = ref(false);
  const saving = ref(false);
  const errorMsg = ref("");
  const fieldErrors = ref({});
  const roles = ref([]);

  const form = reactive({
    name: "",
    email: "",
    password: "",
    phone: "",
    status: "pending",
    role_ids: [],
  });

  async function loadRoles() {
    try {
      const { data } = await api.get("/roles");
      roles.value = Array.isArray(data) ? data : [];
    } catch {
      roles.value = [];
    }
  }

  async function loadUser(userId) {
    if (!userId) return;
    const { data } = await api.get(`/users/${userId}`);
    form.name = data.name || "";
    form.email = data.email || "";
    form.password = "";
    form.phone = data.profile?.phone || data.phone || "";
    form.status = data.status || "pending";
    form.role_ids = (data.roles || []).map((r) => r.id);
  }

  function resetForCreate() {
    form.name = "";
    form.email = "";
    form.password = "";
    form.phone = "";
    form.status = "pending";
    form.role_ids = [];
    errorMsg.value = "";
    fieldErrors.value = {};
  }

  function toggleRole(id) {
    const n = Number(id);
    const i = form.role_ids.indexOf(n);
    if (i === -1) {
      form.role_ids.push(n);
    } else {
      form.role_ids.splice(i, 1);
    }
  }

  function roleChecked(id) {
    return form.role_ids.includes(Number(id));
  }

  function clearFieldError(key) {
    if (!fieldErrors.value[key]) return;
    const next = { ...fieldErrors.value };
    delete next[key];
    fieldErrors.value = next;
  }

  function firstError(key) {
    const v = fieldErrors.value[key];
    return Array.isArray(v) && v.length ? v[0] : "";
  }

  /**
   * @returns {Promise<boolean>}
   */
  async function submit({ isEdit, userId }) {
    saving.value = true;
    errorMsg.value = "";
    fieldErrors.value = {};
    try {
      const payload = {
        name: form.name.trim(),
        email: form.email.trim(),
        status: form.status,
        phone: form.phone?.trim() || null,
        role_ids: form.role_ids,
      };
      if (isEdit && userId) {
        if (form.password.trim()) {
          payload.password = form.password;
        }
        await api.put(`/users/${userId}`, payload);
      } else {
        payload.password = form.password;
        await api.post("/users", payload);
      }
      return true;
    } catch (e) {
      if (e.response?.status === 422 && e.response.data?.errors) {
        fieldErrors.value = e.response.data.errors;
      } else {
        const msg =
          e.response?.data?.message ||
          e.response?.data?.error ||
          "Could not save user.";
        errorMsg.value =
          typeof msg === "string" ? msg : "Could not save user.";
      }
      return false;
    } finally {
      saving.value = false;
    }
  }

  return {
    loading,
    saving,
    errorMsg,
    fieldErrors,
    roles,
    form,
    loadRoles,
    loadUser,
    resetForCreate,
    submit,
    toggleRole,
    roleChecked,
    clearFieldError,
    firstError,
  };
}
